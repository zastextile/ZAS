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
.attr .tile .k{display:block;font-size:11.5px;font-weight:600;color:var(--muted);line-height:1.3}
.attr .tile b{display:block;font-size:22px;font-weight:600;line-height:1.2;color:var(--navy);font-variant-numeric:tabular-nums;margin-top:2px}
.attr .tile b small{font-size:12.5px;font-weight:600;color:var(--muted)}
.attr .tile .s{display:block;font-size:11.5px;line-height:1.3;color:var(--muted);margin-top:2px}
.attr .tile.good{border-top-color:var(--good)}.attr .tile.good b{color:var(--good)}
.attr .tile.hot{border-top-color:var(--bad)}.attr .tile.hot b{color:var(--bad)}
.attr .tile.warn{border-top-color:var(--warn)}.attr .tile.warn b{color:var(--warn)}
.attr .tile.info{border-top-color:var(--pri)}
.attr .tile .meter{height:5px;border-radius:2px;background:var(--line2);overflow:hidden;margin-top:6px}
.attr .tile .meter i{display:block;height:100%;background:var(--good)}
/* panels */
.attr .cols{display:grid;grid-template-columns:minmax(0,2.1fr) minmax(260px,1fr);gap:12px;align-items:start}
.attr .panel{background:#fff;border:1px solid var(--line);border-radius:4px;min-width:0}
.attr .panel>.ph{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;padding:9px 12px;border-bottom:1px solid var(--line2)}
.attr .panel>.ph .n{font-size:11.5px;color:var(--muted)}
.attr .panel>.pb{padding:10px 12px}
.attr .panel>.pb.flush{padding:0}
.attr .panel.alert{border-left:3px solid var(--bad)}
.attr .agrp+.agrp{border-top:2px solid var(--line)}
.attr .agh{display:flex;gap:10px;align-items:baseline;padding:9px 12px 7px;background:var(--head);border-top:1px solid var(--line2)}
.attr .agh b{font-size:12.5px;color:var(--navy)}.attr .agh .m{font-size:11.5px;color:var(--muted)}
.attr .agrp[data-day=today] .agh b{color:var(--bad)}
/* tables */
/* tables fit the panel: text columns wrap instead of pushing the page sideways; no box-in-a-box scrolling.
   A phone still slides a wide table inside its own box. */
.attr .scroll{overflow-x:auto;overflow-y:hidden;width:0;min-width:100%}
.attr table{min-width:0;width:100%;border-collapse:separate;border-spacing:0;font-size:12.5px}
.attr th{background:var(--navy);color:#fff;font-size:11.5px;font-weight:600;line-height:1.25;text-align:left;padding:8px 7px;white-space:normal;vertical-align:bottom}
.attr th.n,.attr td.n{text-align:right}
.attr td{padding:6px 7px;border-bottom:1px solid var(--line2);vertical-align:top;white-space:nowrap;font-variant-numeric:tabular-nums}
.attr td.wrap{white-space:normal;min-width:96px;overflow-wrap:break-word}
.attr td .sub{display:block;font-size:11.5px;color:var(--muted);white-space:nowrap}
.attr tbody tr:nth-child(even) td{background:#fafbfc}
.attr tbody tr:hover td{background:var(--hover)}
.attr tbody tr.pick{cursor:pointer}
.attr th:last-child,.attr td:last-child{padding-right:12px}
.attr th:first-child,.attr td:first-child{padding-left:12px}
/* the department table on Today fits its box: tighter cells */
.attr .fit th,.attr .fit td{padding-left:7px;padding-right:7px}
.attr .fit th:first-child,.attr .fit td:first-child{padding-left:12px}
.attr .fit th:last-child,.attr .fit td:last-child{padding-right:12px}
.attr tfoot td{background:var(--head);font-weight:600;border-top:2px solid var(--line);border-bottom:0}
.attr .who b{font-weight:600;color:var(--ink)}.attr .who .c{display:block;font-family:'IBM Plex Mono',ui-monospace,Consolas,monospace;font-size:11px;color:var(--muted);line-height:1.3}
.attr .empty{text-align:center;color:var(--muted);padding:22px 12px}
/* status chips */
.attr .pill{display:inline-block;border-radius:3px;padding:2px 6px;font-size:11px;font-weight:600;background:#e8eef6;color:#33475f;white-space:nowrap}
.attr .pill.present,.attr .pill.approved,.attr .pill.verified,.attr .pill.finalized{background:var(--good-bg);color:var(--good)}
.attr .pill.inside{background:var(--info-bg);color:#17467c}
.attr .pill.absent,.attr .pill.rejected,.attr .pill.disputed{background:var(--bad-bg);color:var(--bad)}
.attr .pill.pending,.attr .pill.draft{background:var(--warn-bg);color:var(--warn)}
.attr .flag{color:var(--bad);font-weight:600}.attr .flagw{color:var(--warn);font-weight:600}
/* attendance-rate bar inside a cell */
.attr .rate{display:inline-flex;align-items:center;gap:6px;justify-content:flex-end;min-width:84px}
.attr .rate .t{width:38px;height:6px;border-radius:2px;background:var(--line2);overflow:hidden}
.attr .rate .t i{display:block;height:100%;background:var(--good)}
.attr .rate.mid .t i{background:var(--warn)}.attr .rate.low .t i{background:var(--bad)}
/* needs attention */
.attr .watch{list-style:none;margin:0;padding:0}
.attr .watch li{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:7px 12px;border-bottom:1px solid var(--line2);font-size:12.5px}
.attr .watch li:last-child{border-bottom:0}
.attr .watch b{min-width:26px;text-align:center;padding:2px 6px;border-radius:3px;background:var(--good-bg);color:var(--good);font-variant-numeric:tabular-nums}
.attr .watch b.hot{background:var(--bad);color:#fff}
.attr .legend{display:flex;flex-wrap:wrap;gap:12px;font-size:11.5px;color:var(--muted)}
/* Insights: charts (one hue for counts; green/amber reserved for on time / late, always with a legend) */
.attr .cols2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.attr .cols3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.attr svg.chart{display:block;width:100%;height:auto;padding:8px 10px 0}
.attr svg.chart .grid{stroke:var(--line2);stroke-width:1}.attr svg.chart .base{stroke:#b9c4d0;stroke-width:1}
.attr svg.chart .ax{font:500 10.5px var(--ff);fill:var(--muted)}
.attr svg.chart .bar.p{fill:var(--pri)}.attr svg.chart .bar.late{fill:#d97706}
.attr svg.chart .hit{fill:transparent;cursor:default}.attr svg.chart .hit:hover{fill:rgba(10,95,180,.06)}
.attr .panel .note{padding:4px 12px 10px}
.attr .clegend{display:flex;flex-wrap:wrap;gap:14px;padding:4px 12px 10px;font-size:11.5px;color:var(--muted)}
.attr .clegend .sw{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:-1px}
.attr .clegend .sw.p{background:var(--pri)}.attr .clegend .sw.late{background:#d97706}
.ctip{position:fixed;z-index:999;pointer-events:none;background:#0f2742;color:#fff;border-radius:4px;padding:6px 9px;font:400 12px/1.4 'IBM Plex Sans','Segoe UI',system-ui,sans-serif;box-shadow:0 6px 16px rgba(15,39,66,.25);max-width:260px}
.ctip b{font-weight:600}
.attr .wl{list-style:none;margin:0;padding:0}
.attr .lefttog{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--ink);white-space:nowrap;cursor:pointer}
.attr .lefttog input{width:15px;height:15px;margin:0;min-height:0}
.attr .wl li{display:flex;justify-content:space-between;gap:10px;align-items:baseline;padding:7px 12px;border-bottom:1px solid var(--line2);font-size:12.5px;cursor:pointer}
.attr .wl li:hover{background:var(--hover)}.attr .wl li:last-child{border-bottom:0}
.attr .wl .v{font-weight:600;color:var(--bad);white-space:nowrap;font-variant-numeric:tabular-nums}
/* Monthly register */
.attr table.reg{font-size:12px;border-collapse:separate;border-spacing:0;width:auto;min-width:100%}
.attr table.reg th,.attr table.reg td{padding:4px 0;text-align:center;border-bottom:1px solid var(--line2);white-space:nowrap}
.attr table.reg th.d,.attr table.reg td.d{width:24px;min-width:24px;max-width:24px;padding-left:0;padding-right:0;border-left:1px solid var(--line2)}
.attr table.reg th.d span{display:block;font-size:9.5px;font-weight:500;opacity:.75}
.attr table.reg th.sun{background:#24405f}.attr table.reg td.sun{background:#f6f7f9}
.attr table.reg th.fut{opacity:.55}
.attr table.reg .who{text-align:left;padding:4px 10px 4px 12px;position:sticky;left:0;z-index:2;background:#fff;min-width:150px;max-width:190px;white-space:normal;line-height:1.25;border-right:1px solid var(--line)}
.attr table.reg th.who{background:var(--navy);z-index:3}
.attr table.reg .who .c{display:block;font:500 10.5px 'IBM Plex Mono',ui-monospace,monospace;color:var(--muted)}
.attr table.reg .tot{padding:4px 7px;min-width:40px;text-align:right;font-variant-numeric:tabular-nums}
.attr table.reg th.tot{padding-right:7px!important}
.attr table.reg td.kP{color:var(--good);font-weight:600}
.attr table.reg td.kL{background:var(--warn-bg)!important;color:var(--warn);font-weight:700}
.attr table.reg td.kA{background:var(--bad-bg)!important;color:var(--bad);font-weight:700}
.attr table.reg td.kI{background:var(--info-bg)!important;color:#17467c;font-weight:700}
.attr table.reg td.off{color:#b9c4d0}
.attr table.reg tr.grp td{background:var(--head)!important;text-align:left;font-weight:600;color:var(--navy);padding:5px 12px;position:sticky;left:0}
.attr table.reg tbody tr:hover td{background:var(--hover)}
.attr table.reg tfoot td{font-weight:600;background:var(--head)}
.attr .reglegend{display:flex;flex-wrap:wrap;gap:12px;font-size:11.5px;color:var(--muted)}
.attr .reglegend i{display:inline-block;min-width:18px;text-align:center;font-style:normal;font-weight:700;border-radius:3px;margin-right:4px;padding:0 3px}
.attr .reglegend i.kP{color:var(--good)}.attr .reglegend i.kL{background:var(--warn-bg);color:var(--warn)}.attr .reglegend i.kA{background:var(--bad-bg);color:var(--bad)}.attr .reglegend i.kI{background:var(--info-bg);color:#17467c}.attr .reglegend i.off{color:#b9c4d0}
/* ---------------- Live: faces ---------------- */
.attr .live-head .when{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.attr .livedot{width:9px;height:9px;border-radius:50%;background:#16a34a;box-shadow:0 0 0 0 rgba(22,163,74,.6);animation:lvp 2s infinite}
@keyframes lvp{0%{box-shadow:0 0 0 0 rgba(22,163,74,.55)}70%{box-shadow:0 0 0 8px rgba(22,163,74,0)}100%{box-shadow:0 0 0 0 rgba(22,163,74,0)}}
@media (prefers-reduced-motion:reduce){.attr .livedot{animation:none}}
.attr .clk{font:600 15px 'IBM Plex Mono',ui-monospace,monospace;color:var(--navy);font-variant-numeric:tabular-nums}
.attr .live-k .tile b{display:flex;align-items:center;gap:6px}
.attr .arw{font-style:normal;font-size:16px;line-height:1;width:22px;height:22px;border-radius:50%;display:inline-grid;place-items:center}
.attr .arw.in{background:var(--good-bg);color:var(--good)}.attr .arw.out{background:var(--warn-bg);color:var(--warn)}
.attr .liverow{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,1fr) minmax(220px,.7fr);gap:12px;align-items:stretch}
.attr .flow{display:flex;flex-wrap:wrap;gap:10px 6px;padding:12px}
.attr .pf{display:flex;flex-direction:column;align-items:center;text-align:center;width:84px;min-width:0}
.attr .pf.big{width:96px}
.attr .pf b{font-size:12px;font-weight:600;line-height:1.25;margin-top:6px;max-width:100%;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.attr .pf .m{font-size:11px;color:var(--muted);white-space:nowrap;max-width:100%;overflow:hidden;text-overflow:ellipsis}
.attr .av{position:relative;border-radius:50%;background:#e3eaf3 center/cover no-repeat;display:grid;place-items:center;color:#33475f;font-weight:600;box-shadow:0 0 0 3px #fff,0 0 0 5px var(--line)}
.attr .av.s52{width:52px;height:52px;font-size:16px}.attr .av.s64{width:64px;height:64px;font-size:19px}
.attr .av.has span{visibility:hidden}
.attr .av.g{box-shadow:0 0 0 3px #fff,0 0 0 5px #16a34a}.attr .av.o{box-shadow:0 0 0 3px #fff,0 0 0 5px #d97706}
.attr .av.b{box-shadow:0 0 0 3px #fff,0 0 0 5px #0a5fb4}.attr .av.x{box-shadow:0 0 0 3px #fff,0 0 0 5px #b9c4d0;filter:grayscale(.35)}
.attr .more{align-self:center;font-weight:600;color:var(--muted);padding:0 8px}
.attr .livering .pb{display:flex;flex-direction:column;align-items:center;gap:8px}
.attr svg.ring{width:150px;height:150px}
.attr svg.ring .trk{fill:none;stroke:var(--line2);stroke-width:14}
.attr svg.ring .val{fill:none;stroke:#16a34a;stroke-width:14;stroke-linecap:round}
.attr svg.ring .big{font:600 26px var(--ff);fill:var(--navy)}.attr svg.ring .sm{font:500 11px var(--ff);fill:var(--muted)}
.attr .rleg{display:grid;gap:4px;font-size:12.5px;width:100%}
.attr .rleg span{display:flex;align-items:center;gap:6px}.attr .rleg b{margin-left:auto;font-variant-numeric:tabular-nums}
.attr .rleg .sw{display:inline-block;width:10px;height:10px;border-radius:2px}.attr .rleg .sw.p{background:#16a34a}.attr .rleg .sw.n{background:var(--line2);box-shadow:inset 0 0 0 1px #b9c4d0}.attr .rleg .sw.i{background:var(--pri)}
.attr .chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.attr .chips button{border:1px solid #b9c4d0;background:#fff;border-radius:99px;padding:5px 11px;font:600 12px var(--ff);color:var(--ink);cursor:pointer}
.attr .chips button .c{margin-left:6px;color:var(--muted);font-weight:500}
.attr .chips button[aria-pressed=true]{background:var(--pri);border-color:var(--pri);color:#fff}.attr .chips button[aria-pressed=true] .c{color:#dbe9f8}
.attr .facegrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(96px,1fr));gap:14px 8px;justify-items:center}
.attr .ph input[type=search]{min-width:0;width:220px;max-width:100%}
.attr .dstrip{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;padding:12px}
.attr .dcard{border:1px solid var(--line);border-radius:4px;padding:9px 11px;display:grid;gap:3px}
.attr .dcard .big{font-size:22px;font-weight:600;color:var(--navy);font-variant-numeric:tabular-nums}.attr .dcard .m{font-size:11.5px;color:var(--muted)}
.attr .dcard .meter{height:5px;border-radius:2px;background:var(--line2);overflow:hidden}.attr .dcard .meter i{display:block;height:100%;background:#16a34a}
.attr details.gonebox summary{cursor:pointer;list-style:none}.attr details.gonebox summary::-webkit-details-marker{display:none}
.attr details.gonebox summary h3::before{content:'▸ ';color:var(--muted)}.attr details.gonebox[open] summary h3::before{content:'▾ '}
@media (max-width:980px){.attr .liverow{grid-template-columns:minmax(0,1fr)}}
/* wall screen: the same page, dark, full screen, for a TV */
.attr.wall{--ink:#e6edf6;--muted:#9fb1c7;--line:#2a3f5c;--line2:#203450;--head:#13243b;--hover:#1a2f4b;--navy:#e6edf6;--good-bg:#12351f;--warn-bg:#3a2a10;--bad-bg:#3b1414;--info-bg:#132c4b;
  background:#0b1726;color:var(--ink);border-color:#0b1726;min-height:100vh;overflow:auto}
.attr.wall .tabs{display:none}
.attr.wall .panel,.attr.wall .tile,.attr.wall .dcard{background:#10213a;border-color:#22385a}
.attr.wall .tile b,.attr.wall .clk,.attr.wall svg.ring .big{color:#fff;fill:#fff}
.attr.wall .av{background-color:#1d3354;color:#cfe0f5;box-shadow:0 0 0 3px #10213a,0 0 0 5px #2a3f5c}
.attr.wall .av.g{box-shadow:0 0 0 3px #10213a,0 0 0 5px #22c55e}.attr.wall .av.o{box-shadow:0 0 0 3px #10213a,0 0 0 5px #f59e0b}
.attr.wall .av.b{box-shadow:0 0 0 3px #10213a,0 0 0 5px #3b8ee8}.attr.wall .av.x{box-shadow:0 0 0 3px #10213a,0 0 0 5px #4b607d}
.attr.wall .act{background:#10213a;color:#cfe0f5;border-color:#2a3f5c}
.attr.wall .chips button{background:#10213a;color:#e6edf6;border-color:#2a3f5c}.attr.wall .chips button[aria-pressed=true]{background:#3b8ee8;border-color:#3b8ee8}
.attr.wall input{background:#10213a;color:#e6edf6;border-color:#2a3f5c}
.attr.wall .note{display:none}
@media print{.attr table.reg{font-size:9px}.attr table.reg th.d,.attr table.reg td.d{width:18px;min-width:18px}.attr .reg .who{position:static}.ctip{display:none}@page{size:landscape;margin:8mm}}
@media (max-width:980px){.attr .cols{grid-template-columns:minmax(0,1fr)}.attr .cols2,.attr .cols3{grid-template-columns:minmax(0,1fr)}}
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

<script src="assets/js/sorttable.js?v=1"></script>
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
    var keys = opts.keys || {};
    rows.forEach(function(r){
      var tr = el('tr', { class: opts.click ? 'pick' : null, title: opts.click ? opts.clickTitle || null : null }, draw(r).map(function(c, i){
        var cls = [(opts.wrap || []).indexOf(i) >= 0 ? 'wrap' : '', num[i] ? 'n' : ''].join(' ').trim() || null;
        /* data-sort: what the column sorts by when the text alone would sort wrongly (a date, "1 · 30m") */
        var k = keys[i] ? keys[i](r) : null;
        return el('td', { class: cls, 'data-sort': k === null || k === undefined ? null : String(k) }, [c === null || c === undefined ? '—' : (typeof c === 'object' ? c : String(c))]);
      }));
      if (opts.click) tr.addEventListener('click', function(){ opts.click(r); });
      tb.appendChild(tr);
    });
    var parts = [el('thead', {}, [el('tr', {}, head.map(function(h, i){ return el('th', { class: num[i] ? 'n' : null, text: num[i] ? h.slice(1) : h }); }))]), tb];
    if (opts.foot) parts.push(el('tfoot', {}, [el('tr', {}, opts.foot.map(function(c, i){ return el('td', { class: num[i] ? 'n' : null }, [c === null || c === undefined ? '' : (typeof c === 'object' ? c : String(c))]); }))]));
    return el('div', { class: 'scroll' + (opts.cls ? ' ' + opts.cls : ''), id: opts.id || null }, [el('table', { class: 'sortable' }, parts)]);
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
  var TABS = [{ id: 'today', label: 'Today', draw: drawToday }, { id: 'live', label: 'Live', draw: drawLive }, { id: 'ins', label: 'Insights', draw: drawInsights }, { id: 'att', label: 'Attendance', draw: drawAttendance },
    { id: 'reg', label: 'Monthly register', draw: drawRegister }, { id: 'mov', label: 'Movements', draw: drawMovements }];
  if (CAN_OT) TABS.push({ id: 'ot', label: 'Overtime', draw: drawOvertime });
  function go(id){
    if (timer) { clearInterval(timer); timer = null; }
    if (clockTimer) { clearInterval(clockTimer); clockTimer = null; }
    if (id !== 'live') document.getElementById('attr').classList.remove('wall');
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
      var deptBox = depts.length ? table(['Department', '#On roll', '#Came in', '#Inside', '#Late', '#Left early', '#Absent', '#Attendance'], depts, function(x){
        return [el('b', { text: x.department_name }), x.active, x.came_in, x.inside, x.late ? el('span', { class: 'flag', text: String(x.late) }) : '0',
          x.left_early ? el('span', { class: 'flagw', text: String(x.left_early) }) : '0', x.absent ? el('span', { class: 'flag', text: String(x.absent) }) : '0', rate(x.came_in, x.active)];
      }, { id: 'inside-bars', cls: 'fit', keys: { 7: function(x){ return x.active ? x.came_in / x.active : ''; } }, foot: ['All departments', tot.active, tot.came_in, tot.inside, tot.late, tot.left_early, tot.absent, rate(tot.came_in, tot.active)] }) : none('Nobody is on the roll in your departments.', 'inside-bars');

      /* the people behind each figure: one box at a time */
      var lateBox = d.late.length ? table(['Employee', 'Department', 'Shift starts', 'First IN', '#Late by'], d.late, function(r){ return [who(r.employee_code, r.full_name), r.department_name, hm(r.starts_at), hm(r.first_in), el('span', { class: 'flag', text: mins(r.late_minutes) })]; }, { wrap: [0, 1], keys: { 0: function(r){ return r.full_name; }, 2: function(r){ return r.starts_at; }, 3: function(r){ return r.first_in; } } }) : none('Nobody was late.');
      var earlyBox = d.left_early.length ? table(['Employee', 'Department', 'Last OUT', 'Shift ends', '#Early by'], d.left_early, function(r){ return [who(r.employee_code, r.full_name), r.department_name, hm(r.last_out), hm(r.ends_at), el('span', { class: 'flagw', text: mins(r.early_minutes) })]; }, { wrap: [0, 1], keys: { 0: function(r){ return r.full_name; }, 2: function(r){ return r.last_out; }, 3: function(r){ return r.ends_at; } } }) : none('Nobody left before shift end.');
      var absBox = d.absent_with_shift.length ? table(['Employee', 'Department', 'Shift', 'Starts', 'Ends'], d.absent_with_shift, function(r){ return [who(r.employee_code, r.full_name), r.department_name, r.shift_name || '', hm(r.starts_at), hm(r.ends_at)]; }, { wrap: [0, 1], keys: { 0: function(r){ return r.full_name; } } }) : none('Everyone with a shift came in.');
      var notBox = d.not_in.length ? table(['Code', 'Name', 'Department'], d.not_in, function(r){ return [r.employee_code, r.full_name, r.department_name]; }, { wrap: [1, 2] }) : none('Everyone on the roll has an entry today.');
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
      ].concat(autoGroups(d)), { id: 'auto-out', flush: true, alert: true }) : null;

      show([strip, tiles, auto, top]);
    });
    timer = setInterval(function(){ if (!document.hidden) drawToday(); }, 60000);
  }
  /* Not scanned out, in groups by the day of the shift: Today first, then
     From yesterday (the evening's forgotten exits), then anything older
     still inside the 36 hours. Each group has its own count and table. */
  function autoGroups(d){
    var y = new Date(d.today + 'T00:00:00Z'); y.setUTCDate(y.getUTCDate() - 1);
    var yest = y.toISOString().slice(0, 10), G = {};
    G[d.today] = [];                                           // Today always shows first, even before any shift has ended
    d.auto_out.forEach(function(r){ var k = r.work_date || d.today; (G[k] = G[k] || []).push(r); });
    return Object.keys(G).sort().reverse().map(function(k){
      var rows = G[k], name = k === d.today ? 'Today' : k === yest ? 'From yesterday' : 'Earlier';
      return el('div', { class: 'agrp', 'data-day': k === d.today ? 'today' : k === yest ? 'yesterday' : 'earlier' }, [
        el('div', { class: 'agh' }, [el('b', { text: name }), el('span', { class: 'm', text: day(k) + ' · ' + (rows.length ? rows.length + ' entr' + (rows.length === 1 ? 'y' : 'ies') : 'none yet') })]),
        !rows.length ? el('p', { class: 'note', style: 'padding:8px 12px', text: 'Nothing yet today. The system closes an entry only when that person\'s shift ends (or shift end plus approved overtime), so today\'s forgotten OUTs appear here this evening.' }) : table(['Employee', 'Department', 'Came in', 'System OUT at', 'Closed by the system'], rows, function(r){ return [who(r.employee_code, r.full_name), r.department_name, dt(r.opened_at), hm(r.closed_at), dt(r.auto_closed_at)]; },
          { wrap: [0, 1], keys: { 0: function(r){ return r.full_name; }, 2: function(r){ return r.opened_at; }, 3: function(r){ return r.closed_at; }, 4: function(r){ return r.auto_closed_at; } } })]);
    });
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
        leftToggle(all, function(){ paint(); }), el('span', { class: 'sep' }), download('/reports/attendance'), printBtn()]);
      narrow.querySelector('#f-dept').value = AF.dept; narrow.querySelector('#f-status').value = AF.status;

      function keep(x){
        if (!onRoll(x)) return false;
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
        }, { click: function(e){ showPerson(e.code); }, clickTitle: 'Show this person’s days', wrap: [0, 1],
          keys: { 0: function(e){ return e.name; }, 5: function(e){ return e.days ? e.present / e.days : ''; }, 7: function(e){ return e.present ? e.mins / e.present : ''; }, 8: function(e){ return e.lateMin; }, 9: function(e){ return e.earlyMin; }, 11: function(e){ return e.ot; } },
          foot: ['All employees', '', t.days, t.present, t.absent, rate(t.present, t.days), mins(t.mins), t.present ? mins(Math.round(t.mins / t.present)) : '—', t.late + ' · ' + mins(t.lateMin), t.early + ' · ' + mins(t.earlyMin), t.unconf, t.ot ? hrs(t.ot) : '—', t.auto] }) : none('Nothing in these dates.');

        var deps = Object.keys(byDept).sort().map(function(k){ return byDept[k]; });
        var depBox = deps.length ? table(['Department', '#Employees', '#Shift days', '#Worked', '#Did not come', '#Attendance', '#Present', '#Late days', '#Left early', '#HOD unconfirmed', '#OT checked', '#Not scanned out'], deps, function(g){
          return [el('b', { text: g.dept }), Object.keys(g.people).length, g.days, g.present, g.absent ? el('span', { class: 'flag', text: String(g.absent) }) : '0', rate(g.present, g.days), mins(g.mins),
            g.late ? el('span', { class: 'flag', text: String(g.late) }) : '0', g.early ? el('span', { class: 'flagw', text: String(g.early) }) : '0', g.unconf || '0', g.ot ? hrs(g.ot) : '—', g.auto || '0'];
        }, { click: function(g){ AF.dept = g.dept; narrow.querySelector('#f-dept').value = g.dept; paint(); }, clickTitle: 'Show only this department', wrap: [0],
          keys: { 5: function(g){ return g.days ? g.present / g.days : ''; }, 10: function(g){ return g.ot; } },
          foot: ['All departments', nPeople, t.days, t.present, t.absent, rate(t.present, t.days), mins(t.mins), t.late, t.early, t.unconf, t.ot ? hrs(t.ot) : '—', t.auto] }) : none('Nothing in these dates.');

        var lineBox = rows.length ? table(['Day', 'Employee', 'Department', 'Shift', 'First IN', 'Last OUT', '#Entries', '#Present', '#Late', '#Left early', '#HOD unconfirmed', '#OT checked', 'Status'], rows, function(x){
          return [day(x.work_date), who(x.employee_code, x.full_name), x.department_name,
            el('span', {}, [x.shift_name, el('span', { class: 'sub', text: hm(x.starts_at) + '–' + hm(x.ends_at) + (x.shift_source === 'default_auto' ? ' · default' : '') })]),
            hm(x.first_in), x.auto_out ? el('span', { class: 'flag', title: 'No OUT was scanned. The system closed the entry at shift end.' }, [hm(x.last_out), el('span', { class: 'sub', style: 'color:inherit', text: 'not scanned' })]) : hm(x.last_out), x.entries, mins(x.present_minutes),
            x.late_minutes ? el('span', { class: 'flag', text: mins(x.late_minutes) }) : '', x.early_minutes ? el('span', { class: 'flagw', text: mins(x.early_minutes) }) : '', x.unconfirmed || '',
            x.ot_actual_minutes !== null ? hrs(x.ot_actual_minutes) : (x.ot_approved_minutes ? 'approved ' + hrs(x.ot_approved_minutes) : ''),
            pill(x.status, x.status === 'absent' ? 'did not come' : x.status)];
        }, { wrap: [1, 2, 3], keys: { 0: function(x){ return x.work_date; }, 1: function(x){ return x.full_name; }, 3: function(x){ return x.shift_name + ' ' + x.starts_at; }, 4: function(x){ return x.first_in || ''; }, 5: function(x){ return x.last_out || ''; }, 11: function(x){ return x.ot_actual_minutes !== null ? x.ot_actual_minutes : (x.ot_approved_minutes || ''); } } }) : none(all.length ? 'No line matches the filters.' : 'Nothing in these dates.', 'att-none');

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
          }, { wrap: [2, 3, 5], keys: { 0: function(x){ return x.occurred_at; }, 2: function(x){ return x.full_name; }, 4: function(x){ return x.entry_sequence || ''; }, 8: function(x){ return x.hod_confirmed_at || ''; } } }) : none(r.rows.length ? 'No scan matches the filter.' : 'No scans in these dates.', 'mov-none')])]));
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
          }, { wrap: [0], foot: ['All departments', t.requested, t.approved, t.rejected, hrs(t.am), hrs(t.cm), n0(t.fin), n0(t.draft)] }) : none('No overtime in these dates.', 'ot-none')], { flush: true }),
        r.rows.length ? panel('Every line', r.rows.length, [table(['Day', 'Employee', 'Department', 'Reason', '#Asked', 'Decision', '#Approved', 'Checked', 'Rate', '#Amount', 'Payroll'], r.rows, function(x){
          return [day(x.work_date), who(x.employee_code, x.full_name), x.department_name, x.reason, hrs(x.estimated_minutes), pill(x.approval_status), x.approved_minutes ? hrs(x.approved_minutes) : '',
            x.verification_status === 'verified' ? hrs(x.actual_minutes) : pill(x.verification_status), x.rate_type ? x.rate_type + ' ' + x.manual_rate : '', x.amount || '', pill(x.payroll_status === 'none' ? 'pending' : x.payroll_status, x.payroll_status)];
        }, { wrap: [1, 2, 3], keys: { 0: function(x){ return x.work_date; }, 1: function(x){ return x.full_name; }, 4: function(x){ return x.estimated_minutes; }, 6: function(x){ return x.approved_minutes || ''; }, 7: function(x){ return x.verification_status === 'verified' ? x.actual_minutes : ''; } } })], { flush: true }) : null,
        r.truncated ? el('p', { class: 'err', text: 'Only the first 20000 lines are shown. Choose fewer days.' }) : null]);
    });
  }

  // -------------------------------------------------------- shared bits
  /* People who left the company are left out of Insights, Attendance and the
     register unless this is ticked — their old absences would only lower the
     figures. The tick box shows only when someone in the dates has left. */
  var WITH_LEFT = false;
  function onRoll(x){ return WITH_LEFT || x.employee_active === undefined || +x.employee_active === 1; }
  function leftToggle(rows, repaint){
    var gone = {}; rows.forEach(function(x){ if (x.employee_active !== undefined && !+x.employee_active) gone[x.employee_code] = 1; });
    var n = Object.keys(gone).length;
    if (!n) return null;
    var cb = el('input', { type: 'checkbox', id: 'with-left', change: function(){ WITH_LEFT = cb.checked; repaint(); } });
    cb.checked = WITH_LEFT;
    return el('label', { class: 'lefttog', title: 'People marked as left the company' }, [cb, ' Include ' + n + (n === 1 ? ' person' : ' people') + ' who left']);
  }
  /* Factory-time minutes of the day for an ISO time (Asia/Karachi is UTC+5, no summer time). */
  function khiMin(iso){ var d = new Date(iso); if (isNaN(d)) return null; var m = (d.getUTCHours() * 60 + d.getUTCMinutes() + 300) % 1440; return m; }
  function clock(m){ return ('0' + Math.floor(m / 60)).slice(-2) + ':' + ('0' + (m % 60)).slice(-2); }
  function deptsOf(rows){ return rows.map(function(x){ return x.department_name; }).filter(function(v, i, a){ return a.indexOf(v) === i; }).sort(); }
  function saveCsv(name, head, lines){
    var esc = function(v){ v = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    var blob = new Blob(['﻿' + [head].concat(lines).map(function(l){ return l.map(esc).join(','); }).join('\r\n')], { type: 'text/csv;charset=utf-8' });
    var a = el('a', { href: URL.createObjectURL(blob), download: name }); document.body.appendChild(a); a.click(); setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 500);
  }
  var SVGNS = 'http://www.w3.org/2000/svg';
  function sv(tag, attrs, kids){ var n = document.createElementNS(SVGNS, tag); Object.keys(attrs || {}).forEach(function(k){ n.setAttribute(k, attrs[k]); }); (kids || []).forEach(function(c){ if (c) n.appendChild(c); }); return n; }
  /* one floating tip for every chart on the page */
  var tipEl = null;
  function tip(e, html){
    if (!tipEl) { tipEl = el('div', { class: 'ctip', role: 'status' }); document.body.appendChild(tipEl); }
    if (html === null) { tipEl.hidden = true; return; }
    tipEl.textContent = ''; html.forEach(function(line, i){ tipEl.appendChild(i ? el('div', { text: line }) : el('b', { text: line })); });
    tipEl.hidden = false;
    var x = Math.min(window.innerWidth - tipEl.offsetWidth - 8, e.clientX + 12), y = e.clientY - tipEl.offsetHeight - 10;
    tipEl.style.left = Math.max(8, x) + 'px'; tipEl.style.top = (y < 8 ? e.clientY + 16 : y) + 'px';
  }
  /* Vertical bars, one measure, one scale (0 to max), labelled ends on hover.
     bars: [{ label, parts:[{ v, cls, name }], tip:[lines] }]. Stacked parts share the bar, 2px gap between them. */
  function barChart(bars, opts){
    opts = opts || {};
    var W = 720, H = 220, L = 34, R = 8, T = 10, B = 26, iw = W - L - R, ih = H - T - B;
    var max = opts.max || niceMax(Math.max(1, Math.max.apply(null, bars.map(function(b){ return b.parts.reduce(function(a, p){ return a + p.v; }, 0); }))));
    var step = iw / Math.max(1, bars.length), bw = Math.max(3, Math.min(34, step * 0.62));
    var g = sv('g');
    [0, 0.5, 1].forEach(function(f){
      var y = T + ih - ih * f;
      g.appendChild(sv('line', { x1: L, x2: W - R, y1: y, y2: y, class: f ? 'grid' : 'base' }));
      var t = sv('text', { x: L - 6, y: y + 4, class: 'ax', 'text-anchor': 'end' }); t.textContent = opts.fmt ? opts.fmt(max * f) : Math.round(max * f); g.appendChild(t);
    });
    var every = Math.ceil(bars.length / 16);
    bars.forEach(function(b, i){
      var x = L + step * i + (step - bw) / 2, y0 = T + ih, total = 0;
      b.parts.forEach(function(p, j){
        if (!p.v) return;
        var h = ih * p.v / max, y = y0 - h - (total ? 2 : 0);
        total += p.v;
        var top = j === b.parts.length - 1 || b.parts.slice(j + 1).every(function(q){ return !q.v; });
        var r = Math.min(4, bw / 2, h / 2);
        var d = top ? 'M' + x + ',' + (y + h) + 'V' + (y + r) + 'Q' + x + ',' + y + ' ' + (x + r) + ',' + y + 'H' + (x + bw - r) + 'Q' + (x + bw) + ',' + y + ' ' + (x + bw) + ',' + (y + r) + 'V' + (y + h) + 'Z'
                    : 'M' + x + ',' + (y + h) + 'V' + y + 'H' + (x + bw) + 'V' + (y + h) + 'Z';
        g.appendChild(sv('path', { d: d, class: 'bar ' + (p.cls || '') }));
        y0 = y;
      });
      if (i % every === 0) { var t = sv('text', { x: L + step * i + step / 2, y: H - 8, class: 'ax', 'text-anchor': 'middle' }); t.textContent = b.label; g.appendChild(t); }
      var hit = sv('rect', { x: L + step * i, y: T, width: step, height: ih, class: 'hit' });
      hit.addEventListener('mousemove', function(e){ tip(e, b.tip); }); hit.addEventListener('mouseleave', function(e){ tip(e, null); });
      g.appendChild(hit);
    });
    return sv('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'chart', role: 'img', 'aria-label': opts.title || 'chart' }, [g]);
  }
  /* the axis ends on a round number: 1, 2, 5 × 10ⁿ (and its half is a round number too) */
  function niceMax(v){ var p = Math.pow(10, Math.floor(Math.log10(v))); return [1, 2, 4, 5, 10].map(function(m){ return m * p; }).filter(function(m){ return m >= v; })[0]; }
  function legend(items){ return el('div', { class: 'clegend' }, items.map(function(it){ return el('span', {}, [el('i', { class: 'sw ' + it[0] }), it[1]]); })); }

  // ------------------------------------------------------------- insights
  /* What a manager asks of attendance: is it getting better or worse, when
     do people actually arrive, which department is weak, and who keeps
     being absent or late. Everything from the same attendance lines as the
     Attendance tab (closed IN–OUT entries; late against each person's shift). */
  var IF = { dept: '' };
  function drawInsights(){
    var bar = filters(drawInsights, false);
    get('/reports/attendance', F).then(function(r){
      if (!r.ok) return show([bar, el('p', { class: 'err', text: r.error })]);
      var names = deptsOf(r.rows); if (IF.dept && names.indexOf(IF.dept) < 0) IF.dept = '';
      var holder = el('div', { style: 'display:grid;gap:12px;min-width:0' });
      var narrow = el('div', { class: 'bar' }, [el('span', { class: 'lbl', text: 'Narrow' }),
        select('i-dept', 'Department', [['', 'All departments']].concat(names.map(function(n){ return [n, n]; })), function(v){ IF.dept = v; paint(); }), leftToggle(r.rows, function(){ paint(); }), el('span', { class: 'sep' }), printBtn()]);
      narrow.querySelector('#i-dept').value = IF.dept;
      function paint(){
        var rows = r.rows.filter(function(x){ return onRoll(x) && (!IF.dept || x.department_name === IF.dept); });
        holder.textContent = '';
        if (!rows.length) { holder.appendChild(none('Nothing in these dates.', 'ins-none')); return; }
        var worked = rows.filter(function(x){ return x.status !== 'absent'; });
        var onTime = worked.filter(function(x){ return !(x.late_minutes > 0); }).length;
        var mins_ = worked.reduce(function(a, x){ return a + (+x.present_minutes || 0); }, 0);
        var closed = worked.filter(function(x){ return +x.present_minutes > 0; }).length;
        var absent = rows.length - worked.length;
        var att = pct(worked.length, rows.length), pun = pct(onTime, worked.length);
        holder.appendChild(el('div', { class: 'tiles', id: 'ins-tiles' }, [
          tile(att === null ? '—' : att + '%', 'Attendance rate', 'good', null, worked.length + ' of ' + rows.length + ' shift days', att),
          tile(pun === null ? '—' : pun + '%', 'Punctuality', pun !== null && pun < 90 ? 'warn' : 'info', null, onTime + ' of ' + worked.length + ' days on time', pun),
          tile(closed ? mins(Math.round(mins_ / closed)) : '—', 'Average day', '', null, 'IN to OUT, closed days'),
          tile(absent, 'Days not come', absent ? 'hot' : '', null, 'had a shift'),
          tile(worked.length - onTime, 'Late days', worked.length - onTime ? 'hot' : '', null, mins(worked.reduce(function(a, x){ return a + (+x.late_minutes || 0); }, 0)) + ' late in all')]));

        /* 1. day by day: attendance rate */
        var byDay = {};
        rows.forEach(function(x){ var d = byDay[x.work_date] || (byDay[x.work_date] = { n: 0, w: 0, late: 0 }); d.n++; if (x.status !== 'absent') { d.w++; if (x.late_minutes > 0) d.late++; } });
        var days = Object.keys(byDay).sort();
        var trend = barChart(days.map(function(d){ var v = byDay[d], p = pct(v.w, v.n) || 0;
          return { label: d.slice(8, 10) + '/' + d.slice(5, 7), parts: [{ v: p, cls: 'p' }], tip: [day(d), 'Attendance ' + p + '%', v.w + ' came of ' + v.n + ' with a shift', v.late + ' late'] }; }),
          { max: 100, fmt: function(v){ return Math.round(v) + '%'; }, title: 'Attendance rate by day' });

        /* 2. when people arrive: first IN in 10-minute steps, on time vs late */
        var bins = {};
        worked.forEach(function(x){ var m = x.first_in ? khiMin(x.first_in) : null; if (m === null) return; var k = Math.floor(m / 10) * 10; var b = bins[k] || (bins[k] = { on: 0, late: 0 }); if (x.late_minutes > 0) b.late++; else b.on++; });
        var keys = Object.keys(bins).map(Number).sort(function(a, b){ return a - b; });
        if (keys.length > 1) { var full = []; for (var k = keys[0]; k <= keys[keys.length - 1]; k += 10) full.push(k); keys = full; }
        var arrive = keys.length ? barChart(keys.map(function(k){ var b = bins[k] || { on: 0, late: 0 };
          return { label: clock(k), parts: [{ v: b.on, cls: 'p' }, { v: b.late, cls: 'late' }], tip: [clock(k) + '–' + clock(k + 9), b.on + ' on time', b.late + ' late'] }; }), { title: 'Arrivals by time' }) : none('No IN scanned in these dates.');

        holder.appendChild(el('div', { class: 'cols2' }, [
          panel('Attendance rate by day', days.length + ' day' + (days.length === 1 ? '' : 's'), [trend, el('p', { class: 'note', text: 'Came in ÷ had a shift, each day. Hover a bar for the numbers.' })], { id: 'ins-trend' }),
          panel('When people arrive', 'first IN of the day', [arrive, legend([['p', 'On time'], ['late', 'Late (after their shift start + allowance)']])], { id: 'ins-arrive' })]));

        /* 3. departments side by side */
        var D = {};
        rows.forEach(function(x){ var g = D[x.department_name] || (D[x.department_name] = { n: 0, w: 0, on: 0, m: 0, c: 0, ab: 0, people: {} });
          g.n++; g.people[x.employee_code] = 1; if (x.status === 'absent') g.ab++; else { g.w++; if (!(x.late_minutes > 0)) g.on++; if (+x.present_minutes > 0) { g.m += +x.present_minutes; g.c++; } } });
        var dl = Object.keys(D).sort().map(function(k){ var g = D[k]; g.name = k; return g; });
        holder.appendChild(panel('Department scorecard', dl.length + ' department' + (dl.length === 1 ? '' : 's'), [table(['Department', '#People', '#Shift days', '#Attendance', '#Punctuality', '#Average day', '#Days not come'], dl, function(g){
          return [el('b', { text: g.name }), Object.keys(g.people).length, g.n, rate(g.w, g.n), rate(g.on, g.w), g.c ? mins(Math.round(g.m / g.c)) : '—', g.ab ? el('span', { class: 'flag', text: String(g.ab) }) : '0'];
        }, { id: 'ins-dept', wrap: [0], keys: { 3: function(g){ return g.n ? g.w / g.n : ''; }, 4: function(g){ return g.w ? g.on / g.w : ''; }, 5: function(g){ return g.c ? g.m / g.c : ''; } } })], { flush: true }));

        /* 4. who needs a word: repeated absence, often late, short days */
        var P = {};
        rows.slice().sort(function(a, b){ return a.work_date < b.work_date ? -1 : 1; }).forEach(function(x){
          var e = P[x.employee_code] || (P[x.employee_code] = { code: x.employee_code, name: x.full_name, dept: x.department_name, run: 0, best: 0, lastRun: '', late: 0, lateMin: 0, ab: 0, short: 0, n: 0 });
          e.n++;
          if (x.status === 'absent') { e.ab++; e.run++; if (e.run > e.best) { e.best = e.run; e.lastRun = x.work_date; } } else e.run = 0;
          if (x.late_minutes > 0) { e.late++; e.lateMin += +x.late_minutes; }
          var shiftMin = x.starts_at && x.ends_at ? (new Date(x.ends_at) - new Date(x.starts_at)) / 60000 : 0;
          if (x.status === 'present' && shiftMin > 0 && +x.present_minutes > 0 && +x.present_minutes < shiftMin * 0.75) e.short++;
        });
        var people = Object.keys(P).map(function(k){ return P[k]; });
        function watchList(id, title, list, line, empty){
          return panel(title, list.length ? list.length + (list.length === 1 ? ' person' : ' people') : null, [list.length ? el('ul', { class: 'wl', id: id }, list.slice(0, 10).map(function(e){
            var li = el('li', { title: 'Show their days' }, [el('span', {}, [el('b', { text: e.name }), el('span', { class: 'muted', text: ' ' + e.code + ' · ' + e.dept })]), el('span', { class: 'v', text: line(e) })]);
            li.addEventListener('click', function(){ F.employee = e.code; try { localStorage.setItem('attr-seg-att', 'lines'); } catch (x) {} go('att'); });
            return li; })) : none(empty)], { flush: true });
        }
        holder.appendChild(el('div', { class: 'cols3' }, [
          watchList('ins-absent', 'Absent several days in a row', people.filter(function(e){ return e.best >= 2; }).sort(function(a, b){ return b.best - a.best || b.ab - a.ab; }),
            function(e){ return e.best + ' days in a row · ' + e.ab + ' in all'; }, 'Nobody missed two shift days in a row.'),
          watchList('ins-late', 'Often late', people.filter(function(e){ return e.late >= 3; }).sort(function(a, b){ return b.late - a.late || b.lateMin - a.lateMin; }),
            function(e){ return e.late + ' times · ' + mins(e.lateMin); }, 'Nobody was late three times or more.'),
          watchList('ins-short', 'Short days', people.filter(function(e){ return e.short >= 2; }).sort(function(a, b){ return b.short - a.short; }),
            function(e){ return e.short + ' days under ¾ of the shift'; }, 'Nobody worked under ¾ of their shift twice or more.')]));
        holder.appendChild(el('p', { class: 'note', text: 'Only people on the roll are counted' + (WITH_LEFT ? ' — and, as ticked, those who left' : '; tick "Include … who left" to add those who left the company') + '. Punctuality = days on time ÷ days worked. Short day = present less than three quarters of the shift (a permission or a half day may explain it). Click a name to see their days.' }));
      }
      paint();
      show([bar, narrow, holder]);
    });
  }

  // ----------------------------------------------------- monthly register
  /* The classic attendance register: one line per person, one column per
     day of the month, a letter in each box, totals at the end. Prints on
     one landscape page per ~25 people. */
  var RF = { month: '', dept: '', q: '' };
  function drawRegister(){
    if (!RF.month) { var t0 = khi(); RF.month = t0.getFullYear() + '-' + ('0' + (t0.getMonth() + 1)).slice(-2); }
    var y = +RF.month.slice(0, 4), mo = +RF.month.slice(5, 7), last = new Date(Date.UTC(y, mo, 0)).getUTCDate();
    var from = RF.month + '-01', to = RF.month + '-' + ('0' + last).slice(-2), today = iso(khi());
    if (to > today) to = today < from ? from : today;
    var month = el('input', { type: 'month', value: RF.month, id: 'r-month', 'aria-label': 'Month' });
    var qbox = el('input', { type: 'search', value: RF.q, id: 'r-q', placeholder: 'Employee code or name' });
    var head = el('div', { class: 'bar' }, [el('span', { class: 'lbl', text: 'Month' }), month,
      el('button', { type: 'button', class: 'act primary', id: 'r-show', text: 'Show', click: function(){ RF.month = month.value || RF.month; RF.q = qbox.value; drawRegister(); } }), qbox]);
    qbox.addEventListener('keydown', function(e){ if (e.key === 'Enter') { RF.q = qbox.value; drawRegister(); } });
    get('/reports/attendance', { from: from, to: to, employee: RF.q }).then(function(r){
      if (!r.ok) return show([head, el('p', { class: 'err', text: r.error })]);
      var names = deptsOf(r.rows); if (RF.dept && names.indexOf(RF.dept) < 0) RF.dept = '';
      var holder = el('div', { style: 'display:grid;gap:12px;min-width:0' });
      var dsel = select('r-dept', 'Department', [['', 'All departments']].concat(names.map(function(n){ return [n, n]; })), function(v){ RF.dept = v; paint(); });
      dsel.value = RF.dept;
      var csvBtn = el('button', { type: 'button', class: 'act', id: 'r-csv', text: 'Download (CSV)' });
      head.appendChild(dsel); var lt = leftToggle(r.rows, function(){ paint(); }); if (lt) head.appendChild(lt); head.appendChild(el('span', { class: 'sep' })); head.appendChild(csvBtn); head.appendChild(printBtn());
      function code(x){
        if (x.status === 'absent') return 'A';
        if (x.status === 'inside') return x.late_minutes > 0 ? 'L' : 'I';
        if (x.late_minutes > 0) return 'L';
        return 'P';
      }
      function paint(){
        var rows = r.rows.filter(function(x){ return onRoll(x) && (!RF.dept || x.department_name === RF.dept); });
        var E = {};
        rows.forEach(function(x){
          var e = E[x.employee_code] || (E[x.employee_code] = { code: x.employee_code, name: x.full_name, dept: x.department_name, d: {}, P: 0, L: 0, A: 0, m: 0 });
          var c = code(x), dd = +x.work_date.slice(8, 10);
          if (e.d[dd] && e.d[dd].c !== 'A') return;                       // two shifts in a day: keep the one they came for
          e.d[dd] = { c: c, x: x };
        });
        var list = Object.keys(E).map(function(k){ var e = E[k];
          Object.keys(e.d).forEach(function(dd){ var c = e.d[dd].c; if (c === 'A') e.A++; else { e.P++; if (c === 'L') e.L++; } e.m += +e.d[dd].x.present_minutes || 0; });
          return e; }).sort(function(a, b){ return a.dept.localeCompare(b.dept) || a.name.localeCompare(b.name); });
        holder.textContent = '';
        if (!list.length) { holder.appendChild(none('Nobody had a shift in this month' + (RF.q ? ' matching "' + RF.q + '"' : '') + '.', 'reg-none')); return; }
        var lastDay = +to.slice(8, 10), wd = function(dd){ return new Date(Date.UTC(y, mo - 1, dd)).getUTCDay(); };
        var thead = el('tr', {}, [el('th', { class: 'who', text: 'Employee' })]);
        for (var dd = 1; dd <= last; dd++) thead.appendChild(el('th', { class: 'd' + (wd(dd) === 0 ? ' sun' : '') + (dd > lastDay ? ' fut' : '') }, [String(dd), el('span', { text: 'SMTWTFS'.charAt(wd(dd)) })]));
        ['Present', 'Late', 'Absent', 'Hours', 'Rate'].forEach(function(h){ thead.appendChild(el('th', { class: 'n tot', text: h })); });
        var tb = el('tbody'), dept = null, colN = last + 6;
        var sum = { P: 0, L: 0, A: 0, m: 0 };
        list.forEach(function(e){
          if (e.dept !== dept) { dept = e.dept; tb.appendChild(el('tr', { class: 'grp' }, [el('td', { colspan: String(colN), text: dept })])); }
          var tr = el('tr', {}, [el('td', { class: 'who' }, [el('span', { class: 'c', text: e.code }), el('b', { text: e.name })])]);
          for (var dd = 1; dd <= last; dd++) {
            var v = e.d[dd], cls = 'd' + (wd(dd) === 0 ? ' sun' : '');
            if (!v) { tr.appendChild(el('td', { class: cls + ' off', text: dd > lastDay ? '' : '·' })); continue; }
            var x = v.x;
            var td = el('td', { class: cls + ' k' + v.c, text: v.c });
            td.title = day(x.work_date) + ' · ' + (v.c === 'A' ? 'had a shift, did not come' : 'IN ' + hm(x.first_in) + (x.last_out ? ' · OUT ' + hm(x.last_out) : ' · inside') + ' · ' + mins(x.present_minutes) + (x.late_minutes > 0 ? ' · late ' + mins(x.late_minutes) : '') + (x.early_minutes > 0 ? ' · left ' + mins(x.early_minutes) + ' early' : '') + (x.auto_out ? ' · OUT not scanned' : ''));
            tr.appendChild(td);
          }
          sum.P += e.P; sum.L += e.L; sum.A += e.A; sum.m += e.m;
          [e.P, e.L || '0', e.A ? el('span', { class: 'flag', text: String(e.A) }) : '0', (e.m / 60).toFixed(1), (pct(e.P, e.P + e.A) || 0) + '%'].forEach(function(v){ tr.appendChild(el('td', { class: 'n tot' }, [typeof v === 'object' ? v : String(v)])); });
          tb.appendChild(tr);
        });
        var tf = el('tr', {}, [el('td', { class: 'who', text: list.length + (list.length === 1 ? ' person' : ' people') })]);
        for (var d2 = 1; d2 <= last; d2++) { var came = 0, had = 0; list.forEach(function(e){ var v = e.d[d2]; if (v) { had++; if (v.c !== 'A') came++; } }); tf.appendChild(el('td', { class: 'd', title: had ? came + ' of ' + had + ' came' : '', text: had ? String(came) : '' })); }
        [sum.P, sum.L, sum.A, Math.round(sum.m / 60).toLocaleString('en-US'), (pct(sum.P, sum.P + sum.A) || 0) + '%'].forEach(function(v){ tf.appendChild(el('td', { class: 'n tot', text: String(v) })); });
        var tbl = el('table', { class: 'reg' }, [el('thead', {}, [thead]), tb, el('tfoot', {}, [tf])]);
        holder.appendChild(el('section', { class: 'panel' }, [
          el('div', { class: 'ph' }, [el('h3', { text: 'Attendance register · ' + new Date(Date.UTC(y, mo - 1, 1)).toLocaleDateString('en-GB', { timeZone: 'UTC', month: 'long', year: 'numeric' }) + (RF.dept ? ' · ' + RF.dept : '') }),
            el('div', { class: 'reglegend' }, [['kP', 'P', 'present'], ['kL', 'L', 'late'], ['kA', 'A', 'did not come'], ['kI', 'I', 'inside now'], ['off', '·', 'no shift / day off']].map(function(k){ return el('span', {}, [el('i', { class: k[0], text: k[1] }), k[2]]); }))]),
          el('div', { class: 'pb flush' }, [el('div', { class: 'scroll', id: 'reg-box' }, [tbl])])]));
        holder.appendChild(el('p', { class: 'note', text: 'Hover a box for IN, OUT, hours and minutes late. The bottom line counts who came each day. Hours are closed IN–OUT entries. A blank day with "·" had no shift (day off, or not yet on the roll).' }));
        csvBtn.onclick = function(){
          var h = ['Code', 'Name', 'Department']; for (var dd = 1; dd <= last; dd++) h.push(String(dd)); h = h.concat(['Present', 'Late', 'Absent', 'Hours', 'Rate %']);
          saveCsv('attendance-register-' + RF.month + '.csv', h, list.map(function(e){ var l = [e.code, e.name, e.dept]; for (var dd = 1; dd <= last; dd++) l.push(e.d[dd] ? e.d[dd].c : ''); return l.concat([e.P, e.L, e.A, (Math.round(e.m / 6) / 10), pct(e.P, e.P + e.A) || 0]); }));
        };
      }
      paint();
      show([head, holder]);
    });
  }

  // ----------------------------------------------------------------- live
  /* The gate as it is now, with faces. Refreshes every 30 seconds while the
     tab is open and the screen is on. A face is the employee's reference
     photo, fetched once per visit through /reports/live/photo (department
     check, every view audited) and kept only in this page's memory — never
     a link, never stored in the browser. "Hide faces" shows initials only,
     for a screen that visitors can see. "Wall screen" fills the screen in a
     dark theme for a TV at the office. */
  var clockTimer = null, FACES = {}, faceQueue = [], faceBusy = 0, LV = { dept: '', q: '' };
  var HIDE = false; try { HIDE = localStorage.getItem('attr-hide-faces') === '1'; } catch (e) {}
  function initials(n){ return String(n || '?').trim().split(/\s+/).slice(0, 2).map(function(w){ return w.charAt(0).toUpperCase(); }).join(''); }
  /* Faces arrive in batches: the screen collects the faces coming into view
     for a moment, then asks for up to 40 in one request (two requests at a
     time at most). Each answer is a small copy of the photo, held in this
     page's memory only. */
  var faceWait = {}, faceTimer = null;
  function pumpFaces(){
    faceQueue.forEach(function(job){
      var id = job.id;
      if (FACES[id] !== undefined && FACES[id] !== 'loading') { job.done(FACES[id]); return; }
      (faceWait[id] = faceWait[id] || []).push(job.done);
    });
    faceQueue = [];
    if (!faceTimer) faceTimer = setTimeout(sendFaces, 40);
  }
  function sendFaces(){
    faceTimer = null;
    while (faceBusy < 2) {
      var ids = Object.keys(faceWait).filter(function(id){ return FACES[id] === undefined; }).slice(0, 40);
      if (!ids.length) return;
      ids.forEach(function(id){ FACES[id] = 'loading'; });
      faceBusy++;
      (function(ids){
        fetch(q('/reports/live/photos', { ids: ids.join(',') }), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
          .then(function(r){ return r.ok ? r.json() : null; }).catch(function(){ return null; })
          .then(function(j){
            faceBusy--;
            var got = (j && j.photos) || {};
            ids.forEach(function(id){
              FACES[id] = got[id] || null;
              (faceWait[id] || []).forEach(function(f){ f(FACES[id]); }); delete faceWait[id];
            });
            sendFaces();
          });
      })(ids);
    }
  }
  var seen = 'IntersectionObserver' in window ? new IntersectionObserver(function(list){
    list.forEach(function(en){ if (!en.isIntersecting) return; seen.unobserve(en.target); var t = en.target; faceQueue.push({ id: +t.dataset.id, done: function(url){ if (url) { t.style.backgroundImage = 'url("' + url + '")'; t.classList.add('has'); } } }); pumpFaces(); });
  }, { rootMargin: '200px' }) : null;
  function face(p, size, ring){
    var av = el('div', { class: 'av ' + (ring || '') + ' s' + size, 'data-id': String(p.employee_id), 'aria-hidden': 'true' }, [el('span', { text: initials(p.full_name) })]);
    if (!HIDE && p.has_photo) {
      if (FACES[p.employee_id]) { av.style.backgroundImage = 'url("' + FACES[p.employee_id] + '")'; av.classList.add('has'); }
      else if (seen) seen.observe(av);
    }
    return av;
  }
  function person(p, ring, sub, cls){
    return el('div', { class: 'pf ' + (cls || ''), title: p.full_name + ' · ' + p.employee_code + ' · ' + p.department_name }, [face(p, cls === 'big' ? 64 : 52, ring),
      el('b', { text: p.full_name }), el('span', { class: 'm', text: sub })]);
  }
  function ringChart(pctV, a, b){
    var r = 52, c = 2 * Math.PI * r, f = Math.max(0, Math.min(100, pctV || 0)) / 100;
    return sv('svg', { viewBox: '0 0 140 140', class: 'ring', role: 'img', 'aria-label': (pctV || 0) + '% present' }, [
      sv('circle', { cx: 70, cy: 70, r: r, class: 'trk' }),
      sv('circle', { cx: 70, cy: 70, r: r, class: 'val', 'stroke-dasharray': (c * f) + ' ' + c, transform: 'rotate(-90 70 70)' }),
      (function(){ var t = sv('text', { x: 70, y: 70, class: 'big', 'text-anchor': 'middle' }); t.textContent = (pctV === null ? '—' : pctV) + (pctV === null ? '' : '%'); return t; })(),
      (function(){ var t = sv('text', { x: 70, y: 92, class: 'sm', 'text-anchor': 'middle' }); t.textContent = 'present today'; return t; })()]);
  }
  function drawLive(){
    var wrap = document.getElementById('attr');
    get('/reports/live').then(function(d){
      if (!d.ok) return show([el('p', { class: 'err', text: d.error || 'Could not load.' })]);
      if (d.empty_scope) return show([el('p', { class: 'muted', text: 'You are not the HOD of a department, so there is nobody to show.' })]);
      var clock = el('b', { class: 'clk', id: 'live-clock' });
      function tick(){ var t = new Date(); clock.textContent = t.toLocaleTimeString('en-GB', { timeZone: 'Asia/Karachi', hour: '2-digit', minute: '2-digit', second: '2-digit' }); }
      tick(); if (clockTimer) clearInterval(clockTimer); clockTimer = setInterval(tick, 1000);
      var hideBtn = el('button', { type: 'button', class: 'act', id: 'live-hide', 'aria-pressed': HIDE ? 'true' : 'false', text: HIDE ? 'Show faces' : 'Hide faces', click: function(){ HIDE = !HIDE; try { localStorage.setItem('attr-hide-faces', HIDE ? '1' : '0'); } catch (e) {} drawLive(); } });
      var wallBtn = el('button', { type: 'button', class: 'act', id: 'live-wall', text: wrap.classList.contains('wall') ? 'Leave wall screen' : 'Wall screen', click: function(){
        var on = !wrap.classList.contains('wall'); wrap.classList.toggle('wall', on);
        try { if (on && wrap.requestFullscreen) wrap.requestFullscreen(); else if (!on && document.fullscreenElement) document.exitFullscreen(); } catch (e) {}
        drawLive(); } });
      var strip = el('div', { class: 'strip live-head' }, [
        el('div', { class: 'when' }, [el('span', { class: 'livedot', 'aria-hidden': 'true' }), el('b', { text: longDay(d.today) }), clock, el('span', { class: 'muted', text: ' · updates every 30 seconds' })]),
        el('div', { class: 'acts' }, [hideBtn, wallBtn])]);

      var pres = pct(d.came_in, d.active_employees);
      var kpis = el('div', { class: 'tiles live-k' }, [
        tile(el('b', {}, [el('i', { class: 'arw in', text: '→' }), String(d.incoming.length)]), 'Incoming', 'good', 'live-in', 'IN in the last ' + d.window_minutes + ' min'),
        tile(d.inside_now, 'Inside now', 'info', 'live-inside', 'in the factory'),
        tile(el('b', {}, [el('i', { class: 'arw out', text: '←' }), String(d.outgoing.length)]), 'Outgoing', 'warn', 'live-out', 'OUT in the last ' + d.window_minutes + ' min'),
        tile(d.gone_home, 'Gone home', '', 'live-gone', 'came today, out now'),
        tile(d.not_in, 'Not in today', d.not_in ? 'hot' : '', 'live-notin', 'of ' + d.active_employees + ' on the roll')]);

      function flow(list, ring, empty, id){
        return list.length ? el('div', { class: 'flow', id: id }, list.slice(0, 14).map(function(p){ return person(p, ring, hm(p.at) + ' · ' + p.department_name); }).concat(list.length > 14 ? [el('div', { class: 'more', text: '+' + (list.length - 14) })] : []))
                           : el('div', { class: 'empty', id: id, text: empty });
      }
      var donut = el('section', { class: 'panel livering' }, [el('div', { class: 'ph' }, [el('h3', { text: 'Present today' })]),
        el('div', { class: 'pb' }, [ringChart(pres), el('div', { class: 'rleg' }, [
          el('span', {}, [el('i', { class: 'sw p' }), 'Came in ', el('b', { text: String(d.came_in) })]),
          el('span', {}, [el('i', { class: 'sw n' }), 'Not in yet ', el('b', { text: String(d.not_in) })]),
          el('span', {}, [el('i', { class: 'sw i' }), 'Inside now ', el('b', { text: String(d.inside_now) })])])])]);
      var row2 = el('div', { class: 'liverow' }, [
        panel('Incoming', 'checked IN · last ' + d.window_minutes + ' min', [flow(d.incoming, 'g', 'Nobody came in in the last ' + d.window_minutes + ' minutes.', 'live-flow-in')], { flush: true }),
        panel('Outgoing', 'checked OUT · last ' + d.window_minutes + ' min', [flow(d.outgoing, 'o', 'Nobody went out in the last ' + d.window_minutes + ' minutes.', 'live-flow-out')], { flush: true }),
        donut]);

      /* everyone inside, by department */
      var names = d.by_department.filter(function(x){ return x.inside; }).map(function(x){ return x.department_name; });
      if (LV.dept && names.indexOf(LV.dept) < 0) LV.dept = '';
      var grid = el('div', { class: 'facegrid', id: 'live-grid' });
      var qbox = el('input', { type: 'search', placeholder: 'Find a name or code', value: LV.q, 'aria-label': 'Find a name or code' });
      function paintGrid(){
        grid.textContent = '';
        var q_ = LV.q.trim().toLowerCase();
        var list = d.inside.filter(function(p){ return (!LV.dept || p.department_name === LV.dept) && (!q_ || (p.full_name + ' ' + p.employee_code).toLowerCase().indexOf(q_) >= 0); });
        if (!list.length) return grid.appendChild(el('div', { class: 'empty', text: d.inside.length ? 'Nobody matches.' : 'Nobody is inside the factory now.' }));
        list.forEach(function(p){ grid.appendChild(person(p, 'b', 'since ' + hm(p.since), 'big')); });
      }
      qbox.addEventListener('input', function(){ LV.q = qbox.value; paintGrid(); });
      var chips = el('div', { class: 'chips', role: 'group', 'aria-label': 'Department' }, [['', 'All', d.inside_now]].concat(d.by_department.filter(function(x){ return x.inside; }).map(function(x){ return [x.department_name, x.department_name, x.inside]; })).map(function(c){
        var b = el('button', { type: 'button', 'aria-pressed': LV.dept === c[0] ? 'true' : 'false', click: function(){ LV.dept = c[0]; Array.prototype.forEach.call(chips.children, function(x){ x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); }); paintGrid(); } }, [c[1], el('span', { class: 'c', text: String(c[2]) })]);
        return b; }));
      paintGrid();
      var insidePanel = el('section', { class: 'panel', id: 'live-inside-panel' }, [
        el('div', { class: 'ph' }, [el('h3', { text: 'Inside the factory — ' + d.inside_now + (d.inside_now === 1 ? ' person' : ' people') }), qbox]),
        el('div', { class: 'pb' }, [chips, grid])]);

      var deptStrip = panel('By department', null, [el('div', { class: 'dstrip' }, d.by_department.map(function(x){
        var p_ = pct(x.came_in, x.active);
        return el('div', { class: 'dcard' }, [el('b', { text: x.department_name }),
          el('div', { class: 'dn' }, [el('span', { class: 'big', text: String(x.inside) }), el('span', { class: 'm', text: ' inside · ' + x.came_in + ' of ' + x.active + ' came' })]),
          el('div', { class: 'meter' }, [el('i', { style: 'width:' + (p_ || 0) + '%' })]), el('span', { class: 'm', text: (p_ === null ? '—' : p_ + '%') + ' present' })]); }))]);

      var gone = d.gone.length ? el('details', { class: 'panel gonebox' }, [el('summary', { class: 'ph' }, [el('h3', { text: 'Gone home today — ' + d.gone.length }), el('span', { class: 'n', text: 'came in today, out now' })]),
        el('div', { class: 'pb' }, [el('div', { class: 'flow' }, d.gone.slice(0, 60).map(function(p){ return person(p, 'x', 'out ' + hm(p.at)); }))])]) : null;

      show([strip, kpis, row2, insidePanel, deptStrip, gone,
        el('p', { class: 'note', text: 'A face is the employee\'s own photo on file, shown only to people allowed to see that department; every view is recorded. Use "Hide faces" on a screen visitors can see.' })]);
    });
    timer = setInterval(function(){ if (!document.hidden) drawLive(); }, 30000);
  }

  var first = (location.hash || '').slice(1);
  go(TABS.some(function(t){ return t.id === first; }) ? first : 'today');
})();
</script>
<?php page_footer(); ?>
