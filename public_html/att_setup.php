<?php
/*
  ATTENDANCE — SETUP
  ==================

  The masters the attendance system runs on: departments, employees, shifts,
  who does which attendance job, and the handful of settings.

  Nothing can be scanned at the gate until this screen has been filled in, so
  it opens on a checklist that says, in order, what is still missing.

  THE PAGE DECIDES NOTHING. Every tab draws what /api/v1/admin/... returns and
  posts changes back to it; the permission and every rule are checked there
  (includes/att/AdminService.php). A button hidden here is a courtesy — the
  server refuses the request whether the button was shown or not.

  Two levels of access, on purpose:
    - Departments, employees and shifts: whoever holds "Attendance —
      Employees, Shifts, Devices" in User Access.
    - People (who is a gatekeeper / HOD / HR) and Settings: ERP admins only.
      Handing out access is not something a setup clerk does for themselves.

  Names and codes are written with textContent throughout.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
zu_require('attadm', 'v');

$me      = current_user();
$isAdmin = ($me['role'] ?? '') === 'admin';
$can     = [
    'c' => zu_can('attadm', 'c'),
    'u' => zu_can('attadm', 'u'),
    'd' => zu_can('attadm', 'd'),
    'admin' => $isAdmin,
];

page_header('Attendance — Setup');
?>
<style>
.atts .tabs{display:flex;flex-wrap:wrap;gap:4px;border-bottom:1px solid #dfe6ef;margin:0 0 14px}
.atts .tabs button{font:inherit;font-size:13.5px;font-weight:600;padding:9px 14px;border:0;border-bottom:3px solid transparent;background:none;color:#5a6b82;cursor:pointer}
.atts .tabs button[aria-selected=true]{color:#10243e;border-bottom-color:#10243e}
/* The ERP's stylesheet gives every table min-width:980px, for its wide
   ledgers. These lists are narrow, and on a phone that rule pushed the whole
   page sideways — so it is undone here, and a table that still does not fit
   scrolls inside its own box (.scroll), not the page. */
.atts table,#attsdlg table{min-width:0}
.atts table{width:100%;border-collapse:collapse;font-size:13.5px}
.atts th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:#5a6b82;padding:7px 8px;border-bottom:1px solid #dfe6ef;white-space:nowrap}
.atts td{padding:8px;border-bottom:1px solid #edf1f6;vertical-align:middle}
/* width:0 + min-width:100% — the box takes its parent's width but tells the
   parent nothing about how wide the table inside would like to be. Without
   it the ERP layout grows the card to fit the table and the page scrolls. */
.atts .scroll{overflow-x:auto;width:0;min-width:100%}
.atts .bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 12px}
.atts .bar .grow{flex:1 1 180px}
.atts .bar>select{width:auto;flex:0 1 auto}
.atts input,.atts select,.atts textarea,#attsdlg input,#attsdlg select,#attsdlg textarea{font:inherit;font-size:13.5px;padding:7px 9px;border:1px solid #cfd9e6;border-radius:8px;box-sizing:border-box;background:#fff;color:inherit;max-width:100%}
.atts button.act,#attsdlg button.act{font:inherit;font-size:12.5px;font-weight:600;padding:6px 10px;border-radius:7px;border:1px solid #cfd9e6;background:#fff;color:#10243e;cursor:pointer;white-space:nowrap}
.atts button.act.primary,#attsdlg button.act.primary{background:#10243e;color:#fff;border-color:#10243e}
.atts button.act:disabled,#attsdlg button.act:disabled{opacity:.5;cursor:default}
.atts .muted,#attsdlg .muted{color:#5a6b82}
.atts .err,#attsdlg .err{color:#a1241c;font-weight:600}
.atts .okmsg{color:#17683a;font-weight:600}
.atts .pill{display:inline-block;border-radius:99px;padding:2px 9px;font-size:12px;font-weight:600;background:#e8eef6;color:#33475f;margin:0 4px 4px 0;white-space:nowrap}
.atts .pill.good{background:#e2f3e8;color:#17683a}.atts .pill.bad{background:#fbe6e4;color:#a1241c}.atts .pill.off{background:#eef0f3;color:#6b7480}
.atts .pill button{font:inherit;border:0;background:none;color:inherit;cursor:pointer;padding:0 0 0 6px;font-weight:700}
.atts .check{list-style:none;margin:0;padding:0}
.atts .check li{display:flex;gap:12px;align-items:flex-start;padding:11px 0;border-bottom:1px solid #edf1f6}
.atts .check .mark{flex:0 0 26px;height:26px;border-radius:99px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;background:#fbe6e4;color:#a1241c}
.atts .check .mark.done{background:#e2f3e8;color:#17683a}
.atts .check b{display:block}.atts .check span.t{font-size:13px;color:#5a6b82}
.atts .note{font-size:13px;color:#5a6b82;margin:0 0 12px}
.atts .pager{display:flex;gap:10px;align-items:center;justify-content:flex-end;margin-top:10px;font-size:13px}
.atts .setting{padding:12px 0;border-bottom:1px solid #edf1f6;display:grid;grid-template-columns:minmax(0,1fr) 200px;gap:6px 16px;align-items:center}
.atts .setting small{grid-column:1/-1;color:#5a6b82;font-size:12.5px}
@media (max-width:640px){.atts .setting{grid-template-columns:1fr}}
#attsdlg{border:1px solid #cfd9e6;border-radius:12px;padding:18px;max-width:560px;width:94vw;max-height:92vh;overflow:auto}
#attsdlg.wide{max-width:860px}
#attsdlg::backdrop{background:rgba(16,36,62,.35)}
#attsdlg label{display:block;font-size:12.5px;font-weight:600;color:#33475f;margin:10px 0 4px}
#attsdlg label.inline{display:inline-flex;align-items:center;gap:6px;font-weight:500;margin:6px 12px 0 0}
#attsdlg .full{width:100%}
#attsdlg .row{display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap}
#attsdlg .two{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}
#attsdlg table{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:10px}
#attsdlg td,#attsdlg th{padding:5px 6px;border-bottom:1px solid #edf1f6;text-align:left;vertical-align:top}
#attsdlg details{margin-top:10px}#attsdlg summary{cursor:pointer;font-size:12.5px;color:#5a6b82}
</style>

<div class="topbar">
  <div>
    <h1>Attendance — Setup</h1>
    <p class="lead">Departments, employees, shifts and who does which attendance job. Nothing can be scanned at the gate until these are filled in.</p>
  </div>
</div>

<div class="card atts" id="atts">
  <div class="tabs" role="tablist" id="atts-tabs"></div>
  <div id="atts-body"><p class="muted">Loading…</p></div>
</div>

<dialog id="attsdlg">
  <form method="dialog" id="attsform" novalidate>
    <strong id="attsdlg-title"></strong>
    <p class="muted" id="attsdlg-text" style="font-size:13px;margin:6px 0 0"></p>
    <div id="attsdlg-fields"></div>
    <p class="err" id="attsdlg-err" role="alert"></p>
    <div class="row">
      <button type="button" class="act" id="attsdlg-cancel">Cancel</button>
      <button type="submit" class="act primary" id="attsdlg-ok">Save</button>
    </div>
  </form>
</dialog>

<script>
(function(){
  'use strict';
  var API = 'api/v1/index.php?route=';
  var CSRF = <?= json_encode(csrf_token()) ?>;
  var CAN = <?= json_encode($can) ?>;
  var body = document.getElementById('atts-body');
  var DAYS = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

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
    (kids || []).forEach(function(c){ if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }
  function call(method, route, data){
    var url = API + encodeURIComponent(route);
    var opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (method === 'GET') {
      Object.keys(data || {}).forEach(function(k){ if (data[k] !== '' && data[k] != null) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); });
    } else {
      opts.headers['Content-Type'] = 'application/json'; opts.headers['X-CSRF-Token'] = CSRF; opts.body = JSON.stringify(data || {});
    }
    return fetch(url, opts).then(function(r){
      return r.json().catch(function(){ return { ok: false, error: 'The server sent something unreadable.' }; });
    }).catch(function(){ return { ok: false, error: 'The server could not be reached.' }; });
  }
  function table(head, rows, draw){
    var tb = el('tbody');
    rows.forEach(function(r){ tb.appendChild(el('tr', {}, draw(r).map(function(c){ return el('td', {}, [c]); }))); });
    return el('div', { class: 'scroll' }, [el('table', {}, [el('thead', {}, [el('tr', {}, head.map(function(h){ return el('th', { text: h }); }))]), tb])]);
  }
  function btn(text, onClick, primary, disabled){
    return el('button', { class: 'act' + (primary ? ' primary' : ''), type: 'button', text: text, click: onClick, disabled: disabled });
  }
  function field(label, input){
    var id = 'f' + Math.random().toString(36).slice(2, 9);
    input.id = id;
    return el('div', {}, [el('label', { for: id, text: label }), input]);
  }
  function options(list, valueKey, textKey, selected, blank){
    var out = [];
    if (blank !== undefined) out.push(el('option', { value: '', text: blank }));
    list.forEach(function(o){
      var op = el('option', { value: o[valueKey], text: o[textKey] });
      if (String(o[valueKey]) === String(selected)) op.selected = true;
      out.push(op);
    });
    return out;
  }
  function hhmm(t){ return String(t || '').slice(0, 5); }
  function show(nodes){ body.textContent = ''; nodes.forEach(function(n){ if (n) body.appendChild(n); }); }
  function fail(res){ show([el('p', { class: 'err', text: res.error || 'That could not be loaded.' })]); }

  // -------------------------------------------------------------- dialog
  var dlg = document.getElementById('attsdlg'), dlgSubmit = null;
  document.getElementById('attsdlg-cancel').addEventListener('click', function(){ dlg.close(); });
  document.getElementById('attsform').addEventListener('submit', function(e){
    e.preventDefault();
    if (!dlgSubmit) return;
    var err = document.getElementById('attsdlg-err'), ok = document.getElementById('attsdlg-ok');
    err.textContent = '';
    var p = dlgSubmit();
    if (!p) return;
    ok.disabled = true;
    p.then(function(res){
      ok.disabled = false;
      if (res && res.keepOpen) return;
      if (!res || !res.ok) { err.textContent = (res && res.error) || 'That could not be saved.'; return; }
      dlg.close();
    });
  });
  function dialog(o){
    document.getElementById('attsdlg-title').textContent = o.title;
    document.getElementById('attsdlg-text').textContent = o.text || '';
    var f = document.getElementById('attsdlg-fields'); f.textContent = '';
    (o.fields || []).forEach(function(n){ if (n) f.appendChild(n); });
    document.getElementById('attsdlg-err').textContent = '';
    var ok = document.getElementById('attsdlg-ok');
    ok.textContent = o.okText || 'Save'; ok.disabled = false; ok.hidden = !!o.noOk;
    document.getElementById('attsdlg-cancel').textContent = o.noOk ? 'Close' : 'Cancel';
    dlg.className = o.wide ? 'wide' : '';
    dlgSubmit = o.submit || null;
    if (!dlg.open) dlg.showModal();
  }
  function dlgError(msg){ document.getElementById('attsdlg-err').textContent = msg; }

  // ---------------------------------------------------------------- tabs
  var TABS = [
    { id: 'start',  label: 'Start here',  draw: drawStart },
    { id: 'emp',    label: 'Employees',   draw: drawEmployees },
    { id: 'dept',   label: 'Departments', draw: drawDepartments },
    { id: 'shift',  label: 'Shifts',      draw: drawShifts },
    { id: 'people', label: 'People',      draw: drawPeople },
    { id: 'set',    label: 'Settings',    draw: drawSettings }
  ];
  var current = 'start';
  function go(id){
    current = id;
    Array.prototype.forEach.call(document.querySelectorAll('#atts-tabs button'), function(b){ b.setAttribute('aria-selected', b.dataset.tab === id ? 'true' : 'false'); });
    show([el('p', { class: 'muted', text: 'Loading…' })]);
    TABS.filter(function(t){ return t.id === id; })[0].draw();
    try { history.replaceState(null, '', '#' + id); } catch (e) {}
  }
  TABS.forEach(function(t){
    var b = el('button', { type: 'button', role: 'tab', text: t.label, click: function(){ go(t.id); } });
    b.dataset.tab = t.id;
    document.getElementById('atts-tabs').appendChild(b);
  });

  // ---------------------------------------------------------- start here
  function drawStart(){
    call('GET', '/admin/overview').then(function(o){
      if (!o.ok) return fail(o);
      function step(done, title, text, tab, action){
        return el('li', {}, [
          el('div', { class: 'mark' + (done ? ' done' : ''), text: done ? '✓' : '!' }),
          el('div', { style: 'flex:1' }, [el('b', { text: title }), el('span', { class: 't', text: text })]),
          tab ? btn(action, function(){ go(tab); }) : null
        ]);
      }
      var noHod = o.departments_without_hod || [];
      show([
        el('p', { class: 'note', text: 'Work down this list once. When every line shows a tick, the gate can start scanning.' }),
        el('ul', { class: 'check' }, [
          step(o.departments > 0, 'Departments', o.departments > 0 ? o.departments + ' in use.' : 'None yet. Each employee belongs to one, and each HOD confirms the arrivals of one.', 'dept', 'Departments'),
          step(o.shifts > 0, 'Shifts', o.shifts > 0 ? o.shifts + ' in use.' : 'None yet. Add the working hours of the factory.', 'shift', 'Shifts'),
          step(!!o.default_shift, 'Default shift', o.default_shift ? '"' + o.default_shift + '" is used for anyone who arrives without a shift for the day.' : 'Not chosen. Without it, an employee with no shift for the day cannot be filed and goes to HR.', CAN.admin ? 'set' : null, 'Settings'),
          step(o.employees > 0, 'Employees', o.employees > 0 ? o.employees + ' on the roll.' : 'Nobody yet. Add them one by one, paste a list from Excel, or bring in the production workers.', 'emp', 'Employees'),
          step(o.gatekeepers_ready > 0, 'A gatekeeper', o.gatekeepers_ready > 0 ? o.gatekeepers_ready + ' login' + (o.gatekeepers_ready === 1 ? '' : 's') + ' can scan at the gate.' : 'No login can scan at the gate yet.', CAN.admin ? 'people' : null, 'People'),
          step(o.employees > 0 && noHod.length === 0, 'A HOD for every department', o.employees === 0 ? 'Comes after the employees.' : (noHod.length === 0 ? 'Every department with employees has a HOD.' : 'No HOD yet for: ' + noHod.join(', ') + '. Arrivals there will wait unconfirmed.'), CAN.admin ? 'people' : null, 'People')
        ]),
        el('p', { class: 'note', style: 'margin-top:14px' }, [
          'Gatekeepers and HODs then open the ', el('a', { href: 'att/', text: 'phone app' }),
          ' on their phone, sign in once, and add it to the home screen.',
          CAN.admin ? '' : ' People and Settings are for an administrator.'
        ])
      ]);
    });
  }

  // ----------------------------------------------------------- employees
  var empFilter = { query: '', department_id: '', state: 'active', page: 1 }, deptCache = [];
  function loadDepts(){ return call('GET', '/admin/departments').then(function(d){ deptCache = d.ok ? d.departments : []; return deptCache; }); }
  function activeDepts(){ return deptCache.filter(function(d){ return parseInt(d.active, 10) === 1; }); }

  function drawEmployees(){
    loadDepts().then(function(){
      var q = el('input', { type: 'search', class: 'grow', placeholder: 'Search by code or name', value: empFilter.query, 'aria-label': 'Search employees' });
      var timer = null;
      q.addEventListener('input', function(){ clearTimeout(timer); timer = setTimeout(function(){ empFilter.query = q.value; empFilter.page = 1; list(); }, 300); });
      var d = el('select', { 'aria-label': 'Department', change: function(){ empFilter.department_id = d.value; empFilter.page = 1; list(); } }, options(deptCache, 'id', 'name', empFilter.department_id, 'All departments'));
      var st = el('select', { 'aria-label': 'Show', change: function(){ empFilter.state = st.value; empFilter.page = 1; list(); } },
        options([{ v: 'active', t: 'Active' }, { v: 'inactive', t: 'Inactive' }, { v: 'all', t: 'Everyone' }], 'v', 't', empFilter.state));
      var holder = el('div', {}, [el('p', { class: 'muted', text: 'Loading…' })]);
      show([
        el('div', { class: 'bar' }, [q, d, st,
          CAN.c ? btn('Add employee', function(){ editEmployee(null); }, true) : null,
          CAN.c ? btn('Add many…', function(){ importDialog(); }) : null]),
        holder
      ]);
      function list(){
        call('GET', '/admin/employees', empFilter).then(function(r){
          holder.textContent = '';
          if (!r.ok) { holder.appendChild(el('p', { class: 'err', text: r.error || 'Could not load.' })); return; }
          if (!r.employees.length) {
            holder.appendChild(el('p', { class: 'muted', text: r.total === 0 && !empFilter.query && !empFilter.department_id && empFilter.state === 'active'
              ? (activeDepts().length ? 'Nobody on the roll yet. Use "Add employee" or "Add many…".' : 'Add a department first (Departments tab), then the employees.')
              : 'Nobody matches.' }));
            return;
          }
          holder.appendChild(table(['Code', 'Name', 'Department', 'State', ''], r.employees, function(e){
            var active = parseInt(e.active, 10) === 1, inside = parseInt(e.inside, 10) === 1;
            var state = el('span', {}, [el('span', { class: 'pill ' + (active ? 'good' : 'off'), text: active ? 'Active' : 'Inactive' }), inside ? el('span', { class: 'pill', text: 'Inside now' }) : null]);
            var acts = el('span', {}, [
              CAN.u ? btn('Edit', function(){ editEmployee(e); }) : null, ' ',
              active ? (CAN.d ? btn('Make inactive', function(){ deactivate(e); }) : null)
                     : (CAN.u ? btn('Make active', function(){ call('POST', '/admin/employees/active', { id: e.id, active: true }).then(function(x){ if (!x.ok) alert(x.error); list(); }); }) : null)
            ]);
            return [el('strong', { text: e.code }), e.full_name, e.department_name, state, acts];
          }));
          var from = (r.page - 1) * r.per_page + 1, to = Math.min(r.total, r.page * r.per_page);
          holder.appendChild(el('div', { class: 'pager' }, [
            el('span', { class: 'muted', text: from + '–' + to + ' of ' + r.total }),
            btn('Previous', function(){ empFilter.page--; list(); }, false, r.page <= 1),
            btn('Next', function(){ empFilter.page++; list(); }, false, to >= r.total)
          ]));
        });
      }
      drawEmployees.refresh = list;
      list();
    });
  }

  function editEmployee(e){
    var code = el('input', { type: 'text', class: 'full', maxlength: 40, value: e ? e.code : '', autocomplete: 'off' });
    var name = el('input', { type: 'text', class: 'full', maxlength: 160, value: e ? e.full_name : '', autocomplete: 'off' });
    var depts = activeDepts();
    if (e && !depts.some(function(d){ return String(d.id) === String(e.department_id); })) depts = depts.concat([{ id: e.department_id, name: e.department_name + ' (switched off)' }]);
    var dept = el('select', { class: 'full' }, options(depts, 'id', 'name', e ? e.department_id : '', 'Choose…'));
    var worker = el('input', { type: 'text', class: 'full', maxlength: 40, value: e && e.worker_code ? e.worker_code : '', autocomplete: 'off' });
    var qr = el('input', { type: 'text', class: 'full', maxlength: 190, value: e && e.qr_payload ? e.qr_payload : '', autocomplete: 'off' });
    dialog({
      title: e ? 'Edit ' + e.full_name : 'Add employee',
      text: 'The code is what the gatekeeper types or scans. It cannot be changed once the employee has attendance recorded.',
      fields: [
        el('div', { class: 'two' }, [field('Employee code', code), field('Department', dept)]),
        field('Full name', name),
        el('details', {}, [el('summary', { text: 'More (optional)' }),
          field('Production worker code — links this person to the production module', worker),
          field('Card text — only if their card scans as something other than the code', qr)])
      ],
      submit: function(){
        return call('POST', '/admin/employees', { id: e ? e.id : null, code: code.value, full_name: name.value, department_id: parseInt(dept.value, 10) || 0, worker_code: worker.value, qr_payload: qr.value })
          .then(function(r){ if (r.ok && drawEmployees.refresh) drawEmployees.refresh(); return r; });
      }
    });
    code.focus();
  }

  function deactivate(e){
    var reason = el('textarea', { class: 'full', rows: 3 });
    dialog({
      title: 'Make ' + e.full_name + ' inactive?',
      text: 'They can no longer be scanned at the gate. Their past attendance is kept, and they can be made active again.',
      fields: [field('Reason (kept on the record)', reason)], okText: 'Make inactive',
      submit: function(){
        return call('POST', '/admin/employees/active', { id: e.id, active: false, reason: reason.value })
          .then(function(r){ if (r.ok && drawEmployees.refresh) drawEmployees.refresh(); return r; });
      }
    });
  }

  /* Paste from a spreadsheet: Code, Name, Department and optionally the
     production worker code. Excel pastes tab-separated; a typed list is
     usually commas. */
  function parseRows(text){
    var rows = [];
    text.split(/\r?\n/).forEach(function(line){
      if (!line.trim()) return;
      var cells = (line.indexOf('\t') >= 0 ? line.split('\t') : line.split(',')).map(function(c){ return c.trim(); });
      if (!rows.length && /^(code|emp|employee)/i.test(cells[0] || '') && /name/i.test(cells[1] || '')) return; // a header row
      rows.push({ code: cells[0] || '', full_name: cells[1] || '', department: cells[2] || '', worker_code: cells[3] || '' });
    });
    return rows;
  }
  function importDialog(prefill){
    var box = el('textarea', { class: 'full', rows: 8, placeholder: 'E001\tImran Ali\tStitching\nE002\tSara Khan\tPacking', spellcheck: 'false' });
    var mk = el('input', { type: 'checkbox', checked: true });
    var result = el('div');
    var rows = prefill || null, checked = false;
    var workersBtn = el('span');
    if (!prefill) call('GET', '/admin/workers').then(function(w){
      if (w.ok && w.available && w.workers.length) workersBtn.appendChild(btn('Bring in ' + w.workers.length + ' production worker' + (w.workers.length === 1 ? '' : 's') + ' not on the roll', function(){
        rows = w.workers; box.value = w.workers.map(function(x){ return [x.code, x.full_name, x.department, x.worker_code].join('\t'); }).join('\n'); check();
      }));
    });
    function reset(){ checked = false; document.getElementById('attsdlg-ok').textContent = 'Check the list'; }
    box.addEventListener('input', function(){ rows = null; reset(); result.textContent = ''; });
    mk.addEventListener('change', function(){ reset(); result.textContent = ''; });
    function send(commit){
      var list = rows || parseRows(box.value);
      if (!list.length) { dlgError('Paste the list first.'); return Promise.resolve({ keepOpen: true }); }
      return call('POST', '/admin/employees/import', { rows: list, commit: commit, create_departments: mk.checked }).then(function(r){
        if (!r.ok) return r;
        result.textContent = '';
        var c = r.counts;
        if (r.committed) {
          result.appendChild(el('p', { class: 'okmsg', text: c.add + ' employee' + (c.add === 1 ? '' : 's') + ' added.' + (c.exists ? ' ' + c.exists + ' were already on the roll.' : '') + (c.error ? ' ' + c.error + ' line' + (c.error === 1 ? ' was' : 's were') + ' not added — see below.' : '') }));
          document.getElementById('attsdlg-ok').hidden = true;
          document.getElementById('attsdlg-cancel').textContent = 'Close';
          if (drawEmployees.refresh) { loadDepts().then(drawEmployees.refresh); }
        } else {
          result.appendChild(el('p', { text: 'Will add ' + c.add + '. Already on the roll: ' + c.exists + '. Cannot be added: ' + c.error + '.' }));
          checked = c.add > 0;
          document.getElementById('attsdlg-ok').textContent = checked ? 'Add ' + c.add + ' employee' + (c.add === 1 ? '' : 's') : 'Check the list';
        }
        var notable = r.lines.filter(function(l){ return l.status !== 'add' || l.message; });
        if (notable.length) {
          var t = el('table', {}, [el('thead', {}, [el('tr', {}, ['Line', 'Code', 'Name', 'What happens'].map(function(h){ return el('th', { text: h }); }))])]);
          var tb = el('tbody');
          notable.slice(0, 300).forEach(function(l){
            tb.appendChild(el('tr', {}, [el('td', { text: String(l.line) }), el('td', { text: l.code }), el('td', { text: l.full_name }),
              el('td', { class: l.status === 'error' ? 'err' : 'muted', text: (l.status === 'error' ? 'Not added: ' : '') + l.message })]));
          });
          t.appendChild(tb); result.appendChild(t);
          if (notable.length > 300) result.appendChild(el('p', { class: 'muted', text: 'Showing the first 300 of ' + notable.length + ' lines with a note.' }));
        }
        return { keepOpen: true };
      });
    }
    function check(){ reset(); send(false).then(function(r){ if (r && !r.keepOpen && !r.ok) dlgError(r.error || 'The list could not be checked.'); }); }
    dialog({
      title: 'Add many employees', wide: true, okText: 'Check the list',
      text: 'Copy three columns from Excel — Code, Name, Department — and paste them here, one employee per line. Nothing is saved until you have seen the check.',
      fields: [box, el('label', { class: 'inline' }, [mk, 'Create departments that do not exist yet']), el('div', { style: 'margin-top:8px' }, [workersBtn]), result],
      submit: function(){ return send(checked); }
    });
  }

  // --------------------------------------------------------- departments
  function drawDepartments(){
    loadDepts().then(function(list){
      var name = el('input', { type: 'text', class: 'grow', maxlength: 100, placeholder: 'New department name', 'aria-label': 'New department name' });
      var msg = el('span', { class: 'err' });
      function add(){
        call('POST', '/admin/departments', { name: name.value }).then(function(r){ if (!r.ok) { msg.textContent = r.error; return; } drawDepartments(); });
      }
      name.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); add(); } });
      show([
        CAN.c ? el('div', { class: 'bar' }, [name, btn('Add department', add, true),
          btn('Copy from Inventory departments', function(){
            call('POST', '/admin/departments/seed', {}).then(function(r){
              if (!r.ok) { msg.textContent = r.error; return; }
              if (r.added === 0) { msg.className = 'muted'; msg.textContent = 'Nothing new to copy.'; return; }
              drawDepartments();
            });
          }), msg]) : null,
        list.length ? table(['Department', 'Active employees', 'State', ''], list, function(d){
          var on = parseInt(d.active, 10) === 1;
          return [el('strong', { text: d.name }), String(d.employees), el('span', { class: 'pill ' + (on ? 'good' : 'off'), text: on ? 'In use' : 'Switched off' }),
            CAN.u ? el('span', {}, [
              btn('Rename', function(){
                var n = el('input', { type: 'text', class: 'full', maxlength: 100, value: d.name });
                dialog({ title: 'Rename department', fields: [field('Name', n)], submit: function(){
                  return call('POST', '/admin/departments', { id: d.id, name: n.value }).then(function(r){ if (r.ok) drawDepartments(); return r; });
                } });
              }), ' ',
              btn(on ? 'Switch off' : 'Switch on', function(){
                call('POST', '/admin/departments', { id: d.id, name: d.name, active: !on }).then(function(r){ if (!r.ok) { msg.className = 'err'; msg.textContent = r.error; return; } drawDepartments(); });
              })
            ]) : el('span', {})];
        }) : el('p', { class: 'muted', text: 'No departments yet.' })
      ]);
    });
  }

  // --------------------------------------------------------------- shifts
  function drawShifts(){
    Promise.all([call('GET', '/admin/shifts'), loadDepts()]).then(function(res){
      var r = res[0];
      if (!r.ok) return fail(r);
      var live = r.shifts.filter(function(s){ return parseInt(s.active, 10) === 1; });
      show([
        el('p', { class: 'note', text: 'An employee who has not been given a shift for the day uses the default shift. Give shifts by department only where people work different hours from the default.' }),
        el('div', { class: 'bar' }, [
          CAN.c ? btn('Add shift', function(){ editShift(null); }, true) : null,
          CAN.c && live.length ? btn('Give a shift to a department…', function(){ assignDialog(live); }) : null
        ]),
        r.shifts.length ? table(['Shift', 'Hours', 'Break', 'Early / late allowed', 'Days given ahead', 'State', ''], r.shifts, function(s){
          var on = parseInt(s.active, 10) === 1, night = parseInt(s.crosses_midnight, 10) === 1;
          return [
            el('span', {}, [el('strong', { text: s.name }), s.is_default ? el('span', { class: 'pill', style: 'margin-left:8px', text: 'Default' }) : null]),
            hhmm(s.start_time) + ' – ' + hhmm(s.end_time) + (night ? ' (ends next day)' : ''),
            s.break_minutes + ' min', s.grace_in_minutes + ' / ' + s.grace_out_minutes + ' min', String(s.days_ahead),
            el('span', { class: 'pill ' + (on ? 'good' : 'off'), text: on ? 'In use' : 'Switched off' }),
            CAN.u ? btn('Edit', function(){ editShift(s); }) : el('span', {})
          ];
        }) : el('p', { class: 'muted', text: 'No shifts yet. Add the factory\'s working hours.' })
      ]);
    });
  }
  function editShift(s){
    var name = el('input', { type: 'text', class: 'full', maxlength: 100, value: s ? s.name : '' });
    var st = el('input', { type: 'time', class: 'full', value: s ? hhmm(s.start_time) : '08:00' });
    var en = el('input', { type: 'time', class: 'full', value: s ? hhmm(s.end_time) : '17:00' });
    var br = el('input', { type: 'number', class: 'full', min: 0, max: 600, value: s ? s.break_minutes : 60 });
    var gi = el('input', { type: 'number', class: 'full', min: 0, max: 240, value: s ? s.grace_in_minutes : 30 });
    var go_ = el('input', { type: 'number', class: 'full', min: 0, max: 240, value: s ? s.grace_out_minutes : 30 });
    var on = el('input', { type: 'checkbox', checked: s ? parseInt(s.active, 10) === 1 : true });
    dialog({
      title: s ? 'Edit shift' : 'Add shift',
      text: 'If the end is earlier than the start, the shift runs past midnight and belongs to the day it starts on. Changing the hours moves days that have not started yet; today and the past keep the hours they had.',
      fields: [
        field('Name', name),
        el('div', { class: 'two' }, [field('Starts', st), field('Ends', en)]),
        field('Break (minutes)', br),
        el('div', { class: 'two' }, [field('May arrive this early (minutes)', gi), field('May leave this late (minutes)', go_)]),
        s ? el('label', { class: 'inline' }, [on, 'In use']) : null
      ],
      submit: function(){
        var data = { id: s ? s.id : null, name: name.value, start_time: st.value, end_time: en.value, break_minutes: br.value, grace_in_minutes: gi.value, grace_out_minutes: go_.value };
        if (s) data.active = on.checked;
        return call('POST', '/admin/shifts', data).then(function(r){ if (r.ok) drawShifts(); return r; });
      }
    });
    name.focus();
  }
  function assignDialog(shifts){
    var today = new Date(), iso = function(d){ return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); };
    var end = new Date(today.getTime() + 29 * 86400000);
    var sh = el('select', { class: 'full' }, options(shifts, 'id', 'name', '', 'Choose…'));
    var dp = el('select', { class: 'full' }, options(activeDepts(), 'id', 'name', '', 'Choose…'));
    var from = el('input', { type: 'date', class: 'full', value: iso(today) });
    var to = el('input', { type: 'date', class: 'full', value: iso(end) });
    var boxes = DAYS.map(function(d, i){ var c = el('input', { type: 'checkbox', checked: true, value: i + 1 }); return { c: c, node: el('label', { class: 'inline' }, [c, d]) }; });
    var out = el('p', { class: 'okmsg' });
    dialog({
      title: 'Give a shift to a department', okText: 'Give shift',
      text: 'Every active employee in the department gets this shift on the chosen days. A day that already has entries recorded keeps the shift it had.',
      fields: [el('div', { class: 'two' }, [field('Shift', sh), field('Department', dp)]),
        el('div', { class: 'two' }, [field('First day', from), field('Last day', to)]),
        el('div', {}, [el('label', { text: 'On these days of the week' })].concat(boxes.map(function(b){ return b.node; }))), out],
      submit: function(){
        var days = boxes.filter(function(b){ return b.c.checked; }).map(function(b){ return parseInt(b.c.value, 10); });
        if (!days.length) { dlgError('Tick at least one day of the week.'); return null; }
        return call('POST', '/admin/shifts/assign', { shift_id: parseInt(sh.value, 10) || 0, department_id: parseInt(dp.value, 10) || 0, from: from.value, to: to.value, weekdays: days.length === 7 ? [] : days })
          .then(function(r){
            if (!r.ok) return r;
            out.textContent = 'Done: ' + r.employees + ' employee' + (r.employees === 1 ? '' : 's') + ' × ' + r.days + ' day' + (r.days === 1 ? '' : 's') + '.'
              + (r.kept_with_entries ? ' ' + r.kept_with_entries + ' day' + (r.kept_with_entries === 1 ? '' : 's') + ' already had entries and kept their shift.' : '');
            document.getElementById('attsdlg-ok').hidden = true;
            document.getElementById('attsdlg-cancel').textContent = 'Close';
            drawShifts();
            return { keepOpen: true };
          });
      }
    });
  }

  // --------------------------------------------------------------- people
  function drawPeople(){
    if (!CAN.admin) return show([el('p', { class: 'muted', text: 'Only an administrator can decide who does which attendance job.' })]);
    Promise.all([call('GET', '/admin/users'), loadDepts()]).then(function(res){
      var r = res[0];
      if (!r.ok) return fail(r);
      var msg = el('p', { class: 'err' });
      function act(route, data){
        call('POST', route, data).then(function(x){
          if (!x.ok) { msg.textContent = x.error; return; }
          drawPeople.note = x.note || '';
          drawPeople();
        });
      }
      if (drawPeople.note) { msg.textContent = drawPeople.note; drawPeople.note = ''; }
      show([
        el('p', { class: 'note', text: 'Give each login its attendance job here. The matching permission in User Access is ticked and un-ticked for you. A HOD is the HOD of one department; give the job again for a second department.' }),
        msg,
        table(['Login', 'Attendance jobs', 'Give a job'], r.users, function(u){
          var jobs = el('span', {}, u.jobs.length ? u.jobs.map(function(j){
            var text = j.label + (j.department_name ? ' — ' + j.department_name : '');
            return el('span', { class: 'pill ' + (j.working ? 'good' : 'bad'), title: j.working ? '' : 'Not working yet: "' + j.missing + '" is not ticked for this person in User Access.' }, [
              text + (j.working ? '' : ' (not working)'),
              el('button', { type: 'button', 'aria-label': 'Remove ' + text + ' from ' + u.name, text: '×', click: function(){
                if (confirm('Remove "' + text + '" from ' + u.name + '?')) act('/admin/jobs/revoke', { user_id: u.id, role: j.role, department_id: j.department_id || 0 });
              } })
            ]);
          }) : [el('span', { class: 'muted', text: 'None' })]);
          var job = el('select', { 'aria-label': 'Job for ' + u.name }, options(r.jobs, 'role', 'label', '', 'Job…'));
          var dept = el('select', { 'aria-label': 'Department for ' + u.name, hidden: 'hidden' }, options(activeDepts(), 'id', 'name', '', 'Department…'));
          job.addEventListener('change', function(){ var j = r.jobs.filter(function(x){ return x.role === job.value; })[0]; dept.hidden = !(j && j.needs_department); });
          var give = btn('Give', function(){
            if (!job.value) { msg.textContent = 'Choose the job first.'; return; }
            act('/admin/jobs/grant', { user_id: u.id, role: job.value, department_id: parseInt(dept.value, 10) || 0 });
          });
          return [el('span', {}, [el('strong', { text: u.name }), el('span', { class: 'muted', text: '  ' + u.email + (u.is_admin ? ' · Administrator' : '') })]), jobs, el('span', { class: 'bar', style: 'margin:0' }, [job, dept, give])];
        })
      ]);
    });
  }

  // ------------------------------------------------------------- settings
  function drawSettings(){
    call('GET', '/admin/settings').then(function(r){
      if (!r.ok) return fail(r);
      var inputs = {}, msg = el('span', {});
      var rows = r.settings.map(function(s){
        var input;
        if (s.type === 'bool') input = el('select', { disabled: !CAN.admin }, options([{ v: '1', t: 'Yes' }, { v: '0', t: 'No' }], 'v', 't', s.value ? '1' : '0'));
        else if (s.type === 'shift') input = el('select', { disabled: !CAN.admin }, options(r.shifts, 'id', 'name', s.value || '', 'Not chosen'));
        else input = el('input', { type: 'number', min: s.min, max: s.max, value: s.value, disabled: !CAN.admin });
        input.style.width = '100%';
        inputs[s.key] = { input: input, type: s.type };
        var w = field(s.label, input);
        var lab = w.firstChild; w.removeChild(lab);
        lab.style.fontWeight = '600';
        return el('div', { class: 'setting' }, [lab, input, el('small', { text: s.help + (s.type === 'int' ? ' (' + s.min + '–' + s.max + ')' : '') })]);
      });
      r.fixed.forEach(function(f){
        rows.push(el('div', { class: 'setting' }, [el('span', { style: 'font-weight:600', text: f.label }), el('span', { text: f.value }), el('small', { text: f.help })]));
      });
      show(rows.concat([el('div', { class: 'bar', style: 'margin-top:14px' }, [
        CAN.admin ? btn('Save settings', function(){
          var values = {};
          Object.keys(inputs).forEach(function(k){ var i = inputs[k]; values[k] = i.type === 'bool' ? i.input.value === '1' : (i.type === 'shift' ? (parseInt(i.input.value, 10) || null) : i.input.value); });
          call('POST', '/admin/settings', { values: values }).then(function(x){
            msg.className = x.ok ? 'okmsg' : 'err';
            msg.textContent = x.ok ? (x.changed ? 'Saved.' : 'Nothing was changed.') : x.error;
          });
        }, true) : el('span', { class: 'muted', text: 'Only an administrator can change these.' }),
        msg
      ])]));
    });
  }

  var first = (location.hash || '').slice(1);
  go(TABS.some(function(t){ return t.id === first; }) ? first : 'start');
})();
</script>
<?php page_footer(); ?>
