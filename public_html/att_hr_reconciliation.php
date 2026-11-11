<?php
/*
  ATTENDANCE — HR RECONCILIATION
  ==============================

  Everything the attendance system declined to decide for itself ends up on
  this one screen:

    - a HOD confirmation that fits more than one entry        (the system never picks)
    - two confirmations for one entry
    - a confirmation whose gate entry never arrived
    - a gate scan that could not be filed                      (no shift, unreadable time)
    - an entry left open                                       (somebody did not scan OUT)

  THE PAGE DECIDES NOTHING. It draws what GET /api/v1/hr/reconciliation
  returns and posts each decision to /hr/reconciliation/resolve, where the
  permission, the reason and the rules are checked again. Every action asks
  for a reason, because the server refuses one without it: the reason is kept
  with the decision in att_corrections and the audit log, and a correction is
  appended, never an edit of the original record.

  Names and codes are written with textContent throughout — a worker's name
  typed with an angle bracket in it must never become markup on an HR screen.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
zu_require('atthr', 'v');

$canAct     = zu_can('atthr', 'u');
$canDevices = zu_can('attadm', 'v');
$canRevoke  = zu_can('attadm', 'u');

page_header('Attendance — Reconciliation');
?>
<style>
.attq h2{font-size:15px;margin:22px 0 4px}.attq .why{color:#5a6b82;font-size:13px;margin:0 0 8px}
/* The ERP's stylesheet gives every table min-width:980px. On a phone that
   pushed this whole page sideways; undone here, and each table scrolls
   inside its own box when it still does not fit. */
.attq table{min-width:0}.attq .scroll{overflow-x:auto;width:0;min-width:100%}
.attq table{width:100%;border-collapse:collapse;font-size:13.5px}
.attq th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:#5a6b82;padding:7px 8px;border-bottom:1px solid #dfe6ef}
.attq td{padding:8px;border-bottom:1px solid #edf1f6;vertical-align:top}
.attq .none{color:#5a6b82;font-size:13.5px;padding:6px 0}
.attq .count{display:inline-block;min-width:22px;text-align:center;border-radius:99px;padding:1px 7px;font-size:12px;font-weight:700;background:#e8eef6;color:#33475f;margin-left:6px}
.attq .count.hot{background:#fbe6e4;color:#a1241c}
.attq button.act{font:inherit;font-size:12.5px;font-weight:600;padding:5px 9px;border-radius:7px;border:1px solid #cfd9e6;background:#fff;cursor:pointer;margin:0 4px 4px 0}
.attq button.act.primary{background:#10243e;color:#fff;border-color:#10243e}
.attq .muted{color:#5a6b82}.attq .err{color:#a1241c;font-weight:600}
#attdlg{border:1px solid #cfd9e6;border-radius:12px;padding:18px;max-width:480px;width:92vw}
#attdlg::backdrop{background:rgba(16,36,62,.35)}
#attdlg label{display:block;font-size:12.5px;font-weight:600;color:#33475f;margin:10px 0 4px}
#attdlg textarea,#attdlg select,#attdlg input{width:100%;font:inherit;padding:8px;border:1px solid #cfd9e6;border-radius:8px;box-sizing:border-box}
#attdlg .row{display:flex;gap:8px;justify-content:flex-end;margin-top:14px}
#attphoto{border:1px solid #cfd9e6;border-radius:12px;padding:18px;max-width:640px;width:94vw}
#attphoto::backdrop{background:rgba(16,36,62,.35)}
#attphoto .pair{display:flex;gap:16px;flex-wrap:wrap;margin:12px 0}
#attphoto figure{margin:0;flex:1 1 240px}#attphoto figcaption{font-size:12.5px;font-weight:600;color:#33475f;margin-bottom:6px}
#attphoto img,#attphoto .nopic{width:100%;border-radius:10px;background:#e9eef5;border:1px solid #cfd9e6;display:block;box-sizing:border-box}
#attphoto img{max-height:60vh;object-fit:contain}
#attphoto .nopic{aspect-ratio:4/5;display:flex;align-items:center;justify-content:center;color:#5a6b82;font-size:13px;text-align:center;padding:12px;border-style:dashed}
</style>

<div class="topbar">
  <div>
    <h1>Attendance — Reconciliation</h1>
    <p class="lead">What the system would not decide on its own. Every decision here needs a reason and is kept.</p>
  </div>
  <button class="btn secondary" id="att-refresh" type="button">Refresh</button>
</div>

<div class="card attq" id="attq"><p class="none">Loading…</p></div>

<dialog id="attdlg">
  <form method="dialog" id="attform">
    <strong id="dlg-title"></strong>
    <p class="muted" id="dlg-text" style="font-size:13px;margin:6px 0 0"></p>
    <div id="dlg-extra"></div>
    <label for="dlg-reason">Reason (kept with the decision)</label>
    <textarea id="dlg-reason" rows="3" required></textarea>
    <p class="err" id="dlg-err"></p>
    <div class="row">
      <button type="button" class="act" id="dlg-cancel">Cancel</button>
      <button type="submit" class="act primary" id="dlg-ok">Save decision</button>
    </div>
  </form>
</dialog>

<dialog id="attphoto">
  <strong id="ph-title"></strong>
  <p class="muted" id="ph-text" style="font-size:13px;margin:6px 0 0"></p>
  <div class="pair" id="ph-pair"></div>
  <div style="text-align:right"><button type="button" class="act" id="ph-close">Close</button></div>
</dialog>

<script>
(function(){
  'use strict';
  var API = 'api/v1/index.php?route=';
  var CSRF = <?= json_encode(csrf_token()) ?>;
  var CAN_ACT = <?= json_encode($canAct) ?>, CAN_DEV = <?= json_encode($canDevices) ?>, CAN_REVOKE = <?= json_encode($canRevoke) ?>;
  var box = document.getElementById('attq');

  function el(tag, attrs, kids){
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function(k){
      if (k === 'text') n.textContent = attrs[k];
      else if (k === 'click') n.addEventListener('click', attrs[k]);
      else if (attrs[k] !== null && attrs[k] !== undefined) n.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function(c){ if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }
  /* The database holds UTC. HR reads factory time. */
  function when(v){
    if (!v) return '—';
    var d = new Date(String(v).replace(' ', 'T').replace(/(\.\d+)?$/, '') + (/[zZ]|[+-]\d\d:?\d\d$/.test(String(v)) ? '' : 'Z'));
    if (isNaN(d)) return String(v);
    return d.toLocaleString('en-GB', { timeZone: 'Asia/Karachi', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
  }
  function call(method, route, body){
    var opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (method !== 'GET') { opts.headers['Content-Type'] = 'application/json'; opts.headers['X-CSRF-Token'] = CSRF; opts.body = JSON.stringify(body || {}); }
    return fetch(API + encodeURIComponent(route), opts).then(function(r){
      return r.json().catch(function(){ return { ok: false, error: 'The server sent something unreadable.' }; });
    });
  }

  // ------------------------------------------------------------- dialog
  var dlg = document.getElementById('attdlg'), pending = null;
  document.getElementById('dlg-cancel').addEventListener('click', function(){ dlg.close(); });
  document.getElementById('attform').addEventListener('submit', function(e){
    e.preventDefault();
    var reason = document.getElementById('dlg-reason').value.trim();
    var err = document.getElementById('dlg-err');
    if (!reason) { err.textContent = 'A reason is required.'; return; }
    var body = pending.build(reason);
    if (!body) return;
    document.getElementById('dlg-ok').disabled = true;
    call('POST', pending.route || '/hr/reconciliation/resolve', body).then(function(res){
      document.getElementById('dlg-ok').disabled = false;
      if (!res.ok) { err.textContent = res.error || 'That could not be saved.'; return; }
      dlg.close(); load();
    });
  });
  function ask(title, text, extra, build, route){
    pending = { build: build, route: route };
    document.getElementById('dlg-title').textContent = title;
    document.getElementById('dlg-text').textContent = text;
    var x = document.getElementById('dlg-extra'); x.textContent = '';
    (extra || []).forEach(function(n){ x.appendChild(n); });
    document.getElementById('dlg-reason').value = '';
    document.getElementById('dlg-err').textContent = '';
    dlg.showModal();
  }

  // ------------------------------------------------------------ sections
  function section(title, why, rows, head, draw){
    box.appendChild(el('h2', {}, [title, el('span', { class: 'count' + (rows.length ? ' hot' : ''), text: String(rows.length) })]));
    box.appendChild(el('p', { class: 'why', text: why }));
    if (!rows.length) { box.appendChild(el('p', { class: 'none', text: 'Nothing waiting.' })); return; }
    var t = el('table', {}, [el('thead', {}, [el('tr', {}, head.map(function(h){ return el('th', { text: h }); }))])]);
    var tb = el('tbody');
    rows.forEach(function(r){ tb.appendChild(el('tr', {}, draw(r).map(function(c){ return el('td', {}, [c]); }))); });
    t.appendChild(tb); box.appendChild(el('div', { class: 'scroll' }, [t]));
  }
  function who(r){ return el('span', {}, [el('strong', { text: r.full_name || r.employee_code || '—' }), el('span', { class: 'muted', text: '  ' + (r.employee_code || '') + (r.department_name ? ' · ' + r.department_name : '') })]); }
  function actions(list){ return el('span', {}, CAN_ACT ? list : [el('span', { class: 'muted', text: 'view only' })]); }

  function arrivalActions(r, candidates){
    var out = [];
    if (candidates && candidates.length) {
      out.push(el('button', { class: 'act primary', type: 'button', text: 'Choose entry…', click: function(){
        var sel = el('select', { id: 'dlg-cycle' }, candidates.map(function(c){
          return el('option', { value: c.entry_cycle_id, text: 'Entry ' + c.entry_sequence + ' — in ' + when(c.opened_at) + (c.closed_at ? ', out ' + when(c.closed_at) : ', still inside') });
        }));
        ask('Which entry does this confirmation belong to?', (r.full_name || '') + ' — HOD confirmed at ' + when(r.occurred_at) + '.',
          [el('label', { for: 'dlg-cycle', text: 'Entry' }), sel],
          function(reason){ return { kind: 'arrival', action: 'link', hod_arrival_id: r.id, entry_cycle_id: parseInt(sel.value, 10), reason: reason }; });
      } }));
    }
    out.push(el('button', { class: 'act', type: 'button', text: 'Set aside', click: function(){
      ask('Set this confirmation aside?', 'It is kept on record as dismissed; it is not deleted.', [],
        function(reason){ return { kind: 'arrival', action: 'dismiss', hod_arrival_id: r.id, reason: reason }; });
    } }));
    return actions(out);
  }

  // ------------------------------------------------------- flagged entries
  var pdlg = document.getElementById('attphoto');
  document.getElementById('ph-close').addEventListener('click', function(){ pdlg.close(); });
  /* Each picture is fetched from this server after the permission check, and
     each opening is one line in the audit log — which is why they load when
     HR asks to see them, not for every row of the list. */
  function seePhotos(f){
    function figure(caption, has, which, missing){
      var inner = has ? el('img', { alt: caption + ' — ' + (f.full_name || ''), src: API + encodeURIComponent('/hr/flags/' + f.id + '/photo') + '&which=' + which + '&t=' + Date.now() })
                      : el('div', { class: 'nopic', text: missing });
      return el('figure', {}, [el('figcaption', { text: caption }), inner]);
    }
    document.getElementById('ph-title').textContent = (f.full_name || '') + ' (' + (f.employee_code || '') + ')';
    document.getElementById('ph-text').textContent = f.reason + (f.note ? ' — ' + f.note : '') + ' · flagged ' + when(f.occurred_at) + (f.flagged_by_name ? ' by ' + f.flagged_by_name : '');
    var pair = document.getElementById('ph-pair'); pair.textContent = '';
    pair.appendChild(figure('Photo on file', parseInt(f.has_reference, 10) === 1, 'reference', 'No photo on file for this employee'));
    pair.appendChild(figure('Taken at the gate', parseInt(f.has_photo, 10) === 1, 'flag',
      parseInt(f.photo_purged, 10) === 1 ? 'Removed after the retention period' : 'No photo was taken with this flag'));
    pdlg.showModal();
  }
  function drawFlags(fl){
    section('Flagged entries', 'A gatekeeper marked these as needing a second look. Compare the photo taken at the gate with the one on file, then record what was found. The gate photo is deleted after ' + fl.retention_days + ' days; the flag and your finding are kept.',
      fl.open, ['Employee', 'Flagged', 'Why', ''], function(f){
        return [who(f), el('span', {}, [when(f.occurred_at), el('span', { class: 'muted', text: f.flagged_by_name ? '  by ' + f.flagged_by_name : '' })]),
          el('span', {}, [el('strong', { text: f.reason }), el('span', { class: 'muted', text: f.note ? '  ' + f.note : '' }),
            el('span', { class: 'muted', text: parseInt(f.has_photo, 10) === 1 ? '  · photo' : (f.entry_cycle_id ? '' : '  · the scan itself was refused') })]),
          el('span', {}, [
            el('button', { class: 'act', type: 'button', text: 'See photos', click: function(){ seePhotos(f); } }),
            CAN_ACT ? el('button', { class: 'act primary', type: 'button', text: 'Mark reviewed…', click: function(){
              ask('What was found?', (f.full_name || '') + ' — ' + f.reason + '.', [], function(reason){ return { reason: reason }; }, '/hr/flags/' + f.id + '/review');
            } }) : null
          ])];
      });
    if (fl.reviewed && fl.reviewed.length) {
      var d = el('details', { style: 'margin:6px 0 0' }, [el('summary', { class: 'muted', style: 'cursor:pointer;font-size:13px', text: fl.reviewed.length + ' reviewed in the last 7 days' })]);
      var t = el('table', {}, [el('tbody', {}, fl.reviewed.map(function(f){
        return el('tr', {}, [el('td', {}, [who(f)]), el('td', { text: f.reason }), el('td', { class: 'muted', text: f.review_note || '' }),
          el('td', {}, [el('button', { class: 'act', type: 'button', text: 'See photos', click: function(){ seePhotos(f); } })])]);
      }))]);
      d.appendChild(el('div', { class: 'scroll' }, [t]));
      box.appendChild(d);
    }
  }

  function draw(q, fl){
    box.textContent = '';
    box.appendChild(el('p', { class: 'muted', text: 'As of ' + when(q.generated_at) + ' (factory time). ' + q.unconfirmed_entries + ' entr' + (q.unconfirmed_entries === 1 ? 'y is' : 'ies are') + ' still owed a HOD confirmation in total.' }));

    if (fl && fl.ok) drawFlags(fl);

    section('Confirmation fits more than one entry', 'The HOD confirmed an arrival and the employee has two or more unconfirmed entries it could be. The system never chooses between them.',
      q.ambiguous, ['Employee', 'HOD confirmed at', 'Could be', ''], function(r){
        var n = (r.candidates || []).length;
        /* The stored reason lists internal entry numbers, which mean nothing
           on this screen. HR needs the count; the entries themselves are in
           the chooser. */
        var why = n > 1 ? n + ' unconfirmed entries it could be' : (n === 1 ? 'one unconfirmed entry is left' : 'no unconfirmed entry is left');
        return [who(r), when(r.occurred_at), el('span', { class: 'muted', text: why }), arrivalActions(r, r.candidates)];
      });

    section('Two confirmations for one entry', 'The entry was already confirmed when this second confirmation was matched to it.',
      q.disputed, ['Employee', 'HOD confirmed at', 'Why', ''], function(r){
        return [who(r), when(r.occurred_at), el('span', { class: 'muted', text: r.ambiguity_reason || '' }), arrivalActions(r, null)];
      });

    section('Confirmation with no gate entry', 'A HOD confirmed an arrival, but no gate entry for that employee has reached the server. Usually a gate phone that has not sent its scans yet.',
      q.waiting, ['Employee', 'HOD confirmed at', 'Received', ''], function(r){
        return [who(r), when(r.occurred_at), when(r.received_at), arrivalActions(r, null)];
      });

    section('Gate scans that could not be filed', 'The scan reached the server but had nothing to be filed against — most often no shift for that employee at that time. Fix the cause (assign the shift), then re-run it.',
      q.held_events, ['Employee code', 'Scan', 'Scanned at', 'Problem', ''], function(r){
        var btns = [];
        if (parseInt(r.can_retry, 10) === 1) btns.push(el('button', { class: 'act primary', type: 'button', text: 'Re-run', click: function(){
          ask('Re-run this scan?', 'Only works once the cause is fixed. It is filed under the gatekeeper who scanned it.', [],
            function(reason){ return { kind: 'event', action: 'retry', event_uuid: r.event_uuid, reason: reason }; });
        } }));
        btns.push(el('button', { class: 'act', type: 'button', text: 'Set aside', click: function(){
          ask('Set this scan aside?', 'It stays on record as dismissed.', [],
            function(reason){ return { kind: 'event', action: 'dismiss', event_uuid: r.event_uuid, reason: reason }; });
        } }));
        return [el('strong', { text: r.employee_code || '—' }), r.event_kind === 'GATE_IN' ? 'IN' : 'OUT', when(r.occurred_at), el('span', { class: 'muted', text: r.message || r.reason_code || '' }), actions(btns)];
      });

    section('Entries left open', 'No exit was scanned. Until the entry is closed, this employee\'s next IN is refused as "already inside". Closing it here records your stated exit time as a correction; no gate scan is invented.',
      q.open_too_long, ['Employee', 'Came in', 'Shift ended', ''], function(r){
        return [who(r), when(r.opened_at), when(r.shift_ends_at), actions([el('button', { class: 'act primary', type: 'button', text: 'Close entry…', click: function(){
          var t = el('input', { type: 'datetime-local', id: 'dlg-time' });
          ask('When did ' + (r.full_name || 'the employee') + ' leave?', 'Came in ' + when(r.opened_at) + '. Enter the exit in factory time.',
            [el('label', { for: 'dlg-time', text: 'Exit time (factory time)' }), t],
            function(reason){
              if (!t.value) { document.getElementById('dlg-err').textContent = 'Give the exit time.'; return null; }
              return { kind: 'cycle', entry_cycle_id: r.entry_cycle_id, closed_at: t.value, timezone: 'Asia/Karachi', reason: reason };
            });
        } })])];
      });

    section('Phones with a wrong clock', 'These phones\' clocks differ from the server\'s. The system does not correct them: somebody sets the phone\'s clock.',
      q.devices_with_clock_drift, ['Phone', 'Worst difference', 'Events', 'Last seen'], function(r){
        return [el('strong', { text: r.label || ('device ' + r.device_id) }), Math.round(Math.abs(r.worst_skew_ms) / 1000) + ' seconds', String(r.events), when(r.last_seen)];
      });

    if (CAN_DEV) call('GET', '/devices').then(function(d){
      section('Registered phones', 'A lost or retired phone should be withdrawn. It is refused from its very next scan and cannot register again.',
        d.devices || [], ['Phone', 'Used for', 'Last seen', 'State', ''], function(r){
          var state = r.revoked_at ? el('span', { class: 'err', text: 'Withdrawn ' + when(r.revoked_at) + (r.revoke_reason ? ' — ' + r.revoke_reason : '') }) : el('span', { text: 'In service' });
          var btn = (!r.revoked_at && CAN_REVOKE) ? el('button', { class: 'act', type: 'button', text: 'Withdraw…', click: function(){
            ask('Withdraw "' + (r.label || 'this phone') + '"?', 'This cannot be undone from here. Scans still on that phone and not yet sent will be refused when it tries to send them.', [],
              function(reason){ return { reason: reason }; }, '/devices/' + r.id + '/revoke');
          } }) : el('span', {});
          return [el('strong', { text: r.label || '—' }), r.role_scope, when(r.last_seen_at), state, btn];
        });
    });
  }

  function load(){
    Promise.all([call('GET', '/hr/reconciliation'), call('GET', '/hr/flags').catch(function(){ return { ok: false }; })]).then(function(v){
      var q = v[0];
      if (!q.ok) { box.textContent = ''; box.appendChild(el('p', { class: 'err', text: q.error || 'The queue could not be loaded.' })); return; }
      draw(q, v[1]);
    }).catch(function(){ box.textContent = ''; box.appendChild(el('p', { class: 'err', text: 'The server could not be reached.' })); });
  }
  document.getElementById('att-refresh').addEventListener('click', load);
  load();
})();
</script>
<?php page_footer(); ?>
