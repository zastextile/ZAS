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
.atts .check .mark.opt{background:#eef0f3;color:#6b7480}
.atts .steps{list-style:none;margin:8px 0;padding:0;font-size:13.5px}.atts .steps li{padding:3px 0}
.atts .facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin:0 0 14px}
.atts .fact{border:1px solid #dfe6ef;border-radius:10px;padding:10px 12px}.atts .fact b{display:block;font-size:18px}.atts .fact span{font-size:12.5px;color:#5a6b82}
#attsdlg .portrait{width:144px;height:180px;border-radius:10px;background:#e9eef5;border:1px solid #cfd9e6;object-fit:cover;display:block}
#attsdlg .portrait.none{display:flex;align-items:center;justify-content:center;color:#5a6b82;font-size:12.5px;border-style:dashed;text-align:center;padding:8px;box-sizing:border-box}
#attsdlg .pair{display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap;margin-top:10px}
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
    // An input that was given an id keeps it; the others get one so the
    // label can point at them.
    if (!input.id) input.id = 'f' + Math.random().toString(36).slice(2, 9);
    return el('div', {}, [el('label', { for: input.id, text: label }), input]);
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
    { id: 'photo',  label: 'Photos',      draw: drawPhotos },
    { id: 'phones', label: 'Phones',      draw: drawPhones },
    { id: 'people', label: 'People',      draw: drawPeople },
    { id: 'set',    label: 'Settings',    draw: drawSettings },
    { id: 'sys',    label: 'System',      draw: drawSystem }
  ];
  if (CAN.admin) TABS.splice(7, 0, { id: 'entries', label: 'Entries', draw: drawEntries });
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
      function step(done, title, text, tab, action, optional){
        return el('li', {}, [
          el('div', { class: 'mark' + (done ? ' done' : (optional ? ' opt' : '')), text: done ? '✓' : (optional ? '–' : '!') }),
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
          step(o.employees > 0 && noHod.length === 0, 'A HOD for every department', o.employees === 0 ? 'Comes after the employees.' : (noHod.length === 0 ? 'Every department with employees has a HOD.' : 'No HOD yet for: ' + noHod.join(', ') + '. Arrivals there will wait unconfirmed.'), CAN.admin ? 'people' : null, 'People'),
          step(o.employees > 0 && o.employees_with_photo >= o.employees, 'Employee photos (not needed to start)',
            o.employees_with_photo + ' of ' + o.employees + ' employees have a photo. The gatekeeper and the HOD see it when the code is typed or scanned; without one they see the name only.', 'photo', 'Photos', true)
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
          holder.appendChild(table(['Code', 'Name', 'Department', 'Photo', 'State', ''], r.employees, function(e){
            var hasPhoto = parseInt(e.has_photo, 10) === 1;
            var active = parseInt(e.active, 10) === 1, inside = parseInt(e.inside, 10) === 1;
            var state = el('span', {}, [el('span', { class: 'pill ' + (active ? 'good' : 'off'), text: active ? 'Active' : 'Inactive' }), inside ? el('span', { class: 'pill', text: 'Inside now' }) : null]);
            var acts = el('span', {}, [
              CAN.u ? btn('Edit', function(){ editEmployee(e); }) : null, ' ',
              CAN.u ? btn('Photo…', function(){ photoDialog(e); }) : null, ' ',
              active ? (CAN.d ? btn('Make inactive', function(){ deactivate(e); }) : null)
                     : (CAN.u ? btn('Make active', function(){ call('POST', '/admin/employees/active', { id: e.id, active: true }).then(function(x){ if (!x.ok) alert(x.error); list(); }); }) : null)
            ]);
            return [el('strong', { text: e.code }), e.full_name, e.department_name,
              el('span', { class: 'pill ' + (hasPhoto ? 'good' : 'off'), text: hasPhoto ? 'On file' : 'None' }), state, acts];
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
    var code = el('input', { type: 'text', class: 'full', maxlength: 40, value: e ? e.code : '', autocomplete: 'off', id: 'emp-code' });
    var name = el('input', { type: 'text', class: 'full', maxlength: 160, value: e ? e.full_name : '', autocomplete: 'off', id: 'emp-name' });
    var depts = activeDepts();
    if (e && !depts.some(function(d){ return String(d.id) === String(e.department_id); })) depts = depts.concat([{ id: e.department_id, name: e.department_name + ' (switched off)' }]);
    var dept = el('select', { class: 'full', id: 'emp-dept' }, options(depts, 'id', 'name', e ? e.department_id : '', 'Choose…'));
    var worker = el('input', { type: 'text', class: 'full', maxlength: 40, value: e && e.worker_code ? e.worker_code : '', autocomplete: 'off' });
    var qr = el('input', { type: 'text', class: 'full', maxlength: 190, value: e && e.qr_payload ? e.qr_payload : '', autocomplete: 'off' });

    /* The photo, in the same dialog. Saving it needs "update" as well as
       "create", so a clerk who may only add people does not see the box. */
    var savedId = e ? e.id : null;                  // set once the employee row exists, so a retry never adds them twice
    var told = !!(e && parseInt(e.notice_given, 10) === 1);
    var has = !!(e && parseInt(e.has_photo, 10) === 1);
    var file = el('input', { type: 'file', id: 'emp-photo', accept: 'image/jpeg,image/png,image/webp' });
    var notice = el('input', { type: 'checkbox', id: 'emp-notice' });
    var preview = has ? el('img', { class: 'portrait', alt: 'Photo on file', src: photoSrc(e.id) }) : el('div', { class: 'portrait none', text: 'No photo yet' });
    var holder = el('div', {}, [preview]);
    file.addEventListener('change', function(){
      var f = file.files && file.files[0];
      if (!f) return;
      var img = el('img', { class: 'portrait', alt: 'The picture chosen' });
      img.src = URL.createObjectURL(f);
      img.onload = function(){ URL.revokeObjectURL(img.src); };
      holder.textContent = ''; holder.appendChild(img);
    });
    var photoBlock = CAN.u ? el('div', { class: 'pair' }, [holder, el('div', { style: 'flex:1;min-width:200px' }, [
      field(has ? 'Replace the photo (optional)' : 'Photo (optional) — JPG, PNG or WebP, up to 10 MB', file),
      el('p', { class: 'muted', style: 'font-size:12.5px;margin:6px 0 0', text: 'Shown to the gatekeeper and HOD when this code is typed or scanned. Cropped to an upright portrait and stored small; the original is not kept.' }),
      told ? null : el('label', { class: 'inline' }, [notice, 'This employee has been told their photo will be kept for attendance'])
    ])]) : null;

    dialog({
      title: e ? 'Edit ' + e.full_name : 'Add employee',
      text: 'The code is what the gatekeeper types or scans. It cannot be changed once the employee has attendance recorded.',
      fields: [
        el('div', { class: 'two' }, [field('Employee code', code), field('Department', dept)]),
        field('Full name', name),
        photoBlock,
        el('details', {}, [el('summary', { text: 'More (optional)' }),
          field('Production worker code — links this person to the production module', worker),
          field('Card text — only if their card scans as something other than the code', qr)])
      ],
      submit: function(){
        var pic = CAN.u && file.files && file.files[0] ? file.files[0] : null;
        if (pic && !told && !notice.checked) { dlgError('Tick that the employee has been told their photo will be kept, or remove the photo.'); return null; }
        return call('POST', '/admin/employees', { id: savedId, code: code.value, full_name: name.value, department_id: parseInt(dept.value, 10) || 0, worker_code: worker.value, qr_payload: qr.value })
          .then(function(r){
            if (!r.ok) return r;
            savedId = r.employee_id;
            if (drawEmployees.refresh) drawEmployees.refresh();
            if (!pic) return r;
            return shrink(pic).then(function(small){
              return sendFile('/admin/employees/photo', { id: savedId, notice_given: (told || notice.checked) ? '1' : '0' }, small);
            }).then(function(p){
              if (drawEmployees.refresh) drawEmployees.refresh();
              if (p.ok) return r;
              // The person is saved; only the picture is not. Say exactly that,
              // and leave the dialog open so another picture can be chosen.
              document.getElementById('attsdlg-title').textContent = 'Edit ' + name.value;
              dlgError('The employee is saved, but the photo was not: ' + (p.error || 'unknown reason') + ' Choose another picture and save again, or close.');
              return { keepOpen: true };
            });
          });
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

  // --------------------------------------------------------------- photos
  /* Multipart, for a picture. No Content-Type by hand; the server decides
     what the file is by reading it. */
  function sendFile(route, fields, file){
    var form = new FormData();
    Object.keys(fields).forEach(function(k){ form.append(k, fields[k]); });
    form.append('photo', file, 'photo');
    return fetch(API + encodeURIComponent(route), { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF }, body: form })
      .then(function(r){ return r.json().catch(function(){ return { ok: false, error: r.status === 413 ? 'That picture is too large to upload.' : 'The server sent something unreadable.' }; }); })
      .catch(function(){ return { ok: false, error: 'The server could not be reached.' }; });
  }
  /* Make a big camera file small in the browser before it travels: servers
     commonly refuse an upload over 2 MB long before this app sees it. The
     server crops and re-makes the picture again whatever arrives; anything
     this cannot read is sent as it is, for the server to refuse by name. */
  function shrink(file){
    return new Promise(function(resolve){
      if (!window.createImageBitmap) return resolve(file);
      createImageBitmap(file, { imageOrientation: 'from-image' }).then(function(bmp){
        var scale = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
        if (scale === 1 && file.size < 1500000) return resolve(file);
        var c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(bmp.width * scale)); c.height = Math.max(1, Math.round(bmp.height * scale));
        c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
        c.toBlob(function(b){ resolve(b && b.size < file.size ? b : file); }, 'image/jpeg', 0.88);
      }).catch(function(){ resolve(file); });
    });
  }
  function photoSrc(id){ return API + encodeURIComponent('/admin/employees/photo') + '&id=' + encodeURIComponent(id) + '&t=' + Date.now(); }

  function photoDialog(e){
    var has = parseInt(e.has_photo, 10) === 1, told = parseInt(e.notice_given, 10) === 1;
    var current = has ? el('img', { class: 'portrait', id: 'ph-current', alt: 'Photo on file for ' + e.full_name, src: photoSrc(e.id) })
                      : el('div', { class: 'portrait none', id: 'ph-current', text: 'No photo on file' });
    var file = el('input', { type: 'file', id: 'ph-file', accept: 'image/jpeg,image/png,image/webp' });
    var notice = el('input', { type: 'checkbox', id: 'ph-notice' });
    var done = el('p', { class: 'okmsg', id: 'ph-done' });
    var holder = el('div', {}, [current]);
    dialog({
      title: 'Photo — ' + e.full_name + ' (' + e.code + ')', okText: 'Save photo',
      text: 'Shown to the gatekeeper and the HOD when this code is typed or scanned. Choose a clear, front-facing picture.',
      fields: [
        el('div', { class: 'pair' }, [holder, el('div', { style: 'flex:1;min-width:200px' }, [
          field('New picture (JPG, PNG or WebP, up to 10 MB)', file),
          el('p', { class: 'muted', style: 'font-size:12.5px;margin:8px 0 0', text: 'Whatever is chosen is cropped to an upright 4:5 portrait and stored small (480 × 600, WebP). The original file is not kept.' }),
          told ? el('p', { class: 'muted', style: 'font-size:12.5px', text: 'This employee has already been told their photo is kept.' })
               : el('label', { class: 'inline' }, [notice, 'This employee has been told their photo will be kept for attendance']),
          (has && CAN.u) ? el('div', { style: 'margin-top:10px' }, [btn('Remove photo…', function(){
            var why = prompt('Why is the photo being removed? (kept on the record)');
            if (why === null) return;
            call('POST', '/admin/employees/photo/remove', { id: e.id, reason: why }).then(function(r){
              if (!r.ok) { dlgError(r.error); return; }
              dlg.close(); if (drawEmployees.refresh) drawEmployees.refresh();
            });
          })]) : null
        ])]), done
      ],
      submit: function(){
        if (!file.files || !file.files[0]) { dlgError('Choose a picture first.'); return null; }
        return shrink(file.files[0]).then(function(small){
          return sendFile('/admin/employees/photo', { id: e.id, notice_given: (told || notice.checked) ? '1' : '0' }, small);
        }).then(function(r){
          if (!r.ok) return r;
          holder.textContent = '';
          holder.appendChild(el('img', { class: 'portrait', id: 'ph-current', alt: 'Photo on file for ' + e.full_name, src: photoSrc(e.id) }));
          done.textContent = 'Saved as ' + r.width + ' × ' + r.height + ', ' + Math.max(1, Math.round(r.bytes / 1024)) + ' KB. This is what the gate will see.';
          document.getElementById('attsdlg-ok').hidden = true;
          document.getElementById('attsdlg-cancel').textContent = 'Close';
          if (drawEmployees.refresh) drawEmployees.refresh();
          return { keepOpen: true };
        });
      }
    });
  }

  function drawPhotos(){
    call('GET', '/admin/photos/status').then(function(st){
      if (!st.ok) return fail(st);
      function fact(big, small){ return el('div', { class: 'fact' }, [el('b', { text: big }), el('span', { text: small })]); }
      var testOut = el('div'), importOut = el('div');
      var notice = el('input', { type: 'checkbox', id: 'imp-notice' });
      var go = btn('Bring in photos', function(){
        if (!notice.checked) { importOut.textContent = ''; importOut.appendChild(el('p', { class: 'err', text: 'Tick the box first: employees must be told before their photo is kept.' })); return; }
        go.disabled = true;
        var skip = [], done = [], failed = [], last = null;
        function round(){
          return call('POST', '/admin/photos/import', { limit: 8, skip: skip, notice_given: true }).then(function(r){
            if (!r.ok) { importOut.textContent = ''; importOut.appendChild(el('p', { class: 'err', text: r.error || 'That did not work.' })); return; }
            last = r;
            r.done.forEach(function(x){ done.push(x); });
            r.failed.forEach(function(x){ if (!failed.some(function(f){ return f.file === x.file; })) failed.push(x); if (x.key) skip.push(x.key); });
            importOut.textContent = '';
            importOut.appendChild(el('p', { class: 'muted', text: 'Working… ' + done.length + ' brought in, ' + r.remaining + ' to go.' }));
            if (r.remaining > 0 && (r.done.length > 0 || r.failed.length > 0)) return round();
            finish();
          });
        }
        function finish(){
          importOut.textContent = '';
          importOut.appendChild(el('p', { class: 'okmsg', id: 'imp-result', text: done.length + ' photo' + (done.length === 1 ? '' : 's') + ' brought in. '
            + last.already + ' were already up to date. ' + last.files + ' file' + (last.files === 1 ? '' : 's') + ' in the folder.' }));
          if (failed.length) importOut.appendChild(table(['File', 'Not brought in because'], failed, function(x){ return [x.file, el('span', { class: 'err', text: x.error })]; }));
          if (last.unmatched.length) importOut.appendChild(el('p', { class: 'muted', id: 'imp-unmatched', text: 'No employee has these codes: ' + last.unmatched.join(', ') + '. Rename the file to the employee code and run this again.' }));
        }
        round().then(function(){ go.disabled = false; });
      }, true, !CAN.u || !st.storage_connected || !st.image_tools);

      show([
        el('div', { class: 'facts' }, [
          fact(st.with_photo + ' of ' + st.employees, 'active employees have a photo'),
          fact(st.storage_connected ? 'Connected' : 'Not connected', 'photo storage (Cloudflare R2, private)'),
          fact(st.image_tools ? 'Ready' : 'Missing', 'picture processing on this server'),
          fact(String(st.failures_7_days), 'failed uploads in the last 7 days' + (st.last_failure ? ' — last: ' + st.last_failure.why : ''))
        ]),
        st.storage_connected ? null : el('p', { class: 'err', text: 'Photos cannot be saved yet. The server\'s config.php needs its four r2_ lines (endpoint, bucket, access key, secret) — the same ones the document storage uses. They are set on the server only, never on this screen.' }),
        st.image_tools ? null : el('p', { class: 'err', text: 'This server is missing the PHP GD extension, so pictures cannot be checked or resized. Ask the host to enable it.' }),
        el('p', { class: 'note', text: 'Every photo is checked, cropped to an upright 4:5 portrait and stored at 480 × 600 as WebP — about 30 KB. Originals are never kept. Photos are private: only a signed-in gatekeeper, HOD of that department, HR or setup user can see one, and every view is logged. The picture taken with a flagged entry is deleted after ' + st.retention_days + ' days.' }),
        CAN.u ? el('div', { class: 'bar' }, [btn('Test the connection', function(){
          testOut.textContent = '';
          call('POST', '/admin/photos/test', {}).then(function(t){
            if (!t.ok) { testOut.appendChild(el('p', { class: 'err', text: t.error })); return; }
            testOut.appendChild(el('ul', { class: 'steps', id: 'test-steps' }, t.steps.map(function(x){
              return el('li', { class: x.ok ? 'okmsg' : 'err', text: (x.ok ? '✓ ' : '✗ ') + x.step + (x.ok ? '' : ' — ' + x.error) });
            })));
          });
        }, false, !st.storage_connected)]) : null,
        testOut,
        el('h2', { style: 'font-size:15px;margin:18px 0 4px', text: 'Bring in many photos at once' }),
        el('p', { class: 'note' }, ['In the Cloudflare bucket, put the pictures in the folder ', el('strong', { text: st.import_prefix }),
          ' and name each one by its employee code — for example ', el('strong', { text: 'E001.jpg' }),
          '. Then press the button. Each picture is matched by its name, checked, cropped and stored; the files you put there are left as they are. One photo at a time can also be added from the Employees tab.']),
        el('label', { style: 'display:block;font-size:13.5px;margin:0 0 10px' }, [notice, ' These employees have been told their photo will be kept for attendance']),
        el('div', { class: 'bar' }, [go]),
        importOut
      ]);
    });
  }

  // --------------------------------------------------------------- system
  /* Is this server ready? The same list as `php att_check.php`, plus one
     thing only a browser can test: that the database files and the code
     cannot be downloaded from the web. */
  function drawSystem(){
    if (!CAN.admin) return show([el('p', { class: 'muted', text: 'Only an administrator can run the system check.' })]);
    call('GET', '/admin/system-check').then(function(r){
      if (!r.ok) return fail(r);
      var probes = el('div', { id: 'sys-probes' }, [el('p', { class: 'muted', text: 'Checking what can be downloaded from the web…' })]);
      var mark = { ok: '✓', warn: '!', fail: '✗' };
      var groups = {};
      r.checks.forEach(function(c){ (groups[c.group] = groups[c.group] || []).push(c); });
      var nodes = [
        el('div', { class: 'facts', id: 'sys-summary' }, [
          el('div', { class: 'fact' }, [el('b', { class: r.ready ? 'okmsg' : 'err', text: r.ready ? 'Ready' : 'Not ready' }), el('span', { text: r.ready ? 'nothing is blocking' : 'fix the red lines first' })]),
          el('div', { class: 'fact' }, [el('b', { text: String(r.counts.ok) }), el('span', { text: 'checks passed' })]),
          el('div', { class: 'fact' }, [el('b', { text: String(r.counts.warn) }), el('span', { text: 'worth a look' })]),
          el('div', { class: 'fact' }, [el('b', { class: r.counts.fail ? 'err' : '', text: String(r.counts.fail) }), el('span', { text: 'must be fixed' })])
        ])
      ];
      Object.keys(groups).forEach(function(g){
        nodes.push(el('h2', { style: 'font-size:15px;margin:16px 0 6px', text: g }));
        nodes.push(el('ul', { class: 'check' }, groups[g].map(function(c){
          return el('li', { 'data-check': c.id, 'data-state': c.state }, [
            el('div', { class: 'mark ' + (c.state === 'ok' ? 'done' : (c.state === 'warn' ? 'opt' : '')), text: mark[c.state] }),
            el('div', { style: 'flex:1;min-width:0;overflow-wrap:anywhere' }, [el('b', { text: c.label }), el('span', { class: 't', text: c.detail }), c.state !== 'ok' && c.fix ? el('span', { class: 't', style: 'display:block;color:#33475f;margin-top:2px', text: '→ ' + c.fix }) : null])
          ]);
        })));
      });
      nodes.push(el('h2', { style: 'font-size:15px;margin:16px 0 6px', text: 'From the web' }));
      nodes.push(probes);
      nodes.push(el('p', { class: 'note', style: 'margin-top:14px;overflow-wrap:anywhere', text: 'Run the same check on the server with: php att_check.php — and prove the attendance backup restores with: php att_backup.php verify <file>' }));
      show(nodes);
      Promise.all(r.web_probes.map(function(u){
        return fetch(u + '?probe=' + Date.now(), { credentials: 'omit', cache: 'no-store' }).then(function(x){ return x.text().then(function(t){ return { url: u, status: x.status, size: t.length }; }); })
          .catch(function(){ return { url: u, status: 0, size: 0 }; });
      })).then(function(res){
        probes.textContent = '';
        probes.appendChild(el('ul', { class: 'check' }, res.map(function(p){
          var sql = /\.sql$/.test(p.url), readable = p.status === 200 && p.size > 0;
          var state = !readable ? 'ok' : (sql ? 'warn' : 'fail');
          return el('li', { 'data-probe': p.url, 'data-state': state }, [
            el('div', { class: 'mark ' + (state === 'ok' ? 'done' : (state === 'warn' ? 'opt' : '')), text: mark[state] }),
            el('div', { style: 'flex:1;min-width:0;overflow-wrap:anywhere' }, [el('b', { text: p.url }), el('span', { class: 't', text: !readable
              ? (p.status === 200 ? 'Runs on the server but shows nothing (empty page). Good.' : 'Cannot be downloaded (answer ' + p.status + '). Good.')
              : (sql ? 'Can be downloaded. It shows the table layout only — no data, no passwords — but it is better hidden. Ask Cloudways support to block .sql files, or move on: nothing else depends on it.'
                     : 'The code can be READ from the web. Tell your developer before going live.') })])
          ]);
        })));
      });
    });
  }

  // -------------------------------------------------------------- entries
  /* Administrator only: add an entry for someone who was never scanned,
     change an IN or OUT time, delete an entry that should not be there.
     Every change needs a reason and is kept with your name; a deleted entry
     leaves every report, and a full copy is kept on record. The gate's own
     scans are never altered: an edited time is shown beside the scan's. */
  var ENT = null;
  function khiDate(d){ var t = new Date((d || new Date()).getTime() + (5 * 60 + new Date().getTimezoneOffset()) * 60000);
    return t.getFullYear() + '-' + ('0' + (t.getMonth() + 1)).slice(-2) + '-' + ('0' + t.getDate()).slice(-2); }
  function khiLocal(iso){ if (!iso) return ''; var d = new Date(iso); if (isNaN(d)) return '';
    var t = new Date(d.getTime() + (5 * 60 + d.getTimezoneOffset()) * 60000);
    return khiDate(d) + 'T' + ('0' + t.getHours()).slice(-2) + ':' + ('0' + t.getMinutes()).slice(-2); }
  function khiTime(iso){ return iso ? khiLocal(iso).slice(11) : '—'; }
  function hm(m){ m = parseInt(m, 10); if (isNaN(m)) return '—'; return Math.floor(m / 60) + 'h ' + (m % 60) + 'm'; }
  function drawEntries(){
    if (!CAN.admin) return show([el('p', { class: 'muted', text: 'Only an administrator can change entries.' })]);
    ENT = ENT || { date: khiDate(), employee: '' };
    var date = el('input', { type: 'date', value: ENT.date, id: 'ent-date', 'aria-label': 'Day' });
    var who = el('input', { type: 'search', value: ENT.employee, id: 'ent-emp', placeholder: 'Employee code or name' });
    var bar = el('div', { class: 'bar' }, [date, who,
      btn('Show', function(){ ENT.date = date.value; ENT.employee = who.value; drawEntries(); }, true),
      btn('Add entry…', function(){ addEntry(); })]);
    call('GET', '/admin/entries', { date: ENT.date, employee: ENT.employee }).then(function(r){
      if (!r.ok) return show([bar, el('p', { class: 'err', text: r.error || 'Could not load.' })]);
      show([
        el('p', { class: 'note', text: 'Add an entry for someone who was not scanned, correct an IN or OUT time, or delete an entry that should not be there. Every change needs a reason and is kept with your name. The gate\'s own scans are never altered: an edited time is shown beside the time it was scanned.' }),
        bar,
        r.entries.length ? table(['Employee', 'Department', 'Shift', 'IN', 'OUT', 'Present', 'Notes', ''], r.entries, function(x){
          var notes = [];
          if (x.in_source === 'admin') notes.push('added by administrator');
          if (x.scanned_in_at && x.scanned_in_at !== x.opened_at) notes.push('IN scanned ' + khiTime(x.scanned_in_at));
          if (x.scanned_out_at && x.closed_at && x.scanned_out_at !== x.closed_at) notes.push('OUT scanned ' + khiTime(x.scanned_out_at));
          if (x.close_source === 'auto_shift_end') notes.push('OUT by the system (not scanned)');
          if ((x.close_source === 'hr_correction' || x.close_source === 'admin') && !x.scanned_out_at) notes.push('OUT put in by HR / administrator');
          if (x.edited_at) notes.push('edited ' + khiLocal(x.edited_at).replace('T', ' '));
          return [el('span', {}, [el('strong', { text: x.employee_code }), ' ' + x.full_name]), x.department_name, x.shift_name,
            khiTime(x.opened_at), x.closed_at ? khiTime(x.closed_at) : el('span', { class: 'muted', text: 'inside' }), x.present_minutes !== null ? hm(x.present_minutes) : '—',
            el('span', { class: 'muted', style: 'font-size:12.5px', text: notes.join(' · ') }),
            el('span', {}, [btn('Edit…', function(){ editEntry(x); }), ' ', btn('Delete…', function(){ deleteEntry(x); })])];
        }) : el('p', { class: 'muted', id: 'ent-none', text: 'No entries that day' + (ENT.employee ? ' for "' + ENT.employee + '"' : '') + '.' })
      ]);
    });
  }
  function reasonBox(id){ return el('textarea', { class: 'full', rows: 3, id: id, maxlength: 1000, placeholder: 'For example: scanned the wrong person; came in by the side gate; phone clock was wrong' }); }
  function addEntry(){
    var code = el('input', { type: 'text', class: 'full', id: 'ent-add-code', placeholder: 'Employee code' });
    var tin = el('input', { type: 'datetime-local', id: 'ent-add-in', value: ENT.date + 'T08:00' });
    var tout = el('input', { type: 'datetime-local', id: 'ent-add-out' });
    var why = reasonBox('ent-add-why');
    dialog({ title: 'Add an entry', text: 'For someone who came in but was not scanned. Leave OUT empty if they are still inside. Factory time.',
      fields: [field('Employee code', code), field('IN', tin), field('OUT (empty = still inside)', tout), field('Reason (required)', why)], okText: 'Add entry',
      submit: function(){ return call('POST', '/admin/entries', { employee_code: code.value, in_at: tin.value, out_at: tout.value, timezone: 'Asia/Karachi', reason: why.value })
        .then(function(x){ if (x.ok) { ENT.date = tin.value.slice(0, 10); drawEntries(); } return x; }); } });
  }
  function editEntry(x){
    var tin = el('input', { type: 'datetime-local', id: 'ent-ed-in', value: khiLocal(x.opened_at) });
    var tout = el('input', { type: 'datetime-local', id: 'ent-ed-out', value: khiLocal(x.closed_at) });
    var why = reasonBox('ent-ed-why');
    dialog({ title: 'Edit — ' + x.full_name, text: 'Change the IN or OUT time. The gate\'s scan keeps the time it was scanned at; this entry and every report use the new time. Factory time.',
      fields: [field('IN', tin), field('OUT' + (x.closed_at ? '' : ' (fill in to close the entry)'), tout), field('Reason (required)', why)], okText: 'Save change',
      submit: function(){
        var body = { reason: why.value, timezone: 'Asia/Karachi' };
        if (tin.value !== khiLocal(x.opened_at)) body.in_at = tin.value;
        if (tout.value && tout.value !== khiLocal(x.closed_at)) body.out_at = tout.value;
        if (!body.in_at && !body.out_at) return Promise.resolve({ ok: false, error: 'Nothing was changed.' });
        return call('POST', '/admin/entries/' + x.entry_cycle_id + '/edit', body).then(function(r){ if (r.ok) drawEntries(); return r; });
      } });
  }
  function deleteEntry(x){
    var why = reasonBox('ent-del-why');
    dialog({ title: 'Delete this entry? — ' + x.full_name, text: 'IN ' + khiTime(x.opened_at) + (x.closed_at ? ', OUT ' + khiTime(x.closed_at) : ', still inside') + '. It leaves attendance and every report. A full copy is kept on record with your name and reason; a HOD confirmation of it is set aside.',
      fields: [field('Reason (required)', why)], okText: 'Delete entry',
      submit: function(){ return call('POST', '/admin/entries/' + x.entry_cycle_id + '/delete', { reason: why.value }).then(function(r){ if (r.ok) drawEntries(); return r; }); } });
  }

  // --------------------------------------------------------------- phones
  /* Every phone that has signed in to the gate / HOD app. Name each one so
     scans say where they came from ("Main gate", "Unit 2 back gate"), and
     withdraw a lost or retired one: it is refused from its very next scan. */
  function utc(v){ if (!v) return '—'; var d = new Date(String(v).replace(' ', 'T').replace(/(\.\d+)?$/, '') + 'Z'); return isNaN(d) ? v : d.toLocaleString('en-GB', { timeZone: 'Asia/Karachi', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }); }
  function drawPhones(){
    call('GET', '/devices').then(function(r){
      if (!r.ok) return fail(r);
      var live = r.devices.filter(function(d){ return !d.revoked_at; });
      show([
        el('p', { class: 'note', text: live.length + ' phone' + (live.length === 1 ? '' : 's') + ' in service. A phone registers itself the first time a gatekeeper or HOD signs in on it. Give each one a name people recognise — the name appears on every scan it sends.' }),
        r.devices.length ? table(['Phone', 'Used for', 'Signed in first by', 'Scans', 'Last heard from', 'App', 'State', ''], r.devices, function(d){
          var off = !!d.revoked_at;
          return [el('strong', { text: d.label || '—' }), d.role_scope === 'both' ? 'gate and HOD' : d.role_scope, d.registered_by_name || '—', String(d.scans || 0), utc(d.last_seen_at), d.app_version || '—',
            el('span', { class: 'pill ' + (off ? 'off' : 'good'), text: off ? 'Withdrawn ' + utc(d.revoked_at) : 'In service' }),
            (!off && CAN.u) ? el('span', {}, [
              btn('Rename', function(){
                var n = el('input', { type: 'text', class: 'full', maxlength: 100, value: d.label || '', id: 'ph-label' });
                dialog({ title: 'Name this phone', text: 'For example "Main gate" or "Stitching HOD". Shown on every scan this phone sends.', fields: [field('Name', n)],
                  submit: function(){ return call('POST', '/admin/devices/' + d.id + '/label', { label: n.value }).then(function(x){ if (x.ok) drawPhones(); return x; }); } });
              }), ' ',
              btn('Withdraw…', function(){
                var why = el('textarea', { class: 'full', rows: 3, id: 'ph-why' });
                dialog({ title: 'Withdraw "' + (d.label || 'this phone') + '"?', text: 'It is refused from its next scan and cannot register again. Scans still on it and not yet sent will be refused too, so send them first if the phone is in your hand.',
                  fields: [field('Reason (kept on the record)', why)], okText: 'Withdraw',
                  submit: function(){ return call('POST', '/devices/' + d.id + '/revoke', { reason: why.value }).then(function(x){ if (x.ok) drawPhones(); return x; }); } });
              })
            ]) : el('span', {})];
        }) : el('p', { class: 'muted', text: 'No phone has signed in yet.' })
      ]);
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
