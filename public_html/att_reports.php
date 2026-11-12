<?php
/*
  ATTENDANCE — REPORTS
  ====================

  Today          who is inside now, who came, who is late, who left early,
                 and (for HR / Accounts / Admin) what needs attention
  Attendance     one line per employee per shift day: first IN, last OUT,
                 present minutes, late, left early, HOD confirmations, OT
  Movements      every IN and OUT, who recorded it, and the HOD confirmation
  Overtime       every overtime line, by department, with what was paid

  READ-ONLY. Every figure comes from /api/v1/reports/...; the department scope
  is decided there (a HOD sees their own departments only). "Download" gives
  the same rows as a CSV file for Excel.

  Words matter here, so the screen uses the service's own definitions: present
  minutes are closed IN–OUT entries only; "left early" is POTENTIAL (an
  approved break or permission may explain it); overtime is only what people
  approved and checked — time inside after the shift is never shown as
  overtime.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
zu_require('attrep', 'v');
$canOt = zu_can('attotap', 'v') || zu_can('attpay', 'v') || zu_can('attot', 'v');
page_header('Attendance — Reports');
?>
<style>
.attr .tabs{display:flex;flex-wrap:wrap;gap:4px;border-bottom:1px solid #dfe6ef;margin:0 0 14px}
.attr .tabs button{font:inherit;font-size:13.5px;font-weight:600;padding:9px 14px;border:0;border-bottom:3px solid transparent;background:none;color:#5a6b82;cursor:pointer}
.attr .tabs button[aria-selected=true]{color:#10243e;border-bottom-color:#10243e}
.attr table{min-width:0;width:100%;border-collapse:collapse;font-size:13px}
.attr th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#5a6b82;padding:7px 8px;border-bottom:1px solid #dfe6ef;white-space:nowrap;position:sticky;top:0;background:#fff}
.attr td{padding:7px 8px;border-bottom:1px solid #edf1f6;vertical-align:top;white-space:nowrap}
.attr td.wrap{white-space:normal;min-width:160px}
.attr .scroll{overflow:auto;width:0;min-width:100%;max-height:70vh}
.attr .bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 12px}
.attr input,.attr select{font:inherit;font-size:13.5px;padding:7px 9px;border:1px solid #cfd9e6;border-radius:8px;background:#fff;color:inherit;width:auto}
.attr button.act,.attr a.act{font:inherit;font-size:12.5px;font-weight:600;padding:7px 11px;border-radius:7px;border:1px solid #cfd9e6;background:#fff;color:#10243e;cursor:pointer;text-decoration:none;white-space:nowrap}
.attr button.act.primary{background:#10243e;color:#fff;border-color:#10243e}
.attr .muted{color:#5a6b82}.attr .err{color:#a1241c;font-weight:600}
.attr .note{font-size:12.5px;color:#5a6b82;margin:0 0 12px}
.attr .tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:0 0 16px}
.attr .tile{border:1px solid #dfe6ef;border-radius:12px;padding:12px 14px;background:#fff}
.attr .tile b{display:block;font-size:26px;line-height:1.1;color:#10243e}.attr .tile span{font-size:12.5px;color:#5a6b82}
.attr .tile.hot b{color:#a1241c}.attr .tile.good b{color:#17683a}
.attr .grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px}
.attr h3{font-size:14px;margin:6px 0 6px}
.attr .pill{display:inline-block;border-radius:99px;padding:1px 8px;font-size:11.5px;font-weight:600;background:#e8eef6;color:#33475f}
.attr .pill.present{background:#e2f3e8;color:#17683a}.attr .pill.inside{background:#e8f0fb;color:#17467c}.attr .pill.absent{background:#fbe6e4;color:#a1241c}
.attr .pill.approved,.attr .pill.verified,.attr .pill.finalized{background:#e2f3e8;color:#17683a}.attr .pill.rejected,.attr .pill.disputed{background:#fbe6e4;color:#a1241c}.attr .pill.pending,.attr .pill.draft{background:#fdf0d9;color:#8a5300}
.attr .bars{display:flex;flex-direction:column;gap:6px}
.attr .barrow{display:grid;grid-template-columns:minmax(90px,160px) 1fr 40px;gap:8px;align-items:center;font-size:13px}
.attr .barrow .track{height:10px;border-radius:99px;background:#edf1f6;overflow:hidden}.attr .barrow .fill{height:100%;background:#1d5fa8;border-radius:99px}
.attr .watch li{padding:6px 0;border-bottom:1px solid #edf1f6;display:flex;justify-content:space-between;gap:10px;font-size:13.5px}
.attr .watch{list-style:none;margin:0;padding:0}.attr .watch b{font-variant-numeric:tabular-nums}.attr .watch .hot{color:#a1241c}
</style>

<div class="topbar">
  <div>
    <h1>Attendance — Reports</h1>
    <p class="lead">Read-only. Times are factory time (Asia/Karachi).</p>
  </div>
</div>

<div class="card attr" id="attr">
  <div class="tabs" role="tablist" id="attr-tabs"></div>
  <div id="attr-body"><p class="muted">Loading…</p></div>
</div>

<script>
(function(){
  'use strict';
  var API = 'api/v1/index.php?route=';
  var CAN_OT = <?= json_encode($canOt) ?>;
  var body = document.getElementById('attr-body');
  var timer = null;

  function el(tag, attrs, kids){
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function(k){
      var v = attrs[k];
      if (k === 'text') n.textContent = v; else if (k === 'click') n.addEventListener('click', v);
      else if (k === 'value') n.value = v; else if (v !== null && v !== undefined && v !== false) n.setAttribute(k, v);
    });
    (kids || []).forEach(function(c){ if (c !== null && c !== undefined && c !== false) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }
  function q(route, data){
    var url = API + encodeURIComponent(route);
    Object.keys(data || {}).forEach(function(k){ if (data[k] !== '' && data[k] != null) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); });
    return url;
  }
  function get(route, data){
    return fetch(q(route, data), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function(r){ return r.json().catch(function(){ return { ok: false, error: 'The server sent something unreadable.' }; }); })
      .catch(function(){ return { ok: false, error: 'The server could not be reached.' }; });
  }
  function show(nodes){ body.textContent = ''; nodes.forEach(function(n){ if (n) body.appendChild(n); }); }
  function table(head, rows, draw, wrapCols){
    var tb = el('tbody');
    rows.forEach(function(r){ tb.appendChild(el('tr', {}, draw(r).map(function(c, i){ return el('td', { class: (wrapCols || []).indexOf(i) >= 0 ? 'wrap' : null }, [c === null || c === undefined ? '—' : (typeof c === 'object' ? c : String(c))]); }))); });
    return el('div', { class: 'scroll' }, [el('table', {}, [el('thead', {}, [el('tr', {}, head.map(function(h){ return el('th', { text: h }); }))]), tb])]);
  }
  function hm(iso){ if (!iso) return '—'; var d = new Date(iso); return isNaN(d) ? iso : d.toLocaleTimeString('en-GB', { timeZone: 'Asia/Karachi', hour: '2-digit', minute: '2-digit' }); }
  function dt(iso){ if (!iso) return '—'; var d = new Date(iso); return isNaN(d) ? iso : d.toLocaleString('en-GB', { timeZone: 'Asia/Karachi', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }); }
  function day(v){ var d = new Date(v + 'T00:00:00Z'); return isNaN(d) ? v : d.toLocaleDateString('en-GB', { timeZone: 'UTC', weekday: 'short', day: '2-digit', month: 'short' }); }
  function mins(m){ m = parseInt(m, 10); if (!m) return m === 0 ? '0' : '—'; return (m >= 60 ? Math.floor(m / 60) + 'h ' : '') + (m % 60) + 'm'; }
  /* Overtime is shown in hours, as it is typed: 1.5 h. */
  function hrs(m){ m = parseInt(m, 10); if (isNaN(m)) return '—'; return (Math.round(m / 60 * 100) / 100) + ' h'; }
  function pill(s, t){ return el('span', { class: 'pill ' + s, text: t || s }); }
  function tile(n, label, cls, id){ return el('div', { class: 'tile ' + (cls || ''), id: id }, [el('b', { text: String(n) }), el('span', { text: label })]); }
  function iso(d){ return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function khi(){ return new Date(Date.now() + (5 * 60 + new Date().getTimezoneOffset()) * 60000); }

  // ---------------------------------------------------------------- tabs
  var TABS = [{ id: 'today', label: 'Today', draw: drawToday }, { id: 'att', label: 'Attendance', draw: drawAttendance }, { id: 'mov', label: 'Movements', draw: drawMovements }];
  if (CAN_OT) TABS.push({ id: 'ot', label: 'Overtime', draw: drawOvertime });
  function go(id){
    if (timer) { clearInterval(timer); timer = null; }
    Array.prototype.forEach.call(document.querySelectorAll('#attr-tabs button'), function(b){ b.setAttribute('aria-selected', b.dataset.tab === id ? 'true' : 'false'); });
    show([el('p', { class: 'muted', text: 'Loading…' })]);
    TABS.filter(function(t){ return t.id === id; })[0].draw();
    try { history.replaceState(null, '', '#' + id); } catch (e) {}
  }
  TABS.forEach(function(t){ var b = el('button', { type: 'button', role: 'tab', text: t.label, click: function(){ go(t.id); } }); b.dataset.tab = t.id; document.getElementById('attr-tabs').appendChild(b); });

  // ---------------------------------------------------------------- today
  function drawToday(){
    get('/reports/dashboard').then(function(d){
      if (!d.ok) return show([el('p', { class: 'err', text: d.error || 'Could not load.' })]);
      if (d.empty_scope) return show([el('p', { class: 'muted', text: 'You are not the HOD of a department, so there is nobody to report on.' })]);
      var max = Math.max.apply(null, [1].concat(d.inside_by_department.map(function(x){ return parseInt(x.inside, 10); })));
      var nodes = [
        el('p', { class: 'note', text: day(d.today) + ' · updated ' + dt(d.generated_at) + ' · refreshes every minute' }),
        el('div', { class: 'tiles' }, [
          tile(d.inside_now, 'inside now', 'good', 't-inside'),
          tile(d.came_in + ' / ' + d.active_employees, 'came in today / active employees', '', 't-came'),
          tile(d.late_count, 'late today', d.late_count ? 'hot' : '', 't-late'),
          tile(d.left_early_count, 'left before shift end (potential)', d.left_early_count ? 'hot' : '', 't-early'),
          tile(d.unconfirmed_entries, 'entries not yet confirmed by HOD', d.unconfirmed_entries ? 'hot' : '', 't-unconf'),
          d.absent_with_shift_count ? tile(d.absent_with_shift_count, 'had a shift, did not come', 'hot', 't-absent') : null,
          d.auto_out_count ? tile(d.auto_out_count, 'not scanned out — system closed at shift end', 'hot', 't-auto') : null
        ]),
        el('div', { class: 'grid2' }, [
          el('div', {}, [el('h3', { text: 'Inside now, by department' }),
            d.inside_by_department.length ? el('div', { class: 'bars', id: 'inside-bars' }, d.inside_by_department.map(function(x){
              return el('div', { class: 'barrow' }, [el('span', { text: x.department_name }), el('div', { class: 'track' }, [el('div', { class: 'fill', style: 'width:' + Math.round(100 * x.inside / max) + '%' })]), el('b', { text: String(x.inside) })]);
            })) : el('p', { class: 'muted', text: 'Nobody is inside.' })]),
          d.monitoring ? el('div', {}, [el('h3', { text: 'Needs attention' }), watch(d.monitoring)]) : null
        ]),
        d.auto_out_count ? el('div', { id: 'auto-out' }, [
          el('h3', { style: 'margin-top:16px', text: 'Not scanned out — the system closed these at shift end' }),
          el('p', { class: 'note', text: 'Nobody scanned OUT for these people, so the system closed the entry at the shift end (or shift end plus approved overtime). HR can put in the real time on the Reconciliation screen.' }),
          table(['Employee', 'Department', 'Came in', 'System OUT at', 'Closed by the system'], d.auto_out, function(r){ return [r.employee_code + ' ' + r.full_name, r.department_name, dt(r.opened_at), hm(r.closed_at), dt(r.auto_closed_at)]; })
        ]) : null,
        el('h3', { style: 'margin-top:16px', text: 'Late today' }),
        d.late.length ? table(['Employee', 'Department', 'Shift starts', 'First IN', 'Late by'], d.late, function(r){ return [r.employee_code + ' ' + r.full_name, r.department_name, hm(r.starts_at), hm(r.first_in), mins(r.late_minutes)]; }) : el('p', { class: 'muted', text: 'Nobody.' }),
        el('h3', { style: 'margin-top:16px', text: 'Left before shift end (potential — a break or permission may explain it)' }),
        d.left_early.length ? table(['Employee', 'Department', 'Last OUT', 'Shift ends', 'Early by'], d.left_early, function(r){ return [r.employee_code + ' ' + r.full_name, r.department_name, hm(r.last_out), hm(r.ends_at), mins(r.early_minutes)]; }) : el('p', { class: 'muted', text: 'Nobody.' }),
        el('details', { style: 'margin-top:16px' }, [el('summary', { class: 'muted', style: 'cursor:pointer', text: d.not_in_count + ' active employee' + (d.not_in_count === 1 ? ' has' : 's have') + ' no entry today (includes days off)' }),
          table(['Code', 'Name', 'Department'], d.not_in, function(r){ return [r.employee_code, r.full_name, r.department_name]; })])
      ];
      show(nodes);
    });
    timer = setInterval(function(){ if (!document.hidden) drawToday(); }, 60000);
  }
  function watch(m){
    function li(n, text, hot){ return el('li', {}, [el('span', { text: text }), el('b', { class: hot && n ? 'hot' : null, text: String(n) })]); }
    var list = el('ul', { class: 'watch', id: 'watch' }, [
      li(m.phones_quiet.length, 'Phones not heard from for over ' + m.quiet_after_minutes + ' min (they may hold unsent scans)', true),
      li(m.held_scans, 'Gate scans waiting for HR', true),
      li(m.hod_unmatched, 'HOD confirmations not matched to an entry', true),
      li(m.entries_left_open, 'Entries left open too long (missed OUT?)', true),
      li(m.phones_clock_wrong, 'Phones with a wrong clock (last 7 days)', true),
      li(m.photo_upload_failures_7d, 'Photo uploads that failed (last 7 days)', true),
      li(m.flags_open, 'Flagged entries open', true),
      li(m.ot_waiting_decision, 'Overtime waiting for a decision', true),
      li(m.ot_waiting_check, 'Overtime waiting for next-day check', true),
      li(m.ot_waiting_rate, 'Overtime checked, waiting for a rate', true)
    ]);
    var wrap = el('div', {}, [list]);
    if (m.phones_quiet.length) wrap.appendChild(el('p', { class: 'note', style: 'margin-top:8px', text: 'Quiet phones: ' + m.phones_quiet.map(function(p){ return (p.label || 'phone') + ' (last seen ' + (p.last_seen_at ? dt(p.last_seen_at.replace(' ', 'T') + 'Z') : 'never') + ')'; }).join(', ') }));
    wrap.appendChild(el('p', { class: 'note', style: 'margin-top:8px', text: m.backups }));
    return wrap;
  }

  // ------------------------------------------------------------- filters
  var F = null;
  function filters(onShow, withEmp){
    if (!F) { var t = khi(), f = new Date(t); f.setDate(f.getDate() - 6); F = { from: iso(f), to: iso(t), employee: '' }; }
    var from = el('input', { type: 'date', value: F.from, id: 'f-from', 'aria-label': 'First day' });
    var to = el('input', { type: 'date', value: F.to, id: 'f-to', 'aria-label': 'Last day' });
    var emp = withEmp ? el('input', { type: 'search', value: F.employee, id: 'f-emp', placeholder: 'Employee code or name' }) : null;
    var go_ = el('button', { type: 'button', class: 'act primary', id: 'f-show', text: 'Show', click: function(){ F.from = from.value; F.to = to.value; F.employee = emp ? emp.value : ''; onShow(); } });
    return el('div', { class: 'bar' }, [from, el('span', { class: 'muted', text: 'to' }), to, emp, go_]);
  }
  function download(route){ return el('a', { class: 'act', id: 'f-csv', href: q(route, { from: F.from, to: F.to, employee: F.employee, format: 'csv' }), text: 'Download (CSV)' }); }

  // ----------------------------------------------------------- attendance
  function drawAttendance(){
    var bar = filters(drawAttendance, true);
    get('/reports/attendance', F).then(function(r){
      if (!r.ok) return show([bar, el('p', { class: 'err', text: r.error })]);
      var t = r.totals;
      bar.appendChild(download('/reports/attendance'));
      show([bar,
        el('div', { class: 'tiles' }, [tile(t.present, 'shift days worked'), tile(t.absent, 'had a shift, did not come', t.absent ? 'hot' : ''), tile(mins(t.present_minutes), 'present (closed entries)'),
          tile(t.late, 'late days', t.late ? 'hot' : ''), tile(t.left_early, 'left early (potential)', t.left_early ? 'hot' : ''), tile(t.unconfirmed, 'entries unconfirmed by HOD', t.unconfirmed ? 'hot' : ''), tile(hrs(t.ot_actual_minutes), 'overtime checked'),
          t.auto_out ? tile(t.auto_out, 'days not scanned out (system OUT)', 'hot') : null]),
        el('p', { class: 'note', text: 'Present = time between IN and OUT. An entry still open shows as "inside". HOD confirmation times never count as an IN. Overtime is only what was approved and checked the next day.' }),
        r.rows.length ? table(['Day', 'Employee', 'Department', 'Shift', 'First IN', 'Last OUT', 'Entries', 'Present', 'Late', 'Left early', 'HOD unconfirmed', 'OT checked', ''], r.rows, function(x){
          return [day(x.work_date), x.employee_code + ' ' + x.full_name, x.department_name, x.shift_name + ' ' + hm(x.starts_at) + '–' + hm(x.ends_at) + (x.shift_source === 'default_auto' ? ' (default)' : ''),
            hm(x.first_in), x.auto_out ? el('span', { class: 'err', title: 'No OUT was scanned. The system closed the entry at shift end.', text: hm(x.last_out) + ' · system, not scanned' }) : hm(x.last_out), x.entries, mins(x.present_minutes), x.late_minutes ? mins(x.late_minutes) : '', x.early_minutes ? mins(x.early_minutes) : '', x.unconfirmed || '',
            x.ot_actual_minutes !== null ? hrs(x.ot_actual_minutes) : (x.ot_approved_minutes ? 'approved ' + hrs(x.ot_approved_minutes) : ''),
            pill(x.status, x.status === 'absent' ? 'did not come' : x.status)];
        }) : el('p', { class: 'muted', id: 'att-none', text: 'Nothing in these dates.' }),
        r.truncated ? el('p', { class: 'err', text: 'Only the first 20000 lines are shown. Choose fewer days.' }) : null]);
    });
  }

  // ------------------------------------------------------------ movements
  function drawMovements(){
    var bar = filters(drawMovements, true);
    get('/reports/movements', F).then(function(r){
      if (!r.ok) return show([bar, el('p', { class: 'err', text: r.error })]);
      bar.appendChild(download('/reports/movements'));
      show([bar, el('p', { class: 'note', text: r.rows.length + ' scan' + (r.rows.length === 1 ? '' : 's') + '. "Later" means the phone had no signal and sent it afterwards; the time shown is when it was scanned. "OUT · system": nobody scanned OUT and the system closed the entry at shift end. "OUT · corrected" / "IN · added": put in by HR or the administrator. "corrected": the administrator changed the time; the scanned time is shown under it.' }),
        r.rows.length ? table(['Time', '', 'Employee', 'Department', 'Entry', 'Recorded by', 'Phone', 'Sent', 'HOD confirmed'], r.rows, function(x){
          var auto = x.event_type === 'AUTO_OUT' || x.event_type === 'FIXED_OUT' || x.event_type === 'ADMIN_IN';
          var isIn = x.event_type === 'GATE_IN' || x.event_type === 'ADMIN_IN';
          var label = { GATE_IN: 'IN', ADMIN_IN: 'IN · added', AUTO_OUT: 'OUT · system', FIXED_OUT: 'OUT · corrected' }[x.event_type] || 'OUT';
          var when = x.edited ? el('span', {}, [dt(x.occurred_at), el('span', { class: 'err', style: 'display:block;font-size:12px', text: 'corrected · scanned ' + dt(x.scanned_at) })]) : dt(x.occurred_at);
          return [when, pill(isIn ? 'present' : (auto ? 'absent' : 'pending'), label), x.employee_code + ' ' + x.full_name, x.department_name,
            x.entry_sequence ? '#' + x.entry_sequence : '', auto ? el('span', { class: 'err', text: x.recorded_by }) : x.recorded_by, x.phone || '', auto ? 'not scanned' : (x.source === 'offline_replay' ? 'later' : 'at once'),
            isIn ? (x.hod_confirmed_at ? hm(x.hod_confirmed_at) + ' · ' + (x.hod_name || '') : el('span', { class: 'err', text: 'not yet' })) : ''];
        }) : el('p', { class: 'muted', id: 'mov-none', text: 'No scans in these dates.' })]);
    });
  }

  // ------------------------------------------------------------- overtime
  function drawOvertime(){
    var bar = filters(drawOvertime, false);
    get('/reports/overtime', F).then(function(r){
      if (!r.ok) return show([bar, el('p', { class: 'err', text: r.error })]);
      bar.appendChild(download('/reports/overtime'));
      show([bar,
        el('h3', { text: 'By department' }),
        r.by_department.length ? table(['Department', 'Asked', 'Approved', 'Rejected', 'Approved time', 'Checked time', 'Finalized pay', 'Not finalized yet'], r.by_department, function(b){
          return [b.department_name, b.requested, b.approved, b.rejected, hrs(b.approved_minutes), hrs(b.actual_minutes), b.finalized_amount, b.draft_amount];
        }) : el('p', { class: 'muted', id: 'ot-none', text: 'No overtime in these dates.' }),
        r.rows.length ? el('h3', { style: 'margin-top:16px', text: 'Every line' }) : null,
        r.rows.length ? table(['Day', 'Employee', 'Department', 'Reason', 'Asked', 'Decision', 'Approved', 'Checked', 'Rate', 'Amount', 'Payroll'], r.rows, function(x){
          return [day(x.work_date), x.employee_code + ' ' + x.full_name, x.department_name, x.reason, hrs(x.estimated_minutes), pill(x.approval_status), x.approved_minutes ? hrs(x.approved_minutes) : '',
            x.verification_status === 'verified' ? hrs(x.actual_minutes) : pill(x.verification_status), x.rate_type ? x.rate_type + ' ' + x.manual_rate : '', x.amount || '', pill(x.payroll_status === 'none' ? 'pending' : x.payroll_status, x.payroll_status)];
        }, [3]) : null]);
    });
  }

  var first = (location.hash || '').slice(1);
  go(TABS.some(function(t){ return t.id === first; }) ? first : 'today');
})();
</script>
<?php page_footer(); ?>
