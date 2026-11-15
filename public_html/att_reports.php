<?php
/*
  ATTENDANCE — REPORTS
  ====================

  Today          headline figures, one line per department (on the roll,
                 came in, inside, late, left early, did not come, attendance
                 rate), what needs attention, and the people behind each
                 figure: late, left early, did not come, no entry, not
                 scanned out
  Attendance     for any dates: totals, a summary per employee and per
                 department, and one line per employee per shift day
  Movements      every IN and OUT, who recorded it, and the HOD confirmation
  Overtime       every overtime line, by department, with what was paid

  READ-ONLY. Every figure comes from /api/v1/reports/...; the department scope
  is decided there (a HOD sees their own departments only). The department,
  status and type filters on the screen only narrow what was already sent.
  "Download" gives the same rows as a CSV file for Excel; "Print" prints the
  report without the menu.

  Words matter here, so the screen uses the service's own definitions: present
  minutes are closed IN–OUT entries only; "left early" is POTENTIAL (an
  approved break or permission may explain it); overtime is only what people
  approved and checked — time inside after the shift is never shown as
  overtime. Attendance rate = shift days worked ÷ shift days.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
zu_require('attrep', 'v');
$canOt = zu_can('attotap', 'v') || zu_can('attpay', 'v') || zu_can('attot', 'v');
page_header('Attendance — Reports');
?>
<style>
/* ENTERPRISE LOOK — the same compact working area as People Finance:
   white panel with a tab bar, figure tiles with a coloured top edge,
   navy-headed tables with right-aligned even-width figures and a shaded
   totals row. Phones keep working: every table scrolls inside its box. */
.content{padding:16px 20px}
.topbar{margin-bottom:12px}
.topbar h1{font:600 20px/1.25 'IBM Plex Sans','Segoe UI',Inter,system-ui,Arial,sans-serif;color:#0f2742;letter-spacing:0;margin:0}
.topbar .lead{font:400 12.5px/1.4 'IBM Plex Sans','Segoe UI',Inter,system-ui,Arial,sans-serif;color:#5b6b7c;margin:3px 0 0}
.attr{--ff:'IBM Plex Sans','Segoe UI',Inter,system-ui,-apple-system,Roboto,Arial,sans-serif;
  --navy:#0f2742;--pri:#0a5fb4;--ink:#1d2733;--muted:#5b6b7c;--line:#d5dbe3;--line2:#e7ebf0;--head:#f4f6f9;--hover:#f0f6fc;
  --good:#107e3e;--good-bg:#e7f4ec;--warn:#b45309;--warn-bg:#fef6ea;--bad:#bb0000;--bad-bg:#ffefef;--info-bg:#e1edf9;
  color:var(--ink);font:400 13px/1.45 var(--ff);background:#fff;border:1px solid var(--line);border-radius:6px;box-shadow:0 1px 2px rgba(15,39,66,.06);padding:0!important}
.attr *{box-sizing:border-box}
.attr [hidden]{display:none!important}
.attr button,.attr input,.attr select{font-family:var(--ff)}
.attr .tabs{width:0;min-width:100%;display:flex;gap:2px;overflow-x:auto;scrollbar-width:none;background:#fff;border-bottom:1px solid var(--line);border-radius:6px 6px 0 0;padding:0 10px;margin:0;position:sticky;top:0;z-index:5}
.attr .tabs::-webkit-scrollbar{display:none}
.attr .tabs button{flex:0 0 auto;border:0;border-bottom:3px solid transparent;background:transparent;color:var(--muted);font:600 13px/1 var(--ff);padding:13px 14px 10px;cursor:pointer;white-space:nowrap}
.attr .tabs button:hover{color:var(--pri)}
.attr .tabs button[aria-selected=true]{color:var(--pri);border-bottom-color:var(--pri)}
#attr-body{padding:14px 16px 18px;display:grid;gap:12px;min-width:0}
.attr h3{font-size:13px;font-weight:600;color:var(--navy);margin:0}
.attr .muted{color:var(--muted)}.attr .err{color:var(--bad);font-weight:600}
.attr .note{font-size:12px;color:var(--muted);margin:0}
/* header strip: date + actions */
.attr .strip{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between}
.attr .strip .when{font-size:12.5px;color:var(--muted)}.attr .strip .when b{color:var(--navy);font-size:14px;margin-right:6px}
.attr .acts{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
/* filter bar */
.attr .bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;background:var(--head);border:1px solid var(--line);border-radius:4px;padding:8px 10px}
.attr .bar .lbl{font-size:11.5px;font-weight:600;color:var(--muted);text-transform:none}
.attr .bar .sep{width:1px;align-self:stretch;background:var(--line);margin:0 4px}
.attr input,.attr select{font-size:13px;padding:5px 8px;min-height:30px;border:1px solid #b9c4d0;border-radius:3px;background:#fff;color:inherit;width:auto;max-width:100%}
.attr input[type=search]{min-width:180px}
.attr .act{font:600 12.5px/1 var(--ff);padding:8px 12px;min-height:30px;border-radius:3px;border:1px solid #b9c4d0;background:#fff;color:var(--pri);cursor:pointer;text-decoration:none;white-space:nowrap;display:inline-flex;align-items:center;gap:6px}
.attr .act:hover{background:var(--hover)}
.attr .act.primary{background:var(--pri);color:#fff;border-color:var(--pri)}
.attr .act.primary:hover{background:#084b8f}
.attr .quick{display:inline-flex;border:1px solid #b9c4d0;border-radius:3px;max-width:100%;overflow-x:auto;scrollbar-width:thin}
.attr .quick button{border:0;border-right:1px solid #d5dbe3;background:#fff;color:var(--ink);font:500 12px/1 var(--ff);padding:8px 9px;cursor:pointer}
.attr .quick button:last-child{border-right:0}.attr .quick button:hover{background:var(--hover);color:var(--pri)}
/* segmented view switch */
.attr .seg{display:inline-flex;border:1px solid var(--pri);border-radius:3px;overflow:hidden}
.attr .seg button{border:0;border-right:1px solid var(--pri);background:#fff;color:var(--pri);font:600 12.5px/1 var(--ff);padding:8px 12px;cursor:pointer;white-space:nowrap}
.attr .seg button:last-child{border-right:0}
.attr .seg button[aria-pressed=true]{background:var(--pri);color:#fff}
.attr .seg .c{display:inline-block;min-width:18px;margin-left:6px;padding:1px 5px;border-radius:3px;background:var(--head);color:var(--navy);font-size:11px}
.attr .seg button[aria-pressed=true] .c{background:rgba(255,255,255,.22);color:#fff}
.attr .segwrap{max-width:100%;overflow-x:auto}
/* figure tiles */
.attr .tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(118px,1fr));gap:8px}
.attr .tile{background:#fff;border:1px solid var(--line);border-top:3px solid var(--navy);border-radius:4px;padding:8px 12px 9px;min-width:0}
.attr .tile .k{display:block;font-size:11.5px;font-weight:600;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.attr .tile b{display:block;font-size:22px;font-weight:600;line-height:1.2;color:var(--navy);font-variant-numeric:tabular-nums;margin-top:2px}
.attr .tile b small{font-size:12.5px;font-weight:600;color:var(--muted)}
.attr .tile .s{display:block;font-size:11.5px;color:var(--muted);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.attr .tile.good{border-top-color:var(--good)}.attr .tile.good b{color:var(--good)}
.attr .tile.hot{border-top-color:var(--bad)}.attr .tile.hot b{color:var(--bad)}
.attr .tile.warn{border-top-color:var(--warn)}.attr .tile.warn b{color:var(--warn)}
.attr .tile.info{border-top-color:var(--pri)}
.attr .tile .meter{height:5px;border-radius:2px;background:var(--line2);overflow:hidden;margin-top:6px}
.attr .tile .meter i{display:block;height:100%;background:var(--good)}
/* panels */
.attr .cols{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(0,1fr);gap:12px;align-items:start}
.attr .panel{background:#fff;border:1px solid var(--line);border-radius:4px;min-width:0}
.attr .panel>.ph{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;padding:9px 12px;border-bottom:1px solid var(--line2)}
.attr .panel>.ph .n{font-size:11.5px;color:var(--muted)}
.attr .panel>.pb{padding:10px 12px}
.attr .panel>.pb.flush{padding:0}
.attr .panel.alert{border-left:3px solid var(--bad)}
/* tables */
.attr .scroll{overflow:auto;width:0;min-width:100%;max-height:70vh}
.attr table{min-width:0;width:100%;border-collapse:separate;border-spacing:0;font-size:12.5px}
.attr th{background:var(--navy);color:#fff;font-size:11.5px;font-weight:600;text-align:left;padding:7px 9px;white-space:nowrap;position:sticky;top:0;z-index:1}
.attr th.n,.attr td.n{text-align:right}
.attr td{padding:6px 9px;border-bottom:1px solid var(--line2);vertical-align:top;white-space:nowrap;font-variant-numeric:tabular-nums}
.attr td.wrap{white-space:normal;min-width:160px}
.attr tbody tr:nth-child(even) td{background:#fafbfc}
.attr tbody tr:hover td{background:var(--hover)}
.attr tbody tr.pick{cursor:pointer}
.attr tfoot td{background:var(--head);font-weight:600;border-top:2px solid var(--line);border-bottom:0}
.attr .who b{font-weight:600;color:var(--ink)}.attr .who .c{font-family:'IBM Plex Mono',ui-monospace,Consolas,monospace;font-size:11.5px;color:var(--muted);margin-right:6px}
.attr .empty{text-align:center;color:var(--muted);padding:22px 12px}
/* status chips */
.attr .pill{display:inline-block;border-radius:3px;padding:2px 7px;font-size:11px;font-weight:600;background:#e8eef6;color:#33475f;white-space:nowrap}
.attr .pill.present,.attr .pill.approved,.attr .pill.verified,.attr .pill.finalized{background:var(--good-bg);color:var(--good)}
.attr .pill.inside{background:var(--info-bg);color:#17467c}
.attr .pill.absent,.attr .pill.rejected,.attr .pill.disputed{background:var(--bad-bg);color:var(--bad)}
.attr .pill.pending,.attr .pill.draft{background:var(--warn-bg);color:var(--warn)}
.attr .flag{color:var(--bad);font-weight:600}.attr .flagw{color:var(--warn);font-weight:600}
/* attendance-rate bar inside a cell */
.attr .rate{display:inline-flex;align-items:center;gap:6px;justify-content:flex-end;min-width:96px}
.attr .rate .t{width:46px;height:6px;border-radius:2px;background:var(--line2);overflow:hidden}
.attr .rate .t i{display:block;height:100%;background:var(--good)}
.attr .rate.mid .t i{background:var(--warn)}.attr .rate.low .t i{background:var(--bad)}
/* needs attention */
.attr .watch{list-style:none;margin:0;padding:0}
.attr .watch li{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:7px 12px;border-bottom:1px solid var(--line2);font-size:12.5px}
.attr .watch li:last-child{border-bottom:0}
.attr .watch b{min-width:26px;text-align:center;padding:2px 6px;border-radius:3px;background:var(--good-bg);color:var(--good);font-variant-numeric:tabular-nums}
.attr .watch b.hot{background:var(--bad);color:#fff}
.attr .legend{display:flex;flex-wrap:wrap;gap:12px;font-size:11.5px;color:var(--muted)}
@media (max-width:980px){.attr .cols{grid-template-columns:minmax(0,1fr)}}
@media (max-width:820px){.content{padding:12px}#attr-body{padding:12px}.attr .tiles{grid-template-columns:repeat(2,minmax(0,1fr))}.attr input[type=search]{min-width:0;flex:1 1 140px}.attr .bar .sep{display:none}}
@media print{
  .sidebar,.nav,.attr .tabs,.attr .bar,.attr .acts,.attr .segwrap,.topbar .lead{display:none!important}
  .attr{border:0;box-shadow:none}.attr .scroll{max-height:none;overflow:visible;width:auto}
  .attr th{background:#0f2742!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .attr [data-hide-print]{display:none!important}
}
</style>

<div class="topbar">
  <div>
    <h1>Attendance reports</h1>
    <p class="lead">Read-only. Times are factory time (Asia/Karachi). Download any report for Excel, or print it.</p>
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
      else if (k === 'change') n.addEventListener('change', v);
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
  /* head: a list of labels; a label starting with '#' is a figure column (right-aligned) */
  function table(head, rows, draw, opts){
    opts = opts || {};
    var num = head.map(function(h){ return h.charAt(0) === '#'; });
    var tb = el('tbody');
    rows.forEach(function(r){
      var tr = el('tr', { class: opts.click ? 'pick' : null, title: opts.click ? opts.clickTitle || null : null }, draw(r).map(function(c, i){
        var cls = [(opts.wrap || []).indexOf(i) >= 0 ? 'wrap' : '', num[i] ? 'n' : ''].join(' ').trim() || null;
        return el('td', { class: cls }, [c === null || c === undefined ? '—' : (typeof c === 'object' ? c : String(c))]);
      }));
      if (opts.click) tr.addEventListener('click', function(){ opts.click(r); });
      tb.appendChild(tr);
    });
    var parts = [el('thead', {}, [el('tr', {}, head.map(function(h, i){ return el('th', { class: num[i] ? 'n' : null, text: num[i] ? h.slice(1) : h }); }))]), tb];
    if (opts.foot) parts.push(el('tfoot', {}, [el('tr', {}, opts.foot.map(function(c, i){ return el('td', { class: num[i] ? 'n' : null }, [c === null || c === undefined ? '' : (typeof c === 'object' ? c : String(c))]); }))]));
    return el('div', { class: 'scroll', id: opts.id || null }, [el('table', {}, parts)]);
  }
  function hm(iso){ if (!iso) return '—'; var d = new Date(iso); return isNaN(d) ? iso : d.toLocaleTimeString('en-GB', { timeZone: 'Asia/Karachi', hour: '2-digit', minute: '2-digit' }); }
  function dt(iso){ if (!iso) return '—'; var d = new Date(iso); return isNaN(d) ? iso : d.toLocaleString('en-GB', { timeZone: 'Asia/Karachi', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }); }
  function day(v){ var d = new Date(v + 'T00:00:00Z'); return isNaN(d) ? v : d.toLocaleDateString('en-GB', { timeZone: 'UTC', weekday: 'short', day: '2-digit', month: 'short' }); }
  function longDay(v){ var d = new Date(v + 'T00:00:00Z'); return isNaN(d) ? v : d.toLocaleDateString('en-GB', { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }); }
  function mins(m){ m = parseInt(m, 10); if (!m) return m === 0 ? '0' : '—'; return (m >= 60 ? Math.floor(m / 60) + 'h ' : '') + (m % 60) + 'm'; }
  /* Overtime is shown in hours, as it is typed: 1.5 h. */
  function hrs(m){ m = parseInt(m, 10); if (isNaN(m)) return '—'; return (Math.round(m / 60 * 100) / 100) + ' h'; }
  function n0(v){ v = Number(v) || 0; return v.toLocaleString('en-US', { maximumFractionDigits: 2 }); }
  function pct(a, b){ return b ? Math.round(1000 * a / b) / 10 : null; }
  function rate(a, b){
    var p = pct(a, b);
    if (p === null) return el('span', { class: 'muted', text: '—' });
    return el('span', { class: 'rate' + (p < 75 ? ' low' : p < 90 ? ' mid' : ''), title: a + ' of ' + b }, [el('span', { class: 't' }, [el('i', { style: 'width:' + Math.min(100, p) + '%' })]), el('b', { text: p + '%' })]);
  }
  function pill(s, t){ return el('span', { class: 'pill ' + s, text: t || s }); }
  function who(code, name){ return el('span', { class: 'who' }, [el('span', { class: 'c', text: code || '' }), el('b', { text: name || '' })]); }
  /* tile(value, label, colour, id, sub-line, meter %) */
  function tile(v, label, cls, id, sub, meter){
    return el('div', { class: 'tile ' + (cls || ''), id: id || null }, [el('span', { class: 'k', text: label }), typeof v === 'object' ? v : el('b', { text: String(v) }),
      sub ? el('span', { class: 's', text: sub }) : null,
      meter !== undefined && meter !== null ? el('div', { class: 'meter' }, [el('i', { style: 'width:' + Math.min(100, meter) + '%' })]) : null]);
  }
  function panel(title, count, content, extra){
    extra = extra || {};
    return el('section', { class: 'panel' + (extra.alert ? ' alert' : ''), id: extra.id || null }, [
      el('div', { class: 'ph' }, [el('h3', { text: title }), count !== null && count !== undefined ? el('span', { class: 'n', text: count }) : null, extra.right || null]),
      el('div', { class: 'pb' + (extra.flush ? ' flush' : '') }, content)]);
  }
  function none(text, id){ return el('div', { class: 'empty', id: id || null, text: text }); }
  /* segmented switch: show one of several boxes, the rest are hidden (not removed) */
  function seg(items, keep){
    var saved = null; try { saved = localStorage.getItem('attr-seg-' + keep); } catch (e) {}
    var cur = items.some(function(i){ return i.id === saved; }) ? saved : items[0].id;
    var bar = el('div', { class: 'seg', role: 'group' });
    function pick(id){
      cur = id;
      items.forEach(function(i){ i.box.hidden = i.id !== id; i.btn.setAttribute('aria-pressed', i.id === id ? 'true' : 'false'); });
      try { localStorage.setItem('attr-seg-' + keep, id); } catch (e) {}
    }
    items.forEach(function(i){
      i.btn = el('button', { type: 'button', 'data-v': i.id, click: function(){ pick(i.id); } }, [i.label, i.count !== undefined ? el('span', { class: 'c', text: String(i.count) }) : null]);
      bar.appendChild(i.btn);
    });
    pick(cur);
    return { bar: el('div', { class: 'segwrap' }, [bar]), pick: pick, boxes: items.map(function(i){ return i.box; }) };
  }
  function iso(d){ return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function khi(){ return new Date(Date.now() + (5 * 60 + new Date().getTimezoneOffset()) * 60000); }
  function printBtn(){ return el('button', { type: 'button', class: 'act', text: 'Print', click: function(){ window.print(); } }); }

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
      var depts = d.by_department || [];
      var shiftDays = d.came_in + d.absent_with_shift_count;
      var ratePct = pct(d.came_in, d.active_employees);

      var strip = el('div', { class: 'strip' }, [
        el('div', { class: 'when' }, [el('b', { text: longDay(d.today) }), 'updated ' + hm(d.generated_at) + ' · refreshes every minute']),
        el('div', { class: 'acts' }, [el('button', { type: 'button', class: 'act', text: 'Refresh', click: function(){ go('today'); } }), printBtn()])]);

      var tiles = el('div', { class: 'tiles' }, [
        tile(d.inside_now, 'Inside now', 'info', 't-inside', d.entries + ' entr' + (d.entries === 1 ? 'y' : 'ies') + ' today'),
        tile(el('b', {}, [String(d.came_in), el('small', { text: ' / ' + d.active_employees })]), 'Came in today', 'good', 't-came',
          ratePct === null ? 'nobody on the roll' : ratePct + '% of those on the roll', ratePct),
        tile(d.late_count, 'Late', d.late_count ? 'hot' : '', 't-late', d.late_count ? 'came after shift start' : 'everyone on time'),
        tile(d.left_early_count, 'Left early', d.left_early_count ? 'warn' : '', 't-early', 'potential, before shift end'),
        tile(d.absent_with_shift_count, 'Did not come', d.absent_with_shift_count ? 'hot' : '', 't-absent', shiftDays ? 'had a shift · ' + shiftDays + ' with one' : 'had a shift today'),
        tile(d.unconfirmed_entries, 'HOD unconfirmed', d.unconfirmed_entries ? 'warn' : '', 't-unconf', 'entries today'),
        tile(d.auto_out_count, 'Not scanned out', d.auto_out_count ? 'hot' : '', 't-auto', 'system closed at shift end'),
        tile(d.not_in_count, 'No entry today', '', null, 'includes days off')
      ]);

      /* one line per department */
      var tot = depts.reduce(function(a, x){ ['active', 'came_in', 'inside', 'late', 'left_early', 'absent'].forEach(function(k){ a[k] += Number(x[k]) || 0; }); return a; }, { active: 0, came_in: 0, inside: 0, late: 0, left_early: 0, absent: 0 });
      var deptBox = depts.length ? table(['Department', '#On the roll', '#Came in', '#Inside now', '#Late', '#Left early', '#Did not come', '#Attendance'], depts, function(x){
        return [el('b', { text: x.department_name }), x.active, x.came_in, x.inside, x.late ? el('span', { class: 'flag', text: String(x.late) }) : '0',
          x.left_early ? el('span', { class: 'flagw', text: String(x.left_early) }) : '0', x.absent ? el('span', { class: 'flag', text: String(x.absent) }) : '0', rate(x.came_in, x.active)];
      }, { id: 'inside-bars', foot: ['All departments', tot.active, tot.came_in, tot.inside, tot.late, tot.left_early, tot.absent, rate(tot.came_in, tot.active)] }) : none('Nobody is on the roll in your departments.', 'inside-bars');

      /* the people behind each figure: one box at a time */
      var lateBox = d.late.length ? table(['Employee', 'Department', 'Shift starts', 'First IN', '#Late by'], d.late, function(r){ return [who(r.employee_code, r.full_name), r.department_name, hm(r.starts_at), hm(r.first_in), el('span', { class: 'flag', text: mins(r.late_minutes) })]; }) : none('Nobody was late.');
      var earlyBox = d.left_early.length ? table(['Employee', 'Department', 'Last OUT', 'Shift ends', '#Early by'], d.left_early, function(r){ return [who(r.employee_code, r.full_name), r.department_name, hm(r.last_out), hm(r.ends_at), el('span', { class: 'flagw', text: mins(r.early_minutes) })]; }) : none('Nobody left before shift end.');
      var absBox = d.absent_with_shift.length ? table(['Employee', 'Department', 'Shift', 'Starts', 'Ends'], d.absent_with_shift, function(r){ return [who(r.employee_code, r.full_name), r.department_name, r.shift_name || '', hm(r.starts_at), hm(r.ends_at)]; }) : none('Everyone with a shift came in.');
      var notBox = d.not_in.length ? table(['Code', 'Name', 'Department'], d.not_in, function(r){ return [r.employee_code, r.full_name, r.department_name]; }) : none('Everyone on the roll has an entry today.');
      var s = seg([
        { id: 'late', label: 'Late', count: d.late_count, box: lateBox },
        { id: 'early', label: 'Left early', count: d.left_early_count, box: earlyBox },
        { id: 'absent', label: 'Did not come', count: d.absent_with_shift_count, box: absBox },
        { id: 'notin', label: 'No entry today', count: d.not_in_count, box: notBox }
      ], 'today');
      var people = el('section', { class: 'panel' }, [
        el('div', { class: 'ph' }, [el('h3', { text: 'Who is behind the figures' }), s.bar]),
        el('div', { class: 'pb flush' }, s.boxes)]);

      var top = el('div', { class: 'cols' }, [
        el('div', { style: 'display:grid;gap:12px;min-width:0' }, [panel('By department', depts.length + ' department' + (depts.length === 1 ? '' : 's'), [deptBox], { flush: true }), people]),
        d.monitoring ? panel('Needs attention', null, [watch(d.monitoring)], { flush: true }) : panel('How to read this', null, [el('p', { class: 'note', text: 'Came in = scanned IN at the gate today. Attendance = came in ÷ on the roll (days off count as not in). Late and left early are measured against each person’s shift. Left early is only potential: an approved break or permission may explain it.' })])
      ]);

      var auto = d.auto_out_count ? panel('Not scanned out — the system closed these at shift end', d.auto_out_count + ' entr' + (d.auto_out_count === 1 ? 'y' : 'ies') + ', last 36 hours', [
        el('p', { class: 'note', style: 'padding:8px 12px', text: 'Nobody scanned OUT for these people, so the system closed the entry at the shift end (or shift end plus approved overtime). HR can put in the real time on the Reconciliation screen.' }),
        table(['Employee', 'Department', 'Came in', 'System OUT at', 'Closed by the system'], d.auto_out, function(r){ return [who(r.employee_code, r.full_name), r.department_name, dt(r.opened_at), hm(r.closed_at), dt(r.auto_closed_at)]; })
      ], { id: 'auto-out', flush: true, alert: true }) : null;

      show([strip, tiles, auto, top]);
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
    if (m.phones_quiet.length) wrap.appendChild(el('p', { class: 'note', style: 'padding:8px 12px;border-top:1px solid #e7ebf0', text: 'Quiet phones: ' + m.phones_quiet.map(function(p){ return (p.label || 'phone') + ' (last seen ' + (p.last_seen_at ? dt(p.last_seen_at.replace(' ', 'T') + 'Z') : 'never') + ')'; }).join(', ') }));
    if (m.backups) wrap.appendChild(el('p', { class: 'note', style: 'padding:8px 12px;border-top:1px solid #e7ebf0', text: m.backups }));
    return wrap;
  }

  // ------------------------------------------------------------- filters
  var F = null;
  function filters(onShow, withEmp){
    if (!F) { var t = khi(), f = new Date(t); f.setDate(f.getDate() - 6); F = { from: iso(f), to: iso(t), employee: '' }; }
    var from = el('input', { type: 'date', value: F.from, id: 'f-from', 'aria-label': 'First day' });
    var to = el('input', { type: 'date', value: F.to, id: 'f-to', 'aria-label': 'Last day' });
    var emp = withEmp ? el('input', { type: 'search', value: F.employee, id: 'f-emp', placeholder: 'Employee code or name' }) : null;
    function run(){ F.from = from.value; F.to = to.value; F.employee = emp ? emp.value : ''; onShow(); }
    function range(a, b){ return function(){ from.value = iso(a); to.value = iso(b); run(); }; }
    var t0 = khi(), y = new Date(t0); y.setDate(y.getDate() - 1);
    var wk = new Date(t0); wk.setDate(wk.getDate() - ((wk.getDay() + 6) % 7));
    var l7 = new Date(t0); l7.setDate(l7.getDate() - 6);
    var mo = new Date(t0.getFullYear(), t0.getMonth(), 1);
    var pm1 = new Date(t0.getFullYear(), t0.getMonth() - 1, 1), pm2 = new Date(t0.getFullYear(), t0.getMonth(), 0);
    var quick = el('div', { class: 'quick', 'aria-label': 'Quick dates' }, [
      el('button', { type: 'button', text: 'Today', click: range(t0, t0) }),
      el('button', { type: 'button', text: 'Yesterday', click: range(y, y) }),
      el('button', { type: 'button', text: 'This week', click: range(wk, t0) }),
      el('button', { type: 'button', text: 'Last 7 days', click: range(l7, t0) }),
      el('button', { type: 'button', text: 'This month', click: range(mo, t0) }),
      el('button', { type: 'button', text: 'Last month', click: range(pm1, pm2) })]);
    if (emp) emp.addEventListener('keydown', function(e){ if (e.key === 'Enter') run(); });
    var go_ = el('button', { type: 'button', class: 'act primary', id: 'f-show', text: 'Show', click: run });
    return el('div', { class: 'bar' }, [el('span', { class: 'lbl', text: 'Dates' }), from, el('span', { class: 'muted', text: 'to' }), to, quick, emp, go_]);
  }
  function download(route){ return el('a', { class: 'act', id: 'f-csv', href: q(route, { from: F.from, to: F.to, employee: F.employee, format: 'csv' }), text: 'Download (CSV)' }); }
  function rangeText(r){ return r.from === r.to ? longDay(r.from) : day(r.from) + ' – ' + day(r.to) + ' ' + r.to.slice(0, 4); }
  function select(id, label, opts, onChange){
    var s = el('select', { id: id, 'aria-label': label, change: function(){ onChange(s.value); } }, opts.map(function(o){ return el('option', { value: o[0], text: o[1] }); }));
    return s;
  }

  // ----------------------------------------------------------- attendance
  var AF = { dept: '', status: '' };
  function drawAttendance(){
    var bar = filters(drawAttendance, true);
    get('/reports/attendance', F).then(function(r){
      if (!r.ok) return show([bar, el('p', { class: 'err', text: r.error })]);
      var all = r.rows;
      var deptNames = all.map(function(x){ return x.department_name; }).filter(function(v, i, a){ return a.indexOf(v) === i; }).sort();
      if (AF.dept && deptNames.indexOf(AF.dept) < 0) AF.dept = '';
      var holder = el('div', { style: 'display:grid;gap:12px;min-width:0' });
      var narrow = el('div', { class: 'bar' }, [
        el('span', { class: 'lbl', text: 'Narrow' }),
        select('f-dept', 'Department', [['', 'All departments']].concat(deptNames.map(function(n){ return [n, n]; })), function(v){ AF.dept = v; paint(); }),
        select('f-status', 'Status', [['', 'Every line'], ['present', 'Came (worked)'], ['absent', 'Did not come'], ['late', 'Late'], ['early', 'Left early'], ['inside', 'Still inside'], ['auto', 'Not scanned out'], ['unconf', 'Not confirmed by HOD'], ['ot', 'With overtime']], function(v){ AF.status = v; paint(); }),
        el('span', { class: 'sep' }), download('/reports/attendance'), printBtn()]);
      narrow.querySelector('#f-dept').value = AF.dept; narrow.querySelector('#f-status').value = AF.status;

      function keep(x){
        if (AF.dept && x.department_name !== AF.dept) return false;
        switch (AF.status) {
          case 'present': return x.status !== 'absent';
          case 'absent': return x.status === 'absent';
          case 'late': return x.late_minutes > 0;
          case 'early': return x.early_minutes > 0;
          case 'inside': return x.status === 'inside';
          case 'auto': return !!x.auto_out;
          case 'unconf': return x.unconfirmed > 0;
          case 'ot': return x.ot_actual_minutes !== null || x.ot_approved_minutes > 0;
        }
        return true;
      }
      function paint(){
        var rows = all.filter(keep);
        var t = { days: 0, present: 0, absent: 0, mins: 0, late: 0, lateMin: 0, early: 0, earlyMin: 0, unconf: 0, ot: 0, auto: 0, people: {} };
        var byEmp = {}, byDept = {};
        rows.forEach(function(x){
          var came = x.status !== 'absent';
          var k = x.employee_code;
          var e = byEmp[k] || (byEmp[k] = { code: x.employee_code, name: x.full_name, dept: x.department_name, days: 0, present: 0, absent: 0, mins: 0, late: 0, lateMin: 0, early: 0, earlyMin: 0, unconf: 0, ot: 0, auto: 0 });
          var g = byDept[x.department_name] || (byDept[x.department_name] = { dept: x.department_name, people: {}, days: 0, present: 0, absent: 0, mins: 0, late: 0, early: 0, unconf: 0, ot: 0, auto: 0 });
          [t, e, g].forEach(function(a){
            a.days++; if (came) a.present++; else a.absent++;
            a.mins += Number(x.present_minutes) || 0;
            if (x.late_minutes > 0) { a.late++; if (a.lateMin !== undefined) a.lateMin += Number(x.late_minutes); }
            if (x.early_minutes > 0) { a.early++; if (a.earlyMin !== undefined) a.earlyMin += Number(x.early_minutes); }
            a.unconf += Number(x.unconfirmed) || 0;
            a.ot += Number(x.ot_actual_minutes) || 0;
            if (x.auto_out) a.auto++;
          });
          t.people[k] = 1; g.people[k] = 1;
        });
        var nPeople = Object.keys(t.people).length;
        var ratePct = pct(t.present, t.days);
        var tiles = el('div', { class: 'tiles' }, [
          tile(nPeople, 'Employees', 'info', null, t.days + ' shift day' + (t.days === 1 ? '' : 's')),
          tile(t.present, 'Shift days worked', 'good', null, ratePct === null ? '' : ratePct + '% attendance', ratePct),
          tile(t.absent, 'Did not come', t.absent ? 'hot' : '', null, t.days ? pct(t.absent, t.days) + '% of shift days' : ''),
          tile(mins(t.mins), 'Time present', '', null, t.present ? 'average ' + mins(Math.round(t.mins / t.present)) + ' a day' : ''),
          tile(t.late, 'Late days', t.late ? 'hot' : '', null, t.late ? mins(t.lateMin) + ' late in all' : 'no late arrivals'),
          tile(t.early, 'Left early', t.early ? 'warn' : '', null, t.early ? mins(t.earlyMin) + ' early in all' : ''),
          tile(t.unconf, 'HOD unconfirmed', t.unconf ? 'warn' : '', null, 'entries'),
          tile(hrs(t.ot), 'Overtime', '', null, 'approved and checked'),
          tile(t.auto, 'Not scanned out', t.auto ? 'hot' : '', null, 'system OUT at shift end')
        ]);

        var emps = Object.keys(byEmp).map(function(k){ return byEmp[k]; }).sort(function(a, b){ return a.dept.localeCompare(b.dept) || a.name.localeCompare(b.name); });
        var empBox = emps.length ? table(['Employee', 'Department', '#Shift days', '#Worked', '#Did not come', '#Attendance', '#Present', '#Average a day', '#Late', '#Left early', '#HOD unconfirmed', '#OT checked', '#Not scanned out'], emps, function(e){
          return [who(e.code, e.name), e.dept, e.days, e.present, e.absent ? el('span', { class: 'flag', text: String(e.absent) }) : '0', rate(e.present, e.days), mins(e.mins), e.present ? mins(Math.round(e.mins / e.present)) : '—',
            e.late ? el('span', { class: 'flag', text: e.late + ' · ' + mins(e.lateMin) }) : '0', e.early ? el('span', { class: 'flagw', text: e.early + ' · ' + mins(e.earlyMin) }) : '0',
            e.unconf || '0', e.ot ? hrs(e.ot) : '—', e.auto ? el('span', { class: 'flag', text: String(e.auto) }) : '0'];
        }, { click: function(e){ showPerson(e.code); }, clickTitle: 'Show this person’s days',
          foot: ['All employees', '', t.days, t.present, t.absent, rate(t.present, t.days), mins(t.mins), t.present ? mins(Math.round(t.mins / t.present)) : '—', t.late + ' · ' + mins(t.lateMin), t.early + ' · ' + mins(t.earlyMin), t.unconf, t.ot ? hrs(t.ot) : '—', t.auto] }) : none('Nothing in these dates.');

        var deps = Object.keys(byDept).sort().map(function(k){ return byDept[k]; });
        var depBox = deps.length ? table(['Department', '#Employees', '#Shift days', '#Worked', '#Did not come', '#Attendance', '#Present', '#Late days', '#Left early', '#HOD unconfirmed', '#OT checked', '#Not scanned out'], deps, function(g){
          return [el('b', { text: g.dept }), Object.keys(g.people).length, g.days, g.present, g.absent ? el('span', { class: 'flag', text: String(g.absent) }) : '0', rate(g.present, g.days), mins(g.mins),
            g.late ? el('span', { class: 'flag', text: String(g.late) }) : '0', g.early ? el('span', { class: 'flagw', text: String(g.early) }) : '0', g.unconf || '0', g.ot ? hrs(g.ot) : '—', g.auto || '0'];
        }, { click: function(g){ AF.dept = g.dept; narrow.querySelector('#f-dept').value = g.dept; paint(); }, clickTitle: 'Show only this department',
          foot: ['All departments', nPeople, t.days, t.present, t.absent, rate(t.present, t.days), mins(t.mins), t.late, t.early, t.unconf, t.ot ? hrs(t.ot) : '—', t.auto] }) : none('Nothing in these dates.');

        var lineBox = rows.length ? table(['Day', 'Employee', 'Department', 'Shift', 'First IN', 'Last OUT', '#Entries', '#Present', '#Late', '#Left early', '#HOD unconfirmed', '#OT checked', 'Status'], rows, function(x){
          return [day(x.work_date), who(x.employee_code, x.full_name), x.department_name, x.shift_name + ' ' + hm(x.starts_at) + '–' + hm(x.ends_at) + (x.shift_source === 'default_auto' ? ' (default)' : ''),
            hm(x.first_in), x.auto_out ? el('span', { class: 'flag', title: 'No OUT was scanned. The system closed the entry at shift end.', text: hm(x.last_out) + ' · system, not scanned' }) : hm(x.last_out), x.entries, mins(x.present_minutes),
            x.late_minutes ? el('span', { class: 'flag', text: mins(x.late_minutes) }) : '', x.early_minutes ? el('span', { class: 'flagw', text: mins(x.early_minutes) }) : '', x.unconfirmed || '',
            x.ot_actual_minutes !== null ? hrs(x.ot_actual_minutes) : (x.ot_approved_minutes ? 'approved ' + hrs(x.ot_approved_minutes) : ''),
            pill(x.status, x.status === 'absent' ? 'did not come' : x.status)];
        }) : none(all.length ? 'No line matches the filters.' : 'Nothing in these dates.', 'att-none');

        var s = seg([
          { id: 'emp', label: 'By employee', count: emps.length, box: empBox },
          { id: 'dept', label: 'By department', count: deps.length, box: depBox },
          { id: 'lines', label: 'Day by day', count: rows.length, box: lineBox }
        ], 'att');
        holder.textContent = '';
        [tiles,
          el('section', { class: 'panel' }, [el('div', { class: 'ph' }, [el('h3', { text: 'Attendance · ' + rangeText(r) + (AF.dept ? ' · ' + AF.dept : '') + (F.employee ? ' · “' + F.employee + '”' : '') }), s.bar]), el('div', { class: 'pb flush' }, s.boxes)]),
          el('p', { class: 'note', text: 'Attendance = shift days worked ÷ shift days. Present = time between IN and OUT; an entry still open shows as "inside". HOD confirmation times never count as an IN. Overtime is only what was approved and checked the next day. Click a person to see their days; click a department to show only it.' }),
          r.truncated ? el('p', { class: 'err', text: 'Only the first 20000 lines are shown. Choose fewer days.' }) : null
        ].forEach(function(n){ if (n) holder.appendChild(n); });
      }
      paint();
      show([bar, narrow, holder]);
    });
  }
  /* clicking a person: their own days, day by day */
  function showPerson(code){ F.employee = code; try { localStorage.setItem('attr-seg-att', 'lines'); } catch (e) {} drawAttendance(); }

  // ------------------------------------------------------------ movements
  var MF = { type: '' };
  function drawMovements(){
    var bar = filters(drawMovements, true);
    get('/reports/movements', F).then(function(r){
      if (!r.ok) return show([bar, el('p', { class: 'err', text: r.error })]);
      function kind(x){ return { GATE_IN: 'in', ADMIN_IN: 'added', AUTO_OUT: 'system', FIXED_OUT: 'corrected' }[x.event_type] || 'out'; }
      var c = { in: 0, out: 0, system: 0, added: 0, corrected: 0, later: 0, unconf: 0, edited: 0 };
      r.rows.forEach(function(x){
        c[kind(x)]++;
        if (x.source === 'offline_replay') c.later++;
        if (x.edited) c.edited++;
        if ((x.event_type === 'GATE_IN' || x.event_type === 'ADMIN_IN') && !x.hod_confirmed_at) c.unconf++;
      });
      var holder = el('div', { style: 'display:grid;gap:12px;min-width:0' });
      var narrow = el('div', { class: 'bar' }, [el('span', { class: 'lbl', text: 'Narrow' }),
        select('f-type', 'Kind of scan', [['', 'Every scan'], ['in', 'IN (gate)'], ['out', 'OUT (gate)'], ['system', 'OUT · system'], ['corrected', 'OUT · corrected'], ['added', 'IN · added'], ['later', 'Sent later'], ['unconf', 'IN not yet confirmed by HOD']], function(v){ MF.type = v; paint(); }),
        el('span', { class: 'sep' }), download('/reports/movements'), printBtn()]);
      narrow.querySelector('#f-type').value = MF.type;
      function paint(){
        var rows = r.rows.filter(function(x){
          if (!MF.type) return true;
          if (MF.type === 'later') return x.source === 'offline_replay';
          if (MF.type === 'unconf') return (x.event_type === 'GATE_IN' || x.event_type === 'ADMIN_IN') && !x.hod_confirmed_at;
          return kind(x) === MF.type;
        });
        holder.textContent = '';
        holder.appendChild(el('div', { class: 'tiles' }, [
          tile(r.rows.length, 'Scans', 'info', null, rangeText(r)),
          tile(c.in + c.added, 'IN', 'good', null, c.added ? c.added + ' added by HR / admin' : 'all at the gate'),
          tile(c.out + c.system + c.corrected, 'OUT', '', null, c.corrected ? c.corrected + ' corrected' : ''),
          tile(c.system, 'OUT · system', c.system ? 'hot' : '', null, 'nobody scanned OUT'),
          tile(c.later, 'Sent later', c.later ? 'warn' : '', null, 'phone had no signal'),
          tile(c.unconf, 'HOD unconfirmed', c.unconf ? 'warn' : '', null, ''),
          tile(c.edited, 'Time corrected', c.edited ? 'warn' : '', null, 'by the administrator')]));
        holder.appendChild(el('section', { class: 'panel' }, [el('div', { class: 'ph' }, [el('h3', { text: 'Every IN and OUT' }), el('span', { class: 'n', text: rows.length + ' of ' + r.rows.length + ' scan' + (r.rows.length === 1 ? '' : 's') })]),
          el('div', { class: 'pb flush' }, [rows.length ? table(['Time', '', 'Employee', 'Department', 'Entry', 'Recorded by', 'Phone', 'Sent', 'HOD confirmed'], rows, function(x){
            var auto = x.event_type === 'AUTO_OUT' || x.event_type === 'FIXED_OUT' || x.event_type === 'ADMIN_IN';
            var isIn = x.event_type === 'GATE_IN' || x.event_type === 'ADMIN_IN';
            var label = { GATE_IN: 'IN', ADMIN_IN: 'IN · added', AUTO_OUT: 'OUT · system', FIXED_OUT: 'OUT · corrected' }[x.event_type] || 'OUT';
            var when = x.edited ? el('span', {}, [dt(x.occurred_at), el('span', { class: 'flag', style: 'display:block;font-size:11.5px', text: 'corrected · scanned ' + dt(x.scanned_at) })]) : dt(x.occurred_at);
            return [when, pill(isIn ? 'present' : (auto ? 'absent' : 'pending'), label), who(x.employee_code, x.full_name), x.department_name,
              x.entry_sequence ? '#' + x.entry_sequence : '', auto ? el('span', { class: 'flag', text: x.recorded_by }) : x.recorded_by, x.phone || '', auto ? 'not scanned' : (x.source === 'offline_replay' ? 'later' : 'at once'),
              isIn ? (x.hod_confirmed_at ? hm(x.hod_confirmed_at) + ' · ' + (x.hod_name || '') : el('span', { class: 'flag', text: 'not yet' })) : ''];
          }) : none(r.rows.length ? 'No scan matches the filter.' : 'No scans in these dates.', 'mov-none')])]));
        holder.appendChild(el('p', { class: 'note', text: '"Later" means the phone had no signal and sent it afterwards; the time shown is when it was scanned. "OUT · system": nobody scanned OUT and the system closed the entry at shift end. "OUT · corrected" / "IN · added": put in by HR or the administrator. "corrected": the administrator changed the time; the scanned time is shown under it.' }));
      }
      paint();
      show([bar, narrow, holder]);
    });
  }

  // ------------------------------------------------------------- overtime
  function drawOvertime(){
    var bar = filters(drawOvertime, false);
    get('/reports/overtime', F).then(function(r){
      if (!r.ok) return show([bar, el('p', { class: 'err', text: r.error })]);
      var t = r.by_department.reduce(function(a, b){
        a.requested += Number(b.requested) || 0; a.approved += Number(b.approved) || 0; a.rejected += Number(b.rejected) || 0;
        a.am += Number(b.approved_minutes) || 0; a.cm += Number(b.actual_minutes) || 0; a.fin += Number(b.finalized_amount) || 0; a.draft += Number(b.draft_amount) || 0; return a;
      }, { requested: 0, approved: 0, rejected: 0, am: 0, cm: 0, fin: 0, draft: 0 });
      var narrow = el('div', { class: 'bar' }, [el('span', { class: 'lbl', text: rangeText(r) }), el('span', { class: 'sep' }), download('/reports/overtime'), printBtn()]);
      show([bar, narrow,
        el('div', { class: 'tiles' }, [
          tile(t.requested, 'Overtime asked', 'info', null, r.rows.length + ' line' + (r.rows.length === 1 ? '' : 's')),
          tile(t.approved, 'Approved', 'good', null, t.requested ? pct(t.approved, t.requested) + '% of asked' : ''),
          tile(t.rejected, 'Rejected', t.rejected ? 'hot' : '', null, ''),
          tile(hrs(t.am), 'Approved time', '', null, ''),
          tile(hrs(t.cm), 'Checked time', 'good', null, 'checked the next day'),
          tile(n0(t.fin), 'Finalized pay', '', null, 'Rs'),
          tile(n0(t.draft), 'Not finalized yet', t.draft ? 'warn' : '', null, 'Rs')]),
        panel('By department', r.by_department.length + ' department' + (r.by_department.length === 1 ? '' : 's'), [
          r.by_department.length ? table(['Department', '#Asked', '#Approved', '#Rejected', '#Approved time', '#Checked time', '#Finalized pay', '#Not finalized yet'], r.by_department, function(b){
            return [b.department_name, b.requested, b.approved, b.rejected, hrs(b.approved_minutes), hrs(b.actual_minutes), b.finalized_amount, b.draft_amount];
          }, { foot: ['All departments', t.requested, t.approved, t.rejected, hrs(t.am), hrs(t.cm), n0(t.fin), n0(t.draft)] }) : none('No overtime in these dates.', 'ot-none')], { flush: true }),
        r.rows.length ? panel('Every line', r.rows.length, [table(['Day', 'Employee', 'Department', 'Reason', '#Asked', 'Decision', '#Approved', 'Checked', 'Rate', '#Amount', 'Payroll'], r.rows, function(x){
          return [day(x.work_date), who(x.employee_code, x.full_name), x.department_name, x.reason, hrs(x.estimated_minutes), pill(x.approval_status), x.approved_minutes ? hrs(x.approved_minutes) : '',
            x.verification_status === 'verified' ? hrs(x.actual_minutes) : pill(x.verification_status), x.rate_type ? x.rate_type + ' ' + x.manual_rate : '', x.amount || '', pill(x.payroll_status === 'none' ? 'pending' : x.payroll_status, x.payroll_status)];
        }, { wrap: [3] })], { flush: true }) : null,
        r.truncated ? el('p', { class: 'err', text: 'Only the first 20000 lines are shown. Choose fewer days.' }) : null]);
    });
  }

  var first = (location.hash || '').slice(1);
  go(TABS.some(function(t){ return t.id === first; }) ? first : 'today');
})();
</script>
<?php page_footer(); ?>
