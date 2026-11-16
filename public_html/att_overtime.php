<?php
/*
  ATTENDANCE — OVERTIME
  =====================

  Blueprint §4, one tab per step, each shown only to the people who do it:

    New request   HOD               employees, date, reason, estimated hours
    Requests      HOD sees own;     approve / reject each employee; amend
                  officers see all
    Verify hours  HR / Accounts /   next day: actual hours, with the gate
                  Admin             history beside each line
    Payroll       Accounts / Admin  rate, finalize a period, correct, download

  THE PAGE DECIDES NOTHING. Every tab draws what /api/v1/overtime/... returns
  and sends each action back; permissions, department scope, the first-
  decision-wins rule, the next-day rule, the excess rule and the payroll lock
  are all kept by the server (includes/att/OvertimeService.php). A button
  hidden here is a courtesy only.

  The actual hours box is EMPTY until a person types in it. The gate
  history is shown (folded away until wanted) to be checked, never copied
  into it: staying late is not overtime (AT08). "Worked as approved" is a
  person's tap recording the APPROVED hours, never the gate's; when the gate
  does not show that much time inside, it asks first.

  Names are written with textContent throughout.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!zu_can('attot', 'v') && !zu_can('attotap', 'v') && !zu_can('attotvf', 'v') && !zu_can('attpay', 'v')) {
    http_response_code(403);
    exit('You do not have permission to view overtime.');
}
$can = [
    'request' => zu_can('attot', 'c'),
    'decide'  => zu_can('attotap', 'u'),
    'see'     => zu_can('attot', 'v') || zu_can('attotap', 'v') || zu_can('attotvf', 'v') || zu_can('attpay', 'v'),
    'verify'  => zu_can('attotvf', 'u'),
    'verifyv' => zu_can('attotvf', 'v'),
    'pay'     => zu_can('attpay', 'u'),
    'payv'    => zu_can('attpay', 'v'),
];
page_header('Attendance — Overtime');
?>
<style>
/* ENTERPRISE LOOK — the same working area as People Finance and Reports:
   white panel with a tab bar, compact inputs, navy-headed tables. */
.content{padding:16px 20px}
.topbar{margin-bottom:12px}
.topbar h1{font:600 20px/1.25 'IBM Plex Sans','Segoe UI',Inter,system-ui,Arial,sans-serif;color:#0f2742;margin:0}
.topbar .lead{font:400 12.5px/1.4 'IBM Plex Sans','Segoe UI',Inter,system-ui,Arial,sans-serif;color:#5b6b7c;margin:3px 0 0}
.atto,#attodlg{--ff:'IBM Plex Sans','Segoe UI',Inter,system-ui,-apple-system,Roboto,Arial,sans-serif;--navy:#0f2742;--pri:#0a5fb4;--ink:#1d2733;--muted:#5b6b7c;--line:#d5dbe3;--line2:#e7ebf0;--head:#f4f6f9;--hover:#f0f6fc;--good:#107e3e;--good-bg:#e7f4ec;--warn:#b45309;--warn-bg:#fef6ea;--bad:#bb0000;--bad-bg:#ffefef}
.atto{color:var(--ink);font:400 13px/1.45 var(--ff);background:#fff;border:1px solid var(--line);border-radius:6px;box-shadow:0 1px 2px rgba(15,39,66,.06);padding:0!important}
.atto *,#attodlg *{box-sizing:border-box}
.atto [hidden],#attodlg [hidden]{display:none!important}
.atto .tabs{width:0;min-width:100%;display:flex;gap:2px;overflow-x:auto;scrollbar-width:none;border-bottom:1px solid var(--line);padding:0 10px;margin:0}
.atto .tabs button{flex:0 0 auto;border:0;border-bottom:3px solid transparent;background:transparent;color:var(--muted);font:600 13px/1 var(--ff);padding:13px 14px 10px;cursor:pointer;white-space:nowrap}
.atto .tabs button:hover{color:var(--pri)}
.atto .tabs button[aria-selected=true]{color:var(--pri);border-bottom-color:var(--pri)}
#atto-body{padding:14px 16px 18px}
.atto table,#attodlg table{min-width:0;width:100%;border-collapse:separate;border-spacing:0;font-size:12.5px}
.atto th,#attodlg th{background:var(--navy);color:#fff;text-align:left;font-size:11.5px;font-weight:600;padding:8px 9px;white-space:nowrap}
.atto td,#attodlg td{padding:8px 9px;border-bottom:1px solid var(--line2);vertical-align:top}
.atto tbody tr:hover td{background:var(--hover)}
.atto .scroll{overflow-x:auto;width:0;min-width:100%;border:1px solid var(--line);border-radius:4px}
.atto .bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 12px}
.atto .bar .grow{flex:1 1 180px}
.atto input,.atto select,.atto textarea,#attodlg input,#attodlg select,#attodlg textarea{font:400 13px var(--ff);padding:6px 9px;min-height:32px;border:1px solid #b9c4d0;border-radius:3px;background:#fff;color:inherit;max-width:100%}
.atto input:focus,.atto select:focus,.atto textarea:focus{outline:2px solid #9cc3ea;outline-offset:0;border-color:var(--pri)}
.atto input[type=number]{width:96px}
.atto td select{width:auto}
.atto button.act,#attodlg button.act{font:600 12.5px/1 var(--ff);padding:8px 12px;min-height:32px;border-radius:3px;border:1px solid #b9c4d0;background:#fff;color:var(--pri);cursor:pointer;white-space:nowrap;margin:0 4px 4px 0}
.atto button.act:hover{background:var(--hover)}
.atto button.act.primary,#attodlg button.act.primary{background:var(--pri);color:#fff;border-color:var(--pri)}
.atto button.act.good{background:var(--good);color:#fff;border-color:var(--good)}
.atto button.act.link{border-color:transparent;background:transparent;padding-left:4px;padding-right:4px}
.atto button.act:disabled,#attodlg button.act:disabled{opacity:.5;cursor:default}
.atto .muted,#attodlg .muted{color:var(--muted)}.atto .err,#attodlg .err{color:var(--bad);font-weight:600}.atto .okmsg,#attodlg .okmsg{color:var(--good);font-weight:600}
.atto .pill{display:inline-block;border-radius:3px;padding:2px 7px;font-size:11px;font-weight:600;background:#e8eef6;color:#33475f;white-space:nowrap}
.atto .pill.approved,.atto .pill.verified,.atto .pill.ready,.atto .pill.finalized{background:var(--good-bg);color:var(--good)}
.atto .pill.rejected,.atto .pill.disputed,.atto .pill.undecided,.atto .pill.unverified,.atto .pill.no_rate{background:var(--bad-bg);color:var(--bad)}
.atto .pill.pending{background:var(--warn-bg);color:var(--warn)}
.atto .req{border:1px solid var(--line);border-radius:4px;padding:12px 14px;margin:0 0 12px}
.atto .req h3{font-size:14px;font-weight:600;color:var(--navy);margin:0 0 2px}.atto .req .meta{font-size:12px;color:var(--muted);margin:0 0 8px}
.atto .note{font-size:12.5px;color:var(--muted);margin:0 0 12px}
.atto .gate{font-size:12px;color:#33475f}.atto .gate div{white-space:nowrap}
.atto .total{font-size:20px;font-weight:700}
.atto .grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px 14px}
.atto label.f,#attodlg label.f{display:block;font-size:12px;font-weight:600;color:#33475f;margin:8px 0 4px}
/* New request: one short form, the planned times only when asked for */
.atto .form{display:grid;grid-template-columns:160px minmax(180px,260px) 150px 1fr;gap:4px 14px;align-items:end;background:var(--head);border:1px solid var(--line);border-radius:4px;padding:6px 12px 12px}
.atto .form .wide{grid-column:1/-1}
.atto .form textarea{width:100%;min-height:58px}
.atto .hrs{display:inline-flex;align-items:center;gap:6px}.atto .hrs input{width:84px;text-align:right}.atto .hrs span{color:var(--muted);font-weight:600}
.atto .pickbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:14px 0 6px}
.atto .pickbar .count{margin-left:auto;color:var(--muted);font-size:12.5px}
.atto .pick{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:6px;margin:0 0 12px}
.atto .pick label{display:flex;gap:8px;align-items:center;border:1px solid var(--line);border-radius:4px;padding:6px 9px;font-size:12.5px;cursor:pointer;background:#fff}
.atto .pick label:has(input:checked){border-color:var(--pri);background:#f3f8fd}
.atto .pick label input[type=checkbox]{width:16px;height:16px;min-height:0;margin:0}
.atto .pick label .nm{flex:1;min-width:0;line-height:1.25}
.atto .pick label input.hval{width:64px;margin-left:auto;text-align:right}
/* Verify hours: one tap when it was worked as approved; the details on request */
.atto .vsum{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 12px;background:var(--head);border:1px solid var(--line);border-radius:4px;padding:8px 10px}
.atto .vsum label{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;cursor:pointer}
.atto .vsum input[type=checkbox]{width:15px;height:15px;min-height:0;margin:0}
.atto .gchip{display:inline-block;margin-top:3px;font-size:11.5px;color:var(--muted)}
.atto .gchip.warn{color:var(--warn);font-weight:600}
.atto .diff{margin-top:6px;padding:8px;border:1px dashed #b9c4d0;border-radius:4px;background:#fbfcfd;display:grid;gap:6px;max-width:330px}
.atto .diff .row2{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.atto .diff input.hours{width:84px;text-align:right}
.atto .verified{color:var(--good);font-weight:600}
.atto td.tick{width:28px}.atto td.tick input,.atto th.tick input{width:15px;height:15px;min-height:0;margin:0}
@media (max-width:820px){.atto .form{grid-template-columns:1fr 1fr}#atto-body{padding:12px}}
#attodlg{border:1px solid var(--line);border-radius:6px;padding:18px;max-width:560px;width:94vw;max-height:92vh;overflow:auto;font:400 13px/1.45 var(--ff)}
#attodlg::backdrop{background:rgba(16,36,62,.35)}
#attodlg .row{display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap}
#attodlg .full{width:100%}
</style>

<div class="topbar">
  <div>
    <h1>Attendance — Overtime</h1>
    <p class="lead">Requested by the HOD, decided by one officer, checked the next day, paid at the rate Accounts types. Staying late on its own is never overtime.</p>
  </div>
</div>

<div class="card atto" id="atto">
  <div class="tabs" role="tablist" id="atto-tabs"></div>
  <div id="atto-body"><p class="muted">Loading…</p></div>
</div>

<dialog id="attodlg">
  <form method="dialog" id="attoform" novalidate>
    <strong id="attodlg-title"></strong>
    <p class="muted" id="attodlg-text" style="font-size:13px;margin:6px 0 0"></p>
    <div id="attodlg-fields"></div>
    <p class="err" id="attodlg-err" role="alert"></p>
    <div class="row">
      <button type="button" class="act" id="attodlg-cancel">Cancel</button>
      <button type="submit" class="act primary" id="attodlg-ok">Save</button>
    </div>
  </form>
</dialog>

<script>
(function(){
  'use strict';
  var API = 'api/v1/index.php?route=';
  var CSRF = <?= json_encode(csrf_token()) ?>;
  var CAN = <?= json_encode($can) ?>;
  var body = document.getElementById('atto-body');

  // ------------------------------------------------------------ helpers
  function el(tag, attrs, kids){
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function(k){
      var v = attrs[k];
      if (k === 'text') n.textContent = v;
      else if (k === 'click') n.addEventListener('click', v);
      else if (k === 'change') n.addEventListener('change', v);
      else if (k === 'input') n.addEventListener('input', v);
      else if (k === 'value') n.value = v;
      else if (k === 'checked') n.checked = !!v;
      else if (k === 'disabled') n.disabled = !!v;
      else if (v !== null && v !== undefined && v !== false) n.setAttribute(k, v);
    });
    (kids || []).forEach(function(c){ if (c !== null && c !== undefined && c !== false) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }
  function call(method, route, data){
    var url = API + encodeURIComponent(route);
    var o = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (method === 'GET') Object.keys(data || {}).forEach(function(k){ if (data[k] !== '' && data[k] != null) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); });
    else { o.headers['Content-Type'] = 'application/json'; o.headers['X-CSRF-Token'] = CSRF; o.body = JSON.stringify(data || {}); }
    return fetch(url, o).then(function(r){ return r.json().catch(function(){ return { ok: false, error: 'The server sent something unreadable.' }; }); })
      .catch(function(){ return { ok: false, error: 'The server could not be reached.' }; });
  }
  function uuid(){
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var b = crypto.getRandomValues(new Uint8Array(16)); b[6] = (b[6] & 15) | 64; b[8] = (b[8] & 63) | 128;
    var h = Array.prototype.map.call(b, function(x){ return ('0' + x.toString(16)).slice(-2); }).join('');
    return h.slice(0,8) + '-' + h.slice(8,12) + '-' + h.slice(12,16) + '-' + h.slice(16,20) + '-' + h.slice(20);
  }
  function btn(text, fn, primary, disabled){ return el('button', { type: 'button', class: 'act' + (primary ? ' primary' : ''), text: text, click: fn, disabled: disabled }); }
  function table(head, rows, draw){
    var tb = el('tbody');
    rows.forEach(function(r){ tb.appendChild(el('tr', {}, draw(r).map(function(c){ return el('td', {}, [c]); }))); });
    return el('div', { class: 'scroll' }, [el('table', {}, [el('thead', {}, [el('tr', {}, head.map(function(h){ return el('th', { text: h }); }))]), tb])]);
  }
  function show(nodes){ body.textContent = ''; nodes.forEach(function(n){ if (n) body.appendChild(n); }); }
  function pill(state, text){ return el('span', { class: 'pill ' + state, text: text || state }); }
  /* Overtime is typed and shown in HOURS: "1.5" or "1:30" is an hour and a
     half. The server still keeps whole minutes, so nothing about how amounts
     are worked out changes. */
  function mins(m){ if (m === null || m === undefined || m === '') return '—'; m = parseInt(m, 10); if (isNaN(m)) return '—';
    var h = Math.round(m / 60 * 100) / 100; return h + ' h' + (m % 15 && m >= 60 ? ' (' + Math.floor(m / 60) + ':' + ('0' + (m % 60)).slice(-2) + ')' : (m % 15 && m < 60 ? ' (' + m + ' min)' : '')); }
  function toMin(v){ v = String(v === null || v === undefined ? '' : v).trim().replace(',', '.');
    var t = /^(\d{1,2}):([0-5]\d)$/.exec(v); if (t) return parseInt(t[1], 10) * 60 + parseInt(t[2], 10);
    if (/^\d{0,2}(\.\d{1,2})?$/.test(v) && v !== '' && v !== '.') return Math.round(parseFloat(v) * 60);
    return NaN; }
  function hoursBox(attrs){ return el('input', Object.assign({ type: 'text', inputmode: 'decimal', autocomplete: 'off', placeholder: 'e.g. 1.5', style: 'width:90px' }, attrs || {})); }
  function hval(m){ m = parseInt(m, 10); return isNaN(m) ? '' : String(Math.round(m / 60 * 100) / 100); }
  var BAD_HOURS = 'Type the hours as a number, for example 2, 1.5 or 1:30.';
  /* The database holds UTC; the factory reads Karachi time. */
  function clock(v){
    if (!v) return '—';
    var d = new Date(String(v).replace(' ', 'T').replace(/(\.\d+)?$/, '') + (/[zZ]|[+-]\d\d:?\d\d$/.test(String(v)) ? '' : 'Z'));
    return isNaN(d) ? String(v) : d.toLocaleString('en-GB', { timeZone: 'Asia/Karachi', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
  }
  function day(v){ var d = new Date(v + 'T00:00:00Z'); return isNaN(d) ? v : d.toLocaleDateString('en-GB', { timeZone: 'UTC', weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' }); }
  function iso(d){ return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function todayKhi(){ return new Date(Date.now() + (5 * 60 + new Date().getTimezoneOffset()) * 60000); }
  function field(label, input){ if (!input.id) input.id = 'f' + Math.random().toString(36).slice(2, 9); return el('div', {}, [el('label', { class: 'f', for: input.id, text: label }), input]); }

  // -------------------------------------------------------------- dialog
  var dlg = document.getElementById('attodlg'), submitFn = null;
  document.getElementById('attodlg-cancel').addEventListener('click', function(){ dlg.close(); });
  document.getElementById('attoform').addEventListener('submit', function(e){
    e.preventDefault();
    if (!submitFn) return;
    var err = document.getElementById('attodlg-err'), ok = document.getElementById('attodlg-ok');
    err.textContent = '';
    var p = submitFn();
    if (!p) return;
    ok.disabled = true;
    p.then(function(r){ ok.disabled = false; if (r && r.keepOpen) return; if (!r || !r.ok) { err.textContent = (r && r.error) || 'That could not be saved.'; return; } dlg.close(); });
  });
  function dialog(o){
    document.getElementById('attodlg-title').textContent = o.title;
    document.getElementById('attodlg-text').textContent = o.text || '';
    var f = document.getElementById('attodlg-fields'); f.textContent = '';
    (o.fields || []).forEach(function(n){ if (n) f.appendChild(n); });
    document.getElementById('attodlg-err').textContent = '';
    var ok = document.getElementById('attodlg-ok'); ok.textContent = o.okText || 'Save'; ok.hidden = !!o.noOk; ok.disabled = false;
    document.getElementById('attodlg-cancel').textContent = o.noOk ? 'Close' : 'Cancel';
    submitFn = o.submit || null;
    if (!dlg.open) dlg.showModal();
  }
  function dlgError(t){ document.getElementById('attodlg-err').textContent = t; }

  function historyDialog(lineId, who){
    call('GET', '/overtime/lines/' + lineId + '/history').then(function(r){
      if (!r.ok) { alert(r.error || 'Could not load the history.'); return; }
      function v(x){ if (x === null || x === undefined) return ''; try { var j = JSON.parse(x); return typeof j === 'object' ? Object.keys(j).map(function(k){ return k.replace(/_/g, ' ') + ': ' + j[k]; }).join(', ') : String(j); } catch (e) { return String(x); } }
      dialog({ title: 'History — ' + who, noOk: true, fields: [table(['When', 'What', 'Change', 'By', 'Why'], r.history, function(h){
        return [clock(h.created_at), h.kind, (h.old_value ? v(h.old_value) + ' → ' : '') + v(h.new_value), h.actor_name || '—', h.remarks || ''];
      })] });
    });
  }

  // ---------------------------------------------------------------- tabs
  var TABS = [];
  if (CAN.request) TABS.push({ id: 'new', label: 'New request', draw: drawNew });
  if (CAN.see) TABS.push({ id: 'req', label: 'Requests', draw: drawRequests });
  if (CAN.verifyv) TABS.push({ id: 'verify', label: 'Verify hours', draw: drawVerify });
  if (CAN.payv) TABS.push({ id: 'pay', label: 'Payroll', draw: drawPayroll });
  function go(id){
    Array.prototype.forEach.call(document.querySelectorAll('#atto-tabs button'), function(b){ b.setAttribute('aria-selected', b.dataset.tab === id ? 'true' : 'false'); });
    show([el('p', { class: 'muted', text: 'Loading…' })]);
    TABS.filter(function(t){ return t.id === id; })[0].draw();
    try { history.replaceState(null, '', '#' + id); } catch (e) {}
  }
  TABS.forEach(function(t){ var b = el('button', { type: 'button', role: 'tab', text: t.label, click: function(){ go(t.id); } }); b.dataset.tab = t.id; document.getElementById('atto-tabs').appendChild(b); });

  // ---------------------------------------------------------- new request
  var pendingUuid = null;      // kept while the form is unchanged, so a retry is the same request
  function drawNew(){
    call('GET', '/overtime/employees').then(function(r){
      if (!r.ok) return show([el('p', { class: 'err', text: r.error || 'Could not load your departments.' })]);
      if (!r.departments.length) return show([el('p', { class: 'muted', text: 'You are not the HOD of any department, so there is nobody you can request overtime for. Ask an administrator (Setup › People).' })]);
      var t = todayKhi();
      var date = el('input', { type: 'date', value: iso(t), id: 'ot-date' });
      var dept = el('select', { id: 'ot-dept' }, r.departments.map(function(d){ return el('option', { value: d.id, text: d.name }); }));
      var reason = el('textarea', { rows: 2, id: 'ot-reason', maxlength: 1000, placeholder: 'For example: export order 4471 must ship Friday' });
      var ps = el('input', { type: 'time', id: 'ot-start' }), pe = el('input', { type: 'time', id: 'ot-end' });
      /* ONE number: hours for everyone ticked. Each person's box follows it until changed. */
      var all = hoursBox({ value: '2', id: 'ot-all', style: '' });
      var times = el('div', { class: 'wide grid2', id: 'ot-times', hidden: true }, [field('Planned start', ps), field('Planned end', pe)]);
      var timesBtn = el('button', { type: 'button', class: 'act link', id: 'ot-times-btn', text: '+ Add planned start and end (optional)', click: function(){
        times.hidden = !times.hidden; timesBtn.textContent = times.hidden ? '+ Add planned start and end (optional)' : '− Hide planned times'; if (times.hidden) { ps.value = ''; pe.value = ''; } } });
      var q = el('input', { type: 'search', placeholder: 'Find by code or name', id: 'ot-find' });
      var pick = el('div', { class: 'pick', id: 'ot-pick' });
      var countEl = el('span', { class: 'count', id: 'ot-count' });
      var msg = el('p', { id: 'ot-msg' });
      var boxes = {};
      function count(){ var n = Object.keys(boxes).filter(function(k){ return boxes[k].c.checked; }).length; countEl.textContent = n ? n + (n === 1 ? ' person' : ' people') + ' ticked' : 'Nobody ticked yet'; send.textContent = n ? 'Send request (' + n + ')' : 'Send request'; }
      function shown(){ var did = parseInt(dept.value, 10), term = q.value.trim().toLowerCase();
        return r.employees.filter(function(e){ return parseInt(e.department_id, 10) === did && (!term || (e.code + ' ' + e.full_name).toLowerCase().indexOf(term) >= 0); }); }
      function paint(){
        pick.textContent = '';
        shown().forEach(function(e){
          var b = boxes[e.id] || (boxes[e.id] = { c: el('input', { type: 'checkbox', 'data-code': e.code, change: function(){ count(); } }), m: hoursBox({ value: all.value, class: 'hval', style: '', 'aria-label': 'Hours for ' + e.full_name }), own: false });
          b.m.addEventListener('input', function(){ b.own = true; if (!b.c.checked) { b.c.checked = true; count(); } });
          pick.appendChild(el('label', {}, [b.c, el('span', { class: 'nm' }, [el('strong', { text: e.code }), ' ' + e.full_name]), b.m, el('span', { class: 'muted', text: 'h' })]));
        });
        if (!pick.children.length) pick.appendChild(el('p', { class: 'muted', text: 'Nobody matches.' }));
        count();
      }
      [date, dept, reason, ps, pe].forEach(function(x){ x.addEventListener('input', function(){ pendingUuid = null; }); });
      dept.addEventListener('change', function(){ boxes = {}; paint(); });
      q.addEventListener('input', paint);
      all.addEventListener('input', function(){ Object.keys(boxes).forEach(function(k){ if (!boxes[k].own) boxes[k].m.value = all.value; }); });
      var send = btn('Send request', function(){
        var chosen = Object.keys(boxes).filter(function(k){ return boxes[k].c.checked; });
        msg.className = 'err';
        if (!chosen.length) { msg.textContent = 'Tick at least one employee.'; return; }
        if (!reason.value.trim()) { msg.textContent = 'Give the reason for the overtime.'; return; }
        var badH = chosen.filter(function(k){ var v = toMin(boxes[k].m.value); return isNaN(v) || v < 1; });
        if (badH.length) { msg.textContent = BAD_HOURS; return; }
        pendingUuid = pendingUuid || uuid();
        send.disabled = true;
        call('POST', '/overtime/requests', {
          request_uuid: pendingUuid, department_id: parseInt(dept.value, 10), work_date: date.value, reason: reason.value,
          planned_start: ps.value, planned_end: pe.value,
          employees: chosen.map(function(k){ return { employee_id: parseInt(k, 10), estimated_minutes: toMin(boxes[k].m.value) }; })
        }).then(function(x){
          send.disabled = false;
          if (!x.ok) { msg.textContent = x.error || 'The request was not sent.'; return; }
          pendingUuid = null;
          msg.className = 'okmsg';
          msg.textContent = 'Sent: ' + x.request.lines.length + ' employee' + (x.request.lines.length === 1 ? '' : 's') + ' for ' + day(x.request.work_date) + '. Waiting for one officer to decide.';
          reason.value = ''; boxes = {}; paint();
        });
      }, true);
      send.id = 'ot-send-desk';
      show([
        el('p', { class: 'note', text: 'Ask for overtime before it is worked: the day, the department, the hours, the reason, and who. One officer (HR, Accounts or Admin) decides; the hours are checked the next day; only approved and checked hours are paid.' }),
        el('div', { class: 'form' }, [field('Date', date), field('Department', dept),
          el('div', {}, [el('label', { class: 'f', for: 'ot-all', text: 'Hours for each' }), el('span', { class: 'hrs' }, [all, el('span', { text: 'h' })])]),
          el('div', { style: 'align-self:center' }, [timesBtn]),
          times,
          el('div', { class: 'wide' }, [field('Reason', reason)])]),
        el('div', { class: 'pickbar' }, [el('b', { text: 'Who' }), q,
          btn('Tick all shown', function(){ shown().forEach(function(e){ boxes[e.id].c.checked = true; }); count(); }),
          btn('Untick all', function(){ Object.keys(boxes).forEach(function(k){ boxes[k].c.checked = false; }); count(); }), countEl]),
        pick, el('div', { class: 'bar' }, [send]), msg
      ]);
      paint();
    });
  }

  // ------------------------------------------------------------ requests
  var reqFilter = { status: 'open' };
  function drawRequests(){
    var st = el('select', { 'aria-label': 'Show', id: 'req-status', change: function(){ reqFilter.status = st.value; drawRequests(); } },
      [['open', 'Waiting for a decision'], ['decided', 'Decided'], ['all', 'All']].map(function(o){ var x = el('option', { value: o[0], text: o[1] }); if (o[0] === reqFilter.status) x.selected = true; return x; }));
    call('GET', '/overtime/requests', reqFilter).then(function(r){
      if (!r.ok) return show([el('p', { class: 'err', text: r.error })]);
      var nodes = [el('div', { class: 'bar' }, [st])];
      if (!r.requests.length) nodes.push(el('p', { class: 'muted', id: 'req-none', text: reqFilter.status === 'open' ? 'Nothing is waiting for a decision.' : 'No requests.' }));
      r.requests.forEach(function(q){ nodes.push(requestCard(q)); });
      show(nodes);
    });
  }
  function requestCard(q){
    var sel = {};
    var card = el('div', { class: 'req', 'data-request': q.id });
    card.appendChild(el('h3', { text: day(q.work_date) + ' · ' + q.department_name }));
    card.appendChild(el('p', { class: 'meta', text: 'Asked by ' + (q.requested_by_name || '—') + ' on ' + clock(q.created_at)
      + (q.planned_start ? ' · planned ' + q.planned_start.slice(0, 5) + '–' + q.planned_end.slice(0, 5) : '') }));
    card.appendChild(el('p', { style: 'margin:0 0 8px', text: q.reason }));
    card.appendChild(table(['', 'Employee', 'Asked', 'Decision', 'Approved', 'Checked next day', ''], q.lines, function(l){
      var box = (l.approval_status === 'pending' && CAN.decide) ? el('input', { type: 'checkbox', 'aria-label': 'Select ' + l.full_name, change: function(e){ sel[l.request_employee_id] = e.target.checked; } }) : el('span');
      var decided = l.approval_status === 'pending' ? pill('pending', 'waiting') : el('span', {}, [pill(l.approval_status), el('div', { class: 'muted', style: 'font-size:12px', text: (l.decided_by_name || '') + (l.decision_note ? ' — ' + l.decision_note : '') })]);
      var check = l.approval_status !== 'approved' ? el('span', { class: 'muted', text: '—' })
        : (l.verification_status === 'verified' ? el('span', {}, [pill('verified', mins(l.actual_minutes)), l.payroll_status === 'finalized' ? el('span', { class: 'muted', text: ' · paid' }) : null])
        : pill(l.verification_status === 'disputed' ? 'disputed' : 'pending', l.verification_status === 'disputed' ? 'disputed' : 'not yet'));
      var acts = el('span', {}, [
        (CAN.decide && l.approval_status !== 'pending' && l.payroll_status !== 'finalized') ? btn('Amend…', function(){ amendDialog(q, l); }) : null,
        btn('History', function(){ historyDialog(l.request_employee_id, l.full_name); })
      ]);
      return [box, el('span', {}, [el('strong', { text: l.employee_code }), ' ' + l.full_name]), mins(l.estimated_minutes), decided, l.approved_minutes ? mins(l.approved_minutes) : '—', check, acts];
    }));
    if (CAN.decide && q.pending > 0) {
      var decide = function(which){
        var ids = Object.keys(sel).filter(function(k){ return sel[k]; });
        if (!ids.length) { alert('Tick the employees to ' + which + ' first.'); return; }
        var remarks = el('textarea', { class: 'full', rows: 2, id: 'dec-remarks', maxlength: 1000 });
        var batch = uuid();
        dialog({ title: (which === 'approve' ? 'Approve ' : 'Reject ') + ids.length + ' employee' + (ids.length === 1 ? '' : 's') + '?',
          text: 'The first decision recorded is the one that stands. If another officer decided someone a moment ago, you will be told.',
          fields: [field('Remarks (optional)', remarks)], okText: which === 'approve' ? 'Approve' : 'Reject',
          submit: function(){
            return call('POST', '/overtime/requests/' + q.id + '/decisions', { request_uuid: batch, employee_decisions: ids.map(function(i){ return { request_employee_id: parseInt(i, 10), decision: which, remarks: remarks.value }; }) })
              .then(function(r){
                if (!r.ok) return r;
                var lost = r.results.filter(function(x){ return x.result !== 'APPROVED' && x.result !== 'REJECTED'; });
                if (lost.length) { dlgError(lost.map(function(x){ return x.message; }).join(' ')); drawRequests(); return { keepOpen: true }; }
                drawRequests(); return r;
              });
          } });
      };
      card.appendChild(el('div', { class: 'bar', style: 'margin:10px 0 0' }, [btn('Approve selected', function(){ decide('approve'); }, true), btn('Reject selected', function(){ decide('reject'); })]));
    }
    return card;
  }
  function amendDialog(q, l){
    var what = el('select', { class: 'full', id: 'am-what' }, [
      el('option', { value: 'approved_minutes', text: 'Approved hours (to allow more or less)' }),
      el('option', { value: 'decision', text: l.approval_status === 'approved' ? 'Change the decision to REJECTED' : 'Change the decision to APPROVED' }),
      (l.verification_status !== 'pending' && l.approval_status === 'approved') ? el('option', { value: 'actual_minutes', text: 'Actual hours already checked' }) : null
    ].filter(Boolean));
    var val = hoursBox({ value: hval(l.approved_minutes || l.estimated_minutes), id: 'am-value' });
    var wrap = field('New value (hours)', val);
    what.addEventListener('change', function(){ wrap.hidden = what.value === 'decision'; val.value = hval(what.value === 'actual_minutes' ? (l.actual_minutes || 0) : (l.approved_minutes || l.estimated_minutes)); });
    var reason = el('textarea', { class: 'full', rows: 3, id: 'am-reason', maxlength: 1000 });
    dialog({ title: 'Amend — ' + l.full_name + ', ' + day(q.work_date), text: 'An amendment is kept with its reason and your name. It cannot be made once payroll for this day is finalized.',
      fields: [field('What changes', what), wrap, field('Reason (required)', reason)], okText: 'Save amendment',
      submit: function(){
        var change = what.value;
        if (change !== 'decision' && isNaN(toMin(val.value))) return Promise.resolve({ ok: false, error: BAD_HOURS });
        return call('POST', '/overtime/requests/' + q.id + '/amend', { request_employee_id: l.request_employee_id, change: change,
          value: change === 'decision' ? (l.approval_status === 'approved' ? 'reject' : 'approve') : toMin(val.value), reason: reason.value })
          .then(function(r){ if (r.ok) drawRequests(); return r; });
      } });
  }

  // -------------------------------------------------------------- verify
  /* VERIFY HOURS. Most overtime is worked exactly as approved, so that is ONE
     tap: "Worked as approved". Nothing is ever filled in by itself — the tap
     is the checker saying so. Different hours open an EMPTY box (AT08: time
     at the gate is shown to help, never copied). The gate history is folded
     away until wanted; when it does not support the approved time, the tap
     asks first. */
  var SHOW_GATE = false;
  /* Does the gate back up the approved time? Said with WHAT was measured:
     inside during the planned overtime hours, or inside after the shift. */
  function gateSupports(l){
    var mv = l.movement || {}, a = parseInt(l.approved_minutes, 10) || 0;
    if (!mv.entries || !mv.entries.length) return { ok: false, why: 'no gate entry that day' };
    var has = function(v){ return v !== null && v !== undefined; };
    var after = has(mv.minutes_inside_after_shift) ? parseInt(mv.minutes_inside_after_shift, 10) || 0 : null;
    var plan = has(mv.minutes_inside_planned) ? parseInt(mv.minutes_inside_planned, 10) || 0 : null;
    if (after === null && plan === null) return { ok: true, why: '' };
    var usePlan = plan !== null && (after === null || plan >= after);
    var seen = usePlan ? plan : after;
    var where = usePlan ? ' inside during the planned time' + (l.planned_start ? ' ' + String(l.planned_start).slice(0, 5) + '–' + String(l.planned_end || '').slice(0, 5) : '') : ' inside after the shift';
    return { ok: seen >= a, why: 'the gate shows ' + mins(seen) + where };
  }
  function drawVerify(){
    var dt = el('input', { type: 'date', id: 'ver-date', 'aria-label': 'Work date' });
    call('GET', '/overtime/verification', verifyDate ? { date: verifyDate } : {}).then(function(r){
      if (!r.ok) return show([el('p', { class: 'err', text: r.error })]);
      dt.value = verifyDate || '';
      dt.addEventListener('change', function(){ verifyDate = dt.value; drawVerify(); });
      var picked = {};
      function verify(l, minutes, note){
        return call('POST', '/overtime/requests/' + l.request_id + '/verify-hours', { request_employee_id: l.request_employee_id, actual_minutes: minutes, verification_note: note || '' });
      }
      var gateTog = el('input', { type: 'checkbox', id: 'ver-gate', change: function(){ SHOW_GATE = gateTog.checked; Array.prototype.forEach.call(document.querySelectorAll('#atto-body .gate'), function(g){ g.hidden = !SHOW_GATE; }); } });
      gateTog.checked = SHOW_GATE;
      var bulk = btn('Worked as approved — ticked', function(){
        var lines = r.lines.filter(function(l){ return picked[l.request_employee_id]; });
        if (!lines.length) { alert('Tick the lines first.'); return; }
        var weak = lines.filter(function(l){ return !gateSupports(l).ok; });
        var ok = lines.filter(function(l){ return gateSupports(l).ok; });
        dialog({ title: 'Record ' + ok.length + ' line' + (ok.length === 1 ? '' : 's') + ' as worked as approved?',
          text: (ok.length ? ok.map(function(l){ return l.full_name + ' ' + mins(l.approved_minutes); }).join(', ') + '. ' : '') + (weak.length ? weak.length + ' ticked line' + (weak.length === 1 ? '' : 's') + ' left for you to check one by one, because the gate does not show the approved time: ' + weak.map(function(l){ return l.full_name; }).join(', ') + '.' : ''),
          okText: ok.length ? 'Record ' + ok.length : 'Close', noOk: !ok.length,
          submit: function(){
            return ok.reduce(function(p, l){ return p.then(function(acc){ return verify(l, parseInt(l.approved_minutes, 10), 'Worked as approved').then(function(x){ acc.push(x); return acc; }); }); }, Promise.resolve([]))
              .then(function(res){ var bad = res.filter(function(x){ return !x.ok; }); drawVerify(); return bad.length ? { ok: false, error: bad.map(function(x){ return x.error; }).join(' ') } : { ok: true }; });
          } });
      }, true);
      bulk.id = 'ver-bulk';
      var nodes = [
        el('p', { class: 'note', text: 'The day after, record what was worked. Worked as approved? One tap. Different? Type the hours (1.5 or 1:30) — the box starts empty, and time inside at the gate is never counted by itself. More than approved needs an amendment first.' }),
        el('div', { class: 'vsum' }, [el('span', { class: 'muted', text: 'Showing ' + (verifyDate ? 'everything approved for' : 'everything still to check') }), dt,
          verifyDate ? btn('Show everything still to check', function(){ verifyDate = ''; drawVerify(); }) : null,
          el('label', {}, [gateTog, 'Show gate history']), el('span', { style: 'flex:1' }), CAN.verify ? bulk : null])
      ];
      if (!r.lines.length) nodes.push(el('p', { class: 'muted', id: 'ver-none', text: 'Nothing to check.' }));
      else nodes.push(table(['', 'Employee', 'Work date', 'Approved', 'Worked', ''], r.lines, function(l){
        var mv = l.movement || {};
        var gate = el('div', { class: 'gate', hidden: !SHOW_GATE }, (mv.entries && mv.entries.length) ? mv.entries.map(function(e){ return el('div', { text: 'In ' + clock(e.in) + ' — ' + (e.out ? 'out ' + clock(e.out) + (e.auto_out ? ' (not scanned — closed by the system at shift end)' : '') : 'still inside') }); }) : [el('div', { class: 'muted', text: 'No gate entry that day' })]);
        if (mv.minutes_inside_after_shift !== null && mv.minutes_inside_after_shift !== undefined) gate.appendChild(el('div', { class: 'muted', text: 'Inside after the shift ended: ' + mins(mv.minutes_inside_after_shift) }));
        if (mv.minutes_inside_planned !== null && mv.minutes_inside_planned !== undefined) gate.appendChild(el('div', { class: 'muted', text: 'Inside during the planned time: ' + mins(mv.minutes_inside_planned) }));
        var sup = gateSupports(l);
        /* Before the day after, the overtime may not even have been worked yet: no warning, just when it can be checked. */
        var chip = (l.verification_status !== 'verified' && !l.can_verify_now)
          ? el('span', { class: 'gchip', text: 'Overtime not worked yet — check from ' + day(nextDay(l.work_date)) })
          : el('span', { class: 'gchip' + (sup.ok ? '' : ' warn'), text: sup.ok ? (sup.why ? 'Gate: ' + sup.why.replace('the gate shows ', '') : '') : '⚠ ' + sup.why.charAt(0).toUpperCase() + sup.why.slice(1) });
        var out = el('div', { class: 'err', style: 'font-size:12px' });
        var tick = el('span'), work, acts = el('span');
        if (l.verification_status === 'verified') {
          work = el('span', { class: 'verified', text: '✓ ' + mins(l.actual_minutes) });
        } else if (!l.can_verify_now) {
          work = el('span', { class: 'muted', text: 'From ' + day(nextDay(l.work_date)) });
        } else if (CAN.verify) {
          tick = el('input', { type: 'checkbox', style: 'width:16px;height:16px;min-height:0;margin:2px 0 0', 'aria-label': 'Tick ' + l.full_name, change: function(e){ picked[l.request_employee_id] = e.target.checked; } });
          var input = hoursBox({ 'aria-label': 'Actual hours for ' + l.full_name, 'data-line': l.request_employee_id, class: 'hours', style: '' });
          var note = el('input', { type: 'text', placeholder: 'Note (optional)', maxlength: 1000, style: 'flex:1;min-width:120px' });
          var diff = el('div', { class: 'diff', hidden: l.verification_status !== 'disputed' }, [
            el('div', { class: 'row2' }, [input, el('span', { class: 'muted', text: 'h actually worked' })]),
            el('div', { class: 'row2' }, [note]),
            el('div', { class: 'row2' }, [btn('Save hours', function(){
                if (input.value.trim() === '') { out.textContent = 'Type the actual hours (0 if no overtime was worked).'; return; }
                if (isNaN(toMin(input.value))) { out.textContent = BAD_HOURS; return; }
                verify(l, toMin(input.value), note.value).then(function(x){ if (!x.ok) { out.textContent = x.error; return; } drawVerify(); });
              }, true),
              l.verification_status === 'pending' ? btn('Dispute…', function(){
                var why = el('textarea', { class: 'full', rows: 3, id: 'disp-note' });
                dialog({ title: 'Dispute — ' + l.full_name, text: 'Use this when the record does not add up and you cannot settle it. An officer settles it later with an amendment.',
                  fields: [field('What does not add up?', why)], okText: 'Record dispute',
                  submit: function(){ return call('POST', '/overtime/requests/' + l.request_id + '/dispute', { request_employee_id: l.request_employee_id, verification_note: why.value }).then(function(x){ if (x.ok) drawVerify(); return x; }); } });
              }) : null])]);
          var asOk = btn('✓ Worked as approved', function(){
            function go_(){ verify(l, parseInt(l.approved_minutes, 10), 'Worked as approved').then(function(x){ if (!x.ok) { out.textContent = x.error; return; } drawVerify(); }); }
            if (sup.ok) return go_();
            dialog({ title: 'Record ' + mins(l.approved_minutes) + ' for ' + l.full_name + '?', text: 'Careful: ' + sup.why + ', less than the ' + mins(l.approved_minutes) + ' approved. Record it only if you know the overtime was worked (for example at another gate). Otherwise use "Different hours".',
              okText: 'Record ' + mins(l.approved_minutes), submit: function(){ go_(); return Promise.resolve({ ok: true }); } });
          }, true);
          asOk.classList.add('good'); asOk.classList.remove('primary');
          var other = btn(l.verification_status === 'disputed' ? 'Hide' : 'Different hours…', function(){ diff.hidden = !diff.hidden; other.textContent = diff.hidden ? 'Different hours…' : 'Hide'; if (!diff.hidden) input.focus(); });
          other.classList.add('link');
          work = el('div', {}, [asOk, other, diff]);
        } else work = el('span', { class: 'muted', text: 'view only' });
        acts = btn('History', function(){ historyDialog(l.request_employee_id, l.full_name); });
        return [tick, el('span', {}, [el('strong', { text: l.employee_code }), ' ' + l.full_name, el('div', { class: 'muted', style: 'font-size:12px', text: l.department_name + ' · ' + l.reason }), chip, gate]),
          day(l.work_date), mins(l.approved_minutes),
          el('div', {}, [work, l.verification_status === 'disputed' ? el('div', { class: 'err', style: 'font-size:12px', text: 'Disputed: ' + (l.verification_note || '') }) : null, out]), acts];
      }));
      show(nodes);
    });
  }
  var verifyDate = '';
  function nextDay(d){ var x = new Date(d + 'T00:00:00Z'); x.setUTCDate(x.getUTCDate() + 1); return x.toISOString().slice(0, 10); }

  // -------------------------------------------------------------- payroll
  var period = null;
  function drawPayroll(){
    if (!period) { var y = todayKhi(); y.setDate(y.getDate() - 1); var f = new Date(y); f.setDate(f.getDate() - 6); period = { from: iso(f), to: iso(y) }; }
    var from = el('input', { type: 'date', value: period.from, id: 'pay-from', 'aria-label': 'First work date' });
    var to = el('input', { type: 'date', value: period.to, id: 'pay-to', 'aria-label': 'Last work date' });
    Promise.all([call('GET', '/overtime/payroll', period), call('GET', '/overtime/payroll/runs')]).then(function(v){
      var r = v[0], runs = v[1];
      var nodes = [el('div', { class: 'bar' }, [el('span', { class: 'muted', text: 'Work dates' }), from, el('span', { class: 'muted', text: 'to' }), to,
        btn('Show', function(){ period = { from: from.value, to: to.value }; drawPayroll(); })])];
      if (!r.ok) { nodes.push(el('p', { class: 'err', text: r.error })); return show(nodes); }
      var WORDS = { ready: 'ready', finalized: 'finalized', rejected: 'rejected', undecided: 'not decided', unverified: 'not checked', disputed: 'disputed', no_rate: 'no rate' };
      nodes.push(el('p', { class: 'note', text: 'Type the rate for each checked line — hourly, or a fixed amount. The system never works a rate out. A period can be finalized once every line in it is decided, checked and rated; after that a line changes only by a correction, which is kept beside the finalized figure.' }));
      var lines = r.lines.filter(function(l){ return l.readiness !== 'rejected'; });
      if (!lines.length) nodes.push(el('p', { class: 'muted', id: 'pay-none', text: 'No approved overtime in these dates.' }));
      else nodes.push(table(['Employee', 'Work date', 'Approved', 'Actual', 'Rate', 'Amount', 'State'], lines, function(l){
        var rate;
        if (l.readiness === 'finalized') {
          rate = el('span', {}, [l.rate_type === 'none' ? '—' : l.rate_type + ' ' + l.manual_rate,
            CAN.pay ? el('div', {}, [btn('Correct…', function(){ correctDialog(l); })]) : null]);
        } else if (l.approval_status === 'approved' && l.verification_status === 'verified' && parseInt(l.actual_minutes, 10) > 0 && CAN.pay) {
          var type = el('select', { 'aria-label': 'Rate type for ' + l.full_name }, [el('option', { value: 'hourly', text: 'per hour' }), el('option', { value: 'fixed', text: 'fixed amount' })]);
          if (l.rate_type === 'fixed') type.value = 'fixed';
          var amt = el('input', { type: 'text', inputmode: 'decimal', value: l.manual_rate || '', placeholder: 'Rate', style: 'width:90px', 'aria-label': 'Rate for ' + l.full_name });
          var o = el('div', { class: 'err', style: 'font-size:12px' });
          rate = el('span', {}, [type, ' ', amt, ' ', btn('Save', function(){
            call('POST', '/overtime/payroll/rate', { request_employee_id: l.request_employee_id, rate_type: type.value, manual_rate: amt.value }).then(function(x){ if (!x.ok) { o.textContent = x.error; return; } drawPayroll(); });
          }), o]);
        } else rate = el('span', { class: 'muted', text: l.rate_type ? l.rate_type + ' ' + l.manual_rate : '—' });
        var amount = l.payable_amount ? (l.effective_amount && l.effective_amount !== l.payable_amount ? el('span', {}, [el('s', { class: 'muted', text: l.payable_amount }), ' ' + l.effective_amount]) : l.payable_amount) : '—';
        return [el('span', {}, [el('strong', { text: l.employee_code }), ' ' + l.full_name]), day(l.work_date), mins(l.approved_minutes), mins(l.actual_minutes), rate, amount, pill(l.readiness, WORDS[l.readiness])];
      }));
      var fin = btn('Finalize these dates…', function(){
        var note = el('input', { type: 'text', class: 'full', id: 'fin-note', maxlength: 500 });
        var runId = uuid();
        dialog({ title: 'Finalize ' + day(r.from) + ' to ' + day(r.to) + '?',
          text: r.ready + ' line' + (r.ready === 1 ? '' : 's') + ', total ' + r.total_ready + '. After this, these lines are locked: only a correction, with a reason, can change what is owed.',
          fields: [field('Note (optional)', note)], okText: 'Finalize ' + r.total_ready,
          submit: function(){
            return call('POST', '/overtime/payroll/finalize', { run_uuid: runId, from: r.from, to: r.to, expected_total: r.total_ready, note: note.value })
              .then(function(x){ if (x.ok) drawPayroll(); return x; });
          } });
      }, true, !CAN.pay || r.blocking > 0 || r.ready === 0);
      nodes.push(el('div', { class: 'bar', style: 'margin-top:12px' }, [el('span', {}, [el('span', { class: 'muted', text: 'Ready to finalize: ' }), el('span', { class: 'total', id: 'pay-total', text: r.total_ready })]),
        el('span', { class: r.blocking ? 'err' : 'muted', id: 'pay-blocking', text: r.blocking ? r.blocking + ' line' + (r.blocking === 1 ? '' : 's') + ' still need a decision, a check or a rate' : '' }), fin]));
      if (runs.ok && runs.runs.length) {
        nodes.push(el('h2', { style: 'font-size:15px;margin:20px 0 6px', text: 'Finalized runs' }));
        nodes.push(table(['Dates', 'Lines', 'Total as finalized', 'Corrections since', 'Finalized', ''], runs.runs, function(x){
          return [day(x.period_from) + ' – ' + day(x.period_to), String(x.line_count), x.total_amount,
            parseInt(x.corrections, 10) ? x.corrections + ' (' + (parseFloat(x.corrections_total) >= 0 ? '+' : '') + x.corrections_total + ')' : '—',
            (x.finalized_by_name || '') + ', ' + clock(x.finalized_at),
            el('a', { href: API + encodeURIComponent('/overtime/payroll/runs/' + x.id + '/export'), text: 'Download (CSV)' })];
        }));
      }
      show(nodes);
    });
  }
  function correctDialog(l){
    var m = hoursBox({ value: hval(l.payable_minutes || 0), id: 'cor-min' });
    var type = el('select', { id: 'cor-type' }, [el('option', { value: 'hourly', text: 'per hour' }), el('option', { value: 'fixed', text: 'fixed amount' }), el('option', { value: 'none', text: 'nothing owed' })]);
    if (l.rate_type) type.value = l.rate_type;
    var rate = el('input', { type: 'text', inputmode: 'decimal', value: l.manual_rate === '0.00' ? '' : (l.manual_rate || ''), id: 'cor-rate' });
    var why = el('textarea', { class: 'full', rows: 3, id: 'cor-reason' });
    dialog({ title: 'Correct finalized payroll — ' + l.full_name, text: 'The finalized figure stays on record. This adds what is owed now, with the difference and your reason.',
      fields: [el('div', { class: 'grid2' }, [field('Hours', m), field('Rate type', type), field('Rate', rate)]), field('Reason (required)', why)], okText: 'Save correction',
      submit: function(){ if (isNaN(toMin(m.value))) return Promise.resolve({ ok: false, error: BAD_HOURS });
        return call('POST', '/overtime/payroll/correct', { request_employee_id: l.request_employee_id, minutes: toMin(m.value), rate_type: type.value, manual_rate: rate.value, reason: why.value }).then(function(x){ if (x.ok) drawPayroll(); return x; }); } });
  }

  var first = (location.hash || '').slice(1);
  if (TABS.length) go(TABS.some(function(t){ return t.id === first; }) ? first : (CAN.decide ? 'req' : (CAN.verify ? 'verify' : (CAN.pay ? 'pay' : TABS[0].id))));
})();
</script>
<?php page_footer(); ?>
