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
 * PICTURES. Two things, kept apart.
 *   - The employee's photo is SHOWN when a code is typed or scanned. It is
 *     fetched from the server each time, needs a connection, and is never
 *     stored on this phone.
 *   - The camera TAKES a picture in one place only: openFlag(), when the
 *     gatekeeper marks an entry as needing a second look. Recording IN, OUT
 *     or a HOD confirmation never opens the camera for a picture.
 *
 * Nothing secret is in this file. It holds no keys; it identifies itself with
 * a random device id and the signed-in session, and the server re-checks
 * permission on every request whatever this file chooses to show.
 */
(function () {
  'use strict';

  var API = '../api/v1/index.php?route=';
  var APP_VERSION = '1.4.2';
  var CHUNK = 50;
  var LOCK_AFTER_MS = 5 * 60 * 1000;

  var S = {
    screen: 'boot', tab: 'gate', user: null, can: { gate: false, hod: false, flag: false, ot: false }, csrf: '', flagReasons: {}, otUuid: null, hodDepts: [], mode: null, fx: { sound: true, vibe: true }, kbd: 'num',
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
        if (r.body.flag_reasons) { S.flagReasons = r.body.flag_reasons; AttDB.setMeta('flag_reasons', S.flagReasons); }
        if ('hod_departments' in r.body) { S.hodDepts = r.body.hod_departments; AttDB.setMeta('hod_departments', S.hodDepts); }
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
    return Promise.all([AttDB.pending(), waitingFlags()]).then(function (v) {
      var n = v[0].length + v[1].length;
      var warn = n
        ? n + ' item(s) on this phone have NOT been sent yet. They stay on this phone and are sent after the next sign-in. Sign out anyway?'
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
          // A refusal for something saved in the last few minutes is news the
          // person at the gate needs now, not on the Queue tab later.
          var fresh = {};
          batch.forEach(function (b) { fresh[b.event_uuid] = b; });
          var refused = res.body.receipts.filter(function (x) { return x.receipt_status === 'REJECTED' && fresh[x.event_uuid] && Date.now() - fresh[x.event_uuid].created_at < 300000; });
          if (refused.length) {
            var b0 = fresh[refused[refused.length - 1].event_uuid];
            toast('bad', 'Server refused ' + (b0.event_type === 'GATE_IN' ? 'IN' : b0.event_type === 'GATE_OUT' ? 'OUT' : 'confirmation') + ' for ' + (b0.employee_name || b0.employee_code) + ': ' + (refused[refused.length - 1].message || refused[refused.length - 1].reason_code || ''));
            feedback('bad');
          }
          return AttDB.applyReceipts(res.body.receipts).then(function () {
            // A RETRY means "not now": stop, or this loop would resend the
            // same event for ever.
            return hadRetry ? 'stop' : step();
          });
        });
      });
    }

    return step().then(function (how) {
      // Flags go after the scans they are about, never before.
      if (how !== 'done') return how;
      return syncFlags().then(function () { return how; });
    }).then(function (how) {
      if (how === 'done' && !hadRetry) { S.lastSync = Date.now(); AttDB.setMeta('last_sync', S.lastSync); }
      return AttDB.prune(3).then(function () { return AttDB.pruneFlags(3); });
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

  // ----------------------------------------------------- flags and stills
  /* Flags that still have something to send: the flag itself, or its still. */
  function waitingFlags() {
    return AttDB.getAll('flags').then(function (all) {
      return all.filter(function (f) { return f.state === 'pending' || (f.state === 'acked' && f.photo_state === 'pending'); });
    });
  }

  /* Send a still. Multipart, so no Content-Type is set by hand; the server
     decides what the file is by reading it. */
  function upload(route, blob, retried) {
    var form = new FormData();
    form.append('photo', blob, 'still');
    return fetch(API + encodeURIComponent(route), {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': S.csrf || '' }, body: form
    }).then(function (res) {
      return res.text().then(function (t) {
        var j = {};
        try { j = JSON.parse(t); } catch (e) { j = { ok: false, error: 'The server sent something this app could not read.' }; }
        if (res.status === 419 && !retried) {
          return api('GET', '/auth/me').then(function (me) {
            if (me.status === 200 && me.body.csrf_token) { S.csrf = me.body.csrf_token; return upload(route, blob, true); }
            return { status: res.status, body: j };
          });
        }
        return { status: res.status, body: j };
      });
    });
  }

  /* "Keep it and try again" — the server is busy, down, or has not seen the
     scan yet. Anything else below 500 is a decision. */
  function keepForLater(status) { return status === 409 || status === 429 || status >= 500; }

  function syncFlags() {
    return Promise.all([AttDB.getAll('flags'), AttDB.allOutbox()]).then(function (v) {
      var events = {};
      v[1].forEach(function (o) { events[o.event_uuid] = o; });
      var chain = Promise.resolve(true);
      v[0].forEach(function (f) { chain = chain.then(function (go) { return go ? sendFlag(f, events[f.gate_event_uuid]) : false; }); });
      return chain;
    });
  }

  /* Resolves true to carry on with the next flag, false to stop this run. */
  function sendFlag(f, scan) {
    var first = Promise.resolve(true);
    if (f.state === 'pending') {
      if (scan && scan.state === 'pending') return Promise.resolve(true);   // its scan has not gone up yet
      first = api('POST', '/flags', {
        flag_uuid: f.flag_uuid, gate_event_uuid: f.gate_event_uuid, reason_code: f.reason_code, note: f.note || '',
        occurred_at: f.occurred_at, timezone: f.timezone, device_id: S.deviceUuid
      }).then(function (res) {
        if (res.status === 401) { S.needLogin = true; return false; }
        if (res.status === 200 && res.body.ok) { f.state = 'acked'; f.answered_at = Date.now(); f.error = ''; }
        else if (keepForLater(res.status) && res.body.reason_code !== 'PHOTO_EXISTS') { f.attempts = (f.attempts || 0) + 1; f.error = res.body.error || ''; }
        else { f.state = 'rejected'; f.answered_at = Date.now(); f.error = res.body.error || ('The server answered ' + res.status + '.'); }
        return AttDB.put('flags', f).then(function () { return true; });
      });
    }
    return first.then(function (go) {
      if (!go || f.state !== 'acked' || f.photo_state !== 'pending' || !f.photo) return go;
      return upload('/flags/' + f.flag_uuid + '/photo', f.photo).then(function (res) {
        if (res.status === 401) { S.needLogin = true; return false; }
        if (res.status === 200 && res.body.ok) {
          // Sent: the still leaves this phone. Only the fact of it stays.
          f.photo = null; f.photo_state = 'sent'; f.photo_error = '';
        } else if (keepForLater(res.status) && res.body.reason_code !== 'PHOTO_EXISTS') {
          f.photo_error = res.body.error || '';
        } else {
          f.photo = null; f.photo_state = 'rejected'; f.photo_error = res.body.error || ('The server answered ' + res.status + '.');
        }
        return AttDB.put('flags', f).then(function () { return true; });
      });
    });
  }

  /* Make the still small before it is kept or sent: at most 1600 on the long
     side, WebP where this browser can write it and JPEG where it cannot. The
     server re-makes it again whatever arrives. */
  function shrink(file) {
    function draw(source, w, h) {
      var scale = Math.min(1, 1600 / Math.max(w, h));
      var c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(w * scale)); c.height = Math.max(1, Math.round(h * scale));
      c.getContext('2d').drawImage(source, 0, 0, c.width, c.height);
      return new Promise(function (resolve, reject) {
        c.toBlob(function (webp) {
          if (webp && webp.type === 'image/webp') return resolve(webp);
          c.toBlob(function (jpeg) { jpeg ? resolve(jpeg) : reject(new Error('This phone could not prepare the photo.')); }, 'image/jpeg', 0.82);
        }, 'image/webp', 0.8);
      });
    }
    if (window.createImageBitmap) {
      return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) { return draw(bmp, bmp.width, bmp.height); })
        .catch(function () { return viaImage(); });
    }
    return viaImage();
    function viaImage() {
      return new Promise(function (resolve, reject) {
        var url = URL.createObjectURL(file), img = new Image();
        img.onload = function () { draw(img, img.naturalWidth, img.naturalHeight).then(resolve, reject).then(function () { URL.revokeObjectURL(url); }); };
        img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('That file is not a picture.')); };
        img.src = url;
      });
    }
  }

  /* CAPTURE & FLAG — the one place this app takes a picture. */
  function openFlag(row) {
    var reasons = Object.keys(S.flagReasons || {});
    if (!reasons.length) { say('bad', 'Flagging needs one connection first, to load the list of reasons.'); return; }
    var still = null, busy = false;
    var who = (row.employee_name ? row.employee_name + ' · ' : '') + row.employee_code;
    var reason = el('select', { id: 'flag-reason' }, [el('option', { value: '', text: 'Why is this being flagged?' })].concat(
      reasons.map(function (k) { return el('option', { value: k, text: S.flagReasons[k] }); })));
    var note = el('textarea', { id: 'flag-note', rows: '2', placeholder: 'Note (needed for "Other")', maxlength: '500' });
    var preview = el('div', { class: 'photo none', id: 'flag-preview' }, [el('span', { text: 'No photo taken' })]);
    var err = el('p', { class: 'msg bad', id: 'flag-err' });
    var file = el('input', { type: 'file', id: 'flag-file', accept: 'image/*', capture: 'environment', hidden: '' });
    file.addEventListener('change', function () {
      var f = file.files && file.files[0];
      if (!f) return;
      err.textContent = ''; busy = true;
      shrink(f).then(function (blob) {
        still = blob; busy = false;
        preview.textContent = ''; preview.className = 'photo';
        var img = el('img', { alt: 'The photo just taken' });
        img.src = URL.createObjectURL(blob);
        img.onload = function () { URL.revokeObjectURL(img.src); };
        preview.appendChild(img);
      }).catch(function (e) { busy = false; still = null; err.textContent = (e && e.message) || 'The photo could not be used.'; });
    });
    function close() { sheet.remove(); }
    var sheet = el('div', { class: 'sheet', id: 'flag-sheet', role: 'dialog', 'aria-label': 'Flag this entry' }, [el('div', { class: 'card' }, [
      el('h1', { text: 'Flag this entry' }),
      el('p', { class: 'muted', text: (row.event_type === 'GATE_IN' ? 'IN' : 'OUT') + ' · ' + who + ' · ' + timeOf(Date.parse(row.occurred_at)) }),
      reason, note,
      el('div', { class: 'row' }, [preview, el('div', {}, [
        el('button', { class: 'btn', id: 'flag-cam', type: 'button', text: 'Take photo', on: { click: function () { file.click(); } } }),
        el('p', { class: 'muted small', text: 'Optional. Kept on this phone only until it has been sent, then removed from it.' })
      ])]),
      file, err,
      el('div', { class: 'row' }, [
        el('button', { class: 'btn', id: 'flag-cancel', type: 'button', text: 'Cancel', on: { click: close } }),
        el('button', { class: 'btn primary', id: 'flag-save', type: 'button', text: 'Save flag', on: { click: function () {
          if (busy) { err.textContent = 'The photo is still being prepared. Try again in a moment.'; return; }
          if (!reason.value) { err.textContent = 'Choose why this is being flagged.'; return; }
          if (reason.value === 'OTHER' && !note.value.trim()) { err.textContent = 'Write a short note saying what was wrong.'; return; }
          var flag = {
            flag_uuid: uuid(), gate_event_uuid: row.event_uuid, employee_code: row.employee_code, employee_name: row.employee_name || '',
            event_type: row.event_type, reason_code: reason.value, reason: S.flagReasons[reason.value], note: note.value.trim(),
            occurred_at: new Date().toISOString(), timezone: tz(), actor_user_id: S.user ? S.user.id : null,
            state: 'pending', photo: still, photo_state: still ? 'pending' : 'none', created_at: Date.now(), attempts: 0
          };
          AttDB.put('flags', flag).then(function () {
            close();
            say('ok', 'Flag for ' + who + (still ? ' with photo' : '') + ' — saved on this phone. Sending…');
            refreshLists(); paintStatus();
            return syncNow();
          }).catch(function (e) { err.textContent = 'COULD NOT SAVE the flag. ' + (e && e.message ? e.message : ''); });
        } } })
      ])
    ])]);
    document.body.appendChild(sheet);
    reason.focus();
  }

  // ---------------------------------------------------------- feedback
  /* Every action answers in three ways at once, because a gate is noisy and
     a gatekeeper is looking at the person, not the screen: a sound, a buzz,
     and a burst on the button. Sound and buzz can be switched off on the
     Queue tab; the burst is skipped for anyone whose phone asks for less
     motion. None of it says more than the words on screen do — a green
     burst still means "saved on this phone", not "recorded by the server". */
  var audio = null;
  function tone(seq) {
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      if (audio.state === 'suspended') audio.resume();
      var t = audio.currentTime;
      seq.forEach(function (n) {
        var o = audio.createOscillator(), g = audio.createGain();
        o.type = n.type || 'sine'; o.frequency.value = n.f;
        g.gain.setValueAtTime(0.0001, t + n.at);
        g.gain.exponentialRampToValueAtTime(n.vol || 0.18, t + n.at + 0.015);
        g.gain.exponentialRampToValueAtTime(0.0001, t + n.at + n.d);
        o.connect(g); g.connect(audio.destination);
        o.start(t + n.at); o.stop(t + n.at + n.d + 0.02);
      });
    } catch (e) { /* no sound on this phone: the words and the buzz still show */ }
  }
  var SOUNDS = {
    ok:   [{ f: 784, at: 0, d: 0.09 }, { f: 1175, at: 0.09, d: 0.14 }],
    bad:  [{ f: 196, at: 0, d: 0.22, type: 'square', vol: 0.12 }, { f: 165, at: 0.24, d: 0.26, type: 'square', vol: 0.12 }],
    warn: [{ f: 523, at: 0, d: 0.1 }, { f: 523, at: 0.16, d: 0.1 }],
    tap:  [{ f: 880, at: 0, d: 0.04, vol: 0.06 }]
  };
  var BUZZ = { ok: [35], bad: [120, 70, 120], warn: [50, 50, 50], tap: [10] };
  var lessMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function feedback(kind, anchor) {
    if (S.fx.vibe && navigator.vibrate) { try { navigator.vibrate(BUZZ[kind] || 20); } catch (e) {} }
    if (S.fx.sound) tone(SOUNDS[kind] || SOUNDS.tap);
    var card = document.getElementById('scan-card');
    if (card && kind !== 'tap') { card.classList.remove('flash-ok', 'flash-bad'); void card.offsetWidth; card.classList.add(kind === 'ok' ? 'flash-ok' : 'flash-bad'); }
    if (kind === 'ok' && anchor && !lessMotion) sparkle(anchor);
  }
  function sparkle(anchor) {
    var r = anchor.getBoundingClientRect(), burst = el('div', { class: 'burst', 'aria-hidden': 'true' });
    burst.style.left = (r.left + r.width / 2) + 'px'; burst.style.top = (r.top + r.height / 2) + 'px';
    for (var i = 0; i < 14; i++) {
      var a = (Math.PI * 2 * i) / 14, dist = 46 + Math.random() * 38, p = el('i');
      p.style.setProperty('--dx', Math.round(Math.cos(a) * dist) + 'px');
      p.style.setProperty('--dy', Math.round(Math.sin(a) * dist) + 'px');
      p.style.animationDelay = Math.round(Math.random() * 60) + 'ms';
      burst.appendChild(p);
    }
    document.body.appendChild(burst);
    setTimeout(function () { burst.remove(); }, 900);
  }
  /* A message that comes back later, from the server — shown over whatever
     tab is open, so a refusal is not missed because the gatekeeper moved on. */
  function toast(kind, text) {
    var old = document.getElementById('toast'); if (old) old.remove();
    var t = el('div', { class: 'toast ' + kind, id: 'toast', role: 'status', text: text, on: { click: function () { t.remove(); } } });
    document.body.appendChild(t);
    setTimeout(function () { if (t.parentNode) t.remove(); }, 7000);
  }

  // ----------------------------------------------------------- keypad
  /* Employee codes here are numbers, so the phone shows its number pad, not
     the full keyboard. "ABC" beside the box switches to letters for a code
     that has them; the choice is remembered on this phone. The keypad's
     bottom-right key reads "Next": it closes the pad and points at what to
     press, it never records anything by itself. */
  function codeInput(id, onType, onNext) {
    var num = S.kbd !== 'text';
    var inp = el('input', { id: id, type: 'text', placeholder: 'Employee code', autocomplete: 'off', autocorrect: 'off', spellcheck: 'false',
      inputmode: num ? 'numeric' : 'text', pattern: num ? '[0-9]*' : null, enterkeyhint: 'next', autocapitalize: num ? 'off' : 'characters',
      'aria-label': 'Employee code', on: { input: function () { onType(inp.value.trim()); } } });
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); onNext(); } });
    var toggle = el('button', { class: 'btn kbd', id: id + '-kbd', type: 'button', 'aria-label': num ? 'Type letters' : 'Type numbers', text: num ? 'ABC' : '123', on: { click: function () {
      S.kbd = S.kbd === 'text' ? 'num' : 'text'; AttDB.setMeta('kbd', S.kbd);
      var n = S.kbd !== 'text';
      inp.setAttribute('inputmode', n ? 'numeric' : 'text');
      if (n) inp.setAttribute('pattern', '[0-9]*'); else inp.removeAttribute('pattern');
      inp.setAttribute('autocapitalize', n ? 'off' : 'characters');
      toggle.textContent = n ? 'ABC' : '123';
      inp.blur(); setTimeout(function () { inp.focus(); }, 30);
    } } });
    return { input: inp, toggle: toggle };
  }

  /* IN or OUT — only the right one is shown. Freshest knowledge wins:
       1. a scan still waiting on THIS phone (the server has not heard it yet);
       2. the server's answer just now — one database for every gate;
       3. this phone's own settled scan, if newer than its employee list;
       4. the employee list's "inside" mark, from its last refresh.
     It only chooses which button to show; the gatekeeper still presses it,
     and "Not right?" shows the other one. The server has the final say. */
  var decideSeq = 0;
  function utcMs(v) { if (!v) return null; var t = Date.parse(String(v).replace(' ', 'T').replace(/\.\d+$/, '') + 'Z'); return isNaN(t) ? null : t; }
  function gateView(state, emp, st) {
    var idle = document.getElementById('gate-idle'), person = document.getElementById('gate-person');
    var bi = document.getElementById('btn-in'), bo = document.getElementById('btn-out'), other = document.getElementById('btn-other');
    var chip = document.getElementById('empstate'), hint = document.getElementById('emphint');
    if (!idle || !person || !bi || !bo) return;
    idle.hidden = state !== 'idle';
    person.hidden = state === 'idle';
    [bi, bo].forEach(function (b) { b.classList.remove('suggest', 'pop'); });
    chip.className = 'chip'; chip.textContent = ''; hint.textContent = '';
    var note = document.getElementById('empnote'); if (note) { note.textContent = ''; note.hidden = true; }
    other.hidden = true;
    if (state === 'idle' || state === 'unknown') { bi.hidden = true; bo.hidden = true; return; }
    if (state === 'nolist') {                     // no employee list on this phone yet: both, as before
      bi.hidden = false; bo.hidden = false; S.gateNext = null;
      hint.textContent = 'This phone has no employee list yet — choose IN or OUT.';
      return;
    }
    var next = st.inside ? 'GATE_OUT' : 'GATE_IN', show = st.inside ? bo : bi, hide = st.inside ? bi : bo;
    var changed = S.gateNext !== next || show.hidden;
    S.gateNext = next;
    hide.hidden = true; show.hidden = false;
    if (changed) { void show.offsetWidth; show.classList.add('pop'); }
    var first = (emp.full_name || '').split(' ')[0];
    show.querySelector('.who-sub').textContent = st.inside ? first + ' is leaving' : first + ' is coming in';
    chip.classList.add(st.inside ? 'inside' : 'outside');
    chip.textContent = st.inside ? ('Inside' + (st.since ? ' since ' + timeOf(st.since) : '')) : 'Outside';
    hint.textContent = { server: 'Checked with the server just now', phone: 'From this phone\'s last scan', waiting: 'From this phone — that scan is not sent yet',
                         list: 'From the employee list' + (S.listAt ? ', updated ' + timeOf(S.listAt) : ''), none: 'No entry recorded yet' }[st.src] || '';
    if (note && st.lastAuto && !st.inside) {
      note.hidden = false;
      note.textContent = 'Last time no OUT was scanned — the system closed it at ' + timeOf(st.lastAuto) + ' on ' + new Date(st.lastAuto).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) + '.';
    }
    other.hidden = false;
    other.textContent = 'Not right? Record ' + (st.inside ? 'IN' : 'OUT') + ' instead';
  }
  function decideFor(code) {
    var seq = ++decideSeq;
    if (!code) { S.gateNext = null; gateView('idle'); return Promise.resolve(); }
    return Promise.all([findEmployee(code), AttDB.allOutbox(), AttDB.getMeta('employees_at')]).then(function (v) {
      if (seq !== decideSeq) return;
      var emp = v[0].hit, listAt = v[2] || 0;
      S.listAt = listAt || null;
      if (!emp) { gateView(v[0].known ? 'unknown' : 'nolist'); return; }
      var mine = v[1].filter(function (r) { return r.employee_code === emp.code && (r.event_type === 'GATE_IN' || r.event_type === 'GATE_OUT') && r.state !== 'rejected'; });
      var last = mine[mine.length - 1];
      var fromLast = last ? { inside: last.event_type === 'GATE_IN', since: last.event_type === 'GATE_IN' ? Date.parse(last.occurred_at) : null } : null;
      var st;
      if (last && last.state === 'pending') st = Object.assign(fromLast, { src: 'waiting' });
      else if (last && last.created_at > listAt) st = Object.assign(fromLast, { src: 'phone' });
      else if (emp.inside !== undefined && emp.inside !== null) st = { inside: !!parseInt(emp.inside, 10), since: utcMs(emp.inside_since), src: 'list' };
      else if (fromLast) st = Object.assign(fromLast, { src: 'phone' });
      else st = { inside: false, since: null, src: 'none' };
      gateView('person', emp, st);
      if (st.src === 'waiting' || !navigator.onLine) return;
      return api('GET', '/employees?query=' + encodeURIComponent(emp.code)).then(function (r) {
        if (seq !== decideSeq || r.status !== 200 || !r.body.employees) return;
        var s = r.body.employees.filter(function (e) { return e.code === emp.code; })[0];
        if (!s || s.inside === undefined) return;
        gateView('person', emp, { inside: !!parseInt(s.inside, 10), since: utcMs(s.inside_since), src: 'server', lastAuto: utcMs(s.last_auto_out_at) });
      }).catch(function () {});
    });
  }
  /* "Next" on the keypad: point at the button, never press it. */
  function pointAtAction() {
    var b = S.gateNext === 'GATE_OUT' ? document.getElementById('btn-out') : document.getElementById('btn-in');
    if (!b || b.hidden) return;
    b.classList.remove('suggest'); void b.offsetWidth; b.classList.add('suggest');
    try { b.focus({ preventScroll: true }); } catch (e) { b.focus(); }
    b.scrollIntoView({ block: 'nearest', behavior: lessMotion ? 'auto' : 'smooth' });
  }

  // --------------------------------------------------------------- overtime
  /* An overtime REQUEST is not a gate record and does not go through the
     outbox: it needs a connection, and the screen says so. It carries its
     own id, kept until the server answers, so pressing Send again after a
     dropped connection is the same request and never a second one. */
  function renderOvertime(body) {
    var box = el('div', { class: 'card', id: 'ot-card' }, [el('p', { class: 'muted', text: 'Loading…' })]);
    var mine = el('div', { id: 'ot-mine' });
    body.appendChild(box);
    body.appendChild(el('h2', { text: 'Your recent requests' }));
    body.appendChild(mine);
    if (!navigator.onLine) {
      box.textContent = '';
      box.appendChild(el('p', { class: 'msg bad', id: 'ot-offline', text: 'Overtime requests need a connection. Nothing here is saved on the phone.' }));
      return;
    }
    Promise.all([api('GET', '/overtime/employees'), api('GET', '/overtime/requests?status=all')]).then(function (v) {
      var r = v[0].body, list = v[1].body;
      box.textContent = '';
      if (!r.ok || !r.departments.length) { box.appendChild(el('p', { class: 'muted', text: (r && r.error) || 'You are not the HOD of a department.' })); return; }
      var now = new Date(Date.now() + (5 * 60 + new Date().getTimezoneOffset()) * 60000);
      var date = el('input', { type: 'date', id: 'ot-date', value: now.getFullYear() + '-' + ('0' + (now.getMonth() + 1)).slice(-2) + '-' + ('0' + now.getDate()).slice(-2) });
      var dept = el('select', { id: 'ot-dept' }, r.departments.map(function (d) { return el('option', { value: d.id, text: d.name }); }));
      var reason = el('textarea', { id: 'ot-reason', rows: '2', maxlength: '1000', placeholder: 'Reason' });
      /* Hours, not minutes: "2", "1.5" or "1:30". Sent to the server as whole minutes. */
      var minutes = el('input', { type: 'text', id: 'ot-min', value: '2', inputmode: 'decimal', autocomplete: 'off', placeholder: 'e.g. 1.5' });
      var toMin = function (v) {
        v = String(v || '').trim().replace(',', '.');
        var t = /^(\d{1,2}):([0-5]\d)$/.exec(v); if (t) return parseInt(t[1], 10) * 60 + parseInt(t[2], 10);
        return (/^\d{0,2}(\.\d{1,2})?$/.test(v) && v !== '' && v !== '.') ? Math.round(parseFloat(v) * 60) : NaN;
      };
      var list2 = el('div', { id: 'ot-people' });
      var msg = el('p', { class: 'msg', id: 'ot-msg' });
      var picked = {};
      function paint() {
        list2.textContent = '';
        r.employees.filter(function (e) { return String(e.department_id) === dept.value; }).forEach(function (e) {
          var c = el('input', { type: 'checkbox', 'data-id': e.id });
          c.checked = !!picked[e.id];
          c.addEventListener('change', function () { picked[e.id] = c.checked; S.otUuid = null; });
          list2.appendChild(el('label', { class: 'item pick' }, [c, el('span', { text: ' ' + e.full_name + ' · ' + e.code })]));
        });
      }
      [date, reason, minutes].forEach(function (x) { x.addEventListener('input', function () { S.otUuid = null; }); });
      dept.addEventListener('change', function () { picked = {}; S.otUuid = null; paint(); });
      var send = el('button', { class: 'btn primary', id: 'ot-send', text: 'Send overtime request', on: { click: function () {
        var ids = Object.keys(picked).filter(function (k) { return picked[k]; });
        msg.className = 'msg bad';
        if (!ids.length) { msg.textContent = 'Tick at least one employee.'; return; }
        if (!reason.value.trim()) { msg.textContent = 'Give the reason.'; return; }
        var est = toMin(minutes.value);
        if (isNaN(est) || est < 1) { msg.textContent = 'Type the hours as a number, for example 2, 1.5 or 1:30.'; return; }
        S.otUuid = S.otUuid || uuid();
        send.disabled = true;
        api('POST', '/overtime/requests', {
          request_uuid: S.otUuid, department_id: parseInt(dept.value, 10), work_date: date.value, reason: reason.value,
          employees: ids.map(function (k) { return { employee_id: parseInt(k, 10), estimated_minutes: est }; })
        }).then(function (x) {
          send.disabled = false;
          if (!x.body.ok) { msg.textContent = x.body.error || 'Not sent.'; return; }
          S.otUuid = null;
          msg.className = 'msg ok';
          msg.textContent = 'Sent for ' + x.body.request.lines.length + ' employee(s). One officer will decide each.';
          picked = {}; reason.value = ''; paint();
          return api('GET', '/overtime/requests?status=all').then(function (l) { paintMine(l.body); });
        }).catch(function () {
          send.disabled = false;
          msg.textContent = 'No connection — NOT SENT. Press Send again when there is a signal; it will not be sent twice.';
        });
      } } });
      box.appendChild(el('h1', { text: 'Ask for overtime' }));
      box.appendChild(el('p', { class: 'muted small', text: 'Only approved and checked hours are paid. Staying late without a request is not overtime.' }));
      [date, r.departments.length > 1 ? dept : null, reason, el('label', { class: 'muted', for: 'ot-min', text: 'Estimated hours for each (e.g. 2 or 1.5)' }), minutes, list2, send, msg]
        .forEach(function (n) { if (n) box.appendChild(n); });
      paint();
      paintMine(list);
    }).catch(function () {
      box.textContent = '';
      box.appendChild(el('p', { class: 'msg bad', text: 'Overtime requests need a connection.' }));
    });
    function paintMine(l) {
      mine.textContent = '';
      var reqs = (l && l.requests) || [];
      if (!reqs.length) { mine.appendChild(el('p', { class: 'muted', text: 'None yet.' })); return; }
      reqs.slice(0, 10).forEach(function (q) {
        mine.appendChild(el('div', { class: 'item note' }, [
          el('div', {}, [el('b', { text: q.work_date + '  ' }), el('span', { class: 'muted', text: q.reason })]),
          el('div', { class: 'sub' }, q.lines.map(function (x) {
            var word = x.approval_status === 'pending' ? 'waiting' : x.approval_status;
            var m = parseInt(x.approved_minutes || x.estimated_minutes, 10);
            var h = isNaN(m) ? '' : ' · ' + (Math.round(m / 60 * 100) / 100) + ' h';
            return el('div', { 'data-status': x.approval_status, text: x.full_name + h + ' — ' + word + (x.decided_by_name ? ' by ' + x.decided_by_name : '') });
          }))
        ]));
      });
    }
  }

  // ------------------------------------------------------ employee's photo
  /* Shown from the server, every time; never kept on the phone. The address
     is this app's own API — the phone is not told where pictures are stored. */
  function photoUrl(code) { return API + encodeURIComponent('/employees/' + code + '/photo'); }

  function paintPhoto(boxId, emp) {
    var box = document.getElementById(boxId);
    if (!box) return;
    var key = emp ? emp.code + (navigator.onLine ? '' : ':offline') : '';
    if (box.getAttribute('data-code') === key) return;          // already showing this one
    box.setAttribute('data-code', key);
    box.textContent = ''; box.className = 'photo none'; box.hidden = !emp;
    if (!emp) return;
    function note(text, state) { box.textContent = ''; box.className = 'photo none'; box.setAttribute('data-state', state); box.appendChild(el('span', { text: text })); }
    if (!parseInt(emp.has_photo, 10)) return note('No photo on file', 'none');
    if (!navigator.onLine) return note('Photo needs a connection', 'offline');
    var img = el('img', { alt: 'Photo of ' + emp.full_name });
    img.addEventListener('load', function () { if (box.getAttribute('data-code') === key) { box.className = 'photo'; box.setAttribute('data-state', 'shown'); } });
    img.addEventListener('error', function () { if (box.getAttribute('data-code') === key) note(navigator.onLine ? 'Photo could not be loaded' : 'Photo needs a connection', 'failed'); });
    img.addEventListener('click', function () { enlarge(emp); });
    box.setAttribute('data-state', 'loading');
    box.appendChild(img);
    img.src = photoUrl(emp.code);
  }

  function enlarge(emp) {
    var over = el('div', { class: 'sheet', id: 'photo-sheet', on: { click: function () { over.remove(); } } }, [
      el('div', { class: 'card center' }, [
        el('img', { class: 'bigphoto', alt: 'Photo of ' + emp.full_name, src: photoUrl(emp.code) }),
        el('p', { text: emp.full_name + ' · ' + emp.code }),
        el('button', { class: 'btn', type: 'button', text: 'Close' })
      ])
    ]);
    document.body.appendChild(over);
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
  /* Messages appear only when something happened. A success fades by itself
     after a few seconds; a refusal stays until the next code is typed. */
  var sayTimer = null;
  function say(kind, text) {
    S.message = { kind: kind, text: text }; paintMessage();
    clearTimeout(sayTimer);
    if (kind === 'ok') sayTimer = setTimeout(function () { S.message = null; var m = document.getElementById('msg'); if (m) { m.classList.add('fade'); setTimeout(paintMessage, 400); } }, 4500);
  }
  function quiet() { if (S.message) { clearTimeout(sayTimer); S.message = null; paintMessage(); } }

  function findEmployee(value) {
    return AttDB.getAll('employees').then(function (all) {
      var hit = null;
      all.forEach(function (e) { if (e.code === value || (e.qr_payload && e.qr_payload === value)) hit = e; });
      return { hit: hit, known: all.length };
    });
  }

  /* Is this employee one the HOD confirms? null = every department. */
  function inHodDepartments(emp) {
    if (!emp || S.hodDepts === null) return true;
    return (S.hodDepts || []).some(function (d) { return String(d.id) === String(emp.department_id); });
  }
  function hodDeptNames() {
    return S.hodDepts === null ? 'all departments' : ((S.hodDepts || []).map(function (d) { return d.name; }).join(', ') || 'no department');
  }

  /* The HOD tab's lookup: the same list, narrowed to the HOD's own
     departments. Someone from another department is "not yours" here even
     when this phone knows them for the gate. */
  function findForHod(value) {
    return findEmployee(value).then(function (v) {
      if (v.hit && !inHodDepartments(v.hit)) return { hit: null, known: v.known, outside: v.hit };
      return v;
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
        feedback('bad');
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
        feedback('warn');
        return;
      }
      if (last && last.event_type === type) {
        var q = 'This phone already has ' + (type === 'GATE_IN' ? 'an IN with no OUT' : 'an OUT with no IN') + ' for ' + (emp ? emp.full_name : code) +
                '. Save anyway? The server will decide whether it stands.';
        if (!confirm(q)) return;
      }

      return saveEvent(type, code, emp ? emp.full_name : '', {}).then(function () {
        feedback('ok', document.getElementById(type === 'GATE_IN' ? 'btn-in' : 'btn-out'));
        input.value = ''; paintEmployee(''); decideFor('');
        say('ok', (type === 'GATE_IN' ? 'IN' : 'OUT') + ' for ' + (emp ? emp.full_name + ' (' + code + ')' : code) + ' — saved on this phone. Sending…');
        try { input.focus({ preventScroll: true }); } catch (e) {}
        refreshLists(); paintStatus();
        return syncNow();
      });
    }).catch(function (e) {
      /* The one message that must never be soft. If storage refused the
         write, the scan does not exist anywhere. */
      say('bad', 'COULD NOT SAVE. Nothing was recorded. ' + (e && e.message ? e.message : '') + ' Try again, and tell a supervisor if it repeats.');
      feedback('bad');
    });
  }

  function hodByCode() {
    var input = document.getElementById('hodcode');
    var value = (input.value || '').trim();
    if (!value) { say('bad', 'Enter or scan an employee code first.'); return; }
    findForHod(value).then(function (v) {
      if (v.outside) { say('bad', v.outside.full_name + ' is not in your department (' + hodDeptNames() + '). Only their own HOD confirms them. Nothing was saved.'); feedback('bad'); return; }
      if (!v.hit && v.known > 0) { say('bad', '"' + value + '" is not in your department\'s list on this phone. Nothing was saved.'); feedback('bad'); return; }
      var code = v.hit ? v.hit.code : value;
      return saveEvent('HOD_CONFIRM', code, v.hit ? v.hit.full_name : '', {}).then(function () {
        feedback('ok', document.getElementById('btn-hodcode'));
        input.value = ''; paintEmployee('', 'hodname', 'hodphoto');
        say('ok', 'Arrival of ' + (v.hit ? v.hit.full_name : code) + ' — saved on this phone. Sending…');
        refreshLists(); paintStatus();
        return syncNow();
      });
    }).catch(function (e) { say('bad', 'COULD NOT SAVE. Nothing was recorded. ' + (e && e.message ? e.message : '')); });
  }

  function hodConfirmSelected() {
    var ids = Object.keys(S.selected).filter(function (k) { return S.selected[k]; });
    if (!ids.length) { say('bad', 'Tick at least one entry.'); feedback('warn'); return; }
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
        feedback('ok', document.getElementById('btn-confirm'));
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

  /* What this login can do on a phone, as "modes". Somebody with one job
     sees one set of tabs and nothing else. Somebody with both — usually an
     administrator — gets a switch at the top, and the phone remembers which
     side it was last used for, so a phone kept at the gate stays a gate phone. */
  function modes() {
    var m = [];
    if (S.can.gate) m.push('gate');
    if (S.can.hod || S.can.ot) m.push('hod');
    return m;
  }
  function tabsFor(mode) {
    var t = [];
    if (mode === 'gate') { if (S.can.gate) t.push(['gate', 'IN / OUT']); }
    else { if (S.can.hod) t.push(['hod', 'Arrivals']); if (S.can.ot) t.push(['ot', 'Overtime']); }
    t.push(['queue', 'Queue']);
    return t;
  }

  function renderMain() {
    var ms = modes();
    if (ms.indexOf(S.mode) < 0) S.mode = ms[0] || 'gate';
    var tabs = tabsFor(S.mode);
    if (!tabs.some(function (t) { return t[0] === S.tab; })) S.tab = tabs[0][0];
    var roleLabel = S.mode === 'gate' ? 'Security gate' : ('HOD · ' + hodDeptNames());

    root.appendChild(el('header', {}, [
      el('div', { class: 'brand' }, [
        el('div', { class: 'logo', 'aria-hidden': 'true', text: 'Z' }),
        el('div', {}, [el('strong', { text: 'ZAS Attendance' }), el('div', { class: 'muted role', id: 'who', text: (S.user ? S.user.name : '') + ' · ' + roleLabel })])
      ]),
      el('div', { id: 'status', class: 'status' })
    ]));
    if (ms.length > 1) {
      root.appendChild(el('div', { class: 'modes', role: 'tablist', 'aria-label': 'Use this phone as' }, ms.map(function (m) {
        return el('button', { class: 'mode' + (S.mode === m ? ' on' : ''), id: 'mode-' + m, type: 'button', role: 'tab', 'aria-selected': S.mode === m ? 'true' : 'false',
          on: { click: function () { if (S.mode === m) return; S.mode = m; AttDB.setMeta('mode', m); S.tab = tabsFor(m)[0][0]; S.message = null; feedback('tap'); render(); } } },
          [el('span', { class: 'mode-ico', 'aria-hidden': 'true', text: m === 'gate' ? '⛨' : '✓' }), m === 'gate' ? 'Security gate' : 'HOD']);
      })));
    }
    root.appendChild(el('div', { id: 'banners' }));
    root.appendChild(el('nav', {}, tabs.map(function (t) {
      return el('button', { class: 'tab' + (S.tab === t[0] ? ' on' : ''), id: 'tab-' + t[0], text: t[1], on: { click: function () { S.tab = t[0]; S.message = null; render(); } } });
    })));
    var body = el('main', { id: 'body' });
    root.appendChild(body);
    root.appendChild(el('div', { id: 'message' }));

    if (S.tab === 'gate') {
      var ci = codeInput('code', function (v) { quiet(); paintEmployee(v); decideFor(v); }, pointAtAction);
      var bigBtn = function (type) {
        var isIn = type === 'GATE_IN';
        return el('button', { class: 'btn big ' + (isIn ? 'in' : 'out'), id: isIn ? 'btn-in' : 'btn-out', type: 'button', hidden: '', on: { click: function () { gateAction(type); } } }, [
          el('span', { class: 'arrow', 'aria-hidden': 'true', text: isIn ? '→' : '←' }),
          el('span', { class: 'big-label' }, [el('span', { class: 'word', text: isIn ? 'IN' : 'OUT' }), el('span', { class: 'who-sub' })])
        ]);
      };
      body.appendChild(el('div', { class: 'card scan-card', id: 'scan-card' }, [
        el('label', { class: 'step', for: 'code' }, [el('b', { text: '1' }), 'Type or scan the employee code']),
        el('div', { class: 'row' }, [ci.input, ci.toggle, ('BarcodeDetector' in window) ? el('button', { class: 'btn', id: 'scan', type: 'button', text: 'Scan', on: { click: function () { scanInto('code'); } } }) : null]),
        el('div', { class: 'idle', id: 'gate-idle' }, [
          el('div', { class: 'idle-ico', 'aria-hidden': 'true' }, [el('span', { text: '⇄' })]),
          el('p', { class: 'idle-title', text: 'Ready for the next person' }),
          el('p', { class: 'idle-sub', text: 'The right button appears here: IN if they are outside, OUT if they are inside.' })
        ]),
        el('div', { class: 'person', id: 'gate-person', hidden: '' }, [
          el('div', { class: 'who' }, [el('div', { class: 'photo none', id: 'empphoto', hidden: '' }), el('div', { class: 'whotext' }, [
            el('p', { id: 'empname', class: 'empname' }), el('span', { id: 'empstate', class: 'chip' }), el('p', { id: 'emphint', class: 'hint' })])]),
          el('p', { class: 'autonote', id: 'empnote', hidden: '' }),
          el('div', { class: 'action', id: 'gate-action' }, [bigBtn('GATE_IN'), bigBtn('GATE_OUT')]),
          el('button', { class: 'linkbtn', id: 'btn-other', type: 'button', hidden: '', on: { click: function () {
            document.getElementById('btn-in').hidden = false; document.getElementById('btn-out').hidden = false;
            this.hidden = true; S.gateNext = null; feedback('tap');
          } } })
        ])
      ]));
      body.appendChild(el('h2', { text: 'Recent on this phone' }));
      body.appendChild(el('div', { id: 'recent' }));
    }
    if (S.tab === 'hod') {
      var hi = codeInput('hodcode', function (v) { paintEmployee(v, 'hodname', 'hodphoto'); }, function () {
        var b = document.getElementById('btn-hodcode'); if (b) { b.classList.remove('suggest'); void b.offsetWidth; b.classList.add('suggest'); b.focus(); }
      });
      body.appendChild(el('p', { class: 'scope', id: 'hod-scope', text: 'You confirm arrivals for: ' + hodDeptNames() + '.' }));
      body.appendChild(el('h2', { text: 'Waiting for your confirmation' }));
      body.appendChild(el('div', { id: 'pendinglist' }));
      body.appendChild(el('div', { class: 'row sticky' }, [
        el('button', { class: 'btn', id: 'btn-all', type: 'button', text: 'Select all', on: { click: function () {
          feedback('tap');
          AttDB.getAll('cycles').then(function (c) { c.forEach(function (x) { S.selected[x.entry_cycle_id] = true; }); refreshLists(); });
        } } }),
        el('button', { class: 'btn primary', id: 'btn-confirm', type: 'button', text: 'Confirm selected', on: { click: hodConfirmSelected } })
      ]));
      body.appendChild(el('h2', { text: 'Or confirm one person by code' }));
      body.appendChild(el('div', { class: 'card scan-card', id: 'scan-card' }, [
        el('div', { class: 'row' }, [hi.input, hi.toggle,
          ('BarcodeDetector' in window) ? el('button', { class: 'btn', type: 'button', text: 'Scan', on: { click: function () { scanInto('hodcode'); } } }) : null
        ]),
        el('div', { class: 'who' }, [el('div', { class: 'photo none', id: 'hodphoto', hidden: '' }), el('div', { class: 'whotext' }, [el('p', { id: 'hodname', class: 'empname' })])]),
        el('button', { class: 'btn primary', id: 'btn-hodcode', type: 'button', text: 'Confirm arrival', on: { click: hodByCode } })
      ]));
    }
    if (S.tab === 'ot') renderOvertime(body);
    if (S.tab === 'queue') {
      body.appendChild(el('div', { class: 'card', id: 'queueinfo' }));
      body.appendChild(el('div', { class: 'row' }, [
        el('button', { class: 'btn primary', id: 'btn-send', text: 'Send now', on: { click: function () { syncNow().then(refreshLookups).then(function () { refreshLists(); paintStatus(); }); } } }),
        el('button', { class: 'btn', id: 'btn-lock', text: 'Lock', on: { click: function () { S.screen = 'locked'; render(); } } }),
        el('button', { class: 'btn', id: 'btn-out2', text: 'Sign out', on: { click: signOut } })
      ]));
      function sw(id, label, key) {
        var c = el('input', { type: 'checkbox', id: id, role: 'switch' });
        c.checked = !!S.fx[key];
        c.addEventListener('change', function () { S.fx[key] = c.checked; AttDB.setMeta('fx', S.fx); if (c.checked) feedback('ok', c); });
        return el('label', { class: 'switch' }, [c, el('span', { class: 'track', 'aria-hidden': 'true' }), el('span', { text: label })]);
      }
      body.appendChild(el('div', { class: 'card prefs' }, [el('strong', { text: 'On this phone' }), sw('fx-sound', 'Sound', 'sound'), sw('fx-vibe', 'Vibrate', 'vibe')]));
      body.appendChild(el('h2', { text: 'Everything on this phone' }));
      body.appendChild(el('div', { id: 'queuelist' }));
    }
    /* AT12. Said plainly, on every screen, in the same words. */
    root.appendChild(el('footer', { id: 'honest', text:
      'Scans are saved on this phone first and sent to the server when there is a connection. ' +
      'If this phone is lost, reset, or its browser data is cleared before they are sent, unsent scans are lost. ' +
      'Keep the waiting count at zero. Employee photos are shown from the server and are not kept on this phone.' }));
    paintStatus(); paintMessage(); refreshLists();
  }

  function paintEmployee(value, nameId, photoId) {
    nameId = nameId || 'empname'; photoId = photoId || 'empphoto';
    var p = document.getElementById(nameId);
    if (!p) return;
    if (!value) { p.textContent = ''; paintPhoto(photoId, null); return; }
    (nameId === 'hodname' ? findForHod(value) : findEmployee(value)).then(function (v) {
      p.textContent = v.hit ? v.hit.full_name : (v.outside ? v.outside.full_name + ' — not in your department' : (v.known ? 'Not in this phone\'s list' : ''));
      p.className = 'empname' + (v.hit ? '' : ' bad');
      // The face goes with the name: shown as soon as the code is recognised,
      // before IN, OUT or Confirm is pressed.
      paintPhoto(photoId, v.hit || null);
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

  /* What has happened to a flag, in the same honest words as a scan. */
  function flagText(f) {
    var what = 'Flagged: ' + (f.reason || f.reason_code);
    if (f.state === 'rejected') return ['bad', what + ' — refused by the server: ' + (f.error || '')];
    if (f.state === 'pending') return ['wait', what + ' — on this phone, not sent yet'];
    if (f.photo_state === 'pending') return ['wait', what + ' — recorded by the server. Its photo is on this phone, not sent yet'];
    if (f.photo_state === 'rejected') return ['bad', what + ' — recorded by the server. Its photo was refused: ' + (f.photo_error || '')];
    return ['ok', what + ' — recorded by the server' + (f.photo_state === 'sent' ? ', with its photo' : '')];
  }

  function rowFor(r, withClear, flag) {
    var st = stateText(r);
    var label = r.event_type === 'GATE_IN' ? 'IN' : (r.event_type === 'GATE_OUT' ? 'OUT' : 'HOD');
    var ft = flag ? flagText(flag) : null;
    var gate = r.event_type === 'GATE_IN' || r.event_type === 'GATE_OUT';
    return el('div', { class: 'item ' + st[0], 'data-state': r.state, 'data-uuid': r.event_uuid }, [
      el('div', {}, [el('b', { text: label + '  ' }), el('span', { text: (r.employee_name ? r.employee_name + ' · ' : '') + r.employee_code }), el('span', { class: 'muted', text: '  ' + timeOf(Date.parse(r.occurred_at)) })]),
      el('div', { class: 'sub', text: st[1] }),
      ft ? el('div', { class: 'sub flag ' + ft[0], 'data-flag': flag.state, 'data-photo': flag.photo_state, text: ft[1] }) : null,
      (withClear && r.state === 'rejected') ? el('button', { class: 'btn small', text: 'Clear', on: { click: function () { AttDB.dismiss(r.event_uuid).then(refreshLists); } } }) : null,
      (flag && flag.state === 'rejected') ? el('button', { class: 'btn small', text: 'Clear flag', on: { click: function () { AttDB.removeOne('flags', flag.flag_uuid).then(refreshLists); } } }) : null,
      (gate && S.can.flag && !flag) ? el('button', { class: 'btn small flagbtn', text: 'Flag…', on: { click: function () { openFlag(r); } } }) : null
    ]);
  }

  function refreshLists() {
    return Promise.all([AttDB.allOutbox(), AttDB.getAll('cycles'), AttDB.getAll('flags'), AttDB.getAll('employees')]).then(function (v) {
      var rows = v[0].filter(function (r) { return !r.dismissed; }), cycles = v[1], flags = {}, emps = {};
      v[2].forEach(function (f) { flags[f.gate_event_uuid] = f; });
      v[3].forEach(function (e) { emps[e.code] = e; });
      var recent = document.getElementById('recent');
      if (recent) {
        recent.textContent = '';
        var g = rows.filter(function (r) { return r.event_type !== 'HOD_CONFIRM'; }).slice(-15).reverse();
        if (!g.length) recent.appendChild(el('p', { class: 'muted', text: 'Nothing yet.' }));
        g.forEach(function (r) { recent.appendChild(rowFor(r, true, flags[r.event_uuid])); });
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
            el('span', { class: 'muted', text: '  entry ' + c.entry_sequence + ', in at ' + timeOf(Date.parse(String(c.opened_at).replace(' ', 'T') + 'Z')) }),
            // The HOD can look at the face before ticking the box.
            parseInt(c.has_photo, 10) ? el('button', { class: 'btn small seephoto', type: 'button', text: 'Photo', on: { click: function (e) {
              e.preventDefault();
              if (!navigator.onLine) { say('bad', 'The photo needs a connection.'); return; }
              enlarge({ code: c.employee_code, full_name: c.full_name });
            } } }) : null]));
        });
        if (S.waitingForGate) pl.appendChild(el('p', { class: 'muted', text: S.waitingForGate + ' of your confirmations are waiting for the gate\'s record to reach the server.' }));
      }
      var ql = document.getElementById('queuelist');
      if (ql) {
        ql.textContent = '';
        rows.slice(-60).reverse().forEach(function (r) { ql.appendChild(rowFor(r, true, flags[r.event_uuid])); });
        if (!rows.length) ql.appendChild(el('p', { class: 'muted', text: 'Nothing yet.' }));
      }
      var qi = document.getElementById('queueinfo');
      if (qi) {
        var pend = rows.filter(function (r) { return r.state === 'pending'; });
        var fwait = v[2].filter(function (f) { return f.state === 'pending' || (f.state === 'acked' && f.photo_state === 'pending'); });
        qi.textContent = '';
        qi.appendChild(el('p', { id: 'qcount', text: (pend.length + fwait.length) + ' waiting to send' + (pend.length ? ' — oldest ' + ago(pend[0].created_at) : '') }));
        if (fwait.length) qi.appendChild(el('p', { class: 'muted', id: 'qflags', text: 'Of those, ' + fwait.length + ' flag(s) or flag photo(s).' }));
        qi.appendChild(el('p', { class: 'muted', text: 'Last complete send: ' + (S.lastSync ? ago(S.lastSync) : 'never on this phone') }));
        qi.appendChild(el('p', { class: 'muted', text: 'Storage protected from automatic clearing: ' + (S.persisted === true ? 'yes' : (S.persisted === false ? 'NO — the browser may clear it if the phone runs low on space' : 'unknown')) }));
        qi.appendChild(el('p', { class: 'muted', text: 'App ' + APP_VERSION + ' · device ' + (S.deviceId || 'not registered yet') }));
      }
    });
  }

  function paintStatus() {
    return Promise.all([AttDB.pending(), waitingFlags()]).then(function (both) {
      var pend = both[0], waiting = pend.length + both[1].length;
      var st = document.getElementById('status');
      if (st) {
        st.textContent = '';
        st.appendChild(el('span', { class: 'pill ' + (S.online ? 'ok' : 'bad'), id: 'net', text: S.online ? 'Online' : 'Offline' }));
        st.appendChild(el('span', { class: 'pill ' + (waiting ? 'wait' : 'ok'), id: 'waiting', 'data-count': String(waiting),
          text: S.syncing ? 'Sending…' : (waiting + ' waiting') }));
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
      return Promise.all(['device_uuid', 'device_id', 'user', 'can', 'pin', 'last_sync', 'policies', 'flag_reasons', 'hod_departments', 'mode', 'fx', 'kbd'].map(AttDB.getMeta));
    }).then(function (m) {
      S.deviceUuid = m[0]; S.deviceId = m[1] || null; S.user = m[2] || null; S.can = m[3] || S.can;
      S.pin = m[4] || null; S.lastSync = m[5] || null; if (m[6]) S.policies = m[6]; if (m[7]) S.flagReasons = m[7]; if (m[8] !== undefined) S.hodDepts = m[8];
      if (m[9]) S.mode = m[9]; if (m[10]) S.fx = m[10]; if (m[11]) S.kbd = m[11];
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
  window.AttApp = { state: S, syncNow: syncNow, refreshLookups: refreshLookups, refreshLists: refreshLists, version: APP_VERSION };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
