/* ZAS Attendance — gate and HOD phone app.
 *
 * ONE WRITE PATH. Every scan and every confirmation goes: phone storage
 * first, then the server. There is no "online shortcut" that posts straight
 * to the API, because a shortcut is a second code path, and the day the
 * signal drops half way through a request is the day the two disagree.
 *
 *   tap  →  AttDB.enqueue() commits  →  "Saved on this phone"
 *        →  sync sends it            →  server receipt  →  "Recorded by the server"
 *
 * The words on screen follow that exactly. "Saved on this phone" is never
 * shortened to "Saved" or "Recorded": until the server has answered, the scan
 * exists in one place only, and the gatekeeper is entitled to know that.
 *
 * Nothing secret is in this file. It holds no keys; it identifies itself with
 * a random device id and the signed-in session, and the server re-checks
 * permission on every request whatever this file chooses to show.
 */
(function () {
  'use strict';

  var API = '../api/v1/index.php?route=';
  var APP_VERSION = '1.0.0';
  var CHUNK = 50;
  var LOCK_AFTER_MS = 5 * 60 * 1000;

  var S = {
    screen: 'boot', tab: 'gate', user: null, can: { gate: false, hod: false }, csrf: '',
    deviceUuid: null, deviceId: null, revoked: false, online: navigator.onLine, needLogin: false,
    syncing: false, lastSync: null, lastError: '', persisted: null, pin: null, pinFails: 0,
    policies: { dup: 60, warnMin: 120 }, waitingForGate: 0, hiddenAt: 0, message: null, selected: {}
  };
  var root;

  // ------------------------------------------------------------ small tools
  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') n.textContent = attrs[k];
      else if (k === 'on') Object.keys(attrs.on).forEach(function (ev) { n.addEventListener(ev, attrs.on[ev]); });
      else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) n.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }
  function uuid() {
    if (crypto.randomUUID) return crypto.randomUUID();
    var b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
    var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }
  function timeOf(ms) {
    return new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }
  function ago(ms) {
    var m = Math.floor((Date.now() - ms) / 60000);
    if (m < 1) return 'just now';
    if (m < 60) return m + ' min ago';
    return Math.floor(m / 60) + ' h ' + (m % 60) + ' min ago';
  }
  function tz() { try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; } catch (e) { return 'UTC'; } }

  /* Network. Throws only when the server could not be reached at all; any
     HTTP answer, including an error, comes back as {status, body}. */
  function api(method, route, body, retried) {
    var url = API + encodeURIComponent(route.split('?')[0]) + (route.indexOf('?') > -1 ? '&' + route.split('?')[1] : '');
    var opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' };
    if (method !== 'GET') {
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-Token'] = S.csrf || '';
      opts.body = JSON.stringify(body || {});
    }
    return fetch(url, opts).then(function (res) {
      return res.text().then(function (t) {
        var j = {};
        try { j = JSON.parse(t); } catch (e) { j = { ok: false, reason_code: 'BAD_RESPONSE', error: 'The server sent something this app could not read.' }; }
        if (res.status === 419 && !retried) {
          // The session token moved on. Fetch the current one and try once more.
          return api('GET', '/auth/me').then(function (me) {
            if (me.status === 200 && me.body.csrf_token) { S.csrf = me.body.csrf_token; return api(method, route, body, true); }
            return { status: res.status, body: j };
          });
        }
        return { status: res.status, body: j };
      });
    });
  }

  // ------------------------------------------------------------------- PIN
  /* A local lock for a phone left on a table, not a server credential. It
     keeps a passer-by from tapping IN for a friend; the server-side controls
     (session, device revoke) are what protect the data. */
  function hex(buf) { return Array.prototype.map.call(new Uint8Array(buf), function (x) { return ('0' + x.toString(16)).slice(-2); }).join(''); }
  function derive(pin, saltHex) {
    var salt = new Uint8Array(saltHex.match(/.{2}/g).map(function (h) { return parseInt(h, 16); }));
    return crypto.subtle.importKey('raw', new TextEncoder().encode(pin), 'PBKDF2', false, ['deriveBits']).then(function (key) {
      return crypto.subtle.deriveBits({ name: 'PBKDF2', salt: salt, iterations: 100000, hash: 'SHA-256' }, key, 256);
    }).then(hex);
  }
  function setPin(pin) {
    var salt = hex(crypto.getRandomValues(new Uint8Array(16)));
    return derive(pin, salt).then(function (h) { S.pin = { salt: salt, hash: h }; return AttDB.setMeta('pin', S.pin); });
  }
  function checkPin(pin) { return derive(pin, S.pin.salt).then(function (h) { return h === S.pin.hash; }); }

  // --------------------------------------------------------------- session
  function refreshSession() {
    return api('GET', '/auth/me').then(function (r) {
      S.online = true;
      if (r.status === 200) {
        S.user = r.body.user; S.can = r.body.can || { gate: false, hod: false }; S.csrf = r.body.csrf_token || '';
        S.needLogin = false;
        if (r.body.policies) {
          S.policies.dup = r.body.policies.duplicate_window_seconds || 60;
          S.policies.warnMin = r.body.policies.sync_backlog_warn_minutes || 120;
        }
        return Promise.all([AttDB.setMeta('user', S.user), AttDB.setMeta('can', S.can), AttDB.setMeta('policies', S.policies)])
          .then(ensureDevice).then(function () { return true; });
      }
      if (r.status === 401) { S.needLogin = true; }
      return false;
    }).catch(function () { S.online = false; return false; });
  }

  function ensureDevice() {
    if (!S.user || (!S.can.gate && !S.can.hod)) return Promise.resolve();
    return api('POST', '/devices/register', {
      device_uuid: S.deviceUuid, app_version: APP_VERSION,
      // The last four characters of the device id: two phones signed in by
      // the same person must be tellable apart on the HR screen before one
      // of them is withdrawn.
      label: 'Phone — ' + (S.user.name || 'user') + ' · ' + String(S.deviceUuid).slice(-4)
    }).then(function (r) {
      if (r.status === 200 && r.body.ok) {
        S.deviceId = r.body.device_id; S.revoked = false;
        return AttDB.setMeta('device_id', S.deviceId);
      }
      if (r.body && r.body.reason_code === 'DEVICE_REVOKED') S.revoked = true;
      if (r.body && r.body.reason_code === 'DEVICE_NOT_REGISTERED') S.lastError = r.body.error;
    }).catch(function () {});
  }

  function signIn(email, password) {
    return api('POST', '/auth/login', { email: email, password: password }).then(function (r) {
      if (r.status !== 200) return r.body.error || 'Sign-in failed.';
      S.csrf = r.body.csrf_token || '';
      return refreshSession().then(function (ok) { return ok ? null : 'Signed in, but the app could not load your details.'; });
    }).catch(function () { return 'No connection. Signing in needs the internet.'; });
  }

  function signOut() {
    return AttDB.pending().then(function (p) {
      var warn = p.length
        ? p.length + ' scan(s) on this phone have NOT been sent yet. They stay on this phone and are sent after the next sign-in. Sign out anyway?'
        : 'Sign out?';
      if (!confirm(warn)) return;
      return api('POST', '/auth/logout', {}).catch(function () {}).then(function () {
        S.user = null; S.pin = null; S.can = { gate: false, hod: false };
        // The outbox is deliberately left alone. Signing out forgets WHO is
        // using the phone, never WHAT the phone has not sent.
        return Promise.all([AttDB.setMeta('user', null), AttDB.setMeta('pin', null)]);
      }).then(function () { S.screen = 'login'; render(); });
    });
  }

  // ------------------------------------------------------------------ sync
  /* Send what is waiting, oldest first, and apply the answers.
     Runs on: a new event, app open, coming back to the foreground, the
     network returning, and a timer. Background Sync, where the browser has
     it, is an extra nudge and nothing depends on it. */
  function syncNow() {
    if (S.syncing || !S.user) return Promise.resolve();
    S.syncing = true; paintStatus();
    var hadRetry = false;

    function step() {
      return AttDB.pending().then(function (rows) {
        if (!rows.length) return 'done';
        var batch = rows.slice(0, CHUNK);
        return api('POST', '/sync/events', {
          device_now: new Date().toISOString(),
          more: rows.length > batch.length,           // tells the server not to reconcile mid-upload
          events: batch.map(function (r) {
            return {
              event_uuid: r.event_uuid, device_id: S.deviceUuid, device_sequence: r.device_sequence,
              employee_code: r.employee_code, event_type: r.event_type, occurred_at: r.occurred_at,
              timezone: r.timezone, payload: r.payload || {}, actor_user_id: r.actor_user_id, app_version: APP_VERSION
            };
          })
        }).then(function (res) {
          S.online = true;
          if (res.status === 401) { S.needLogin = true; return 'stop'; }
          if (res.status === 429) { S.lastError = 'The server asked this phone to slow down. It will try again shortly.'; return 'stop'; }
          if (res.status !== 200 || !res.body.receipts) {
            S.lastError = (res.body && res.body.error) || ('The server answered ' + res.status + '.');
            return 'stop';
          }
          S.lastError = '';
          hadRetry = res.body.receipts.some(function (x) { return x.receipt_status === 'RETRY'; });
          return AttDB.applyReceipts(res.body.receipts).then(function () {
            // A RETRY means "not now": stop, or this loop would resend the
            // same event for ever.
            return hadRetry ? 'stop' : step();
          });
        });
      });
    }

    return step().then(function (how) {
      if (how === 'done' && !hadRetry) { S.lastSync = Date.now(); AttDB.setMeta('last_sync', S.lastSync); }
      return AttDB.prune(3);
    }).catch(function () {
      S.online = false;                                // could not reach the server: everything stays queued
      // Where the browser has Background Sync, ask it to nudge us when the
      // network returns. An optimisation only: the timer and the 'online'
      // event do the same job without it.
      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.ready.then(function (reg) { if (reg.sync) return reg.sync.register('att-sync'); }).catch(function () {});
      }
    }).then(function () {
      S.syncing = false;
      if (S.screen === 'main') { refreshLists(); paintStatus(); }
    });
  }

  function refreshLookups() {
    if (!S.user || !S.online) return Promise.resolve();
    var jobs = [];
    if (S.can.gate || S.can.hod) {
      jobs.push(api('GET', '/employees/sync-snapshot').then(function (r) {
        if (r.status === 200 && r.body.employees) {
          return AttDB.replaceAll('employees', r.body.employees).then(function () { return AttDB.setMeta('employees_at', Date.now()); });
        }
      }));
    }
    if (S.can.hod) jobs.push(refreshPending());
    jobs.push(api('GET', '/sync/status?device_id=' + encodeURIComponent(S.deviceUuid || '')).then(function (r) {
      if (r.status === 200) {
        S.waitingForGate = r.body.confirmations_waiting_for_gate || 0;
        if (r.body.device && r.body.device.revoked) S.revoked = true;
      }
    }));
    return Promise.all(jobs).catch(function () {});
  }

  /* The HOD's list of entries owing a confirmation. Anything this phone has
     already queued a confirmation for is left out, sent or not, so the same
     entry is not offered twice while its confirmation is still in the outbox. */
  function refreshPending() {
    return Promise.all([api('GET', '/sync/changes'), AttDB.allOutbox()]).then(function (v) {
      var r = v[0], mine = {};
      v[1].forEach(function (o) {
        if (o.event_type === 'HOD_CONFIRM' && o.payload && o.payload.entry_cycle_id && o.state !== 'rejected') mine[o.payload.entry_cycle_id] = true;
      });
      if (r.status === 200 && r.body.pending) {
        return AttDB.replaceAll('cycles', r.body.pending.filter(function (c) { return !mine[c.entry_cycle_id]; }));
      }
    }).catch(function () {});
  }

  // --------------------------------------------------------------- actions
  function say(kind, text) { S.message = { kind: kind, text: text }; paintMessage(); }

  function findEmployee(value) {
    return AttDB.getAll('employees').then(function (all) {
      var hit = null;
      all.forEach(function (e) { if (e.code === value || (e.qr_payload && e.qr_payload === value)) hit = e; });
      return { hit: hit, known: all.length };
    });
  }

  function saveEvent(type, code, name, payload) {
    var event = {
      event_uuid: uuid(), event_type: type, employee_code: code, employee_name: name || '',
      occurred_at: new Date().toISOString(), timezone: tz(), payload: payload || {},
      actor_user_id: S.user ? S.user.id : null
    };
    return AttDB.enqueue(event);
  }

  function gateAction(type) {
    var input = document.getElementById('code');
    var value = (input.value || '').trim();
    if (!value) { say('bad', 'Enter or scan an employee code first.'); return; }

    Promise.all([findEmployee(value), AttDB.allOutbox()]).then(function (v) {
      var emp = v[0].hit, known = v[0].known, rows = v[1];
      if (!emp && known > 0) {
        say('bad', '"' + value + '" is not in this phone\'s employee list. Check the code. Nothing was saved.');
        return;
      }
      var code = emp ? emp.code : value;
      var mineForCode = rows.filter(function (r) { return r.employee_code === code && (r.event_type === 'GATE_IN' || r.event_type === 'GATE_OUT') && r.state !== 'rejected'; });
      var last = mineForCode[mineForCode.length - 1];

      // The 60-second rule, applied here too so a double-tap never reaches
      // the queue. The server applies it again and has the final say.
      var dup = mineForCode.filter(function (r) { return r.event_type === type && (Date.now() - Date.parse(r.occurred_at)) < S.policies.dup * 1000; });
      if (dup.length) {
        say('bad', 'Already scanned ' + (type === 'GATE_IN' ? 'IN' : 'OUT') + ' for ' + (emp ? emp.full_name : code) + ' less than ' + S.policies.dup + ' seconds ago. Nothing was saved.');
        return;
      }
      if (last && last.event_type === type) {
        var q = 'This phone already has ' + (type === 'GATE_IN' ? 'an IN with no OUT' : 'an OUT with no IN') + ' for ' + (emp ? emp.full_name : code) +
                '. Save anyway? The server will decide whether it stands.';
        if (!confirm(q)) return;
      }

      return saveEvent(type, code, emp ? emp.full_name : '', {}).then(function () {
        input.value = ''; paintEmployee('');
        say('ok', (type === 'GATE_IN' ? 'IN' : 'OUT') + ' for ' + (emp ? emp.full_name + ' (' + code + ')' : code) + ' — saved on this phone. Sending…');
        refreshLists(); paintStatus();
        return syncNow();
      });
    }).catch(function (e) {
      /* The one message that must never be soft. If storage refused the
         write, the scan does not exist anywhere. */
      say('bad', 'COULD NOT SAVE. Nothing was recorded. ' + (e && e.message ? e.message : '') + ' Try again, and tell a supervisor if it repeats.');
    });
  }

  function hodByCode() {
    var input = document.getElementById('hodcode');
    var value = (input.value || '').trim();
    if (!value) { say('bad', 'Enter or scan an employee code first.'); return; }
    findEmployee(value).then(function (v) {
      if (!v.hit && v.known > 0) { say('bad', '"' + value + '" is not in your department\'s list on this phone. Nothing was saved.'); return; }
      var code = v.hit ? v.hit.code : value;
      return saveEvent('HOD_CONFIRM', code, v.hit ? v.hit.full_name : '', {}).then(function () {
        input.value = '';
        say('ok', 'Arrival of ' + (v.hit ? v.hit.full_name : code) + ' — saved on this phone. Sending…');
        refreshLists(); paintStatus();
        return syncNow();
      });
    }).catch(function (e) { say('bad', 'COULD NOT SAVE. Nothing was recorded. ' + (e && e.message ? e.message : '')); });
  }

  function hodConfirmSelected() {
    var ids = Object.keys(S.selected).filter(function (k) { return S.selected[k]; });
    if (!ids.length) { say('bad', 'Tick at least one entry.'); return; }
    AttDB.getAll('cycles').then(function (cycles) {
      var chosen = cycles.filter(function (c) { return S.selected[c.entry_cycle_id]; });
      var chain = Promise.resolve(), done = 0;
      chosen.forEach(function (c) {
        chain = chain.then(function () {
          // One event, one UUID, per entry — and the gate event's own UUID
          // goes along, so the server never has to work out which entry.
          return saveEvent('HOD_CONFIRM', c.employee_code, c.full_name, { entry_cycle_id: c.entry_cycle_id, gate_event_uuid: c.gate_event_uuid })
            .then(function () { done++; return AttDB.removeOne('cycles', c.entry_cycle_id); });
        });
      });
      return chain.then(function () {
        S.selected = {};
        say('ok', done + ' confirmation(s) saved on this phone. Sending…');
        refreshLists(); paintStatus();
        return syncNow();
      });
    }).catch(function (e) { say('bad', 'COULD NOT SAVE every confirmation. ' + (e && e.message ? e.message : '') + ' Check the Queue tab for what was kept.'); refreshLists(); });
  }

  // ------------------------------------------------------------- scanning
  /* The camera is used ONLY to read a code. Frames are examined in memory and
     discarded; no picture is taken, kept or sent. (A photo exists only in the
     separate Capture & Flag step, which is not part of ordinary attendance.) */
  function scanInto(inputId) {
    if (!('BarcodeDetector' in window)) return;
    var overlay = el('div', { class: 'scan' }), video = el('video', { playsinline: '', muted: '' }), stream = null, stop = false;
    function close() { stop = true; if (stream) stream.getTracks().forEach(function (t) { t.stop(); }); overlay.remove(); }
    overlay.appendChild(video);
    overlay.appendChild(el('p', { text: 'Point at the code. The camera only reads it — no picture is saved or sent.' }));
    overlay.appendChild(el('button', { class: 'btn', text: 'Cancel', on: { click: close } }));
    document.body.appendChild(overlay);
    var detector = new BarcodeDetector({ formats: ['qr_code', 'code_128', 'code_39', 'ean_13'] });
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
      stream = s; video.srcObject = s; return video.play();
    }).then(function tick() {
      if (stop) return;
      detector.detect(video).then(function (codes) {
        if (codes.length) {
          var input = document.getElementById(inputId);
          input.value = codes[0].rawValue; input.dispatchEvent(new Event('input'));
          close();
        } else setTimeout(tick, 250);
      }).catch(function () { setTimeout(tick, 400); });
    }).catch(function () { close(); say('bad', 'The camera could not be opened. Type the code instead.'); });
  }

  // ---------------------------------------------------------------- render
  function render() {
    root.textContent = '';
    if (S.screen === 'login') return renderLogin();
    if (S.screen === 'pinset') return renderPin(true);
    if (S.screen === 'locked') return renderPin(false);
    if (S.screen === 'firstrun') {
      root.appendChild(el('div', { class: 'card center' }, [
        el('h1', { text: 'ZAS Attendance' }),
        el('p', { text: 'Connect to the internet to sign in the first time. After that the app opens without a connection.' }),
        el('button', { class: 'btn primary', text: 'Try again', on: { click: function () { location.reload(); } } })
      ]));
      return;
    }
    if (S.screen === 'main') return renderMain();
    root.appendChild(el('p', { class: 'center muted', text: 'Starting…' }));
  }

  function renderLogin() {
    var email = el('input', { type: 'email', id: 'email', placeholder: 'Email', autocomplete: 'username' });
    var pass = el('input', { type: 'password', id: 'password', placeholder: 'Password', autocomplete: 'current-password' });
    var err = el('p', { class: 'msg bad', id: 'loginerr' });
    var btn = el('button', { class: 'btn primary', id: 'signin', text: 'Sign in', on: { click: function () {
      btn.disabled = true; err.textContent = '';
      signIn(email.value.trim(), pass.value).then(function (problem) {
        btn.disabled = false;
        if (problem) { err.textContent = problem; return; }
        if (!S.can.gate && !S.can.hod) { err.textContent = 'This login has no gate or HOD permission. Ask an admin.'; return; }
        S.screen = S.pin ? 'main' : 'pinset'; S.tab = S.can.gate ? 'gate' : 'hod';
        render(); if (S.screen === 'main') afterUnlock();
      });
    } } });
    root.appendChild(el('div', { class: 'card' }, [
      el('h1', { text: 'ZAS Attendance' }), el('p', { class: 'muted', text: 'Sign in with your ZAS account.' }),
      email, pass, btn, err
    ]));
  }

  function renderPin(setting) {
    var pin = el('input', { type: 'password', id: 'pin', inputmode: 'numeric', maxlength: '6', placeholder: setting ? 'Choose a PIN (4–6 digits)' : 'PIN', autocomplete: 'off' });
    var err = el('p', { class: 'msg bad', id: 'pinerr' });
    function go() {
      var v = pin.value.trim();
      if (!/^\d{4,6}$/.test(v)) { err.textContent = 'The PIN is 4 to 6 digits.'; return; }
      if (setting) {
        setPin(v).then(function () { S.screen = 'main'; render(); afterUnlock(); });
        return;
      }
      checkPin(v).then(function (ok) {
        if (ok) { S.pinFails = 0; S.screen = 'main'; render(); afterUnlock(); return; }
        S.pinFails++;
        if (S.pinFails >= 5) {
          // Forget who was signed in. The unsent scans are NOT touched.
          S.user = null; S.pin = null; S.pinFails = 0;
          Promise.all([AttDB.setMeta('user', null), AttDB.setMeta('pin', null)]).then(function () { S.screen = 'login'; render(); });
          return;
        }
        err.textContent = 'Wrong PIN. ' + (5 - S.pinFails) + ' tries left before a full sign-in is needed.';
        pin.value = '';
      });
    }
    pin.addEventListener('keydown', function (e) { if (e.key === 'Enter') go(); });
    root.appendChild(el('div', { class: 'card' }, [
      el('h1', { text: setting ? 'Choose a PIN for this phone' : 'Enter PIN' }),
      el('p', { class: 'muted', text: setting
        ? 'The PIN locks the app on this phone. It is asked for when the app is opened.'
        : (S.user ? S.user.name : '') }),
      pin, el('button', { class: 'btn primary', id: 'pingo', text: setting ? 'Save PIN' : 'Unlock', on: { click: go } }), err
    ]));
    pin.focus();
  }

  function renderMain() {
    var tabs = [];
    if (S.can.gate) tabs.push(['gate', 'Gate']);
    if (S.can.hod) tabs.push(['hod', 'HOD']);
    tabs.push(['queue', 'Queue']);
    if (!tabs.some(function (t) { return t[0] === S.tab; })) S.tab = tabs[0][0];

    root.appendChild(el('header', {}, [
      el('div', {}, [el('strong', { text: 'ZAS Attendance' }), el('span', { class: 'muted', id: 'who', text: ' · ' + (S.user ? S.user.name : '') })]),
      el('div', { id: 'status', class: 'status' })
    ]));
    root.appendChild(el('div', { id: 'banners' }));
    root.appendChild(el('nav', {}, tabs.map(function (t) {
      return el('button', { class: 'tab' + (S.tab === t[0] ? ' on' : ''), id: 'tab-' + t[0], text: t[1], on: { click: function () { S.tab = t[0]; S.message = null; render(); } } });
    })));
    var body = el('main', { id: 'body' });
    root.appendChild(body);
    root.appendChild(el('div', { id: 'message' }));

    if (S.tab === 'gate') {
      var code = el('input', { id: 'code', placeholder: 'Employee code', autocomplete: 'off', autocapitalize: 'characters', on: { input: function () { paintEmployee(code.value.trim()); } } });
      body.appendChild(el('div', { class: 'card' }, [
        el('div', { class: 'row' }, [code, ('BarcodeDetector' in window) ? el('button', { class: 'btn', id: 'scan', text: 'Scan', on: { click: function () { scanInto('code'); } } }) : null]),
        el('p', { id: 'empname', class: 'empname' }),
        el('div', { class: 'row' }, [
          el('button', { class: 'btn big in', id: 'btn-in', text: 'IN', on: { click: function () { gateAction('GATE_IN'); } } }),
          el('button', { class: 'btn big out', id: 'btn-out', text: 'OUT', on: { click: function () { gateAction('GATE_OUT'); } } })
        ])
      ]));
      body.appendChild(el('h2', { text: 'Recent on this phone' }));
      body.appendChild(el('div', { id: 'recent' }));
    }
    if (S.tab === 'hod') {
      body.appendChild(el('div', { class: 'card' }, [
        el('div', { class: 'row' }, [
          el('input', { id: 'hodcode', placeholder: 'Employee code', autocomplete: 'off' }),
          ('BarcodeDetector' in window) ? el('button', { class: 'btn', text: 'Scan', on: { click: function () { scanInto('hodcode'); } } }) : null
        ]),
        el('button', { class: 'btn primary', id: 'btn-hodcode', text: 'Confirm arrival', on: { click: hodByCode } })
      ]));
      body.appendChild(el('h2', { text: 'Entries waiting for your confirmation' }));
      body.appendChild(el('div', { id: 'pendinglist' }));
      body.appendChild(el('div', { class: 'row' }, [
        el('button', { class: 'btn', id: 'btn-all', text: 'Select all', on: { click: function () {
          AttDB.getAll('cycles').then(function (c) { c.forEach(function (x) { S.selected[x.entry_cycle_id] = true; }); refreshLists(); });
        } } }),
        el('button', { class: 'btn primary', id: 'btn-confirm', text: 'Confirm selected', on: { click: hodConfirmSelected } })
      ]));
    }
    if (S.tab === 'queue') {
      body.appendChild(el('div', { class: 'card', id: 'queueinfo' }));
      body.appendChild(el('div', { class: 'row' }, [
        el('button', { class: 'btn primary', id: 'btn-send', text: 'Send now', on: { click: function () { syncNow().then(refreshLookups).then(function () { refreshLists(); paintStatus(); }); } } }),
        el('button', { class: 'btn', id: 'btn-lock', text: 'Lock', on: { click: function () { S.screen = 'locked'; render(); } } }),
        el('button', { class: 'btn', id: 'btn-out2', text: 'Sign out', on: { click: signOut } })
      ]));
      body.appendChild(el('h2', { text: 'Everything on this phone' }));
      body.appendChild(el('div', { id: 'queuelist' }));
    }
    /* AT12. Said plainly, on every screen, in the same words. */
    root.appendChild(el('footer', { id: 'honest', text:
      'Scans are saved on this phone first and sent to the server when there is a connection. ' +
      'If this phone is lost, reset, or its browser data is cleared before they are sent, unsent scans are lost. ' +
      'Keep the waiting count at zero.' }));
    paintStatus(); paintMessage(); refreshLists();
  }

  function paintEmployee(value) {
    var p = document.getElementById('empname');
    if (!p) return;
    if (!value) { p.textContent = ''; return; }
    findEmployee(value).then(function (v) {
      p.textContent = v.hit ? v.hit.full_name : (v.known ? 'Not in this phone\'s list' : '');
      p.className = 'empname' + (v.hit ? '' : ' bad');
    });
  }

  function paintMessage() {
    var box = document.getElementById('message');
    if (!box) return;
    box.textContent = '';
    if (S.message) box.appendChild(el('p', { class: 'msg ' + S.message.kind, id: 'msg', text: S.message.text }));
  }

  function stateText(r) {
    var rc = r.receipt || {};
    if (r.state === 'pending') {
      return rc.receipt_status === 'RETRY'
        ? ['wait', 'On this phone — not sent: ' + (rc.message || rc.reason_code || 'will try again')]
        : ['wait', 'On this phone — not sent yet'];
    }
    if (r.state === 'rejected') return ['bad', 'Refused by the server: ' + (rc.message || rc.reason_code || '')];
    if (rc.receipt_status === 'PENDING_MATCH') return ['note', 'Received — waiting for the gate\'s record of this entry'];
    if (rc.receipt_status === 'NEEDS_REVIEW') return ['note', 'Received — sent to HR to check: ' + (rc.message || rc.reason_code || '')];
    return ['ok', 'Recorded by the server'];
  }

  function rowFor(r, withClear) {
    var st = stateText(r);
    var label = r.event_type === 'GATE_IN' ? 'IN' : (r.event_type === 'GATE_OUT' ? 'OUT' : 'HOD');
    return el('div', { class: 'item ' + st[0], 'data-state': r.state, 'data-uuid': r.event_uuid }, [
      el('div', {}, [el('b', { text: label + '  ' }), el('span', { text: (r.employee_name ? r.employee_name + ' · ' : '') + r.employee_code }), el('span', { class: 'muted', text: '  ' + timeOf(Date.parse(r.occurred_at)) })]),
      el('div', { class: 'sub', text: st[1] }),
      (withClear && r.state === 'rejected') ? el('button', { class: 'btn small', text: 'Clear', on: { click: function () { AttDB.dismiss(r.event_uuid).then(refreshLists); } } }) : null
    ]);
  }

  function refreshLists() {
    return Promise.all([AttDB.allOutbox(), AttDB.getAll('cycles')]).then(function (v) {
      var rows = v[0].filter(function (r) { return !r.dismissed; }), cycles = v[1];
      var recent = document.getElementById('recent');
      if (recent) {
        recent.textContent = '';
        var g = rows.filter(function (r) { return r.event_type !== 'HOD_CONFIRM'; }).slice(-15).reverse();
        if (!g.length) recent.appendChild(el('p', { class: 'muted', text: 'Nothing yet.' }));
        g.forEach(function (r) { recent.appendChild(rowFor(r, true)); });
      }
      var pl = document.getElementById('pendinglist');
      if (pl) {
        pl.textContent = '';
        if (!cycles.length) pl.appendChild(el('p', { class: 'muted', text: S.online ? 'Nobody is waiting.' : 'No list on this phone yet. It loads when there is a connection.' }));
        cycles.forEach(function (c) {
          var box = el('input', { type: 'checkbox', 'data-cycle': c.entry_cycle_id });
          box.checked = !!S.selected[c.entry_cycle_id];
          box.addEventListener('change', function () { S.selected[c.entry_cycle_id] = box.checked; });
          pl.appendChild(el('label', { class: 'item pick' }, [box, el('span', { text: ' ' + c.full_name + ' · ' + c.employee_code }),
            el('span', { class: 'muted', text: '  entry ' + c.entry_sequence + ', in at ' + timeOf(Date.parse(String(c.opened_at).replace(' ', 'T') + 'Z')) })]));
        });
        if (S.waitingForGate) pl.appendChild(el('p', { class: 'muted', text: S.waitingForGate + ' of your confirmations are waiting for the gate\'s record to reach the server.' }));
      }
      var ql = document.getElementById('queuelist');
      if (ql) {
        ql.textContent = '';
        rows.slice(-60).reverse().forEach(function (r) { ql.appendChild(rowFor(r, true)); });
        if (!rows.length) ql.appendChild(el('p', { class: 'muted', text: 'Nothing yet.' }));
      }
      var qi = document.getElementById('queueinfo');
      if (qi) {
        var pend = rows.filter(function (r) { return r.state === 'pending'; });
        qi.textContent = '';
        qi.appendChild(el('p', { id: 'qcount', text: pend.length + ' waiting to send' + (pend.length ? ' — oldest ' + ago(pend[0].created_at) : '') }));
        qi.appendChild(el('p', { class: 'muted', text: 'Last complete send: ' + (S.lastSync ? ago(S.lastSync) : 'never on this phone') }));
        qi.appendChild(el('p', { class: 'muted', text: 'Storage protected from automatic clearing: ' + (S.persisted === true ? 'yes' : (S.persisted === false ? 'NO — the browser may clear it if the phone runs low on space' : 'unknown')) }));
        qi.appendChild(el('p', { class: 'muted', text: 'App ' + APP_VERSION + ' · device ' + (S.deviceId || 'not registered yet') }));
      }
    });
  }

  function paintStatus() {
    return AttDB.pending().then(function (pend) {
      var st = document.getElementById('status');
      if (st) {
        st.textContent = '';
        st.appendChild(el('span', { class: 'pill ' + (S.online ? 'ok' : 'bad'), id: 'net', text: S.online ? 'Online' : 'Offline' }));
        st.appendChild(el('span', { class: 'pill ' + (pend.length ? 'wait' : 'ok'), id: 'waiting', 'data-count': String(pend.length),
          text: S.syncing ? 'Sending…' : (pend.length + ' waiting') }));
      }
      var b = document.getElementById('banners');
      if (!b) return;
      b.textContent = '';
      function banner(kind, text, action) { b.appendChild(el('div', { class: 'banner ' + kind }, [el('span', { text: text }), action || null])); }
      if (S.revoked) banner('bad', 'This phone has been withdrawn from service by an admin. Scans on it will be refused. Hand it to a supervisor.');
      if (S.needLogin) banner('bad', 'You are signed out on the server. Scans are still being saved on this phone, but nothing is being sent.',
        el('button', { class: 'btn small', id: 'relogin', text: 'Sign in', on: { click: function () { S.screen = 'login'; render(); } } }));
      if (pend.length && (Date.now() - pend[0].created_at) > S.policies.warnMin * 60000) {
        banner('bad', pend.length + ' scan(s) have been waiting more than ' + S.policies.warnMin + ' minutes. Get this phone a connection. Until they are sent they exist only on this phone.');
      }
      if (S.lastError) banner('note', S.lastError);
      if (S.persisted === false) banner('note', 'This browser has not promised to keep the app\'s storage. Installing the app to the home screen usually fixes that.');
    });
  }

  // ------------------------------------------------------------------ boot
  function afterUnlock() {
    syncNow().then(refreshLookups).then(function () { if (S.screen === 'main') { refreshLists(); paintStatus(); } });
  }

  function boot() {
    root = document.getElementById('app');
    render();
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('sw.js', { scope: './' }).catch(function () {});
      navigator.serviceWorker.addEventListener('message', function (e) { if (e.data === 'att-sync') syncNow(); });
    }
    AttDB.open().then(function () {
      return Promise.all(['device_uuid', 'device_id', 'user', 'can', 'pin', 'last_sync', 'policies'].map(AttDB.getMeta));
    }).then(function (m) {
      S.deviceUuid = m[0]; S.deviceId = m[1] || null; S.user = m[2] || null; S.can = m[3] || S.can;
      S.pin = m[4] || null; S.lastSync = m[5] || null; if (m[6]) S.policies = m[6];
      var jobs = [];
      if (!S.deviceUuid) { S.deviceUuid = uuid(); jobs.push(AttDB.setMeta('device_uuid', S.deviceUuid)); }
      // Ask the browser not to evict this app's storage. It may say no.
      if (navigator.storage && navigator.storage.persist) {
        jobs.push(navigator.storage.persist().then(function (p) { S.persisted = !!p; }).catch(function () {}));
      }
      return Promise.all(jobs);
    }).then(refreshSession).then(function () {
      if (!S.user) { S.screen = S.online ? 'login' : 'firstrun'; }
      else if (!S.can.gate && !S.can.hod) { S.screen = 'login'; }
      else if (!S.pin) { S.screen = 'pinset'; }
      else { S.screen = 'locked'; }
      S.tab = S.can.gate ? 'gate' : 'hod';
      render();
    }).catch(function (e) {
      root.textContent = '';
      root.appendChild(el('div', { class: 'card' }, [
        el('h1', { text: 'This phone cannot store scans' }),
        el('p', { text: 'The app could not open its storage (' + (e && e.message ? e.message : 'unknown reason') + '). Do not use this phone for attendance until that is fixed. Private/incognito windows cannot be used.' })
      ]));
    });

    window.addEventListener('online', function () { S.online = true; paintStatus(); if (S.screen === 'main') afterUnlock(); });
    window.addEventListener('offline', function () { S.online = false; paintStatus(); });
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { S.hiddenAt = Date.now(); return; }
      if (S.screen === 'main' && S.pin && S.hiddenAt && Date.now() - S.hiddenAt > LOCK_AFTER_MS) { S.screen = 'locked'; render(); return; }
      if (S.screen === 'main') afterUnlock();
    });
    setInterval(function () {
      if (S.screen !== 'main') return;
      paintStatus();
      if (navigator.onLine) syncNow().then(function () { if (S.can.hod && S.tab === 'hod') return refreshPending().then(refreshLists); });
    }, 30000);
  }

  // For the automated tests, and for a supervisor with the console open.
  window.AttApp = { state: S, syncNow: syncNow, refreshLookups: refreshLookups, version: APP_VERSION };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
