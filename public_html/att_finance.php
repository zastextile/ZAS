<?php
/*
  PEOPLE FINANCE — WHAT EACH EMPLOYEE IS OWED, AND PAYING IT
  ==========================================================

  Three kinds of pay, one road for all of them:

      PREPARE  →  APPROVE  →  PAY

    Fixed salary   prepared at month end (or custom dates)
    Daily wages    prepared weekly, Friday to Thursday, from Attendance
    Contractors    whatever Production has booked and not yet paid

  Tabs
    Home         what is owed, and how many sheets wait at each step
    Prepare pay  tick people, look at each one's LINES, amend any line with a
                 reason, add a bonus or a deduction, send for approval
    Pay sheets   open a sheet: amend, approve, send back, record payment,
                 print pay slips, download for the bank
    Employees    THE ONE FORM for an employee: code, name, department, how they
                 are paid and their opening balance — one person or many in a
                 grid, typed or pasted from Excel; their ledger and advances
    Ledger       everybody in one place: owed before, earned, deducted, paid,
                 owed at the end, between any two dates; click for a statement
    Advances     advances and loans, and how much of each is still owed

  THE PAGE DECIDES NOTHING. Every figure comes from /api/v1/finance/...; the
  server works out each line itself and checks every amendment, every
  permission and every date again (includes/att/FinanceService.php). The
  totals this page shows while you type are a courtesy; the sheet that is
  saved is the one the server computed. A hidden button is a courtesy too.

  Money travels as whole paisa. Names are written with textContent throughout.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!zfin_can_open()) {
    http_response_code(403);
    exit('You do not have permission to open People Finance.');
}
page_header('People Finance');
?>
<style>
/* ENTERPRISE LOOK — compact, high-density desktop working area.
   The navy menu on the left is the app's own. This page adds a compact
   title, a white working panel with a tab bar, square-cornered controls,
   and accounting tables: grey header, thin rules, right-aligned figures in
   even-width digits, a shaded totals row. Phones keep working (see 820px). */
.content{padding:16px 20px}
.topbar{margin-bottom:12px}
.topbar h1{font:600 20px/1.25 'IBM Plex Sans','Segoe UI',Inter,system-ui,Arial,sans-serif;color:#0f2742;letter-spacing:0;margin:0}
.topbar .lead{font:400 12.5px/1.4 'IBM Plex Sans','Segoe UI',Inter,system-ui,Arial,sans-serif;color:#5b6b7c;margin:3px 0 0}
.fin{--ff:'IBM Plex Sans','Segoe UI',Inter,system-ui,-apple-system,Roboto,Arial,sans-serif;
  --navy:#0f2742;--pri:#0a5fb4;--pri-d:#084b8f;--ink:#1d2733;--muted:#5b6b7c;--line:#d5dbe3;--line2:#e7ebf0;--head:#f4f6f9;--hover:#f0f6fc;--sel:#e1edf9;
  --card:#fff;--soft:#f4f6f9;--accent:#0a5fb4;--accent2:#0a5fb4;--earn:#107e3e;--paid:#3d3fa3;--owed:#b45309;
  --warn-bg:#fef6ea;--warn-ink:#7a4100;--bad:#bb0000;--bad-bg:#ffefef;
  color:var(--ink);font:400 13px/1.45 var(--ff);background:#fff;border:1px solid var(--line);border-radius:6px;box-shadow:0 1px 2px rgba(15,39,66,.06)}
.fin *{box-sizing:border-box}
.fin [hidden]{display:none!important}
.fin button,.fin input,.fin select,.fin textarea{font-family:var(--ff)}
.fin h2{font-size:15px;font-weight:600;color:var(--navy);margin:0}
.fin h3{font-size:13px;font-weight:600;color:var(--navy);margin:0}
.fin .muted{color:var(--muted)}.fin .small{font-size:12px}
/* the tab bar: the top edge of the white panel. width:0 + min-width:100% keeps a phone from scrolling sideways */
.fin .tabs{width:0;min-width:100%;display:flex;gap:2px;overflow-x:auto;scrollbar-width:none;background:#fff;border-bottom:1px solid var(--line);border-radius:6px 6px 0 0;padding:0 10px;margin:0;position:sticky;top:0;z-index:5}
.fin .tabs::-webkit-scrollbar{display:none}
.fin .tabs button{flex:0 0 auto;border:0;border-bottom:3px solid transparent;background:transparent;color:var(--muted);font:600 13px/1 var(--ff);padding:13px 14px 10px;cursor:pointer;white-space:nowrap}
.fin .tabs button:hover{color:var(--pri)}
.fin .tabs button[aria-selected=true]{color:var(--pri);border-bottom-color:var(--pri)}
.fin .tabs .dot{display:inline-block;min-width:18px;padding:2px 5px;margin-left:6px;border-radius:3px;background:var(--owed);color:#fff;font-size:11px;font-weight:700;line-height:1.2}
#fin-body{padding:14px 16px 18px}
/* Employees list: the tick column, the bar that appears when someone is ticked, today's chip, the advance line */
.fin td.tick,.fin th.tick{width:30px;padding-right:0}
.fin td.tick input,.fin th.tick input{width:16px;height:16px;margin:0;vertical-align:middle}
.fin .selbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:var(--sel);border:1px solid #c5dbf2;border-radius:4px;padding:7px 10px}
.fin .selbar b{color:var(--navy);font-size:12.5px;margin-right:4px}
.fin .tchip{display:inline-block;margin-left:2px;padding:0 6px;border-radius:3px;font-size:11px;font-weight:600;line-height:17px;white-space:nowrap;vertical-align:1px}
.fin .tchip.in{background:#e1edf9;color:#17467c}.fin .tchip.done{background:#e7f4ec;color:#107e3e}.fin .tchip.bad{background:var(--bad-bg);color:var(--bad)}
.fin .tchip.none{background:#eef1f5;color:var(--muted)}.fin .tchip.off{background:#e3e6ea;color:#4b5563}
.fin .advline{font-size:11.5px;color:var(--owed);font-weight:600;margin-top:2px;white-space:nowrap}
.fin .stack{display:grid;gap:12px}
.fin .cardx{background:#fff;border:1px solid var(--line);border-radius:4px;padding:12px 14px;min-width:0}
.fin .cardx.scroll{padding:0}
.fin .cardx>h3:first-child{padding-bottom:8px;margin-bottom:8px;border-bottom:1px solid var(--line2)}

/* Home: a row of figures, then the process */
.fin .kpirow{display:grid;grid-template-columns:minmax(0,1.5fr) repeat(3,minmax(0,1fr));gap:10px}
.fin .hero{background:#fff;color:var(--ink);border:1px solid var(--line);border-top:3px solid var(--navy);border-radius:4px;padding:10px 14px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}
.fin .hero .k{font-size:12px;font-weight:600;color:var(--muted)}
.fin .hero .v{font-size:26px;font-weight:600;line-height:1.15;color:var(--navy);font-variant-numeric:tabular-nums}
.fin .hero .v::before{content:'Rs ';font-size:13px;font-weight:600;color:var(--muted)}
.fin .hero .n{font-size:12px;color:var(--muted)}
.fin .steps{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.fin .stepc{background:#fff;border:1px solid var(--line);border-top:3px solid var(--owed);border-radius:4px;padding:10px 14px;text-align:left;font:inherit;color:inherit;cursor:pointer;display:flex;flex-direction:column;gap:2px;transition:border-color .12s,background .12s}
.fin button.stepc:hover{background:var(--hover);border-color:var(--pri)}
.fin .stepc i{display:none}
.fin .stepc.s2{border-top-color:var(--earn)}.fin .stepc.s3{border-top-color:var(--paid)}
.fin .stepc b{order:2;display:block;font-size:24px;font-weight:600;line-height:1.15;color:var(--navy);font-variant-numeric:tabular-nums}
.fin .stepc span{order:1;font-size:12px;font-weight:600;color:var(--muted)}
.fin .flow{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));border:1px solid var(--line);border-radius:4px;overflow:hidden}
.fin .flow button{position:relative;border:0;border-right:1px solid var(--line2);background:#fff;text-align:left;padding:10px 22px 10px 12px;font:inherit;color:inherit;cursor:pointer;display:grid;gap:3px;align-content:start}
.fin .flow button:last-child{border-right:0}
.fin .flow button:hover{background:var(--hover)}
.fin .flow button:not(:last-child)::after{content:'';position:absolute;right:8px;top:50%;width:7px;height:7px;border-top:2px solid #9aa8b7;border-right:2px solid #9aa8b7;transform:translateY(-50%) rotate(45deg)}
.fin .flow .no{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:var(--navy);color:#fff;font-size:11px;font-weight:700;margin-right:6px}
.fin .flow b{font-size:13px;color:var(--navy);display:flex;align-items:center}
.fin .flow span{font-size:12px;color:var(--muted);line-height:1.35}
.fin .flow small{font-size:11px;color:var(--pri);font-weight:600}
.fin .flowh{display:flex;justify-content:space-between;align-items:baseline;margin:2px 0 -4px}
.fin .grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px;align-items:start}
.fin .kv{display:grid;grid-template-columns:1fr auto;font-variant-numeric:tabular-nums}
.fin .kv>*{padding:6px 0;border-bottom:1px solid var(--line2)}
.fin .kv>b{text-align:right;font-weight:600}
.fin .who{display:grid;grid-template-columns:1fr repeat(3,72px);gap:0;font-size:12.5px;align-items:center}
.fin .who>*{padding:5px 4px;border-bottom:1px solid var(--line2)}
.fin .who .hd{font-size:11.5px;font-weight:600;color:var(--muted);text-align:center;background:var(--head)}
.fin .who>span:first-child{background:var(--head)}
.fin .who .y{color:var(--earn);font-weight:700;text-align:center}.fin .who .n{color:#b9c4d0;text-align:center}

/* buttons and inputs */
.fin .btn{display:inline-flex;align-items:center;justify-content:center;gap:4px;border:1px solid #b9c4d0;background:#fff;color:var(--pri);font:600 13px/1.2 var(--ff);padding:6px 12px;min-height:30px;border-radius:4px;cursor:pointer;white-space:nowrap;text-decoration:none;box-shadow:none;transition:background .12s,border-color .12s}
.fin .btn:hover{background:var(--hover);border-color:var(--pri)}
.fin .btn.pri,.fin .btn.light{background:var(--pri);border-color:var(--pri);color:#fff}
.fin .btn.pri:hover,.fin .btn.light:hover{background:var(--pri-d);border-color:var(--pri-d)}
.fin .btn.danger{color:var(--bad);border-color:#e3b9b9}
.fin .btn.danger:hover{background:var(--bad-bg);border-color:var(--bad)}
.fin .btn.sm{padding:3px 9px;min-height:26px;font-size:12px}
.fin .btn:disabled{opacity:.45;cursor:default}
.fin .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.fin .seg{display:inline-flex;flex-wrap:wrap;border:1px solid #b9c4d0;border-radius:4px;overflow:hidden;background:#fff}
.fin .seg button{border:0;border-right:1px solid var(--line);background:#fff;color:var(--ink);font:600 12.5px/1 var(--ff);padding:8px 13px;cursor:pointer}
.fin .seg button:last-child{border-right:0}
.fin .seg button:hover{background:var(--hover)}
.fin .seg button[aria-pressed=true]{background:var(--sel);color:var(--pri);box-shadow:inset 0 -2px 0 var(--pri)}
.fin .seg.mini button{padding:5px 10px;font-size:12px}
.fin .fld{display:grid;gap:3px;font-size:12px;font-weight:600;color:var(--muted);min-width:0}
.fin input[type=text],.fin input[type=date],.fin input[type=month],.fin input[type=search],.fin input[type=number],.fin select,.fin textarea{font:400 13px/1.3 var(--ff);color:var(--ink);background:#fff;border:1px solid #b9c4d0;border-radius:4px;padding:6px 8px;min-height:30px;width:100%;min-width:0}
.fin input:hover,.fin select:hover{border-color:#8396a8}
.fin input:focus,.fin select:focus,.fin textarea:focus{outline:none;border-color:var(--pri);box-shadow:0 0 0 2px rgba(10,95,180,.16)}
.fin button:focus-visible{outline:2px solid var(--pri);outline-offset:1px}
.fin input:disabled,.fin select:disabled{background:var(--soft);border-color:var(--line2);color:var(--muted)}
.fin input[type=checkbox]{accent-color:var(--pri)}
.fin .formgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;align-items:end}
/* status: square chips, colour by meaning */
.fin .pill{display:inline-block;font-size:11.5px;font-weight:600;padding:2px 8px;border-radius:3px;background:#eef1f5;color:#3b4a5a;white-space:nowrap}
.fin .pill.waiting,.fin .pill.amend{background:var(--warn-bg);color:var(--warn-ink)}
.fin .pill.approved{background:var(--sel);color:var(--pri)}
.fin .pill.paid,.fin .pill.posted{background:#e7f4ea;color:var(--earn)}
.fin .pill.returned,.fin .pill.cancelled,.fin .pill.none{background:var(--bad-bg);color:var(--bad)}
.fin .av{width:30px;height:30px;border-radius:50%;background:#e1e9f3;color:var(--navy);font-weight:700;font-size:11.5px;display:flex;align-items:center;justify-content:center;flex:0 0 auto}
.fin .nm b{display:block;font-size:13px;font-weight:600}.fin .nm span{font-size:12px;color:var(--muted)}
.fin .num{font-variant-numeric:tabular-nums;text-align:right;white-space:nowrap}
.fin .err{background:var(--bad-bg);border:1px solid #f0b8b8;border-left:4px solid var(--bad);color:#8e0000;border-radius:4px;padding:8px 12px;font-weight:600;font-size:13px}
.fin .why{background:var(--warn-bg);border:1px solid #f1d3a2;border-left:4px solid #e07b00;color:var(--warn-ink);border-radius:4px;padding:8px 12px;font-size:12.5px}
.fin .empty{text-align:center;color:var(--muted);padding:24px 10px}

/* Prepare: one row per person, like a table */
.fin .plist{display:grid;gap:0;border:1px solid var(--line);border-radius:4px;overflow:hidden}
.fin .stack>.phead+.plist{margin-top:-12px;border-top:0;border-radius:0 0 4px 4px}
.fin .prow{background:#fff;border:0;border-bottom:1px solid var(--line2);border-radius:0;padding:7px 12px;display:grid;grid-template-columns:auto auto minmax(0,1.1fr) minmax(0,2fr) auto auto;grid-template-areas:"cb av nm sum net act";gap:4px 12px;align-items:center;transition:opacity .2s,background .12s}
.fin .prow:last-child{border-bottom:0}
.fin .prow:hover{background:#fafcfe}
.fin .prow.off{opacity:.5}
.fin .prow.open{background:#f7fafd;box-shadow:inset 3px 0 0 var(--pri)}
.fin .prow>input[type=checkbox]{grid-area:cb;width:16px;height:16px;margin:0}
.fin .prow .av{grid-area:av}.fin .prow .nm{grid-area:nm;min-width:0}.fin .prow .sum{grid-area:sum;font-size:12px;color:var(--muted);font-variant-numeric:tabular-nums;min-width:0}
.fin .prow .net{grid-area:net;text-align:right;font-variant-numeric:tabular-nums}
.fin .prow .net b{display:block;font-size:14px;font-weight:600}
.fin .prow .net s{display:block;font-size:11.5px;color:var(--muted)}
.fin .prow .act{grid-area:act}
.fin .prow .lines{grid-column:1/-1;border-top:1px solid var(--line2);margin-top:4px;padding-top:8px;display:grid;gap:2px}
.fin .phead{display:grid;grid-template-columns:16px 30px minmax(0,1.1fr) minmax(0,2fr) 110px 78px;gap:12px;padding:7px 12px;background:var(--head);border:1px solid var(--line);border-radius:4px 4px 0 0;font-size:11.5px;font-weight:600;color:#3b4a5a}
.fin .phead.nocb{grid-template-columns:30px minmax(0,1.1fr) minmax(0,2fr) 110px 78px}
.fin .prow.nocb{grid-template-columns:auto minmax(0,1.1fr) minmax(0,2fr) auto auto;grid-template-areas:"av nm sum net act"}
.fin .phead.nocb{grid-template-columns:30px minmax(0,1.1fr) minmax(0,2fr) 110px 150px}

/* one line of one person's pay */
.fin .ln{display:grid;grid-template-columns:minmax(0,1.5fr) 96px 130px minmax(0,1.6fr) 28px;gap:4px 10px;align-items:center;padding:4px 8px;border-radius:0;border-bottom:1px solid var(--line2)}
.fin .ln.hd{font-size:11.5px;font-weight:600;color:#3b4a5a;background:var(--head);padding-block:6px}
.fin .ln .lb b{font-weight:600}.fin .ln .lb small{display:block;color:var(--muted);font-size:11.5px}
.fin .ln .calc{text-align:right;font-variant-numeric:tabular-nums;color:var(--muted);font-size:12.5px}
.fin .ln .amt{display:flex;align-items:center;gap:4px}
.fin .ln .amt i{font-style:normal;font-weight:700;width:10px;color:var(--muted)}
.fin .ln.deduct .amt i{color:var(--bad)}
.fin .ln input.n{text-align:right;font-weight:600;font-variant-numeric:tabular-nums;padding:4px 8px;min-height:28px}
.fin .ln input.r{padding:4px 8px;min-height:28px}
.fin .ln input.n:disabled{background:var(--soft);border-color:transparent;color:var(--muted)}
.fin .ln.chg{background:var(--warn-bg)}
.fin .ln.chg input.n{border-color:var(--owed)}
.fin .ln.bad input.n,.fin .ln.bad input.r{border-color:var(--bad)}
.fin .ln .fixed{font-size:12px;color:var(--muted)}
.fin .ln .x{border:0;background:none;color:var(--bad);font-size:18px;line-height:1;cursor:pointer;padding:2px 6px}
.fin .ln.ro{grid-template-columns:minmax(0,1.5fr) 96px 110px minmax(0,1.6fr)}
.fin .ln.ro .now{text-align:right;font-weight:600;font-variant-numeric:tabular-nums}
.fin .ln.ro.deduct .now{color:var(--bad)}
.fin .ln.tot{background:var(--head);border-top:1px solid var(--line);border-bottom:0;padding-block:7px;font-weight:600}
.fin .sumbar{position:sticky;bottom:10px;background:var(--navy);color:#fff;border-radius:4px;padding:9px 14px;display:flex;gap:12px;align-items:center;justify-content:space-between;z-index:4;box-shadow:0 6px 18px rgba(15,39,66,.25);margin-top:4px}
.fin .sumbar b{font-size:17px;font-weight:600;display:block;font-variant-numeric:tabular-nums}
.fin .sumbar small{font-size:12px;opacity:.8}
.fin .sumbar .btn{background:#fff;color:var(--navy);border-color:#fff}
.fin .sumbar .btn:hover{background:#e1edf9}

/* Pay sheets */
.fin .scard{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:4px 12px;align-items:center;width:100%;text-align:left;font:inherit;color:inherit;cursor:pointer;padding:9px 14px;transition:background .12s,border-color .12s}
.fin .scard:hover{background:var(--hover);border-color:var(--pri)}
.fin .scard .amt b{font-size:14px;font-weight:600;display:block;text-align:right;font-variant-numeric:tabular-nums}
.fin .track3{display:grid;grid-template-columns:repeat(3,1fr);gap:0;border:1px solid var(--line);border-radius:4px;overflow:hidden}
.fin .track3 div{font-size:12px;padding:7px 10px;background:#fff;border-right:1px solid var(--line2);color:var(--muted)}
.fin .track3 div:last-child{border-right:0}
.fin .track3 div.done{background:#e7f4ea;color:var(--earn)}
.fin .track3 div b{display:block;font-size:12.5px;color:inherit}

/* accounting tables */
.fin table.t{width:100%;border-collapse:separate;border-spacing:0;font-size:13px;min-width:0}
.fin table.t th{text-align:left;font-size:11.5px;font-weight:600;text-transform:none;letter-spacing:0;color:#3b4a5a;background:var(--head);padding:7px 10px;border-bottom:1px solid var(--line);white-space:nowrap}
.fin table.t td{padding:6px 10px;border-bottom:1px solid var(--line2);vertical-align:middle}
.fin table.t th:first-child,.fin table.t td:first-child{padding-left:14px}
.fin table.t th:last-child,.fin table.t td:last-child{padding-right:14px}
.fin table.t td b{font-weight:600}
.fin table.t tr.click{cursor:pointer}.fin table.t tr.click:hover td{background:var(--hover)}
.fin table.t tr.click:focus{outline:2px solid var(--pri);outline-offset:-2px}
.fin table.t tr.dead td{text-decoration:line-through;color:var(--muted)}
.fin table.t tr.tot td{background:var(--head);border-top:1px solid var(--line);border-bottom:0;font-weight:600}
.fin table.t tr.open td{background:#fafbfc}
.fin table.bulk td{vertical-align:middle;padding:4px 5px}.fin table.bulk input.n{text-align:right;min-width:92px}.fin table.bulk select{min-width:118px}
.fin table.bulk input:disabled{background:var(--soft);border-color:transparent}
.fin .scroll{overflow:auto;width:0;min-width:100%}
.fin .bar-r{height:6px;border-radius:3px;background:var(--line2);overflow:hidden;min-width:70px}
.fin .bar-r i{display:block;height:100%;background:var(--earn);border-radius:3px;transition:width .8s cubic-bezier(.2,.8,.2,1)}
.fin table.stmt td{white-space:nowrap}.fin table.stmt td:nth-child(2){font-family:'IBM Plex Mono',ui-monospace,Consolas,monospace;font-size:12px}
.fin table.stmt td:nth-child(3){white-space:normal;min-width:180px}
.fin table.stmt tr.tot td{font-family:var(--ff);font-size:13px}
/* Employee Ledger: filter panel, coloured action buttons, figures, navy-headed ledger */
.fin .lggrid{display:grid;grid-template-columns:2fr 1.2fr 1.2fr 1fr 1fr 1fr;gap:10px;align-items:end}
.fin .lgacts{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.fin .lgacts .btn{color:#fff;border:0;min-height:32px;padding:7px 14px}
.fin .lgacts .act-earn{background:#1f7a3a}.fin .lgacts .act-earn:hover{background:#17602d}
.fin .lgacts .act-ded{background:#d9730d}.fin .lgacts .act-ded:hover{background:#b85f08}
.fin .lgacts .act-pay{background:var(--pri)}.fin .lgacts .act-pay:hover{background:var(--pri-d)}
.fin .lgacts .act-adv{background:#6b46c1}.fin .lgacts .act-adv:hover{background:#5a37a8}
.fin .lgacts .act-prod{background:#0f766e}.fin .lgacts .act-prod:hover{background:#0b5f59}
.fin table.t.entry.padj td{padding:5px 6px}
.fin table.t.entry.padj input.n{min-width:0;width:96px}
.fin table.t.entry.padj td:nth-child(8) input{min-width:200px}
.fin table.t.entry.padj input.chg{border-color:#e07b00;background:var(--warn-bg)}
.fin table.t.entry.padj tr.sub td{background:#f7fafd;border-bottom:1px solid #e3ebf5;font-size:12.5px}
.fin table.t.entry.padj tr.sub.h td{font-size:11px;font-weight:600;color:var(--muted);padding-top:4px;padding-bottom:4px}
.fin table.t.entry.padj tr.sub td:first-child{box-shadow:inset 3px 0 0 var(--pri)}
.fin table.t.entry.padj .tog{border:0;background:none;color:var(--pri);font:600 12px/1 var(--ff);cursor:pointer;white-space:nowrap;padding:4px}
.fin table.t.entry.padj td.c-earn{color:#1f7a3a;font-weight:700}.fin table.t.entry.padj td.c-ded{color:#c2620a;font-weight:700}.fin table.t.entry.padj td.nil{color:#b6c0cb}
.fin table.t.entry.padj input[type=checkbox]{width:15px;height:15px;min-width:0;min-height:0;padding:0}
.fin .btn.pct[aria-pressed=true]{background:var(--pri);border-color:var(--pri);color:#fff}
.fin .lgtip{margin-left:auto;background:var(--sel);color:#123d6a;border:1px solid #c5dbf2;border-radius:4px;padding:6px 10px;font-size:12px}
.fin .lgcards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
.fin .lgcard{background:#fff;border:1px solid var(--line);border-left:4px solid var(--navy);border-radius:4px;padding:9px 12px;display:grid;gap:2px}
.fin .lgcard span{font-size:12px;font-weight:600;color:var(--muted)}
.fin .lgcard b{font-size:19px;font-weight:600;font-variant-numeric:tabular-nums;color:var(--navy)}
.fin .lgcard.k-earn{border-left-color:#1f7a3a;background:#f4faf5}.fin .lgcard.k-earn b{color:#1f7a3a}
.fin .lgcard.k-ded{border-left-color:#d9730d;background:#fff8f1}.fin .lgcard.k-ded b{color:#b85f08}
.fin .lgcard.k-pay{border-left-color:var(--pri);background:#f3f8fd}.fin .lgcard.k-pay b{color:var(--pri)}
.fin .lghead .av{width:36px;height:36px}
.fin table.stmt.lg th{background:var(--navy);color:#fff;border-bottom:0;font-size:12px;padding:8px 10px}
.fin table.stmt.lg tr.open td{background:#e8f1fb;font-weight:600}
.fin table.stmt.lg tr.tot td{background:#e8f1fb;border-top:1px solid #b9d2ee;font-weight:700}
.fin table.stmt.lg td{font-size:13px}
.fin table.stmt.lg td:nth-child(2){font-family:var(--ff);font-weight:500;font-size:12.5px}
.fin table.stmt td.c-earn{color:#1f7a3a;font-weight:600}.fin table.stmt td.c-ded{color:#c2620a;font-weight:600}.fin table.stmt td.c-pay{color:var(--pri);font-weight:600}
.fin table.stmt td.nil{color:#b6c0cb}
.fin .gatepic{width:120px;height:150px;border:1px solid var(--line);border-radius:4px;background:var(--soft);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:12px;overflow:hidden;flex:0 0 auto}
.fin .gatepic img{width:100%;height:100%;object-fit:cover}
.fin .mwgrid{display:grid;grid-template-columns:auto 1fr auto auto 2fr;gap:10px;align-items:end}
.fin .mwgrid input[type=time]{width:auto;min-width:96px}.fin .mwgrid input[type=date]{width:auto}
.fin table.mw th.c,.fin table.mw td.c{text-align:center;padding-left:4px;padding-right:4px}
.fin table.mw th .sub{display:block;font-size:10.5px;font-weight:500;opacity:.75}
.fin .seg3{display:inline-flex;border:1px solid #b9c4d0;border-radius:4px;overflow:hidden;background:#fff}
.fin .seg3.chg{outline:2px solid #e07b00;outline-offset:1px}
.fin .seg3 button{width:26px;padding:4px 0;border:0;border-right:1px solid var(--line2);background:#fff;color:var(--muted);font:700 12px/1 var(--ff);cursor:pointer}
.fin .seg3 button:last-child{border-right:0}.fin .seg3 button:hover{background:var(--hover)}
.fin .seg3 button.f.on{background:#1f7a3a;color:#fff}.fin .seg3 button.h.on{background:#d9730d;color:#fff}.fin .seg3 button.a.on{background:#8a96a3;color:#fff}
.fin .mwscan{display:inline-grid;line-height:1.1;color:var(--pri);font-weight:700;font-size:12px;background:#eef5fc;border:1px solid #c5dbf2;border-radius:4px;padding:3px 8px}
.fin .mwscan small,.fin .mwsheet small{font-weight:500;font-size:10px;color:var(--muted)}
.fin .mwsheet{display:inline-grid;line-height:1.1;color:#3b4a5a;font-weight:600;font-size:12px;background:#eef1f5;border:1px solid var(--line);border-radius:4px;padding:3px 8px}
.fin .mwlock{color:#b6c0cb}
.fin .daypick{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);border-radius:4px;padding:5px 9px;font-size:12.5px;cursor:pointer}
.fin .daypick input{width:15px;height:15px;margin:0}
.fin table.mw input[type=checkbox]{width:16px;height:16px;margin:0}
.fin .mwkey{display:inline-block;width:18px;text-align:center;border-radius:3px;color:#fff;font-weight:700;font-size:11px;margin-left:8px}
.fin .mwkey.f{background:#1f7a3a}.fin .mwkey.h{background:#d9730d}.fin .mwkey.a{background:#8a96a3}
.fin .btn.go{background:#1f7a3a;border-color:#1f7a3a}.fin .btn.go:hover{background:#17602d}
@media (max-width:1100px){.fin .mwgrid{grid-template-columns:1fr 1fr}}
.fin table.t.entry td{padding:5px 6px;vertical-align:middle}
.fin table.t.entry input,.fin table.t.entry select{min-height:28px;padding:4px 7px}
.fin table.t.entry input.n{text-align:right;font-weight:600;min-width:110px}
.fin table.t.entry td:first-child input{min-width:210px}
.fin table.t.entry select[aria-label=Type]{min-width:160px}
.fin table.t.entry input.bad{border-color:var(--bad)}
.fin table.t.entry .x{border:0;background:none;color:var(--bad);font-size:18px;cursor:pointer;padding:2px 6px}
.fin table.t.entry select[hidden]{display:none}
.fin table.t.entry td:nth-child(3) select{margin-top:4px}
@media (max-width:1100px){.fin .lggrid{grid-template-columns:repeat(3,minmax(0,1fr))}.fin .lgcards{grid-template-columns:repeat(2,minmax(0,1fr))}.fin .lgtip{margin-left:0}}
@media (max-width:620px){.fin .lggrid,.fin .lgcards{grid-template-columns:1fr}}

/* dialogs */
.fin-shade{position:fixed;inset:0;background:rgba(15,39,66,.42);z-index:900;display:flex;align-items:center;justify-content:center;padding:14px}
.fin-shade[hidden]{display:none}
.fin-shade .box{background:#fff;color:#1d2733;width:100%;max-width:560px;max-height:92vh;overflow:auto;border:0;border-radius:6px;padding:16px 18px;display:grid;gap:12px;box-shadow:0 18px 50px rgba(15,39,66,.32)}
.fin-shade .box>h2:first-child,.fin-shade .box>.row:first-child{padding-bottom:10px;border-bottom:1px solid var(--line2)}
.fin-shade .box.wide{max-width:920px}
.fin-shade .box.xwide{max-width:1320px}
.fin table.staff input,.fin table.staff select{min-width:92px;padding:4px 7px;min-height:28px;font-size:12.5px}
.fin table.staff input[type=checkbox]{min-width:0;width:16px;height:16px;min-height:0;padding:0;margin:0}
.fin table.staff select[data-k=type]{min-width:128px}.fin table.staff select[data-k=dept]{min-width:160px}.fin table.staff thead th{position:sticky;top:0;z-index:1}
.fin table.staff td:nth-child(3) input{min-width:150px}
.fin-toast{position:fixed;left:50%;transform:translateX(-50%);bottom:24px;background:#0f2742;color:#fff;padding:9px 16px;border-radius:4px;font:600 13px/1.4 'IBM Plex Sans','Segoe UI',Inter,system-ui,Arial,sans-serif;max-width:calc(100% - 32px);z-index:950;box-shadow:0 8px 22px rgba(15,39,66,.3)}
.fin-toast[hidden]{display:none}
.fin-toast.bad{background:#bb0000}
@media (prefers-reduced-motion:no-preference){
  .fin-shade .box{animation:finpop .18s cubic-bezier(.2,.8,.2,1)}
  .fin-toast{animation:fintoast .2s ease}
}
@keyframes finpop{from{transform:translateY(8px);opacity:0}to{opacity:1}}
@keyframes fintoast{from{transform:translate(-50%,10px);opacity:0}to{transform:translate(-50%,0);opacity:1}}
@media (max-width:1100px){
  .fin .kpirow{grid-template-columns:repeat(2,minmax(0,1fr))}
  .fin .flow{grid-template-columns:1fr}
  .fin .flow button{border-right:0;border-bottom:1px solid var(--line2)}
  .fin .flow button::after{display:none}
}
@media (max-width:820px){
  .fin .phead{display:none}
  .fin .prow,.fin .prow.nocb{grid-template-columns:auto auto minmax(0,1fr) auto;grid-template-areas:"cb av nm net" "sum sum sum act"}
  .fin .prow.nocb{grid-template-columns:auto minmax(0,1fr) auto;grid-template-areas:"av nm net" "sum sum act"}
  .fin .ln,.fin .ln.ro{grid-template-columns:minmax(0,1fr) 120px 28px;grid-template-areas:"lb amt x" "calc calc calc" "r r r"}
  .fin .ln.ro{grid-template-columns:minmax(0,1fr) auto;grid-template-areas:"lb now" "calc calc" "r r"}
  .fin .ln.hd{display:none}
  .fin .ln .lb{grid-area:lb}.fin .ln .calc{grid-area:calc;text-align:left;font-size:12px}.fin .ln .amt{grid-area:amt}.fin .ln .rs{grid-area:r}.fin .ln .x{grid-area:x}.fin .ln .now{grid-area:now}
  .fin .ln .calc:empty,.fin .ln .rs:empty{display:none}
  .fin .steps,.fin .kpirow{grid-template-columns:1fr}
  .fin .stack>.phead+.plist{margin-top:0;border-top:1px solid var(--line);border-radius:4px}
  .content{padding:10px}
  #fin-body{padding:10px}
  .fin .sumbar{bottom:6px}
}
@media print{
  body *{visibility:hidden!important}
  #fin-print,#fin-print *{visibility:visible!important}
  #fin-print{position:absolute;left:0;top:0;width:100%;display:block!important;color:#000;font:12px/1.4 Arial,sans-serif}
  #fin-print .slip{page-break-inside:avoid;border:1px solid #999;border-radius:6px;padding:10px 12px;margin:0 0 10px}
  #fin-print table{width:100%;border-collapse:collapse}
  #fin-print td,#fin-print th{padding:3px 6px;border-bottom:1px solid #ddd;text-align:left}
  #fin-print td.r,#fin-print th.r{text-align:right}
  #fin-print table.elist{margin-top:8px;font-size:10.5px}#fin-print table.elist th{background:#eee!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  #fin-print table.elist tr.grp td{background:#f1f4f8!important;font-weight:700;padding-top:7px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  #fin-print table.elist tr.sub td,#fin-print table.elist tfoot td{font-weight:700;border-top:1px solid #999}
  #fin-print table.elist td.signc{border-bottom:1px solid #777;min-width:90px}
  #fin-print table.elist tr{page-break-inside:avoid}
  #fin-print table.elist th{color:#000!important;text-transform:none!important;letter-spacing:0!important;font-size:inherit!important;font-weight:700;vertical-align:bottom;line-height:1.2}
  #fin-print table.elist td{white-space:nowrap;padding-top:2px;padding-bottom:2px;font-size:inherit!important;color:#000!important}
  #fin-print table.elist.dense{font-size:8.6px}#fin-print table.elist.dense td,#fin-print table.elist.dense th{padding-left:3px;padding-right:3px}
  #fin-print h1{font-size:15px;margin:0 0 2px}#fin-print .sig{display:flex;justify-content:space-between;margin-top:22px;font-size:11px}
}
#fin-print{display:none}
/* Employee list: options, column boxes, the table (also used in print) */
.fin .elist-opts .formgrid{grid-template-columns:repeat(4,minmax(0,1fr))}
.fin .elist-presets{gap:6px;margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid var(--line2)}
.fin .elist-presets .preset.on{background:var(--sel);border-color:var(--pri);color:var(--navy);font-weight:700}
.fin .elist-cols{display:flex;flex-wrap:wrap;gap:6px 14px;margin-top:8px}
.fin .elist-cols label{display:inline-flex;align-items:center;gap:5px;font-size:12.5px;cursor:pointer;white-space:nowrap}
.fin .elist-cols label.money{color:var(--owed)}
.fin .elist-cols input{width:15px;height:15px;margin:0}
/* the window is the screen's height: the options stay on top, the list takes the rest and scrolls on its own */
.fin-shade .box.elist-box{height:92vh;display:flex;flex-direction:column;gap:10px;overflow:hidden}
.fin-shade .box.elist-box>*{flex:none}
.fin-shade .box.elist-box>.elist-wrap{flex:1 1 0;min-height:160px}
.fin .elist-wrap,.fin-shade .elist-wrap{flex:1 1 auto;min-height:160px;overflow:auto;border:1px solid var(--line);border-radius:4px}
.fin-shade .elist-colbar{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:10px}
.fin-shade .elist-colbar .tog[aria-expanded="true"] .car{transform:rotate(180deg)}
.fin-shade .elist-colbar .tog{white-space:normal;text-align:left}
.fin-shade .elist-colbar .car{display:inline-block;transition:transform .15s;margin-left:4px}
@media (max-width:700px){
  .fin-shade .box.elist-box{height:auto;max-height:94vh;overflow:auto;display:grid}
  .fin-shade .box.elist-box>.elist-wrap{min-height:0}
  .fin .elist-wrap,.fin-shade .elist-wrap{max-height:none;overflow-x:auto}
  .fin-shade .elist-presets .btn{flex:1 1 auto}
}
.fin table.elist{font-size:12px}.fin table.elist td{white-space:nowrap}
.fin table.elist tr.grp td{background:var(--sel);color:var(--navy);font-size:12.5px}
.fin table.elist tr.sub td{background:var(--head);font-weight:600}
.fin table.elist tfoot td{background:var(--head);font-weight:700;border-top:2px solid var(--line)}
.fin table.elist td.signc{min-width:110px;border-bottom:1px solid #9aa7b4}
@media (max-width:820px){.fin .elist-opts .formgrid{grid-template-columns:1fr 1fr}}
</style>

<div class="topbar">
  <div>
    <h1>People Finance</h1>
    <p class="lead">Prepare → Approve → Pay, for fixed salaries, daily wages and contractors. Every changed figure keeps its reason.</p>
  </div>
</div>

<div class="fin" id="fin">
  <div class="tabs" role="tablist" id="fin-tabs"></div>
  <div id="fin-body" class="stack"><p class="muted">Loading…</p></div>
</div>
<div class="fin-shade" id="fin-shade" hidden></div>
<div class="fin-toast" id="fin-toast" role="status" hidden></div>
<div id="fin-print"></div>

<script src="assets/js/sorttable.js?v=1"></script>
<script>
(function(){
  'use strict';
  var API = 'api/v1/index.php?route=';
  var CSRF = <?= json_encode(csrf_token()) ?>;
  var body = document.getElementById('fin-body'), shade = document.getElementById('fin-shade'), toastEl = document.getElementById('fin-toast');
  var S = { caps: {}, depts: [], today: '', payDay: 4, tab: 'home', sheetFilter: 'waiting', counts: {} };
  var KIND = { salary: 'Fixed salary', daily: 'Daily wages', contract: 'Contractors', entry: 'Entry' };
  var KIND1 = { salary: 'Fixed salary', daily: 'Daily wages', contract: 'Contractor', entry: 'Entry' };
  var STATUS = { waiting: 'Waiting for approval', approved: 'Approved — ready to pay', paid: 'Paid', returned: 'Sent back', cancelled: 'Cancelled', posted: 'Posted to ledger' };
  var DAYS = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
  var ADD = { bonus: 'Bonus', other_earning: 'Other earning', deduction: 'Deduction' };

  // ------------------------------------------------------------ helpers
  function el(tag, attrs, kids){
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function(k){
      var v = attrs[k];
      if (k === 'text') n.textContent = v;
      else if (k === 'click' || k === 'change' || k === 'input' || k === 'keydown') { if (v) n.addEventListener(k, v); }
      else if (k === 'value') n.value = v;
      else if (k === 'checked') n.checked = !!v;
      else if (k === 'disabled') n.disabled = !!v;
      else if (k === 'hidden') n.hidden = !!v;
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
  /* Money: whole paisa in, text out. Never a float on the way to the server. */
  function rs(p){
    if (p === null || p === undefined) return '—';
    var neg = p < 0; p = Math.abs(p);
    var s = String(Math.floor(p / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ','), c = p % 100;
    return (neg ? '−' : '') + s + (c ? '.' + ('0' + c).slice(-2) : '');
  }
  function plain(p){ var c = p % 100; return String(Math.floor(p / 100)) + (c ? '.' + ('0' + c).slice(-2) : ''); }
  function toPaisa(v){
    v = String(v === null || v === undefined ? '' : v).replace(/,/g, '').trim();
    if (!/^\d{1,11}(\.\d{1,2})?$/.test(v)) return null;
    var a = v.split('.');
    return parseInt(a[0], 10) * 100 + (a[1] ? parseInt((a[1] + '0').slice(0, 2), 10) : 0);
  }
  function d2(n){ return ('0' + n).slice(-2); }
  function ymd(d){ return d.getUTCFullYear() + '-' + d2(d.getUTCMonth() + 1) + '-' + d2(d.getUTCDate()); }
  function dt(s){ var a = s.split('-'); return new Date(Date.UTC(+a[0], +a[1] - 1, +a[2])); }
  function addDays(s, n){ var d = dt(s); d.setUTCDate(d.getUTCDate() + n); return ymd(d); }
  function isoDay(s){ var w = dt(s).getUTCDay(); return w === 0 ? 7 : w; }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function nice(s){ if (!s) return ''; var d = dt(String(s).slice(0, 10)); return d.getUTCDate() + ' ' + MON[d.getUTCMonth()] + ' ' + d.getUTCFullYear(); }
  function period(k, f, t){
    if (k === 'entry') return nice(t);
    if (k === 'contract') return 'up to ' + nice(t);
    var a = dt(f), b = dt(t);
    if (k === 'salary' && a.getUTCDate() === 1 && addDays(t, 1).slice(8) === '01') return MON[a.getUTCMonth()] + ' ' + a.getUTCFullYear();
    return a.getUTCDate() + ' ' + MON[a.getUTCMonth()] + ' – ' + nice(t);
  }
  function initials(n){ return String(n || '?').split(/\s+/).slice(0, 2).map(function(x){ return x.charAt(0).toUpperCase(); }).join(''); }
  function btn(text, fn, cls, disabled){ return el('button', { type: 'button', class: 'btn ' + (cls || ''), text: text, click: fn, disabled: disabled }); }
  function show(nodes){ body.textContent = ''; nodes.forEach(function(n){ if (n) body.appendChild(n); }); }
  function fail(r){ return el('div', { class: 'err', text: (r && r.error) || 'Something went wrong.' }); }
  var toastT = null;
  function toast(msg, bad){
    toastEl.textContent = msg; toastEl.className = 'fin-toast' + (bad ? ' bad' : ''); toastEl.hidden = false;
    clearTimeout(toastT); toastT = setTimeout(function(){ toastEl.hidden = true; }, bad ? 6000 : 3200);
  }
  function modal(nodes, wide){
    shade.textContent = '';
    var box = el('div', { class: 'box fin' + (wide ? ' wide' : ''), role: 'dialog', 'aria-modal': 'true' }, nodes);
    shade.appendChild(box); shade.hidden = false;
    var f = box.querySelector('input,select,textarea,button'); if (f) f.focus();
    return box;
  }
  function closeModal(){ shade.hidden = true; shade.textContent = ''; }
  shade.addEventListener('click', function(e){ if (e.target === shade) closeModal(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !shade.hidden) closeModal(); });
  function field(label, input){ return el('label', { class: 'fld' }, [label, input]); }
  /* A small form in a dialog. fields: [{k,label,type,value,options,placeholder}] */
  function ask(title, note, fields, okText, onOk){
    var inputs = {}, errBox = el('div', { class: 'err', hidden: true });
    var grid = el('div', { class: 'formgrid' }, fields.map(function(f){
      var i;
      if (f.type === 'select') { i = el('select', {}, f.options.map(function(o){ return el('option', { value: o[0], text: o[1] }); })); i.value = f.value || f.options[0][0]; }
      else i = el('input', { type: f.type || 'text', value: f.value || '', placeholder: f.placeholder || '', inputmode: f.money ? 'decimal' : null, maxlength: f.type === 'date' ? null : 255 });
      inputs[f.k] = i;
      var w = field(f.label, i); if (f.wide) w.style.gridColumn = '1/-1';
      return w;
    }));
    var ok = btn(okText, function(){
      var v = {}; Object.keys(inputs).forEach(function(k){ v[k] = inputs[k].value.trim(); });
      ok.disabled = true; errBox.hidden = true;
      Promise.resolve(onOk(v)).then(function(r){
        ok.disabled = false;
        if (r && r.ok === false) { errBox.textContent = r.error || 'That could not be saved.'; errBox.hidden = false; }
      });
    }, 'pri');
    modal([el('h2', { text: title }), note ? el('p', { class: 'muted small', text: note }) : null, grid, errBox,
           el('div', { class: 'row', style: 'justify-content:flex-end' }, [btn('Close', closeModal), ok])]);
    return inputs;
  }

  // ------------------------------------------------------------ tabs
  var TABS = [['home', 'Home'], ['mark', 'Mark attendance'], ['prepare', 'Prepare pay'], ['sheets', 'Pay sheets'], ['people', 'Employees'], ['ledger', 'Employee Ledger'], ['advances', 'Advances & loans']];
  function drawTabs(){
    var t = document.getElementById('fin-tabs'); t.textContent = '';
    TABS.forEach(function(x){
      if (x[0] === 'prepare' && !S.caps.salary && !S.caps.wage) return;
      if (x[0] === 'mark' && !S.caps.mark) return;
      var n = x[0] === 'sheets' ? (S.caps.approve ? S.counts.waiting : 0) + (S.caps.pay ? S.counts.approved : 0) : 0;
      t.appendChild(el('button', { type: 'button', role: 'tab', 'aria-selected': S.tab === x[0] ? 'true' : 'false', click: function(){ go(x[0]); } },
        [x[1], n ? el('span', { class: 'dot', text: String(n) }) : null]));
    });
  }
  function go(tab, arg){
    S.tab = tab; drawTabs();
    try { history.replaceState(null, '', '#' + tab); } catch (e) {}
    ({ home: home, mark: markWeek, prepare: prepare, sheets: sheets, people: people, ledger: ledger, advances: advances })[tab](arg);
  }
  function refreshCounts(){
    return call('GET', '/finance/overview').then(function(r){
      if (r.ok) { S.caps = r.caps; S.depts = r.departments || []; S.today = r.today; S.payDay = r.pay_weekday; S.counts = r.sheets; drawTabs(); }
      return r;
    });
  }

  // ------------------------------------------------------------ home
  function home(){
    show([el('p', { class: 'muted', text: 'Loading…' })]);
    refreshCounts().then(function(r){
      if (!r.ok) return show([fail(r)]);
      var c = S.caps, heroV = el('div', { class: 'v', text: '0' });
      var hero = el('div', { class: 'hero' }, [
        el('div', {}, [el('div', { class: 'k', text: 'Owed to people right now' }), heroV,
          el('div', { class: 'n', text: 'Pay day is ' + DAYS[S.payDay] + ' · today is ' + DAYS[isoDay(S.today)] + ' ' + nice(S.today) })]),
        (c.salary || c.wage) ? btn('Prepare pay', function(){ go('prepare'); }, 'light sm') : null]);
      countUp(heroV, r.owed_paisa);
      var step = function(cls, n, label, filter){ return el('button', { type: 'button', class: 'stepc ' + cls, click: function(){ S.sheetFilter = filter; go('sheets'); } }, [el('i'), el('b', { text: String(n) }), el('span', { text: label })]); };
      var steps = el('div', { class: 'kpirow' }, [hero, step('s1', r.sheets.waiting, 'Sheets waiting for approval', 'waiting'), step('s2', r.sheets.approved, 'Approved, ready to pay', 'approved'), step('s3', r.sheets.paid, 'Sheets paid', 'paid')]);
      /* HOW PAY MOVES — the whole system in five boxes, each one opens its tab */
      var flow = el('div', { class: 'flow' }, [
        ['people', 'Employees', 'Who each person is, how they are paid, and what they were owed on day one.', 'HR · Accounts'],
        ['prepare', 'Prepare pay', 'The system works out every line. Change a line only with a reason.', 'Accounts · HR · Audit · HOD for own wages'],
        ['sheets', 'Approve', 'A second person checks the sheet and approves it, or sends it back.', 'Audit · Admin'],
        ['sheets', 'Pay', 'Record the cash or bank payment and print the pay slips.', 'Accounts · Admin'],
        ['ledger', 'Ledger', 'Every rupee earned, deducted and paid, person by person.', 'Everyone who may see pay']
      ].map(function(x, i){
        return el('button', { type: 'button', click: function(){ if (i === 2) S.sheetFilter = 'waiting'; if (i === 3) S.sheetFilter = 'approved'; go(x[0] === 'prepare' && !(c.salary || c.wage) ? 'sheets' : x[0]); } },
          [el('b', {}, [el('span', { class: 'no', text: String(i + 1) }), x[1]]), el('span', { text: x[2] }), el('small', { text: x[3] })]);
      }));
      var flowH = el('div', { class: 'flowh' }, [el('h3', { text: 'How pay moves through the system' }), el('span', { class: 'muted small', text: 'Click a step to open it' })]);
      var facts = el('div', { class: 'cardx' }, [el('h3', { text: 'At a glance' }), el('div', { class: 'kv', style: 'margin-top:10px' }, [
        el('span', { text: 'Advances and loans still to recover' }), el('b', { text: rs(r.advances_out_paisa) }),
        el('span', { text: 'Earned so far (all time)' }), el('b', { text: rs(r.earned_paisa) }),
        el('span', { text: 'Paid so far (all time)' }), el('b', { text: rs(r.paid_paisa) })]),
        el('div', { class: 'row', style: 'margin-top:10px' }, [btn('Employee list — print / download', employeeList, 'sm')]),
        r.no_setup ? el('div', { class: 'why', style: 'margin-top:12px' }, [r.no_setup + (r.no_setup === 1 ? ' active employee has' : ' active employees have') + ' no pay set up yet, so they cannot be put on a sheet. ',
          el('a', { href: '#people', text: 'Open People', click: function(e){ e.preventDefault(); go('people', 'none'); } })]) : null]);
      var who = el('div', { class: 'cardx' }, [el('h3', { text: 'Who does what' }), el('div', { class: 'who', style: 'margin-top:10px' },
        [el('span'), el('span', { class: 'hd', text: 'Prepare' }), el('span', { class: 'hd', text: 'Approve' }), el('span', { class: 'hd', text: 'Pay' })].concat(
          [['Admin', 1, 1, 1], ['Audit', 'salaries', 1, 0], ['Accounts', 1, 0, 1], ['HR', 'salaries', 0, 0], ['HOD', 'own dept wages', 0, 0]].reduce(function(a, x){
            a.push(el('b', { text: x[0] }));
            [x[1], x[2], x[3]].forEach(function(v){ a.push(el('span', { class: v ? 'y' : 'n', text: v === 1 ? '✓' : (v || '·'), style: typeof v === 'string' ? 'font-size:10.5px;font-weight:600' : null })); });
            return a; }, []))),
        el('p', { class: 'muted small', style: 'margin:10px 0 0', text: 'You can: ' + ([c.salary && 'prepare salaries', c.wage && 'prepare wages', c.approve && 'approve', c.pay && 'record payments', c.setup && 'set up pay'].filter(Boolean).join(', ') || 'look only') + '.' })]);
      var uw = r.unlinked_workers || [];
      var move = uw.length ? el('div', { class: 'why' }, [el('b', { text: uw.length + (uw.length === 1 ? ' production worker is' : ' production workers are') + ' still paid on the old Worker Pay screen: ' }),
        uw.slice(0, 12).map(function(w){ return w.name + ' (' + w.code + ')'; }).join(', ') + (uw.length > 12 ? ' and ' + (uw.length - 12) + ' more' : '') + '. ',
        'To pay one here instead: add them under Attendance › Setup › Employees with the same code, then choose "Contractor" for them in People. From then on they leave the old screen, and what was paid there is carried into their statement here.']) : null;
      show([steps, flowH, flow, move, el('div', { class: 'grid2' }, [facts, who])]);
    });
  }
  function countUp(node, to){
    if (window.matchMedia && matchMedia('(prefers-reduced-motion:reduce)').matches) { node.textContent = rs(to); return; }
    var t0 = null;
    (function f(t){ if (!t0) t0 = t; var k = Math.min(1, (t - t0) / 700); node.textContent = rs(Math.round(to * (1 - Math.pow(1 - k, 3)) / 100) * 100); if (k < 1) requestAnimationFrame(f); else node.textContent = rs(to); })(performance.now());
  }

  // ------------------------------------------------- the line editor
  /* model: { method, ch:{key:{amt,reason}}, added:[{kind,label,amt,reason}], removed:{key:true} }
     ch holds only lines that differ from what is stored (the worked-out figure
     on a new sheet, the saved figure on an existing one). */
  function newModel(method){ return { method: method || 'cash', ch: {}, added: [], removed: {} }; }
  function netOf(lines, m){
    var n = 0, bad = false;
    lines.forEach(function(l){
      if (m.removed[l.key]) return;
      var c = m.ch[l.key], p = c ? toPaisa(c.amt) : l.amount_paisa;
      if (p === null) { bad = true; return; }
      if (c && p !== l.calc_paisa && l.side !== 'carry' && !c.reason) bad = true;
      if (c && l.side === 'carry' && p > l.calc_paisa) bad = true;
      n += l.side === 'deduct' ? -p : p;
    });
    m.added.forEach(function(a){ var p = toPaisa(a.amt); if (p === null || p <= 0 || !a.reason) { bad = true; return; } n += a.kind === 'deduction' ? -p : p; });
    return { net: n, bad: bad || n < 0 };
  }
  function touched(lines, m){
    return Object.keys(m.ch).length + m.added.length + Object.keys(m.removed).length
      + lines.filter(function(l){ return !m.ch[l.key] && !m.removed[l.key] && l.amount_paisa !== l.calc_paisa && l.side !== 'carry'; }).length;
  }
  function payload(empId, lines, m, method0){
    var it = { employee_id: empId };
    var ch = Object.keys(m.ch).filter(function(k){ return !m.removed[k]; }).map(function(k){ return { key: k, amount_paisa: toPaisa(m.ch[k].amt), reason: m.ch[k].reason }; });
    if (ch.length) it.changes = ch;
    if (m.added.length) it.added = m.added.map(function(a){ return { kind: a.kind, label: a.label, amount_paisa: toPaisa(a.amt), reason: a.reason }; });
    var rm = Object.keys(m.removed); if (rm.length) it.remove_keys = rm;
    if (m.method !== method0) it.method = m.method;
    return it;
  }
  function summary(lines, m){
    var parts = [];
    lines.forEach(function(l){
      if (m.removed[l.key]) return;
      var c = m.ch[l.key], p = c ? toPaisa(c.amt) : l.amount_paisa; if (p === null) p = 0;
      if (p === 0 && l.calc_paisa === 0) return;
      parts.push(l.label.split(' · ')[0] + ' ' + (l.side === 'deduct' ? '−' : '') + rs(p));
    });
    m.added.forEach(function(a){ var p = toPaisa(a.amt) || 0; parts.push((a.label || ADD[a.kind]) + ' ' + (a.kind === 'deduction' ? '−' : '') + rs(p)); });
    return parts.join('  ·  ');
  }
  function editor(lines, m, refresh){
    var wrap = el('div', { class: 'lines' });
    wrap.appendChild(el('div', { class: 'ln hd' }, [el('span', { text: 'Line' }), el('span', { class: 'calc', text: 'Worked out' }), el('span', { text: 'Amount' }), el('span', { text: 'Reason for the change' }), el('span')]));
    lines.forEach(function(l){
      if (m.removed[l.key]) return;
      var byHand = l.key.indexOf('add:') === 0, c = m.ch[l.key];
      var amt = el('input', { type: 'text', inputmode: 'decimal', class: 'n', value: c ? c.amt : plain(l.amount_paisa), disabled: !l.editable, 'aria-label': l.label + ' — amount' });
      var why = el('input', { type: 'text', class: 'r', maxlength: 255, value: c ? c.reason : (l.reason || ''), placeholder: l.side === 'carry' ? 'Paying part now — no reason needed' : 'Why was it changed? (needed)', 'aria-label': l.label + ' — reason' });
      var calc = el('span', { class: 'calc', text: byHand ? 'added by hand' : rs(l.calc_paisa) });
      var row = el('div', { class: 'ln ' + l.side }, [
        el('span', { class: 'lb' }, [el('b', { text: l.label }), l.detail && !byHand ? el('small', { text: l.detail }) : null]), calc,
        el('span', { class: 'amt' }, [el('i', { text: l.side === 'deduct' ? '−' : '' }), amt]),
        el('span', { class: 'rs' }, [l.editable ? why : el('span', { class: 'fixed', text: 'Fixed — change it in People › pay setup' })]),
        byHand ? el('button', { type: 'button', class: 'x', title: 'Remove this line', 'aria-label': 'Remove ' + l.label, text: '×', click: function(){ m.removed[l.key] = true; delete m.ch[l.key]; row.remove(); refresh(); } }) : el('span')]);
      function sync(){
        var p = toPaisa(amt.value), r = why.value.trim();
        if (p !== l.amount_paisa || r !== (l.reason || '')) m.ch[l.key] = { amt: amt.value, reason: r }; else delete m.ch[l.key];
        var differs = p !== null && p !== l.calc_paisa;
        why.hidden = !differs || l.side === 'carry';
        row.classList.toggle('chg', differs && !byHand);
        row.classList.toggle('bad', p === null || (differs && l.side !== 'carry' && !r) || (l.side === 'carry' && p > l.calc_paisa));
      }
      amt.addEventListener('input', function(){ sync(); refresh(); }); why.addEventListener('input', function(){ sync(); refresh(); });
      sync(); wrap.appendChild(row);
    });
    var addBox = el('div', { style: 'display:grid;gap:6px' });
    function addRow(a){
      var lab = el('input', { type: 'text', maxlength: 100, value: a.label, placeholder: ADD[a.kind], 'aria-label': 'Name of the added line' });
      var amt = el('input', { type: 'text', inputmode: 'decimal', class: 'n', value: a.amt, placeholder: '0', 'aria-label': ADD[a.kind] + ' — amount' });
      var why = el('input', { type: 'text', class: 'r', maxlength: 255, value: a.reason, placeholder: 'What is it for? (needed)', 'aria-label': ADD[a.kind] + ' — reason' });
      var row = el('div', { class: 'ln chg ' + (a.kind === 'deduction' ? 'deduct' : 'earn') }, [el('span', { class: 'lb' }, [lab]), el('span', { class: 'calc', text: 'new ' + ADD[a.kind].toLowerCase() }),
        el('span', { class: 'amt' }, [el('i', { text: a.kind === 'deduction' ? '−' : '+' }), amt]), el('span', { class: 'rs' }, [why]),
        el('button', { type: 'button', class: 'x', title: 'Remove', 'aria-label': 'Remove this added line', text: '×', click: function(){ m.added.splice(m.added.indexOf(a), 1); row.remove(); refresh(); } })]);
      function sync(){ a.label = lab.value.trim(); a.amt = amt.value; a.reason = why.value.trim(); var p = toPaisa(a.amt); row.classList.toggle('bad', p === null || p <= 0 || !a.reason); refresh(); }
      [lab, amt, why].forEach(function(i){ i.addEventListener('input', sync); });
      var p0 = toPaisa(a.amt); row.classList.toggle('bad', p0 === null || p0 <= 0 || !a.reason);
      addBox.appendChild(row); return amt;
    }
    m.added.forEach(addRow);
    wrap.appendChild(addBox);
    var adders = el('div', { class: 'row' }, Object.keys(ADD).map(function(k){
      return btn('+ ' + ADD[k], function(){ var a = { kind: k, label: '', amt: '', reason: '' }; m.added.push(a); addRow(a).focus(); refresh(); }, 'sm');
    }));
    /* A CONTRACTOR'S PRODUCTION, ADJUSTED. What Production booked is not
       retyped here — it is corrected at its source, or adjusted on this sheet
       with a line that says exactly what and why: pieces x rate, added or
       taken off. The booked figure itself stays as Production has it. */
    if (lines.some(function(l){ return l.key === 'carry' && /Production/.test(l.label); })) {
      adders.appendChild(btn('± Production adjustment', function(){
        var inputs = ask('Production adjustment', 'For rejected or uncounted pieces, or a rate that was wrong. Give the pieces and the rate, or just an amount. It becomes its own line on this sheet with your reason; what Production booked is not changed.',
          [{ k: 'dir', label: 'What', type: 'select', options: [['minus', 'Take off (less to pay)'], ['plus', 'Add (more to pay)']] }, { k: 'qty', label: 'Pieces', placeholder: 'e.g. 20' }, { k: 'rate', label: 'Rate each', placeholder: 'e.g. 12.50' },
           { k: 'amt', label: 'Amount', money: true, placeholder: 'pieces × rate' }, { k: 'reason', label: 'Why', wide: true, placeholder: 'e.g. 20 pieces rejected in checking' }], 'Add to sheet', function(v){
            var p = toPaisa(v.amt);
            if (p === null || p <= 0) return { ok: false, error: 'Give the pieces and the rate, or the amount.' };
            if (!v.reason) return { ok: false, error: 'Say why — the reason is kept on the line.' };
            var q = parseFloat(v.qty), rt = parseFloat(v.rate), how = q > 0 && rt > 0 ? ' · ' + v.qty + ' × ' + v.rate : '';
            var a = { kind: v.dir === 'plus' ? 'other_earning' : 'deduction', label: 'Production adjustment' + how, amt: v.amt, reason: v.reason };
            m.added.push(a); addRow(a); closeModal(); refresh(); return { ok: true };
          });
        var calc = function(){ var q = parseFloat(inputs.qty.value), rt = parseFloat(inputs.rate.value); if (q > 0 && rt > 0) inputs.amt.value = (Math.round(q * rt * 100) / 100).toString(); };
        inputs.qty.addEventListener('input', calc); inputs.rate.addEventListener('input', calc);
      }, 'sm'));
    }
    var meth = el('div', { class: 'seg mini', role: 'group', 'aria-label': 'Paid by' }, ['cash', 'bank'].map(function(x){
      return el('button', { type: 'button', 'aria-pressed': m.method === x ? 'true' : 'false', text: x === 'cash' ? 'Cash' : 'Bank',
        click: function(e){ m.method = x; Array.prototype.forEach.call(e.target.parentNode.children, function(b){ b.setAttribute('aria-pressed', b === e.target ? 'true' : 'false'); }); refresh(); } });
    }));
    wrap.appendChild(el('div', { class: 'row', style: 'justify-content:space-between;margin-top:4px' }, [adders, el('span', { class: 'row small muted' }, ['Paid by', meth])]));
    return wrap;
  }
  function readLines(lines){
    var w = el('div', { class: 'lines' }), net = 0;
    w.appendChild(el('div', { class: 'ln ro hd' }, [el('span', { text: 'Line' }), el('span', { class: 'calc', text: 'Worked out' }), el('span', { class: 'now', text: 'Amount' }), el('span', { text: 'Reason' })]));
    lines.forEach(function(l){
      var byHand = l.key.indexOf('add:') === 0, ch = l.amount_paisa !== l.calc_paisa && !byHand;
      net += l.side === 'deduct' ? -l.amount_paisa : l.amount_paisa;
      w.appendChild(el('div', { class: 'ln ro ' + l.side + (ch ? ' chg' : '') }, [
        el('span', { class: 'lb' }, [el('b', { text: l.label }), l.detail && !byHand ? el('small', { text: l.detail }) : null]),
        el('span', { class: 'calc', text: byHand ? 'added by hand' : rs(l.calc_paisa) }),
        el('span', { class: 'now', text: (l.side === 'deduct' ? '−' : '') + rs(l.amount_paisa) }),
        el('span', { class: 'rs small', text: l.reason || '' })]));
    });
    w.appendChild(el('div', { class: 'ln ro tot' }, [el('span', { text: 'Net to pay' }), el('span'), el('span', { class: 'now', text: rs(net) }), el('span')]));
    return w;
  }

  // ------------------------------------------------------------ prepare
  var P = { kind: null, from: '', to: '', custom: false, rows: [], models: {}, on: {}, open: {}, dept: '', q: '' };
  function weekOf(end){ return [addDays(end, -6), end]; }
  function lastPayDay(){ var d = S.today; while (isoDay(d) !== S.payDay) d = addDays(d, -1); return d; }
  function monthRange(ym){ var a = ym.split('-'), last = new Date(Date.UTC(+a[0], +a[1], 0)); var to = ymd(last); return [ym + '-01', to > S.today ? S.today : to]; }
  function prevMonth(){ var d = dt(S.today); d.setUTCDate(0); return d.getUTCFullYear() + '-' + d2(d.getUTCMonth() + 1); }
  function setKind(k){
    P.kind = k; P.custom = false; P.models = {}; P.on = {}; P.open = {};
    if (k === 'salary') { var r = monthRange(prevMonth()); P.from = r[0]; P.to = r[1]; }
    else if (k === 'daily') { var w = weekOf(lastPayDay()); P.from = w[0]; P.to = w[1]; }
    else { P.from = S.today; P.to = S.today; }
  }
  function prepare(){
    var kinds = [S.caps.salary && 'salary', S.caps.wage && 'daily', S.caps.wage && 'contract'].filter(Boolean);
    if (!kinds.length) return show([el('div', { class: 'empty', text: 'You look at pay sheets; you do not prepare them.' })]);
    if (!P.kind || kinds.indexOf(P.kind) < 0) setKind(kinds[0]);
    var listBox = el('div', { class: 'stack' }), bar = el('div', { class: 'sumbar', hidden: true });
    var seg = el('div', { class: 'seg', role: 'group', 'aria-label': 'Kind of pay' }, kinds.map(function(k){
      return el('button', { type: 'button', 'aria-pressed': P.kind === k ? 'true' : 'false', text: KIND[k], click: function(){ setKind(k); prepare(); } }); }));
    var dates = el('div', { class: 'formgrid' });
    function reload(){ P.models = {}; P.on = {}; P.open = {}; load(); }
    if (P.kind === 'salary') {
      var mon = el('input', { type: 'month', value: P.from.slice(0, 7), max: S.today.slice(0, 7), change: function(){ if (!mon.value) return; var r = monthRange(mon.value); P.from = r[0]; P.to = r[1]; P.custom = false; prepare(); } });
      dates.appendChild(field('Month', mon));
      if (P.custom) {
        dates.appendChild(field('From', el('input', { type: 'date', value: P.from, max: S.today, change: function(e){ P.from = e.target.value; reload(); } })));
        dates.appendChild(field('To', el('input', { type: 'date', value: P.to, max: S.today, change: function(e){ P.to = e.target.value; reload(); } })));
      } else dates.appendChild(el('div', {}, [btn('Custom dates (someone joining or leaving)', function(){ P.custom = true; prepare(); }, 'sm')]));
    } else if (P.kind === 'daily') {
      dates.appendChild(field('From (Friday)', el('input', { type: 'date', value: P.from, max: S.today, change: function(e){ P.from = e.target.value; reload(); } })));
      dates.appendChild(field('To (' + DAYS[S.payDay] + ')', el('input', { type: 'date', value: P.to, max: S.today, change: function(e){ P.to = e.target.value; reload(); } })));
      var lp = lastPayDay();
      dates.appendChild(el('div', { class: 'row' }, [btn('This week', function(){ P.from = addDays(lp, 1) > S.today ? weekOf(lp)[0] : addDays(lp, 1); P.to = S.today; prepare(); }, 'sm'),
        btn('Week to last ' + DAYS[S.payDay], function(){ var w = weekOf(lp); P.from = w[0]; P.to = w[1]; prepare(); }, 'sm')]));
    } else dates.appendChild(el('p', { class: 'muted small', style: 'margin:0;grid-column:1/-1', text: 'Contractors are owed as soon as Production books their work. This lists everyone with money owed today; pay all of it or part of it.' }));
    var dept = el('select', { 'aria-label': 'Department', change: function(){ P.dept = dept.value; draw(); } }, [el('option', { value: '', text: 'All departments' })]);
    var search = el('input', { type: 'search', placeholder: 'Search name or code', value: P.q, 'aria-label': 'Search', input: function(){ P.q = search.value.toLowerCase(); draw(); } });
    var tools = el('div', { class: 'row' }, [el('div', { style: 'flex:1 1 160px' }, [dept]), el('div', { style: 'flex:2 1 200px' }, [search]),
      btn('Tick all shown', function(){ shown().forEach(function(r){ P.on[r.employee_id] = true; }); draw(); }, 'sm'),
      btn('Untick all', function(){ P.on = {}; draw(); }, 'sm'),
      btn('Add a line to everyone ticked', bulk, 'sm'),
      P.kind === 'contract' ? btn('± Production adjustment (many)', function(){ prodAdjust(); }, 'sm') : null]);
    show([el('div', { class: 'cardx stack' }, [el('div', { class: 'row', style: 'justify-content:space-between' }, [seg, el('span', { class: 'pill', text: period(P.kind, P.from, P.to) })]), dates]),
          el('div', { class: 'cardx' }, [tools]), listBox, bar]);
    function shown(){ return P.rows.filter(function(r){ return (!P.dept || r.department_name === P.dept) && (!P.q || (r.full_name + ' ' + r.code).toLowerCase().indexOf(P.q) >= 0); }); }
    function ticked(){ return P.rows.filter(function(r){ return P.on[r.employee_id]; }); }
    function bulk(){
      var t = ticked(); if (!t.length) return toast('Tick the people first.', true);
      ask('Add a line to ' + t.length + (t.length === 1 ? ' person' : ' people'), 'The same line is added to everyone ticked. You can still change it for one person afterwards.',
        [{ k: 'kind', label: 'What', type: 'select', options: [['bonus', 'Bonus'], ['other_earning', 'Other earning'], ['deduction', 'Deduction']] }, { k: 'label', label: 'Name on the slip', placeholder: 'e.g. Eid bonus' },
         { k: 'amt', label: 'Amount each', money: true }, { k: 'reason', label: 'Reason', wide: true }], 'Add to all ticked', function(v){
          var p = toPaisa(v.amt); if (p === null || p <= 0) return { ok: false, error: 'Give the amount.' };
          if (!v.reason) return { ok: false, error: 'Give the reason — it is kept on every line.' };
          t.forEach(function(r){ P.models[r.employee_id].added.push({ kind: v.kind, label: v.label, amt: v.amt, reason: v.reason }); });
          closeModal(); draw(); toast('Added to ' + t.length + '.');
        });
    }
    function sums(){
      var t = ticked(), total = 0, bad = false;
      t.forEach(function(r){ var x = netOf(r.lines, P.models[r.employee_id]); total += x.net; if (x.bad) bad = true; });
      bar.hidden = !t.length; bar.textContent = '';
      bar.appendChild(el('div', {}, [el('small', { text: t.length + (t.length === 1 ? ' person' : ' people') + ' · ' + KIND[P.kind] + ' · ' + period(P.kind, P.from, P.to) }), el('b', { text: 'Rs ' + rs(total) })]));
      bar.appendChild(btn(bad ? 'Fix the red lines first' : 'Send for approval', send, '', bad));
    }
    function send(){
      var t = ticked();
      var items = t.map(function(r){ return payload(r.employee_id, r.lines, P.models[r.employee_id], r.method); });
      call('POST', '/finance/sheets', { kind: P.kind, from: P.from, to: P.to, items: items }).then(function(r){
        if (!r.ok) return toast(r.error || 'That could not be saved.', true);
        toast('Pay sheet ' + r.ref + ' sent for approval — ' + r.people + (r.people === 1 ? ' person' : ' people') + ', Rs ' + rs(r.total_paisa) + '.');
        P.models = {}; P.on = {}; P.open = {}; S.sheetFilter = 'waiting'; refreshCounts(); go('sheets');
      });
    }
    function draw(){
      listBox.textContent = '';
      var rows = shown();
      if (!P.rows.length) { listBox.appendChild(el('div', { class: 'cardx empty', text: P.kind === 'contract' ? 'Nobody is owed anything right now.' : 'Nobody to pay for these dates. Either they are already on a pay sheet for them, or (daily wages) nobody was scanned in. People with no pay set up are not listed — see People.' })); sums(); return; }
      listBox.appendChild(el('div', { class: 'phead' }, [el('span'), el('span'), el('span', { text: 'Employee' }), el('span', { text: 'Lines' }), el('span', { style: 'text-align:right', text: 'To pay' }), el('span')]));
      var list = el('div', { class: 'plist' });
      rows.forEach(function(r){
        var m = P.models[r.employee_id], id = r.employee_id;
        var cb = el('input', { type: 'checkbox', checked: P.on[id], 'aria-label': 'Put ' + r.full_name + ' on the sheet', change: function(){ P.on[id] = cb.checked; row.classList.toggle('off', !cb.checked); sums(); } });
        var sum = el('div', { class: 'sum' }), netB = el('b'), netS = el('s'), amendPill = el('span', { class: 'pill amend', hidden: true });
        var actBtn = btn(P.open[id] ? 'Close' : 'Amend', function(){ P.open[id] = !P.open[id]; draw(); }, 'sm');
        var row = el('div', { class: 'prow' + (P.on[id] ? '' : ' off') + (P.open[id] ? ' open' : '') }, [cb, el('div', { class: 'av', text: initials(r.full_name) }),
          el('div', { class: 'nm' }, [el('b', { text: r.full_name }), el('span', { text: r.code + ' · ' + r.department_name + ' · ' }), amendPill]), sum,
          el('div', { class: 'net' }, [netB, netS]), el('div', { class: 'act' }, [actBtn])]);
        function refresh(){
          var x = netOf(r.lines, m), n = touched(r.lines, m);
          netB.textContent = rs(x.net); netB.style.color = x.bad ? 'var(--bad)' : '';
          netS.textContent = x.net !== r.calc_paisa ? rs(r.calc_paisa) : '';
          amendPill.hidden = !n; amendPill.textContent = n + (n === 1 ? ' line changed' : ' lines changed');
          sum.textContent = summary(r.lines, m); sums();
        }
        if (P.open[id]) row.appendChild(editor(r.lines, m, refresh));
        refresh(); list.appendChild(row);
      });
      listBox.appendChild(list);
      if (!rows.length) listBox.appendChild(el('div', { class: 'cardx empty', text: 'Nobody matches the filter.' }));
      sums();
    }
    function load(){
      listBox.textContent = ''; listBox.appendChild(el('p', { class: 'muted', text: 'Working it out…' })); bar.hidden = true;
      call('GET', '/finance/preview', { kind: P.kind, from: P.from, to: P.to }).then(function(r){
        if (!r.ok) { P.rows = []; listBox.textContent = ''; listBox.appendChild(fail(r)); return; }
        P.rows = r.rows;
        var seen = {};
        P.rows.forEach(function(x){ if (!P.models[x.employee_id]) { P.models[x.employee_id] = newModel(x.method); P.on[x.employee_id] = true; } seen[x.department_name] = 1; });
        dept.textContent = ''; dept.appendChild(el('option', { value: '', text: 'All departments' }));
        Object.keys(seen).sort().forEach(function(d){ dept.appendChild(el('option', { value: d, text: d })); });
        if (!seen[P.dept]) P.dept = ''; dept.value = P.dept;
        draw();
      });
    }
    load();
  }

  // ------------------------------------------------------------ pay sheets
  function sheets(){
    show([el('p', { class: 'muted', text: 'Loading…' })]);
    call('GET', '/finance/sheets').then(function(r){
      if (!r.ok) return show([fail(r)]);
      var all = r.sheets, f = S.sheetFilter;
      var chips = el('div', { class: 'seg', role: 'group', 'aria-label': 'Which sheets' }, [['waiting', 'Waiting'], ['approved', 'To pay'], ['paid', 'Paid'], ['posted', 'Posted entries'], ['closed', 'Sent back / cancelled'], ['all', 'All']].map(function(x){
        var n = all.filter(function(s){ return match(s, x[0]); }).length;
        return el('button', { type: 'button', 'aria-pressed': f === x[0] ? 'true' : 'false', text: x[1] + ' ' + n, click: function(){ S.sheetFilter = x[0]; sheets(); } }); }));
      function match(s, k){ return k === 'all' || (k === 'closed' ? (s.status === 'returned' || s.status === 'cancelled') : s.status === k); }
      var list = all.filter(function(s){ return match(s, f); });
      var cards = el('div', { class: 'plist' }, list.map(function(s){
        return el('button', { type: 'button', class: 'cardx scard', click: function(){ sheet(s.id); } }, [
          el('div', { class: 'av', text: s.kind === 'salary' ? 'S' : s.kind === 'daily' ? 'D' : s.kind === 'entry' ? 'E' : 'C' }),
          el('div', { class: 'nm' }, [el('b', { text: KIND[s.kind] + ' · ' + period(s.kind, s.period_from, s.period_to) }),
            el('span', { text: s.ref + ' · ' + s.people + (s.people === 1 ? ' person' : ' people') + ' · prepared by ' + (s.prepared_by_name || 'someone') + (s.amended ? ' · ' + s.amended + ' changed by hand' : '') })]),
          el('div', { class: 'amt' }, [el('b', { text: (s.total_paisa < 0 ? '−' : '') + rs(Math.abs(s.total_paisa)) }), el('span', { class: 'pill ' + s.status, text: STATUS[s.status] })])]);
      }));
      show([chips, list.length ? cards : el('div', { class: 'cardx empty', text: 'No pay sheets here.' })]);
    });
  }
  function sheet(id, editing){
    show([el('p', { class: 'muted', text: 'Loading…' })]);
    call('GET', '/finance/sheets/' + id).then(function(r){
      if (!r.ok) return show([btn('← Pay sheets', function(){ sheets(); }, 'sm'), fail(r)]);
      var s = r.sheet, c = S.caps, models = {}, open = {};
      r.items.forEach(function(i){ models[i.employee_id] = newModel(i.method); });
      var isEntry = s.kind === 'entry', payEntry = isEntry && r.items.some(function(i){ return i.lines.some(function(l){ return l.side === 'carry'; }); });
      var maySendBack = s.status === 'waiting' && (c.approve || r.own);
      /* one wrong person can be taken off without sending the whole sheet back */
      var mayRemove = maySendBack || ((s.status === 'approved' || s.status === 'posted') && c.approve);
      var mayAmend = maySendBack && !isEntry;
      var mayApprove = s.status === 'waiting' && c.approve && (!r.own || c.admin);
      var head = el('div', { class: 'cardx stack' }, [
        el('div', { class: 'row', style: 'justify-content:space-between' }, [
          el('div', {}, [el('h2', { text: KIND[s.kind] + ' · ' + period(s.kind, s.period_from, s.period_to) }), el('div', { class: 'muted small', text: s.ref + ' · ' + r.items.length + (r.items.length === 1 ? ' person' : ' people') })]),
          el('span', { class: 'pill ' + s.status, text: STATUS[s.status] })]),
        el('div', { class: 'track3' }, [
          el('div', { class: 'done' }, [el('b', { text: '1 · Prepared' }), nice(s.prepared_at)]),
          el('div', { class: s.approved_at ? 'done' : '' }, [el('b', { text: '2 · Approved' }), s.approved_at ? nice(s.approved_at) : (s.status === 'waiting' ? 'waiting' : '—')]),
          isEntry && !payEntry ? el('div', { class: s.status === 'posted' ? 'done' : '' }, [el('b', { text: '3 · Posted to ledger' }), s.status === 'posted' ? nice(s.approved_at) : '—'])
            : el('div', { class: s.status === 'paid' ? 'done' : '' }, [el('b', { text: '3 · Paid' }), s.pay_date && s.status === 'paid' ? nice(s.pay_date) : (s.status === 'approved' ? 'ready' : '—')])]),
        (s.status === 'returned' || s.status === 'cancelled') && s.return_reason ? el('div', { class: 'err', text: (s.status === 'returned' ? 'Sent back: ' : 'Cancelled: ') + s.return_reason }) : null,
        s.off_day_reason ? el('div', { class: 'why', text: 'Paid on a ' + DAYS[isoDay(s.pay_date)] + ', not the usual pay day: ' + s.off_day_reason }) : null,
        s.status === 'waiting' && r.own && c.approve && !c.admin ? el('div', { class: 'why', text: 'You prepared this sheet, so someone else must approve it.' }) : null,
        isEntry && !payEntry
          ? el('div', { class: 'kv' }, [el('span', { text: 'Entry date' }), el('b', { text: nice(s.period_to) }), el('span', { text: r.total_paisa < 0 ? 'Taken off what is owed' : 'Added to what is owed' }), el('b', { style: 'font-size:18px', text: 'Rs ' + rs(Math.abs(r.total_paisa)) })])
          : el('div', { class: 'kv' }, [el('span', { text: 'By bank' }), el('b', { text: rs(r.bank_paisa) }), el('span', { text: 'In cash' }), el('b', { text: rs(r.cash_paisa) }), el('span', { text: 'Total to pay' }), el('b', { style: 'font-size:18px', text: 'Rs ' + rs(r.total_paisa) })])]);
      var listBox = el('div', { class: 'plist' }), bar = el('div', { class: 'sumbar', hidden: true });
      function drawList(){
        listBox.textContent = '';
        r.items.forEach(function(i){
          var m = models[i.employee_id], id = i.employee_id;
          var sum = el('div', { class: 'sum' }), netB = el('b'), netS = el('s'), pill = el('span', { class: 'pill amend', hidden: true });
          var row = el('div', { class: 'prow nocb' + (open[id] ? ' open' : '') }, [el('div', { class: 'av', text: initials(i.full_name) }),
            el('div', { class: 'nm' }, [el('b', { text: i.full_name }), el('span', { text: i.code + ' · ' + i.department_name + ' · ' + (m.method === 'bank' ? 'bank' : 'cash') + ' · ' }), pill]), sum,
            el('div', { class: 'net' }, [netB, netS]),
            el('div', { class: 'act row', style: 'flex-wrap:nowrap;gap:4px' }, [btn(open[id] ? 'Close' : (editing ? 'Amend' : 'Lines'), function(){ open[id] = !open[id]; drawList(); }, 'sm'),
              mayRemove && !editing ? btn('Remove', function(){
                ask('Take ' + i.full_name + ' off this ' + (isEntry ? 'entry' : 'sheet'), s.status === 'waiting' ? 'Only this person leaves; everybody else stays exactly as entered.' + (r.items.length === 1 ? ' They are the last one, so the ' + (isEntry ? 'entry' : 'sheet') + ' will be closed.' : '')
                    : 'Their lines are struck through in the ledger with your reason, and any advance recovered here is put back. Everybody else stays.',
                  [{ k: 'reason', label: 'Why', wide: true }], 'Remove', function(v){
                    return call('POST', '/finance/sheets/' + s.id + '/remove', { employee_id: id, reason: v.reason }).then(function(x){
                      if (!x.ok) return x;
                      closeModal(); toast(i.full_name + ' taken off.' + (x.closed ? ' The ' + (isEntry ? 'entry' : 'sheet') + ' is closed.' : '')); refreshCounts(); if (x.closed) sheets(); else sheet(s.id); return x; });
                  });
              }, 'sm danger') : null])]);
          function refresh(){
            var x = netOf(i.lines, m), n = touched(i.lines, m);
            netB.textContent = rs(x.net); netB.style.color = x.bad ? 'var(--bad)' : '';
            netS.textContent = x.net !== i.calc_paisa ? rs(i.calc_paisa) : '';
            pill.hidden = !n; pill.textContent = n + (n === 1 ? ' line changed' : ' lines changed');
            sum.textContent = summary(i.lines, m); sums();
          }
          if (open[id]) row.appendChild(editing ? editor(i.lines, m, refresh) : readLines(i.lines));
          refresh(); listBox.appendChild(row);
        });
      }
      function dirty(){ return r.items.filter(function(i){ var m = models[i.employee_id]; return Object.keys(m.ch).length || m.added.length || Object.keys(m.removed).length || m.method !== i.method; }); }
      function sums(){
        if (!editing) return;
        var total = 0, bad = false; r.items.forEach(function(i){ var x = netOf(i.lines, models[i.employee_id]); total += x.net; if (x.bad) bad = true; });
        var d = dirty();
        bar.hidden = false; bar.textContent = '';
        bar.appendChild(el('div', {}, [el('small', { text: d.length ? d.length + (d.length === 1 ? ' person' : ' people') + ' changed · new total' : 'Nothing changed yet · total' }), el('b', { text: 'Rs ' + rs(total) })]));
        bar.appendChild(el('div', { class: 'row' }, [btn('Stop amending', function(){ sheet(id); }), btn(bad ? 'Fix the red lines first' : 'Save amendments', save, '', bad || !d.length)]));
      }
      function save(){
        var items = dirty().map(function(i){ return payload(i.employee_id, i.lines, models[i.employee_id], i.method); });
        call('POST', '/finance/sheets/' + id + '/amend', { items: items }).then(function(x){
          if (!x.ok) return toast(x.error || 'That could not be saved.', true);
          toast('Amendments saved.'); sheet(id);
        });
      }
      function act(route, data, done){
        return call('POST', '/finance/sheets/' + id + '/' + route, data).then(function(x){
          if (!x.ok) return x;
          closeModal(); toast(done); refreshCounts(); sheet(id); return x;
        });
      }
      var acts = el('div', { class: 'row' }, [btn('← Pay sheets', function(){ sheets(); }, 'sm')]);
      if (!editing) {
        if (mayAmend) acts.appendChild(btn('Amend', function(){ sheet(id, true); }));
        if (mayApprove) acts.appendChild(btn('Approve', function(){
          modal([el('h2', { text: isEntry ? 'Approve this entry?' : 'Approve this pay sheet?' }), el('p', { class: 'muted', text: r.items.length + (r.items.length === 1 ? ' person' : ' people') + ' · Rs ' + rs(Math.abs(r.total_paisa)) + '. ' + (isEntry && !payEntry ? 'Once approved it goes into each person\'s ledger at once.' : 'Once approved it cannot be amended; the earnings and deductions go into each person\'s statement and advances are recovered.') }),
            el('div', { class: 'row', style: 'justify-content:flex-end' }, [btn('Not yet', closeModal), btn('Approve', function(e){ e.target.disabled = true; act('approve', {}, isEntry && !payEntry ? 'Approved and posted to the ledger.' : 'Approved. Accounts can now pay it.').then(function(x){ if (x && !x.ok) { closeModal(); toast(x.error, true); } }); }, 'pri')])]);
        }, 'pri'));
        if (maySendBack) acts.appendChild(btn('Send back', function(){
          ask('Send this sheet back', 'It is closed, and the people on it can be prepared again.', [{ k: 'reason', label: 'Why', wide: true }], 'Send back', function(v){ return act('return', v, 'Sent back.'); });
        }, 'danger'));
        if (s.status === 'approved' && c.pay) acts.appendChild(btn('Record payment', function(){
          var inputs = ask('Record the payment', 'Rs ' + rs(r.total_paisa) + ' — ' + rs(r.bank_paisa) + ' by bank, ' + rs(r.cash_paisa) + ' in cash.',
            [{ k: 'pay_date', label: 'Paid on', type: 'date', value: S.today }, { k: 'reason', label: 'Reason (only if not ' + DAYS[S.payDay] + ')', wide: true, placeholder: 'e.g. Eid holiday on ' + DAYS[S.payDay] }], 'Paid', function(v){ return act('pay', v, 'Payment recorded.'); });
          inputs.pay_date.max = S.today;
        }, 'pri'));
        if (((s.status === 'approved' || s.status === 'posted') && c.approve) || (s.status === 'paid' && c.admin)) acts.appendChild(btn(isEntry ? 'Cancel entry' : 'Cancel sheet', function(){
          ask('Cancel this ' + (s.status === 'paid' ? 'PAID ' : '') + 'sheet', 'Nothing is deleted: every line stays in the statements, struck through with your reason, and recovered advances are put back. The people can then be prepared again.',
            [{ k: 'reason', label: 'Why', wide: true }], 'Cancel the sheet', function(v){ return act('cancel', v, 'Sheet cancelled.'); });
        }, 'danger'));
        acts.appendChild(btn('Print pay slips', function(){ printSlips(r); }));
        acts.appendChild(el('a', { class: 'btn', href: API + encodeURIComponent('/finance/sheets/' + id + '/export'), text: 'Download (Excel)' }));
        acts.appendChild(btn('Show all lines', function(){ var any = r.items.some(function(i){ return !open[i.employee_id]; }); r.items.forEach(function(i){ open[i.employee_id] = any; }); drawList(); }, 'sm'));
      } else {
        r.items.forEach(function(i){ open[i.employee_id] = r.items.length <= 3; });
        acts.appendChild(btn('Open everyone', function(){ var any = r.items.some(function(i){ return !open[i.employee_id]; }); r.items.forEach(function(i){ open[i.employee_id] = any; }); drawList(); }, 'sm'));
      }
      show([acts, head, editing ? el('div', { class: 'why', text: 'Amending: open a person, change any line and say why. Basic salary is fixed. Nothing is saved until you press Save amendments.' }) : null, listBox, bar]);
      drawList(); sums();
    });
  }
  function printSlips(r){
    var box = document.getElementById('fin-print'), s = r.sheet; box.textContent = '';
    r.items.forEach(function(i){
      var net = 0, rows = i.lines.map(function(l){ net += l.side === 'deduct' ? -l.amount_paisa : l.amount_paisa;
        return el('tr', {}, [el('td', { text: l.label + (l.reason ? ' (' + l.reason + ')' : '') }), el('td', { class: 'r', text: (l.side === 'deduct' ? '−' : '') + rs(l.amount_paisa) })]); });
      box.appendChild(el('div', { class: 'slip' }, [el('h1', { text: 'ZAS Textiles — Pay slip' }),
        el('div', { text: i.full_name + ' (' + i.code + ') · ' + i.department_name }),
        el('div', { text: KIND1[s.kind] + ' · ' + period(s.kind, s.period_from, s.period_to) + ' · ' + s.ref + ' · ' + STATUS[s.status] + (s.pay_date ? ' on ' + nice(s.pay_date) : '') }),
        el('table', { style: 'margin-top:6px' }, [el('tbody', {}, rows.concat([el('tr', {}, [el('th', { text: 'Net ' + (i.method === 'bank' ? 'by bank' : 'in cash') }), el('th', { class: 'r', text: 'Rs ' + rs(net) })])]))]),
        el('div', { class: 'sig' }, [el('span', { text: 'Paid by: ____________________' }), el('span', { text: 'Received by: ____________________' })])]));
    });
    window.print();
  }

  // ------------------------------------------------------------ people
  var PF = { type: '', dept: '', q: '', state: 'on' };
  /* EMPLOYEE LIST — print or download, with the columns you choose.
     Grouped by how people are paid (or by department), with totals.
     Three ready choices: "Short list" (the usual), "Finance / audit" (every pay
     and advance figure, to match against the books) and "Codes only — no
     money" (safe to hand over for checking codes and names).
     The figures are those the server already lets this user see: a salary
     hidden from them stays hidden here. The download keeps amounts as plain
     numbers so Excel can add them up. Choices are remembered on this computer.
     [key, title, in the short list, is money, adds up in totals] */
  var EL_COLS = [
    ['sr', '#', 1, 0, 0], ['code', 'Code', 1, 0, 0], ['name', 'Name', 1, 0, 0], ['dept', 'Department', 1, 0, 0], ['type', 'Paid as', 1, 0, 0],
    ['worker', 'Production code', 0, 0, 0], ['method', 'Paid by', 1, 0, 0], ['bank', 'Bank account', 0, 1, 0],
    ['rate', 'Salary / day rate', 1, 1, 0], ['allow', 'Allowance', 0, 1, 1], ['ot', 'Overtime / hour', 0, 1, 0],
    ['oadv', 'Opening advance', 0, 1, 1], ['adv', 'Advance left', 1, 1, 1], ['advper', 'Recovered each pay', 0, 1, 1],
    ['open', 'Opening balance', 0, 1, 1], ['earned', 'Earned (all time)', 0, 1, 1], ['deducted', 'Deducted (all time)', 0, 1, 1], ['paid', 'Paid (all time)', 0, 1, 1],
    ['owed', 'We owe them', 1, 1, 1], ['net', 'Net (we pay / they owe)', 1, 1, 1],
    ['state', 'On the roll', 0, 0, 0], ['today', 'Today', 0, 0, 0], ['sign', 'Checked / signature', 0, 0, 0]
  ];
  var EL_PRESETS = {
    short: EL_COLS.filter(function(c){ return c[2]; }).map(function(c){ return c[0]; }),
    audit: ['sr', 'code', 'name', 'dept', 'type', 'worker', 'method', 'bank', 'rate', 'allow', 'ot', 'oadv', 'adv', 'advper', 'open', 'earned', 'deducted', 'paid', 'owed', 'net'],
    codes: ['sr', 'code', 'name', 'dept', 'type', 'worker', 'state', 'sign']
  };
  function elCol(k){ return EL_COLS.filter(function(c){ return c[0] === k; })[0]; }
  function employeeList(){
    var saved = {}; try { saved = JSON.parse(localStorage.getItem('fin-elist') || '{}') || {}; } catch (e) { saved = {}; }
    var O = { group: saved.group || 'type', who: saved.who || 'on', kind: '', dept: '', cols: (saved.cols || EL_PRESETS.short).filter(elCol) };
    function keep(){ try { localStorage.setItem('fin-elist', JSON.stringify({ group: O.group, who: O.who, cols: O.cols })); } catch (e) {} }
    var box = modal([el('p', { class: 'muted', text: 'Loading…' })], true); box.classList.add('xwide'); box.classList.add('elist-box');
    call('GET', '/finance/people').then(function(r){
      if (!r.ok) { box.textContent = ''; box.appendChild(fail(r)); return; }
      var all = r.people;
      var depts = Object.keys(all.reduce(function(a, p){ a[p.department_name] = 1; return a; }, {})).sort();
      function rows(){
        return all.filter(function(p){ return (O.who === 'all' || (O.who === 'left' ? !+p.active : +p.active))
          && (!O.kind || (O.kind === 'none' ? !p.pay_type : p.pay_type === O.kind)) && (!O.dept || p.department_name === O.dept); });
      }
      /* the amount behind each money column, in paisa; null = hidden from this user, '' = nothing */
      function amount(k, p){
        var hid = p.balance === null || p.balance === undefined;
        switch (k) {
          case 'rate': return p.pay_type === 'salary' ? p.basic_paisa : (p.pay_type === 'daily' ? p.daily_rate_paisa : '');
          case 'allow': return p.allowance_paisa === null ? null : (+p.allowance_paisa || '');
          case 'ot': return +p.ot_rate_paisa || '';
          case 'oadv': return hid ? null : (+p.opening_advance_paisa || '');
          case 'adv': return hid ? null : (+p.advance_left_paisa || '');
          case 'advper': return hid ? null : (+p.advance_per_pay_paisa || '');
          case 'open': return hid ? null : (+p.opening_paisa || '');
          case 'earned': return hid ? null : +p.earned || 0;
          case 'deducted': return hid ? null : +p.deducted || 0;
          case 'paid': return hid ? null : +p.paid || 0;
          case 'owed': return hid ? null : +p.balance || 0;
          case 'net': return hid ? null : (+p.balance || 0) - (+p.advance_left_paisa || 0);
        }
        return '';
      }
      function netText(n){ return n > 0 ? 'We pay ' + rs(n) : (n < 0 ? 'They owe ' + rs(-n) : '0'); }
      function todayText(p){ var t = p.today; if (!+p.active) return 'left'; if (!t) return 'not in'; if (t.status === 'inside') return 'inside'; if (t.status === 'absent') return 'did not come'; return 'came, gone'; }
      function cell(k, p, i){
        var c = elCol(k);
        if (c[3] && k !== 'bank') {
          if (k === 'rate' && p.pay_type === 'contract') return 'by production';
          var a = amount(k, p);
          if (a === null) return 'hidden'; if (a === '') return '';
          if (k === 'rate') return rs(a) + (p.pay_type === 'salary' ? ' / month' : ' / day');
          return k === 'net' ? netText(a) : rs(a);
        }
        switch (k) {
          case 'sr': return String(i);
          case 'code': return p.code; case 'name': return p.full_name; case 'dept': return p.department_name;
          case 'type': return p.pay_type ? KIND1[p.pay_type] : 'Not set up'; case 'worker': return p.worker_code || '';
          case 'method': return p.pay_type ? (p.pay_method === 'bank' ? 'Bank' : 'Cash') : '';
          case 'bank': return p.pay_method === 'bank' ? (p.bank_account || '') : '';
          case 'state': return +p.active ? 'Yes' : 'Left';
          case 'today': return todayText(p);
        }
        return '';
      }
      function groups(list){
        var key = O.group === 'type' ? function(p){ return p.pay_type ? KIND1[p.pay_type] : 'Not set up'; } : (O.group === 'dept' ? function(p){ return p.department_name; } : function(){ return 'Everyone'; });
        var order = ['Fixed salary', 'Daily wages', 'Contractor', 'Not set up'];
        var G = {}; list.forEach(function(p){ (G[key(p)] = G[key(p)] || []).push(p); });
        return Object.keys(G).sort(function(a, b){ return O.group === 'type' ? order.indexOf(a) - order.indexOf(b) : a.localeCompare(b); })
          .map(function(k){ return { name: k, list: G[k].sort(function(a, b){ return (O.group === 'type' ? a.department_name.localeCompare(b.department_name) : 0) || a.full_name.localeCompare(b.full_name); }) }; });
      }
      /* totals of the columns that add up; the monthly salaries (or day rates)
         add up too when everyone in the group is paid the same way */
      function totals(cols, list){
        var kinds = {}; list.forEach(function(p){ kinds[p.pay_type || ''] = 1; });
        var one = Object.keys(kinds).length === 1 && (kinds.salary || kinds.daily) ? Object.keys(kinds)[0] : null;
        var t = {}, hid = {};
        cols.forEach(function(k){ if (!(elCol(k)[4] || (k === 'rate' && one))) return; t[k] = 0;
          list.forEach(function(p){ var a = amount(k, p); if (a === null) hid[k] = 1; else t[k] += +a || 0; }); });
        return cols.map(function(k){
          if (!(k in t)) return '';
          if (hid[k]) return 'part hidden';
          if (k === 'rate') return rs(t[k]) + (one === 'salary' ? ' / month' : ' / day');
          return k === 'net' ? netText(t[k]) : rs(t[k]);
        });
      }
      function totalRow(cols, list, label, tag, cls){
        var v = totals(cols, list); if (!v.some(function(x){ return x; })) return null;
        v[0] = label;
        return el(tag || 'tr', { class: cls }, v.map(function(x, i){ return el('td', { class: (elCol(cols[i])[3] && i ? 'r' : null), colspan: null, text: x }); }));
      }
      /* the list as a table: the same for the screen and the print */
      function build(){
        var cols = EL_COLS.filter(function(c){ return O.cols.indexOf(c[0]) >= 0; }).map(function(c){ return c[0]; });
        var head = el('tr', {}, cols.map(function(k){ return el('th', { class: elCol(k)[3] && k !== 'bank' ? 'r' : null, text: elCol(k)[1] }); }));
        var tb = el('tbody'), list = rows(), gs = groups(list), n = 0;
        gs.forEach(function(g){
          if (O.group !== 'none') tb.appendChild(el('tr', { class: 'grp' }, [el('td', { colspan: String(cols.length) }, [el('b', { text: g.name }), ' · ' + g.list.length + (g.list.length === 1 ? ' person' : ' people')])]));
          g.list.forEach(function(p){ n++; tb.appendChild(el('tr', {}, cols.map(function(k){ return el('td', { class: (elCol(k)[3] && k !== 'bank' ? 'r ' : '') + (k === 'sign' ? 'signc' : ''), text: cell(k, p, n) }); }))); });
          if (O.group !== 'none' && gs.length > 1) { var sr = totalRow(cols, g.list, 'Total', 'tr', 'sub'); if (sr) tb.appendChild(sr); }
        });
        var all1 = totalRow(cols, list, 'Total · ' + list.length);
        return { table: el('table', { class: 't elist', id: 'elist-table' }, [el('thead', {}, [head]), tb, all1 ? el('tfoot', {}, [all1]) : null]), count: list.length, cols: cols, groups: gs };
      }
      var preview = el('div', { class: 'scroll elist-wrap' });
      var countEl = el('span', { class: 'muted small' });
      var presetBtns = {};
      function paint(){
        keep(); var b = build(); preview.textContent = ''; preview.appendChild(b.table);
        countEl.textContent = b.count + (b.count === 1 ? ' person' : ' people') + ' · ' + b.cols.length + ' columns';
        colTogText.textContent = 'Choose columns (' + b.cols.length + ' of ' + EL_COLS.length + ' · money in brown)';
        Object.keys(presetBtns).forEach(function(k){ presetBtns[k].classList.toggle('on', EL_PRESETS[k].join() === b.cols.join()); });
      }
      function sel(label, value, opts, set){ var x = el('select', { 'aria-label': label, change: function(){ set(x.value); paint(); } }, opts.map(function(o){ return el('option', { value: o[0], text: o[1] }); })); x.value = value; return field(label, x); }
      var colBoxes = el('div', { class: 'elist-cols' }, EL_COLS.map(function(c){
        var cb = el('input', { type: 'checkbox', 'data-col': c[0] }); cb.checked = O.cols.indexOf(c[0]) >= 0;
        cb.addEventListener('change', function(){ readBoxes(); paint(); });
        return el('label', { class: c[3] ? 'money' : null }, [cb, ' ' + c[1]]);
      }));
      /* the column ticks fold away, so the list itself has the room */
      colBoxes.hidden = true;
      var colTogText = el('span'), colTog = btn('', function(){ colBoxes.hidden = !colBoxes.hidden; colTog.setAttribute('aria-expanded', String(!colBoxes.hidden)); }, 'sm tog');
      colTog.textContent = ''; colTog.appendChild(colTogText); colTog.appendChild(el('span', { class: 'car', text: '▾' }));
      colTog.setAttribute('aria-expanded', 'false'); colTog.id = 'elist-coltog';
      function readBoxes(){ O.cols = EL_COLS.map(function(x){ return x[0]; }).filter(function(k){ return colBoxes.querySelector('input[data-col="' + k + '"]').checked; }); }
      function setCols(list){ Array.prototype.forEach.call(colBoxes.querySelectorAll('input'), function(b){ b.checked = list.indexOf(b.getAttribute('data-col')) >= 0; }); readBoxes(); paint(); }
      function setMoney(on){ Array.prototype.forEach.call(colBoxes.querySelectorAll('label.money input'), function(b){ b.checked = on; }); readBoxes(); paint(); }
      function preset(k, label){ var b = btn(label, function(){ setCols(EL_PRESETS[k]); }, 'sm preset'); b.setAttribute('data-preset', k); presetBtns[k] = b; return b; }
      var title = function(){ return 'Employee list' + (O.kind ? ' — ' + (O.kind === 'none' ? 'Not set up' : KIND1[O.kind]) : '') + (O.dept ? ' — ' + O.dept : ''); };
      var doPrint = btn('Print', function(){
        var b = build(), pbox = document.getElementById('fin-print'); pbox.textContent = '';
        var noMoney = !b.cols.some(function(k){ return elCol(k)[3]; });
        pbox.appendChild(el('h1', { text: 'ZAS Textiles — ' + title() }));
        pbox.appendChild(el('div', { text: 'As on ' + nice(S.today) + ' · ' + b.count + (b.count === 1 ? ' person' : ' people') + ' · ' + ({ on: 'on the roll', left: 'left the company', all: 'everyone' })[O.who] + (noMoney ? ' · no pay figures on this list' : ' · amounts in PKR') }));
        var t = b.table; t.removeAttribute('id'); t.classList.toggle('dense', b.cols.length > 13); pbox.appendChild(t);
        pbox.appendChild(el('div', { class: 'sig' }, [el('span', { text: 'Prepared by' }), el('span', { text: 'Checked by' })]));
        /* a wide list prints across the page */
        var pg = el('style', { text: '@page{size:A4 ' + (b.cols.length > 9 ? 'landscape' : 'portrait') + ';margin:10mm}' }); document.head.appendChild(pg);
        window.print();
        setTimeout(function(){ pg.remove(); }, 1000);
      }, 'pri');
      doPrint.id = 'elist-print';
      /* the download: one line per person, amounts as plain rupees (Excel can add them) */
      var doCsv = btn('Download (Excel)', function(){
        var b = build(), lines = [], n = 0, head = (O.group !== 'none' ? ['Group'] : []);
        b.cols.forEach(function(k){ head.push(k === 'net' ? 'Net (+ we pay, - they owe)' : (k === 'rate' ? 'Salary / day rate' : elCol(k)[1])); if (k === 'rate') head.push('Rate per'); });
        b.groups.forEach(function(g){ g.list.forEach(function(p){ n++;
          var line = O.group !== 'none' ? [g.name] : [];
          b.cols.forEach(function(k){
            var c = elCol(k);
            if (c[3] && k !== 'bank') { var a = amount(k, p); line.push(a === null ? 'hidden' : (a === '' ? '' : (a / 100).toFixed(2)));
              if (k === 'rate') line.push(p.pay_type === 'salary' ? 'month' : (p.pay_type === 'daily' ? 'day' : (p.pay_type === 'contract' ? 'production' : ''))); }
            else line.push(cell(k, p, n));
          });
          lines.push(line); }); });
        var esc = function(v){ v = String(v === null || v === undefined ? '' : v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
        var blob = new Blob(['﻿' + [head].concat(lines).map(function(l){ return l.map(esc).join(','); }).join('\r\n')], { type: 'text/csv;charset=utf-8' });
        var a = el('a', { href: URL.createObjectURL(blob), download: 'employee-list-' + S.today + '.csv' }); document.body.appendChild(a); a.click(); setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 500);
      });
      doCsv.id = 'elist-csv';
      box.textContent = '';
      [el('div', { class: 'row', style: 'justify-content:space-between' }, [el('h2', { text: 'Employee list — print or download' }), btn('Close', closeModal, 'sm')]),
        el('p', { class: 'muted small', style: 'margin:0', text: 'Pick a ready list, or choose the columns yourself. "Finance / audit" shows every pay and advance figure to match against the books. "Codes only" has no money at all — safe to hand to someone checking codes and names.' }),
        el('div', { class: 'cardx elist-opts' }, [
          el('div', { class: 'row elist-presets' }, [el('b', { class: 'small', text: 'Ready lists' }),
            preset('short', 'Short list'), preset('audit', 'Finance / audit (all figures)'), preset('codes', 'Codes only — no money')]),
          el('div', { class: 'formgrid' }, [
            sel('Group by', O.group, [['type', 'How they are paid'], ['dept', 'Department'], ['none', 'No groups']], function(v){ O.group = v; }),
            sel('Who', O.who, [['on', 'On the roll'], ['left', 'Left the company'], ['all', 'Everyone']], function(v){ O.who = v; }),
            sel('Paid as', '', [['', 'All'], ['salary', 'Fixed salary'], ['daily', 'Daily wages'], ['contract', 'Contractors'], ['none', 'Not set up']], function(v){ O.kind = v; }),
            sel('Department', '', [['', 'All departments']].concat(depts.map(function(d){ return [d, d]; })), function(v){ O.dept = v; })]),
          el('div', { class: 'elist-colbar' }, [colTog,
            btn('Hide all money', function(){ setMoney(false); }, 'sm'), btn('Show all money', function(){ setMoney(true); }, 'sm')]),
          colBoxes]),
        el('div', { class: 'row', style: 'justify-content:space-between' }, [countEl, el('div', { class: 'row' }, [doCsv, doPrint])]),
        preview].forEach(function(n){ box.appendChild(n); });
      paint();
    });
  }

  function people(type){
    if (typeof type === 'string') PF.type = type;
    show([el('p', { class: 'muted', text: 'Loading…' })]);
    call('GET', '/finance/people').then(function(r){
      if (!r.ok) return show([fail(r)]);
      /* who is listed: on the roll (the default — a short list), left the company, or everyone */
      var onState = function(p){ return PF.state === 'all' || (PF.state === 'left' ? !+p.active : +p.active); };
      var seg = el('div', { class: 'seg', role: 'group', 'aria-label': 'Kind of pay' }, [['', 'Everyone'], ['salary', 'Fixed salary'], ['daily', 'Daily wages'], ['contract', 'Contractors'], ['none', 'Not set up']].map(function(x){
        var n = r.people.filter(function(p){ return onState(p) && (x[0] === '' || (x[0] === 'none' ? !p.pay_type : p.pay_type === x[0])); }).length;
        return el('button', { type: 'button', 'aria-pressed': PF.type === x[0] ? 'true' : 'false', text: x[1] + ' ' + n, click: function(){ PF.type = x[0]; people(); } }); }));
      var dept = el('select', { 'aria-label': 'Department', change: function(){ PF.dept = dept.value; draw(); } }, [el('option', { value: '', text: 'All departments' })].concat(
        Object.keys(r.people.reduce(function(a, p){ a[p.department_name] = 1; return a; }, {})).sort().map(function(d){ return el('option', { value: d, text: d }); })));
      dept.value = PF.dept;
      var search = el('input', { type: 'search', placeholder: 'Search name or code', value: PF.q, 'aria-label': 'Search', input: function(){ PF.q = search.value.toLowerCase(); draw(); } });
      var box = el('div', { class: 'cardx scroll' });
      /* Admin and HR tick many people; a small bar then offers one action for all of them. */
      var tickOn = !!S.caps.staff;
      PF.sel = PF.sel || {};
      var selBar = el('div', { class: 'selbar', hidden: true });
      function selIds(){ return Object.keys(PF.sel).filter(function(k){ return PF.sel[k]; }).map(Number); }
      function paintSel(){
        var ids = selIds(), n = ids.length;
        selBar.hidden = !n; selBar.textContent = '';
        if (!n) return;
        var picked = r.people.filter(function(p){ return PF.sel[p.employee_id]; });
        var anyOn = picked.some(function(p){ return +p.active; }), anyOff = picked.some(function(p){ return !+p.active; });
        function act(action, title, text, okText){
          ask(title, text, [{ k: 'reason', label: 'Reason (kept with every person)', wide: true }], okText, function(v){
            return call('POST', '/finance/staff/bulk', { ids: selIds(), action: action, reason: v.reason }).then(function(x){
              if (!x.ok) return x;
              PF.sel = {}; PEOPLE = null;
              if (x.refused && x.refused.length) {
                closeModal();
                ask(x.done + ' done · ' + x.refused.length + ' not done', x.refused.slice(0, 12).map(function(f){ return f.name + ' — ' + f.error; }).join('  ·  ') + (x.refused.length > 12 ? '  · and ' + (x.refused.length - 12) + ' more' : ''), [], 'OK', function(){ closeModal(); return { ok: true }; });
              } else { closeModal(); toast(x.done + (x.done === 1 ? ' person' : ' people') + ' done.'); }
              people(); return { ok: true };
            });
          });
        }
        selBar.appendChild(el('b', { text: n + (n === 1 ? ' person ticked' : ' people ticked') }));
        if (anyOn) selBar.appendChild(btn('Mark as left', function(){ act('left', 'Mark ' + n + (n === 1 ? ' person' : ' people') + ' as left', 'They stay in every record and statement; they can no longer be scanned in or put on a new pay sheet. Someone inside the factory now is skipped and listed.', 'Mark as left'); }, 'sm'));
        if (anyOff) selBar.appendChild(btn('Bring back on the roll', function(){ act('back', 'Bring ' + n + (n === 1 ? ' person' : ' people') + ' back on the roll', 'They can be scanned in and put on pay sheets again.', 'Bring back'); }, 'sm'));
        if (S.caps.delete_staff) selBar.appendChild(btn('Delete (entered by mistake)', function(){ act('delete', 'Delete ' + n + (n === 1 ? ' person' : ' people') + '?', 'Only those with nothing on record — no scan, no attendance, nothing in pay, no advance — are deleted. Anyone with history is skipped and listed: mark them as left instead.', 'Delete'); }, 'sm danger'));
        selBar.appendChild(btn('Untick all', function(){ PF.sel = {}; draw(); }, 'sm'));
      }
      /* today, in one short chip on the code line */
      function todayChip(p){
        var t = p.today;
        if (!+p.active) return el('span', { class: 'tchip off', text: 'Left' });
        if (!t) return el('span', { class: 'tchip none', text: 'Not in today' });
        if (t.status === 'inside') return el('span', { class: 'tchip in', text: 'Inside · came ' + hm(t.first_in) + (t.late_minutes ? ' · late ' + mins(t.late_minutes) : '') });
        if (t.status === 'absent') return el('span', { class: 'tchip bad', text: 'Did not come' });
        return el('span', { class: 'tchip done', text: 'Came ' + hm(t.first_in) + (t.late_minutes ? ' · late ' + mins(t.late_minutes) : '') + ' · out ' + hm(t.last_out) });
      }
      function hm(iso){ if (!iso) return '—'; var d = new Date(iso); return isNaN(d) ? '—' : d.toLocaleTimeString('en-GB', { timeZone: 'Asia/Karachi', hour: '2-digit', minute: '2-digit' }); }
      function mins(m){ m = +m || 0; return (m >= 60 ? Math.floor(m / 60) + 'h ' : '') + (m % 60) + 'm'; }
      function draw(){
        var rows = shownNow();
        box.textContent = '';
        if (!rows.length) { paintSel(); return box.appendChild(el('div', { class: 'empty', text: 'Nobody here.' })); }
        var tb = el('tbody');
        rows.forEach(function(p){
          var rate = p.pay_type === 'salary' ? (p.basic_paisa === null ? 'hidden' : rs(p.basic_paisa) + ' a month' + (p.allowance_paisa ? ' + ' + rs(p.allowance_paisa) : ''))
            : p.pay_type === 'daily' ? rs(p.daily_rate_paisa) + ' a day' : p.pay_type === 'contract' ? 'by production' : '';
          var cb = tickOn ? el('input', { type: 'checkbox', 'aria-label': 'Tick ' + p.full_name, click: function(e){ e.stopPropagation(); }, change: function(){ PF.sel[p.employee_id] = cb.checked; paintSel(); } }) : null;
          if (cb) cb.checked = !!PF.sel[p.employee_id];
          var adv = +p.advance_left_paisa ? el('div', { class: 'advline', text: 'Advance left ' + rs(p.advance_left_paisa) + ' · ' + rs(p.advance_per_pay_paisa) + ' a pay' }) : null;
          tb.appendChild(el('tr', { class: 'click', tabindex: '0', click: function(){ person(p); }, keydown: function(e){ if (e.key === 'Enter') person(p); } }, [
            tickOn ? el('td', { class: 'tick' }, [cb]) : null,
            el('td', { 'data-sort': p.full_name }, [el('b', { text: p.full_name }), el('div', { class: 'muted small' }, [p.code + ' · ' + p.department_name + (p.worker_code ? ' · production ' + p.worker_code : '') + ' ', todayChip(p)])]),
            el('td', {}, [el('span', { class: 'pill ' + (p.pay_type ? '' : 'none'), text: p.pay_type ? KIND1[p.pay_type] : 'Not set up' })]),
            el('td', { text: rate, 'data-sort': p.pay_type === 'salary' ? (p.basic_paisa === null ? '' : +p.basic_paisa + (+p.allowance_paisa || 0)) : p.pay_type === 'daily' ? +p.daily_rate_paisa : '' }), el('td', { class: 'num', 'data-sort': +p.balance || 0 }, [el('b', { text: rs(p.balance) }), p.on_sheet ? el('div', {}, [el('span', { class: 'pill waiting', text: 'on a sheet' })]) : null, adv])]));
        });
        var all = tickOn ? el('input', { type: 'checkbox', 'aria-label': 'Tick everyone listed', change: function(){ rows.forEach(function(p){ PF.sel[p.employee_id] = all.checked; }); draw(); } }) : null;
        if (all) all.checked = rows.every(function(p){ return PF.sel[p.employee_id]; });
        box.appendChild(el('table', { class: 't sortable' }, [el('thead', {}, [el('tr', {}, [tickOn ? el('th', { class: 'tick' }, [all]) : null, el('th', { text: 'Employee' }), el('th', { text: 'Paid as' }), el('th', { text: 'Rate' }), el('th', { style: 'text-align:right', text: 'Owed now' })])]), tb]));
        paintSel();
      }
      function shownNow(){ return r.people.filter(function(p){ return onState(p) && (PF.type === '' || (PF.type === 'none' ? !p.pay_type : p.pay_type === PF.type)) && (!PF.dept || p.department_name === PF.dept) && (!PF.q || (p.full_name + ' ' + p.code).toLowerCase().indexOf(PF.q) >= 0); }); }
      var left = r.people.filter(function(p){ return !+p.active; }).length;
      var state = el('select', { 'aria-label': 'Who is listed', change: function(){ PF.state = state.value; people(); } },
        [['on', 'On the roll'], ['left', 'Left the company (' + left + ')'], ['all', 'Everyone']].map(function(o){ return el('option', { value: o[0], text: o[1] }); }));
      state.value = PF.state;
      show([seg, el('div', { class: 'row' }, [el('div', { style: 'flex:0 1 190px' }, [state]), el('div', { style: 'flex:1 1 160px' }, [dept]), el('div', { style: 'flex:2 1 220px' }, [search]),
        S.caps.staff ? btn('+ New employees', function(){ staffForm([], 8); }, 'pri') : null,
        (S.caps.staff || S.caps.setup) ? btn('Edit these in one form', function(){ staffForm(shownNow(), S.caps.staff ? 3 : 0); }) : null,
        btn('Print / download list', employeeList)]), selBar, box]);
      draw();
    });
  }
  /* A statement the way a ledger page is laid out: the balance brought
     forward, every line with its reference, the totals of the period. */
  function statementView(empId, from, to){
    var f = el('input', { type: 'date', value: from, max: S.today, 'aria-label': 'From' }), t = el('input', { type: 'date', value: to, max: S.today, 'aria-label': 'To' });
    var bodyS = el('div', { class: 'scroll', style: 'max-height:46vh' });
    var dl = el('a', { class: 'btn sm', text: 'Download (Excel)' });
    function cell(text, cls, bold){ return el('td', { class: cls || null }, [bold ? el('b', { text: text }) : text]); }
    function load(){
      dl.setAttribute('href', API + encodeURIComponent('/finance/people/' + empId + '/statement/export') + '&from=' + f.value + '&to=' + t.value);
      bodyS.textContent = ''; bodyS.appendChild(el('p', { class: 'muted', text: 'Loading…' }));
      call('GET', '/finance/people/' + empId + '/statement', { from: f.value, to: t.value }).then(function(r){
        bodyS.textContent = '';
        if (!r.ok) return bodyS.appendChild(fail(r));
        var tb = el('tbody');
        tb.appendChild(el('tr', { class: 'open' }, [cell(nice(r.from)), cell('OPENING', 'muted'), cell('Balance brought forward', null, true), cell(''), cell(''), cell(''), cell(rs(r.opening_paisa), 'num', true)]));
        r.rows.forEach(function(x){
          tb.appendChild(el('tr', {}, [el('td', { style: 'white-space:nowrap', text: nice(x.date) }), el('td', { class: 'muted small', style: 'white-space:nowrap', text: x.ref }), el('td', { text: x.label }),
            el('td', { class: 'num', style: 'color:var(--earn)', text: x.earned ? rs(x.earned) : '' }), el('td', { class: 'num', style: 'color:var(--bad)', text: x.deducted ? rs(x.deducted) : '' }),
            el('td', { class: 'num', style: 'color:var(--paid)', text: x.paid ? rs(x.paid) : '' }), cell(rs(x.balance), 'num', true)]));
        });
        if (!r.rows.length) tb.appendChild(el('tr', {}, [el('td', { colspan: '7', class: 'muted', text: 'No entries between these dates.' })]));
        tb.appendChild(el('tr', { class: 'tot' }, [cell(''), cell('PERIOD TOTALS', null, true), cell(r.rows.length + (r.rows.length === 1 ? ' entry' : ' entries'), 'muted'),
          cell(rs(r.totals.earned), 'num', true), cell(rs(r.totals.deducted), 'num', true), cell(rs(r.totals.paid), 'num', true), cell(rs(r.closing_paisa), 'num', true)]));
        bodyS.appendChild(el('table', { class: 't stmt' }, [el('thead', {}, [el('tr', {}, ['Date', 'Reference', 'Description', 'Earnings', 'Deductions', 'Paid', 'Balance'].map(function(h, i){ return el('th', { style: i > 2 ? 'text-align:right' : null, text: h }); }))]), tb]));
      });
    }
    function span(a, b){ f.value = a; t.value = b; load(); }
    f.addEventListener('change', load); t.addEventListener('change', load);
    load();
    return el('div', { class: 'stack' }, [el('div', { class: 'row' }, [el('div', { style: 'flex:0 1 150px' }, [f]), el('span', { class: 'muted small', text: 'to' }), el('div', { style: 'flex:0 1 150px' }, [t]),
      btn('This month', function(){ span(S.today.slice(0, 8) + '01', S.today); }, 'sm'), btn('Last month', function(){ var m = monthRange(prevMonth()); span(m[0], m[1]); }, 'sm'),
      btn('Everything', function(){ span('2000-01-01', S.today); }, 'sm'), dl]), bodyS]);
  }
  /* THE DEPARTMENT BOX: a drop-down of every department, and "+ New
     department…" at the end. (A typing box with suggestions only offered the
     names matching what was already typed — usually just its own.) Reading
     and setting .value works with the department NAME, as before, so a name
     pasted from Excel that is not a department yet is added as "(new)". */
  function deptSelect(value, attrs){
    var s = el('select', attrs || {});
    var proto = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
    var opts = function(){ return [].slice.call(s.options); };
    s.appendChild(el('option', { value: '', text: (attrs && attrs['data-ph']) || '— choose —' }));
    (S.depts || []).forEach(function(d){ s.appendChild(el('option', { value: d.name, text: d.name })); });
    var newOpt = el('option', { value: '__new', text: '+ New department…' }); s.appendChild(newOpt);
    Object.defineProperty(s, 'value', { configurable: true,
      get: function(){ var v = proto.get.call(s); return v === '__new' ? '' : v; },
      set: function(v){
        v = String(v === null || v === undefined ? '' : v).trim();
        if (v) {
          var m = opts().filter(function(o){ return o.value !== '__new' && o.value.toLowerCase() === v.toLowerCase(); })[0];
          if (m) v = m.value; else s.insertBefore(el('option', { value: v, text: v + ' (new)' }), newOpt);
        }
        proto.set.call(s, v);
      } });
    s.value = value || '';
    var last = s.value;
    s.addEventListener('change', function(){
      if (proto.get.call(s) !== '__new') { last = s.value; return; }
      var n = window.prompt('Name of the new department');
      s.value = n && n.trim() ? n.trim() : last; last = s.value;
      s.dispatchEvent(new Event('input', { bubbles: true }));
    });
    return s;
  }
  function deptList(){
    var old = document.getElementById('fin-depts'); if (old) old.remove();
    return el('datalist', { id: 'fin-depts' }, (S.depts || []).map(function(d){ return el('option', { value: d.name }); }));
  }

  /* ONE EMPLOYEE: who they are, how they are paid, their statement, their
     advances — one dialog, one Save for the first two. */
  function person(p){
    var c = S.caps, id = p.employee_id;
    var advBox = el('div');
    var kids = [deptList(), el('div', { class: 'row', style: 'justify-content:space-between' }, [el('div', { class: 'row' }, [el('div', { class: 'av', text: initials(p.full_name) }),
      el('div', { class: 'nm' }, [el('b', { text: p.full_name }), el('span', { text: p.code + ' · ' + p.department_name + (+p.active ? '' : ' · left the company') })])]), btn('Close', closeModal, 'sm')])];
    if (c.staff || c.setup) {
      var m = { code: el('input', { type: 'text', maxlength: 40, value: p.code, disabled: !c.staff }), name: el('input', { type: 'text', maxlength: 160, value: p.full_name, disabled: !c.staff }),
        dept: deptSelect(p.department_name, { disabled: !c.staff, 'aria-label': 'Department' }), worker: el('input', { type: 'text', maxlength: 40, value: p.worker_code || '', disabled: !c.staff }),
        qr: el('input', { type: 'text', maxlength: 190, value: p.qr_payload || '', disabled: !c.staff, placeholder: 'Only if the card scans as something else' }) };
      var type = el('select', { disabled: !c.setup }, [['', '— not set —'], ['salary', 'Fixed salary (monthly)'], ['daily', 'Daily wages (weekly)'], ['contract', 'Contractor (by production)']].map(function(o){ return el('option', { value: o[0], text: o[1] }); }));
      type.value = p.pay_type || '';
      var money = function(v){ return el('input', { type: 'text', inputmode: 'decimal', value: v ? plain(v) : '', disabled: !c.setup }); };
      var f = { basic: money(p.basic_paisa), allow: money(p.allowance_paisa), daily: money(p.daily_rate_paisa), ot: money(p.ot_rate_paisa),
        method: el('select', { disabled: !c.setup }, [el('option', { value: 'cash', text: 'Cash' }), el('option', { value: 'bank', text: 'Bank' })]), bank: el('input', { type: 'text', maxlength: 60, value: p.bank_account || '', disabled: !c.setup }),
        reason: el('input', { type: 'text', maxlength: 255, placeholder: p.pay_type ? 'Why is the pay changing? (needed when it changes)' : 'Optional' }) };
      f.method.value = p.pay_method || 'cash';
      var w = { basic: field('Salary per month', f.basic), allow: field('Allowance per month', f.allow), daily: field('Rate per day', f.daily), ot: field('Overtime rate per hour', f.ot), bank: field('Bank account', f.bank) };
      var vis = function(){ var t = type.value; w.basic.hidden = w.allow.hidden = t !== 'salary'; w.daily.hidden = t !== 'daily'; w.ot.hidden = t === 'contract' || t === ''; w.bank.hidden = f.method.value !== 'bank'; };
      type.addEventListener('change', vis); f.method.addEventListener('change', vis); vis();
      var e1 = el('div', { class: 'err', hidden: true });
      /* DELETE, for someone entered by mistake: Admin only, and only when nothing
         has happened to them yet — otherwise the server says what they have. */
      var del = c.delete_staff ? btn('Delete (entered by mistake)', function(){
        ask('Delete ' + p.full_name + '?', 'For someone entered by mistake — typed twice, the wrong person. It is only possible while nothing has happened to them: no scan, no attendance, nothing in pay. Their pay setup and photo go with them. For anyone who worked here, use "Mark as left the company" instead.',
          [{ k: 'reason', label: 'Why', wide: true, placeholder: 'e.g. entered twice' }], 'Delete', function(v){
            return call('POST', '/admin/employees/delete', { id: id, reason: v.reason }).then(function(x){ if (x.ok) { closeModal(); toast(p.full_name + ' deleted.'); PEOPLE = null; people(); } return x; }); });
      }, 'sm danger') : null;
      var left = c.staff ? btn(+p.active ? 'Mark as left the company' : 'Bring back on the roll', function(){
        ask(+p.active ? p.full_name + ' has left' : 'Bring ' + p.full_name + ' back', +p.active ? 'They stay in every record and statement; they can no longer be scanned in or put on a new pay sheet.' : '', [{ k: 'reason', label: 'Reason', wide: true }], 'Save', function(v){
          return call('POST', '/finance/staff/' + id + '/active', { active: !+p.active, reason: v.reason }).then(function(x){ if (x.ok) { closeModal(); toast('Saved.'); people(); } return x; }); });
      }, 'sm danger') : null;
      kids.push(el('div', { class: 'cardx stack' }, [el('h3', { text: 'Employee and pay' }),
        el('div', { class: 'formgrid' }, [field('Code', m.code), field('Name', m.name), field('Department', m.dept), field('Production worker code', m.worker), field('Card text', m.qr),
          field('Paid as', type), w.basic, w.allow, w.daily, w.ot, field('Paid by', f.method), w.bank, field('Reason', f.reason)]),
        el('p', { class: 'muted small', style: 'margin:0', text: 'A contractor is paid from Production: give the same code here as in Production › Workers (or leave it blank to use the employee code). The overtime rate is used only for approved, checked overtime that Accounts has not already priced.' }), e1,
        el('div', { class: 'row', style: 'justify-content:space-between' }, [el('div', { class: 'row' }, [left, del]), btn('Save', function(ev){
          var num = function(i){ var v = i.value.trim(); return v === '' ? 0 : toPaisa(v); };
          var row = { employee_id: id };
          if (c.staff) { row.code = m.code.value.trim(); row.full_name = m.name.value.trim(); row.department = m.dept.value.trim(); row.worker_code = m.worker.value.trim(); row.qr_payload = m.qr.value.trim(); }
          if (c.setup && type.value) {
            row.pay_type = type.value; row.basic_paisa = num(f.basic); row.allowance_paisa = num(f.allow); row.daily_rate_paisa = num(f.daily); row.ot_rate_paisa = num(f.ot); row.pay_method = f.method.value; row.bank_account = f.bank.value.trim();
            if ([row.basic_paisa, row.allowance_paisa, row.daily_rate_paisa, row.ot_rate_paisa].indexOf(null) >= 0) { e1.textContent = 'Amounts are numbers like 45000 or 1200.50.'; e1.hidden = false; return; }
          }
          ev.target.disabled = true;
          call('POST', '/finance/staff', { rows: [row], reason: f.reason.value.trim() }).then(function(x){
            ev.target.disabled = false;
            if (x.errors && x.errors.length) { e1.textContent = x.errors.join('  ·  '); e1.hidden = false; return; }
            if (!x.ok) { e1.textContent = x.error || 'Nothing was saved.'; e1.hidden = false; return; }
            closeModal(); toast('Saved.'); people();
          });
        }, 'pri')])]));
    }
    if (c.photo) kids.push(photoCard(p));
    kids.push(el('div', { class: 'cardx stack' }, [el('div', { class: 'row', style: 'justify-content:space-between' }, [el('h3', { text: 'Ledger' }),
      c.pay ? btn('Opening balance', function(){ ask('Opening balance for ' + p.full_name, 'What the company owed this person on the day you start using this screen. Entered again, it replaces the old figure. Money the person owes is an advance instead.',
        [{ k: 'amt', label: 'Amount owed to them', money: true, value: p.opening_paisa ? plain(p.opening_paisa) : '' }, { k: 'reason', label: 'Where the figure comes from', wide: true, placeholder: 'e.g. wage register, page 14' }], 'Save', function(v){
          var a = toPaisa(v.amt); if (a === null) return { ok: false, error: 'Give the amount.' };
          return call('POST', '/finance/people/' + id + '/opening', { amount_paisa: a, reason: v.reason }).then(function(x){ if (x.ok) { closeModal(); toast('Opening balance saved.'); people(); } return x; }); }); }, 'sm') : null]),
      statementView(id, '2000-01-01', S.today)]));
    kids.push(el('div', { class: 'cardx stack' }, [el('div', { class: 'row', style: 'justify-content:space-between' }, [el('h3', { text: 'Advances & loans' }), c.pay ? btn('Give advance or loan', function(){ give(p); }, 'sm') : null]), advBox]));
    modal(kids, true);
    call('GET', '/finance/people/' + id + '/ledger').then(function(r){
      advBox.textContent = '';
      if (!r.ok) return advBox.appendChild(fail(r));
      if (!r.advances.length) advBox.appendChild(el('div', { class: 'muted small', text: 'None.' }));
      r.advances.forEach(function(a){ advBox.appendChild(advRow(a, false)); });
    });
  }
  /* THE GATE PHOTO, in the same card as everything else about the person.
     The same upload as Attendance › Setup: cropped and made small on the
     server; the original is not kept. */
  function photoSrc(id){ return API + encodeURIComponent('/admin/employees/photo') + '&id=' + encodeURIComponent(id) + '&t=' + Date.now(); }
  function sendFile(route, fields, file){
    var form = new FormData();
    Object.keys(fields).forEach(function(k){ form.append(k, fields[k]); });
    form.append('photo', file, 'photo');
    return fetch(API + encodeURIComponent(route), { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF }, body: form })
      .then(function(r){ return r.json().catch(function(){ return { ok: false, error: r.status === 413 ? 'That picture is too large to upload.' : 'The server sent something unreadable.' }; }); })
      .catch(function(){ return { ok: false, error: 'The server could not be reached.' }; });
  }
  function shrink(file){
    return new Promise(function(resolve){
      if (!window.createImageBitmap) return resolve(file);
      createImageBitmap(file, { imageOrientation: 'from-image' }).then(function(bmp){
        var scale = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
        if (scale === 1 && file.size < 1500000) return resolve(file);
        var cv = document.createElement('canvas');
        cv.width = Math.max(1, Math.round(bmp.width * scale)); cv.height = Math.max(1, Math.round(bmp.height * scale));
        cv.getContext('2d').drawImage(bmp, 0, 0, cv.width, cv.height);
        cv.toBlob(function(b){ resolve(b && b.size < file.size ? b : file); }, 'image/jpeg', 0.88);
      }).catch(function(){ resolve(file); });
    });
  }
  function photoCard(p){
    var has = +p.has_photo === 1, told = +p.notice_given === 1;
    var pic = el('div', { class: 'gatepic' }, [has ? el('img', { alt: 'Photo on file for ' + p.full_name, src: photoSrc(p.employee_id) }) : el('span', { text: 'No photo yet' })]);
    var file = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', 'aria-label': 'Choose a photo' });
    var notice = el('input', { type: 'checkbox' }), msg = el('div', { class: 'small muted' });
    var save = btn('Save photo', function(){
      var f = file.files && file.files[0];
      if (!f) { msg.textContent = 'Choose a picture first.'; return; }
      if (!told && !notice.checked) { msg.textContent = 'Tick that the employee has been told their photo is kept for attendance.'; return; }
      save.disabled = true; msg.textContent = 'Saving…';
      shrink(f).then(function(small){ return sendFile('/admin/employees/photo', { id: p.employee_id, notice_given: '1' }, small); }).then(function(r){
        save.disabled = false;
        if (!r.ok) { msg.textContent = r.error || 'The photo was not saved.'; return; }
        p.has_photo = 1; p.notice_given = 1; has = true; told = true;
        pic.textContent = ''; pic.appendChild(el('img', { alt: 'Photo on file for ' + p.full_name, src: photoSrc(p.employee_id) }));
        msg.textContent = 'Saved. This is what the gate will see.'; PEOPLE = null;
      });
    }, 'sm pri');
    var remove = btn('Remove photo', function(){
      ask('Remove the photo of ' + p.full_name, 'The gate will show no picture for this person until a new one is saved.', [{ k: 'reason', label: 'Why', wide: true }], 'Remove', function(v){
        return call('POST', '/admin/employees/photo/remove', { id: p.employee_id, reason: v.reason }).then(function(x){ if (x.ok) { closeModal(); toast('Photo removed.'); PEOPLE = null; people(); } return x; });
      });
    }, 'sm danger');
    return el('div', { class: 'cardx' }, [el('h3', { text: 'Photo for the gate' }), el('div', { class: 'row', style: 'align-items:flex-start;gap:14px' }, [pic,
      el('div', { class: 'stack', style: 'flex:1 1 260px;gap:8px' }, [field(has ? 'Replace the photo' : 'Photo — JPG, PNG or WebP', file),
        told ? el('div', { class: 'small muted', text: 'This employee has been told their photo is kept for attendance.' }) : el('label', { class: 'small', style: 'display:flex;gap:6px;align-items:center' }, [notice, 'This employee has been told their photo will be kept for attendance']),
        el('div', { class: 'row' }, [save, has ? remove : null]), msg])])]);
  }

  function advRow(a, withName){
    var pct = a.amount_paisa ? Math.round(a.recovered_paisa * 100 / a.amount_paisa) : 0;
    return el('div', { class: 'row', style: 'justify-content:space-between;padding:8px 0;border-top:1px solid var(--soft)' }, [
      el('div', { class: 'nm', style: 'flex:1 1 200px' }, [el('b', { text: (withName ? a.full_name + ' · ' : '') + (a.kind === 'loan' ? 'Loan' : 'Advance') + ' ' + rs(a.amount_paisa) }),
        el('span', { text: (withName ? a.code + ' · ' + a.department_name + ' · ' : '') + 'given ' + nice(a.given_on) + ' · ' + (a.status === 'active' && !a.per_pay_paisa ? 'recovery paused' : rs(a.per_pay_paisa) + ' from each pay') + (a.note ? ' · ' + a.note : '') })]),
      el('div', { style: 'flex:1 1 120px' }, [el('div', { class: 'bar-r' }, [el('i', { style: 'width:' + pct + '%' })]), el('div', { class: 'small muted', text: rs(a.recovered_paisa) + ' recovered' })]),
      el('div', { class: 'num' }, [el('b', { text: rs(a.left_paisa) }), el('div', { class: 'small muted', text: a.status === 'closed' ? 'cleared' : 'still owed' })]),
      S.caps.pay && a.status === 'active' ? btn('Change plan', function(){
        ask('Change the plan — ' + (a.kind === 'loan' ? 'loan' : 'advance') + ' of ' + rs(a.amount_paisa), 'Rs ' + rs(a.left_paisa) + ' is still owed. Set how much comes off each pay from now on, or 0 to pause the recovery (for example in Eid month).',
          [{ k: 'per', label: 'From each pay', money: true, value: plain(a.per_pay_paisa) }, { k: 'reason', label: 'Why', wide: true }], 'Save', function(v){
            var per = v.per === '' ? null : toPaisa(v.per); if (per === null) return { ok: false, error: 'Give an amount, or 0 to pause.' };
            return call('POST', '/finance/advances/' + a.id + '/change', { per_pay_paisa: per, reason: v.reason }).then(function(x){ if (x.ok) { closeModal(); toast(per ? 'Plan changed.' : 'Recovery paused.'); if (S.tab === 'advances') advances(); } return x; });
          });
      }, 'sm') : null,
      (S.caps.salary || S.caps.wage || S.caps.adjust) && a.status === 'active' ? btn('Recover now', function(){
        loadPeople().then(function(){
          var who = PEOPLE.filter(function(x){ return +x.employee_id === +a.employee_id; })[0];
          if (!who) return toast('That person is not in your list.', true);
          entryForm('deduction', { p: who, type: a.kind, advance_id: a.id });
        });
      }, 'sm') : null,
      S.caps.pay && a.status === 'active' && a.recovered_paisa === 0 ? btn('Cancel', function(){
        ask('Cancel this ' + a.kind, 'Only possible because nothing has been recovered yet.', [{ k: 'reason', label: 'Why', wide: true }], 'Cancel it', function(v){
          return call('POST', '/finance/advances/' + a.id + '/cancel', v).then(function(x){ if (x.ok) { closeModal(); toast('Cancelled.'); if (S.tab === 'advances') advances(); } return x; }); });
      }, 'sm danger') : null]);
  }
  function give(p){
    var go2 = function(list){
      var fields = [];
      if (!p) fields.push({ k: 'employee_id', label: 'Employee', type: 'select', wide: true, options: list.map(function(x){ return [String(x.employee_id), x.full_name + ' (' + x.code + ') · ' + x.department_name]; }) });
      fields = fields.concat([{ k: 'kind', label: 'What', type: 'select', options: [['advance', 'Advance (short)'], ['loan', 'Loan (long)']] }, { k: 'amt', label: 'Amount given', money: true },
        { k: 'per', label: 'Recover from each pay', money: true }, { k: 'given_on', label: 'Given on', type: 'date', value: S.today }, { k: 'method', label: 'Given by', type: 'select', options: [['cash', 'Cash'], ['bank', 'Bank']] }, { k: 'note', label: 'Note', wide: true }]);
      var inputs = ask('Give an advance or loan' + (p ? ' to ' + p.full_name : ''), 'It is recovered a little from each pay sheet. On any sheet the recovery line can be changed or skipped, with a reason.', fields, 'Save', function(v){
        var a = toPaisa(v.amt), per = toPaisa(v.per);
        if (a === null || a <= 0) return { ok: false, error: 'Give the amount.' };
        if (per === null || per <= 0) return { ok: false, error: 'Give how much to recover from each pay.' };
        return call('POST', '/finance/advances', { employee_id: p ? p.employee_id : +v.employee_id, kind: v.kind, amount_paisa: a, per_pay_paisa: per, given_on: v.given_on, method: v.method, note: v.note }).then(function(x){
          if (x.ok) { closeModal(); toast('Saved.'); if (S.tab === 'advances') advances(); else refreshCounts(); } return x; });
      });
      inputs.given_on.max = S.today;
    };
    if (p) return go2();
    call('GET', '/finance/people').then(function(r){ if (!r.ok) return toast(r.error, true); go2(r.people.filter(function(x){ return +x.active; })); });
  }

  // ------------------------------------------------- the one employee form
  /* Everything about many employees in one grid: who they are, how they are
     paid, what they were owed on day one. Type it, or copy a block of cells in
     Excel and paste it into the first cell. Existing people are edited in the
     same grid; empty lines at the bottom are for new people. One Save. */
  var SF_COLS = ['code', 'name', 'dept', 'worker', 'type', 'amt', 'alw', 'ot', 'method', 'bank', 'open', 'oadv', 'oper', 'state'];
  function staffForm(list, blanks){
    var c = S.caps;
    if (list.length > 300) list = list.slice(0, 300);
    var rowsEl = el('tbody'), rows = [];
    var TYPES = [['', '—'], ['salary', 'Fixed salary'], ['daily', 'Daily wages'], ['contract', 'Contractor']];
    function typeOf(text){ var t = String(text || '').toLowerCase(); return /sal|month|fix/.test(t) ? 'salary' : /dai|day|wage|week/.test(t) ? 'daily' : /contr|piece|prod/.test(t) ? 'contract' : ''; }
    function addRow(p){
      p = p || {};
      var isNew = !p.employee_id, hide = !isNew && p.pay_type === 'salary' && p.basic_paisa === null;       // a salary this user may not see
      var txt = function(v, max, dis, ph, cls){ return el('input', { type: 'text', value: v || '', maxlength: max, disabled: dis, placeholder: ph || null, class: cls || null }); };
      var o = { p: p, isNew: isNew,
        cb: el('input', { type: 'checkbox', 'aria-label': 'Save this line' }),
        code: txt(p.code, 40, !c.staff, isNew ? 'auto' : ''), name: txt(p.full_name, 160, !c.staff, isNew ? 'Full name' : ''),
        dept: deptSelect(p.department_name || '', { disabled: !c.staff, 'data-ph': isNew ? 'Department…' : '— choose —' }),
        worker: txt(p.worker_code, 40, !c.staff),
        type: el('select', { disabled: !c.setup || hide }, TYPES.map(function(t){ return el('option', { value: t[0], text: t[1] }); })),
        amt: txt(p.pay_type === 'salary' ? (p.basic_paisa ? plain(p.basic_paisa) : '') : (p.daily_rate_paisa ? plain(p.daily_rate_paisa) : ''), 14, !c.setup || hide, '', 'n'),
        alw: txt(p.allowance_paisa ? plain(p.allowance_paisa) : '', 14, !c.setup || hide, '', 'n'), ot: txt(p.ot_rate_paisa ? plain(p.ot_rate_paisa) : '', 14, !c.setup || hide, '', 'n'),
        method: el('select', { disabled: !c.setup || hide }, [el('option', { value: 'cash', text: 'Cash' }), el('option', { value: 'bank', text: 'Bank' })]),
        bank: txt(p.bank_account, 60, !c.setup || hide), open: txt(p.opening_paisa ? plain(p.opening_paisa) : '', 14, !c.pay || hide, '', 'n'),
        /* money they owed the company on day one (an advance or loan from before): becomes one advance, recovered from each pay.
           Once entered it is changed under Advances & loans, so here it is shown and locked. */
        oadv: txt(p.opening_advance_paisa ? plain(p.opening_advance_paisa) : '', 14, !c.pay || hide || !!p.opening_advance_paisa, '', 'n'),
        oper: txt(p.opening_advance_per_pay_paisa ? plain(p.opening_advance_per_pay_paisa) : '', 14, !c.pay || hide || !!p.opening_advance_paisa, '', 'n'),
        state: el('select', { disabled: isNew || !c.staff }, [el('option', { value: 'on', text: 'On roll' }), el('option', { value: 'left', text: 'Left' })]) };
      o.state.value = isNew || +p.active ? 'on' : 'left';
      o.type.value = p.pay_type || ''; o.method.value = p.pay_method || 'cash';
      o.vis = function(){ var t = o.type.value, off = !c.setup || hide; o.amt.disabled = off || t === 'contract' || t === ''; o.alw.disabled = off || t !== 'salary'; o.ot.disabled = off || t === 'contract' || t === '';
        o.amt.placeholder = t === 'salary' ? 'per month' : t === 'daily' ? 'per day' : ''; o.bank.disabled = off || o.method.value !== 'bank'; };
      o.vis();
      SF_COLS.forEach(function(k){ o[k].setAttribute('data-k', k); o[k].setAttribute('aria-label', k + ' — ' + (p.full_name || 'new line'));
        o[k].addEventListener(o[k].tagName === 'SELECT' ? 'change' : 'input', function(){ o.cb.checked = true; o.vis(); count(); }); });
      o.tr = el('tr', {}, [el('td', {}, [o.cb])].concat(SF_COLS.map(function(k){ return el('td', {}, [o[k]]); })));
      rows.push(o); rowsEl.appendChild(o.tr); return o;
    }
    list.forEach(addRow);
    for (var i = 0; i < blanks; i++) addRow(null);

    /* Paste from Excel: a block of cells lands from the cell you are in,
       across and down, adding lines as it needs them. */
    rowsEl.addEventListener('paste', function(e){
      var text = (e.clipboardData || window.clipboardData).getData('text');
      if (!text || (text.indexOf('\t') < 0 && text.indexOf('\n') < 0)) return;        // one plain value: let the browser paste it
      var at = e.target, k0 = at.getAttribute && at.getAttribute('data-k'); if (!k0) return;
      e.preventDefault();
      var r0 = -1; rows.forEach(function(o, i){ if (o[k0] === at) r0 = i; });
      var c0 = SF_COLS.indexOf(k0), lines = text.replace(/\r/g, '').split('\n'); while (lines.length && !lines[lines.length - 1].trim()) lines.pop();
      lines.forEach(function(line, i){
        var o = rows[r0 + i] || (c.staff ? addRow(null) : null); if (!o) return;
        line.split('\t').forEach(function(v, j){
          var k = SF_COLS[c0 + j]; if (!k) return; var inp = o[k]; v = v.trim();
          if (k === 'type') { if (!o.type.disabled) o.type.value = typeOf(v); o.vis(); return; }
          if (k === 'method') { if (!o.method.disabled) o.method.value = /bank/i.test(v) ? 'bank' : 'cash'; o.vis(); return; }
          if (k === 'state') { if (!o.state.disabled) o.state.value = /left|inactive|off|no/i.test(v) ? 'left' : 'on'; return; }
          if (!inp.disabled || k === 'amt' || k === 'alw' || k === 'ot' || k === 'bank') inp.value = (k === 'amt' || k === 'alw' || k === 'ot' || k === 'open' || k === 'oadv' || k === 'oper') ? v.replace(/,/g, '') : v;
        });
        o.vis(); o.cb.checked = true;
      });
      count(); toast(lines.length + (lines.length === 1 ? ' line' : ' lines') + ' pasted. Check them, then Save.');
    });

    var all = el('input', { type: 'checkbox', 'aria-label': 'Tick or untick every line', change: function(){ rows.forEach(function(o){ o.cb.checked = all.checked; }); count(); } });
    var fT = el('select', { 'aria-label': 'Fill: paid as' }, [['', 'Paid as…'], ['salary', 'Fixed salary'], ['daily', 'Daily wages'], ['contract', 'Contractor']].map(function(o){ return el('option', { value: o[0], text: o[1] }); }));
    var fD = deptSelect('', { 'aria-label': 'Fill: department', 'data-ph': 'Department…' }), fA = el('input', { type: 'text', inputmode: 'decimal', placeholder: 'Salary / day rate', 'aria-label': 'Fill: amount' });
    var fM = el('select', { 'aria-label': 'Fill: paid by' }, [el('option', { value: '', text: 'Paid by…' }), el('option', { value: 'cash', text: 'Cash' }), el('option', { value: 'bank', text: 'Bank' })]);
    var fS = el('select', { 'aria-label': 'Fill: on the roll or left' }, [el('option', { value: '', text: 'State…' }), el('option', { value: 'on', text: 'On the roll' }), el('option', { value: 'left', text: 'Left' })]);
    var reason = el('input', { type: 'text', maxlength: 255, placeholder: 'Reason (needed when changing pay that was already set, or marking someone as Left)' });
    var errBox = el('div', { class: 'err', hidden: true }), countEl = el('span', { class: 'muted small' });
    function count(){ var n = rows.filter(function(o){ return o.cb.checked; }).length; countEl.textContent = n ? n + (n === 1 ? ' line' : ' lines') + ' to save' : 'Nothing changed yet'; }
    var save = btn('Save', function(){
      var out = [], bad = '', anyLeft = false;
      rows.forEach(function(o){
        if (!o.cb.checked) return;
        var who = o.name.value.trim() || 'A line with no name';
        var num = function(i){ var v = i.value.trim().replace(/,/g, ''); return v === '' || i.disabled ? 0 : toPaisa(v); };
        if (o.isNew && !o.name.value.trim() && !o.code.value.trim()) return;                    // an untouched spare line
        if (o.isNew && !o.name.value.trim()) { bad = bad || 'A new line has a code but no name.'; return; }
        if (o.isNew && !o.dept.value.trim()) { bad = bad || who + ': give the department.'; return; }
        var a = num(o.amt), al = num(o.alw), ov = num(o.ot), row = {};
        if (!o.isNew) row.employee_id = o.p.employee_id;
        if (c.staff) { row.code = o.code.value.trim(); row.full_name = o.name.value.trim(); row.department = o.dept.value.trim(); row.worker_code = o.worker.value.trim(); }
        if (c.setup && !o.type.disabled && o.type.value) {
          if (a === null || al === null || ov === null) { bad = bad || who + ': amounts are numbers like 45000 or 1200.50.'; return; }
          row.pay_type = o.type.value; row.basic_paisa = o.type.value === 'salary' ? a : 0; row.daily_rate_paisa = o.type.value === 'daily' ? a : 0; row.allowance_paisa = al; row.ot_rate_paisa = ov;
          row.pay_method = o.method.value; row.bank_account = o.bank.value.trim();
        }
        if (c.pay && !o.open.disabled && o.open.value.trim() !== '') { var op = toPaisa(o.open.value.trim().replace(/,/g, '')); if (op === null) { bad = bad || who + ': the opening balance is a number.'; return; } row.opening_paisa = op; }
        if (c.pay && !o.oadv.disabled && o.oadv.value.trim() !== '') {
          var oa = toPaisa(o.oadv.value.trim().replace(/,/g, '')), pp = toPaisa(o.oper.value.trim().replace(/,/g, ''));
          if (oa === null || oa <= 0) { bad = bad || who + ': the opening advance is a number like 25000.'; return; }
          if (pp === null || pp <= 0) { bad = bad || who + ': give how much of the opening advance to recover from each pay.'; return; }
          if (pp > oa) { bad = bad || who + ': the recovery each pay cannot be more than the advance.'; return; }
          row.opening_advance_paisa = oa; row.opening_advance_per_pay_paisa = pp;
        }
        if (!o.isNew && c.staff && !o.state.disabled && (o.state.value === 'on') !== !!+o.p.active) { row.active = o.state.value === 'on'; if (!row.active) anyLeft = true; }
        out.push(row);
      });
      if (!bad && anyLeft && !reason.value.trim()) bad = 'Give a reason in the box below for those marked as Left — it is kept with each of them.';
      if (bad) { errBox.textContent = bad; errBox.hidden = false; return; }
      if (!out.length) { errBox.textContent = 'Nothing to save: type in a line, or tick the lines you changed.'; errBox.hidden = false; return; }
      save.disabled = true; errBox.hidden = true;
      call('POST', '/finance/staff', { rows: out, reason: reason.value.trim() }).then(function(x){
        save.disabled = false;
        var did = [x.created && x.created + ' added', x.updated && x.updated + ' changed', x.pay_saved && x.pay_saved + ' pay set', x.opening_saved && x.opening_saved + ' opening balance' + (x.opening_saved === 1 ? '' : 's'),
          x.opening_advances && x.opening_advances + ' opening advance' + (x.opening_advances === 1 ? '' : 's'), x.state_changed && x.state_changed + ' on the roll / left changed'].filter(Boolean).join(', ');
        if (did) toast(did + '.' + (x.errors && x.errors.length ? ' ' + x.errors.length + ' not saved — see the list.' : ''));
        if (x.errors && x.errors.length) { errBox.textContent = x.errors.slice(0, 8).join('  ·  ') + (x.errors.length > 8 ? '  · and ' + (x.errors.length - 8) + ' more' : ''); errBox.hidden = false; if (did) refreshCounts(); return; }
        if (!x.ok) { errBox.textContent = x.error || 'Nothing was saved.'; errBox.hidden = false; return; }
        if (!did) toast('Nothing needed changing.');
        closeModal(); refreshCounts().then(function(){ people(); });
      });
    }, 'pri');
    var tools = el('div', { class: 'row' }, [el('b', { class: 'small', text: 'Same for all ticked:' }), c.staff ? el('div', { style: 'flex:1 1 130px' }, [fD]) : null, c.setup ? el('div', { style: 'flex:1 1 120px' }, [fT]) : null,
      c.setup ? el('div', { style: 'flex:1 1 120px' }, [fA]) : null, c.setup ? el('div', { style: 'flex:1 1 100px' }, [fM]) : null, c.staff ? el('div', { style: 'flex:1 1 100px' }, [fS]) : null,
      btn('Fill', function(){ rows.forEach(function(o){ if (!o.cb.checked) return; if (fD.value.trim() && !o.dept.disabled) o.dept.value = fD.value.trim(); if (fT.value && !o.type.disabled) o.type.value = fT.value; if (fS.value && !o.state.disabled) o.state.value = fS.value; o.vis();
        if (fA.value.trim() && !o.amt.disabled) o.amt.value = fA.value.trim(); if (fM.value && !o.method.disabled) o.method.value = fM.value; o.vis(); }); }, 'sm'),
      c.staff ? btn('+ 5 lines', function(){ for (var i = 0; i < 5; i++) addRow(null); }, 'sm') : null,
      c.staff ? btn('Bring in production workers', function(ev){
        ev.target.disabled = true;
        call('GET', '/finance/staff/workers').then(function(w){
          ev.target.disabled = false;
          if (!w.ok || !w.workers || !w.workers.length) return toast('Every production worker is already an employee.', false);
          w.workers.forEach(function(x){ var o = rows.filter(function(r){ return r.isNew && !r.name.value.trim() && !r.code.value.trim(); })[0] || addRow(null);
            o.code.value = x.code || ''; o.name.value = x.full_name || ''; o.dept.value = x.department || ''; o.worker.value = x.worker_code || ''; if (!o.type.disabled) o.type.value = 'contract'; o.vis(); o.cb.checked = true; });
          count(); toast(w.workers.length + ' production worker' + (w.workers.length === 1 ? '' : 's') + ' added to the form as contractors. Give each a department if it is empty, then Save.');
        });
      }, 'sm') : null]);
    modal([deptList(), el('div', { class: 'row', style: 'justify-content:space-between' }, [el('h2', { text: list.length ? 'Employees — one form' : 'New employees' }), btn('Close', closeModal, 'sm')]),
      el('p', { class: 'muted small', style: 'margin:0', text: 'One line per person: who they are, how they are paid, and what they were owed on day one — or owed the company (opening advance / loan, recovered from each pay). Copy cells in Excel and paste into the first cell to fill many lines. Leave Code empty and the next number is given. A line is saved when it is ticked; typing in it ticks it.' }),
      el('div', { class: 'cardx' }, [tools]),
      el('div', { class: 'scroll', style: 'max-height:50vh' }, [el('table', { class: 't bulk staff' }, [el('thead', {}, [el('tr', {}, [el('th', {}, [all])].concat(
        ['Code', 'Name', 'Department', 'Production code', 'Paid as', 'Salary / day rate', 'Allowance', 'Overtime / hour', 'Paid by', 'Bank account', 'Opening balance', 'Opening advance / loan', 'Recover each pay', 'State'].map(function(h){ return el('th', { text: h }); })))]), rowsEl])]),
      reason, errBox, el('div', { class: 'row', style: 'justify-content:space-between' }, [countEl, save])], true).classList.add('xwide');
    count();
  }

  // ------------------------------------------------------------ ledger
  /* Everybody in one place, between two dates. A row opens that person's
     statement for the same dates. */
  // ------------------------------------------------- production adjustment
  /* MANY CONTRACTORS IN ONE GRID. One line per contractor with what Production
     booked between two days. Adjust by pieces x rate, by a fixed amount, by a
     percentage of what was booked, or open the contractor's lines and correct
     the pieces or the rate of any of them. The figures shown while typing are
     a courtesy; the server works every one out again from Production's own
     records, and what Production booked is never changed. */
  var PA = { from: '', to: '', dept: '' };
  function prodAdjust(){
    if (!PA.from) { PA.from = S.today.slice(0, 8) + '01'; PA.to = S.today; }
    var num = function(v){ v = String(v === null || v === undefined ? '' : v).replace(/,/g, '').trim(); if (v === '' || v === '-' ) return null; var n = Number(v); return isFinite(n) ? n : NaN; };
    var paisa = function(n){ return Math.round(n * 100); };
    var signed = function(p){ return (p > 0 ? '+' : '') + rs(p); };
    var from = el('input', { type: 'date', value: PA.from, max: S.today }), to = el('input', { type: 'date', value: PA.to, max: S.today });
    var dept = el('select', {}, [el('option', { value: '', text: 'All departments' })].concat((S.depts || []).map(function(d){ return el('option', { value: String(d.id), text: d.name }); })));
    dept.value = PA.dept;
    var reason = el('input', { type: 'text', maxlength: 255, placeholder: 'e.g. Checked against the packing count (needed)' });
    var dir = el('select', { 'aria-label': 'Take off or add' }, [el('option', { value: '-1', text: 'Take off (less to pay)' }), el('option', { value: '1', text: 'Add (more to pay)' })]);
    var same = el('input', { type: 'text', inputmode: 'decimal', class: 'n', placeholder: 'Amount', 'aria-label': 'Same for all ticked' });
    var pctOn = false;
    var pctBtn = el('button', { type: 'button', class: 'btn sm pct', 'aria-pressed': 'false', title: 'Switch between a fixed amount and a percentage of what was booked', text: '%' });
    var pctHint = el('span', { class: 'muted small', hidden: true, text: 'percent of each contractor\'s booked amount' });
    pctBtn.addEventListener('click', function(){ pctOn = !pctOn; pctBtn.setAttribute('aria-pressed', pctOn ? 'true' : 'false'); pctHint.hidden = !pctOn; same.placeholder = pctOn ? 'Percent, e.g. 2' : 'Amount'; });
    var holder = el('div', { class: 'scroll', style: 'max-height:50vh' }, [el('p', { class: 'muted', text: 'Loading…' })]);
    var errBox = el('div', { class: 'err', hidden: true }), sumText = el('span', { class: 'muted small' });
    var R = [];
    function rowAdj(r){
      var t = 0, bad = false;
      Object.keys(r.edits).forEach(function(id){
        var l = r.c.lines.filter(function(x){ return x.id === +id; })[0], e = r.edits[id]; if (!l) return;
        var q = num(e.qty), rt = num(e.rate);
        if (q !== q || rt !== rt || q === null || rt === null || q < 0 || rt < 0) { bad = true; return; }
        t += paisa(q * rt) - l.amount_paisa;
      });
      var q2 = num(r.pieces.value), r2 = num(r.rate.value);
      if (q2 !== null || r2 !== null) { if (q2 === null || r2 === null || q2 !== q2 || r2 !== r2 || r2 < 0) bad = true; else t += paisa(q2 * r2); }
      var f = num(r.fixed.value);
      if (f !== null) { if (f !== f) bad = true; else t += r.pct !== null ? Math.round(r.c.booked_paisa * r.pct / 100) : paisa(f); }
      return { t: t, bad: bad };
    }
    function paint(){
      var off = 0, on = 0, nOff = 0, nOn = 0, bad = false, tot = 0;
      R.forEach(function(r){
        var a = rowAdj(r);
        r.adj.textContent = a.bad ? 'check' : (a.t ? signed(a.t) : '—');
        r.adj.className = 'num ' + (a.bad ? 'c-ded' : a.t < 0 ? 'c-ded' : a.t > 0 ? 'c-earn' : 'nil');
        r.now.textContent = rs(r.c.booked_paisa + a.t);
        if (a.bad) bad = true;
        if (a.t < 0) { off += a.t; nOff++; } else if (a.t > 0) { on += a.t; nOn++; }
        tot += a.t;
      });
      sumText.textContent = (nOff || nOn) ? [nOff ? nOff + ' taken off (' + signed(off) + ')' : '', nOn ? nOn + ' added (' + signed(on) + ')' : ''].filter(Boolean).join(' · ') : 'Nothing adjusted yet';
      totAdj.textContent = tot ? signed(tot) : '—';
      totNow.textContent = rs(R.reduce(function(a, r){ return a + r.c.booked_paisa; }, 0) + tot);
      save.disabled = bad || !(nOff || nOn); if (saveNow) saveNow.disabled = save.disabled;
    }
    var totAdj = el('td', { class: 'num' }), totNow = el('td', { class: 'num' });
    function drawLines(r){
      r.sub.forEach(function(n){ n.remove(); }); r.sub = [];
      r.tog.textContent = r.open ? '▾ Hide lines' : '▸ Show lines';
      r.pieces.hidden = r.rate.hidden = false;
      if (!r.open) return;
      var after = r.tr;
      var add = function(tr){ after.parentNode.insertBefore(tr, after.nextSibling); after = tr; r.sub.push(tr); };
      add(el('tr', { class: 'sub h' }, [el('td'), el('td', { text: 'Date · Product · Operation' }), el('td', { class: 'num', text: 'Pieces' }), el('td', { class: 'num', text: 'Amount' }),
        el('td', { class: 'num', text: 'Correct pieces' }), el('td', { class: 'num', text: 'Correct rate' }), el('td', { class: 'num', text: 'Booked rate' }), el('td'), el('td', { class: 'num', text: 'Difference' }), el('td'), el('td')]));
      if (!r.c.lines.length) add(el('tr', { class: 'sub' }, [el('td'), el('td', { colspan: '10', class: 'muted', text: 'Production booked nothing for this contractor between these dates.' })]));
      r.c.lines.forEach(function(l){
        var e = r.edits[l.id];
        var q = el('input', { type: 'text', inputmode: 'decimal', class: 'n' + (e ? ' chg' : ''), value: e ? e.qty : String(l.qty), 'aria-label': 'Correct pieces' });
        var rt = el('input', { type: 'text', inputmode: 'decimal', class: 'n' + (e ? ' chg' : ''), value: e ? e.rate : String(l.rate), 'aria-label': 'Correct rate' });
        var diff = el('td', { class: 'num' });
        function sync(){
          var nq = num(q.value), nr = num(rt.value), same2 = nq === l.qty && nr === l.rate;
          if (same2) delete r.edits[l.id]; else r.edits[l.id] = { qty: q.value, rate: rt.value };
          q.classList.toggle('chg', nq !== l.qty); rt.classList.toggle('chg', nr !== l.rate);
          var okN = nq !== null && nr !== null && nq === nq && nr === nr && nq >= 0 && nr >= 0, d = okN ? paisa(nq * nr) - l.amount_paisa : 0;
          diff.textContent = !okN ? 'check' : (d ? signed(d) : '0'); diff.className = 'num ' + (!okN || d < 0 ? 'c-ded' : d > 0 ? 'c-earn' : 'nil');
          paint();
        }
        q.addEventListener('input', sync); rt.addEventListener('input', sync);
        add(el('tr', { class: 'sub' }, [el('td'), el('td', { text: nice(l.date).replace(/ \d{4}$/, '') + ' · ' + l.what }), el('td', { class: 'num', text: String(l.qty) }), el('td', { class: 'num', text: rs(l.amount_paisa) }),
          el('td', { class: 'num' }, [q]), el('td', { class: 'num' }, [rt]), el('td', { class: 'num', text: String(l.rate) }), el('td'), diff, el('td'), el('td')]));
        sync();
      });
    }
    var loadSeq = 0;
    function load(){
      PA.from = from.value; PA.to = to.value; PA.dept = dept.value;
      holder.textContent = ''; holder.appendChild(el('p', { class: 'muted', text: 'Loading…' })); R = [];
      var mine = ++loadSeq;                              // only the newest answer may fill the grid
      call('GET', '/finance/production', { from: PA.from, to: PA.to, department_id: PA.dept }).then(function(x){
        if (mine !== loadSeq) return;
        holder.textContent = '';
        if (!x.ok) return holder.appendChild(fail(x));
        if (!x.contractors.length) return holder.appendChild(el('div', { class: 'empty', text: 'No contractor linked to Production here. In Employees, set the person to "Contractor" with their production worker code.' }));
        var tb = el('tbody');
        x.contractors.forEach(function(c){
          var r = { c: c, edits: {}, pct: null, open: false, sub: [] };
          r.cb = el('input', { type: 'checkbox', checked: true, 'aria-label': 'Tick ' + c.full_name });
          r.pieces = el('input', { type: 'text', inputmode: 'decimal', class: 'n', placeholder: '± pieces', 'aria-label': 'Pieces' });
          r.rate = el('input', { type: 'text', inputmode: 'decimal', class: 'n', placeholder: 'rate', 'aria-label': 'Rate' });
          r.fixed = el('input', { type: 'text', inputmode: 'decimal', class: 'n', placeholder: '± amount', 'aria-label': 'Fixed amount' });
          r.why = el('input', { type: 'text', maxlength: 255, placeholder: 'same as above', 'aria-label': 'Reason for this line' });
          r.adj = el('td', { class: 'num nil', text: '—' }); r.now = el('b', { text: rs(c.booked_paisa) });
          r.tog = el('button', { type: 'button', class: 'tog', text: '▸ Show lines', click: function(){ r.open = !r.open; drawLines(r); } });
          [r.pieces, r.rate].forEach(function(i){ i.addEventListener('input', function(){ i.classList.toggle('chg', i.value.trim() !== ''); paint(); }); });
          r.fixed.addEventListener('input', function(){ r.pct = null; r.fixed.classList.toggle('chg', r.fixed.value.trim() !== ''); r.fixed.title = ''; paint(); });
          r.tr = el('tr', {}, [el('td', {}, [r.cb]), el('td', {}, [el('b', { text: c.full_name }), el('div', { class: 'muted small', text: c.code + ' · ' + c.department_name })]),
            el('td', { class: 'num', text: String(c.booked_qty) }), el('td', { class: 'num', text: rs(c.booked_paisa) }), el('td', { class: 'num' }, [r.pieces]), el('td', { class: 'num' }, [r.rate]),
            el('td', { class: 'num' }, [r.fixed]), el('td', {}, [r.why]), r.adj, el('td', { class: 'num' }, [r.now]), el('td', {}, [r.tog])]);
          tb.appendChild(r.tr); R.push(r);
        });
        tb.appendChild(el('tr', { class: 'tot' }, [el('td'), el('td', { text: 'Total — ' + x.contractors.length + (x.contractors.length === 1 ? ' contractor' : ' contractors') }),
          el('td', { class: 'num', text: String(Math.round(x.contractors.reduce(function(a, c){ return a + c.booked_qty; }, 0) * 100) / 100) }), el('td', { class: 'num', text: rs(x.contractors.reduce(function(a, c){ return a + c.booked_paisa; }, 0)) }),
          el('td'), el('td'), el('td'), el('td'), totAdj, totNow, el('td')]));
        holder.appendChild(el('table', { class: 't entry padj' }, [el('thead', {}, [el('tr', {}, [['', 0], ['Contractor', 0], ['Booked pieces', 1], ['Booked amount', 1], ['Pieces ±', 1], ['Rate', 1], ['Fixed amount ±', 1], ['Reason for this line (optional)', 0], ['Adjustment', 1], ['New amount', 1], ['', 0]].map(function(h){
          return el('th', { style: h[1] ? 'text-align:right' : null, text: h[0] }); }))]), tb]));
        paint();
      });
    }
    function send(now){
      errBox.hidden = true;
      var rows = [];
      R.forEach(function(r){
        var parts = [];
        Object.keys(r.edits).forEach(function(id){ parts.push({ kind: 'line', entry_id: +id, qty: num(r.edits[id].qty), rate: num(r.edits[id].rate) }); });
        var q = num(r.pieces.value), rt = num(r.rate.value); if (q !== null && rt !== null) parts.push({ kind: 'pieces', qty: q, rate: rt });
        var f = num(r.fixed.value); if (f !== null) parts.push(r.pct !== null ? { kind: 'percent', pct: r.pct } : { kind: 'fixed', amount_paisa: paisa(f) });
        if (parts.length) rows.push({ employee_id: r.c.employee_id, reason: r.why.value.trim(), parts: parts });
      });
      if (!rows.length) { errBox.textContent = 'Nothing is adjusted yet.'; errBox.hidden = false; return; }
      save.disabled = true; if (saveNow) saveNow.disabled = true;
      call('POST', '/finance/production/adjust', { from: PA.from, to: PA.to, reason: reason.value.trim(), rows: rows, approve_now: !!now }).then(function(x){
        save.disabled = false; if (saveNow) saveNow.disabled = false;
        if (!x.ok) { errBox.textContent = x.error || 'Nothing was saved.'; errBox.hidden = false; return; }
        var say = function(e, word){ return e ? word + ' Rs ' + rs(e.total_paisa) + ' (' + e.ref + ', ' + ({ posted: 'posted', waiting: 'waiting for approval', approved: 'approved' }[e.status] || e.status) + ')' : ''; };
        closeModal(); refreshCounts(); toast([say(x.taken_off, 'Taken off'), say(x.added, 'Added')].filter(Boolean).join(' · ') + '.');
        if (S.tab === 'ledger') ledger(); else if (S.tab === 'prepare') prepare();
      });
    }
    var save = btn('Save adjustments', function(){ send(false); }, 'pri'), saveNow = S.caps.admin ? btn('Save and approve now', function(){ send(true); }, 'pri go') : null;
    [from, to, dept].forEach(function(i){ i.addEventListener('change', load); });
    var box = modal([
      el('div', { class: 'row', style: 'justify-content:space-between' }, [el('h2', { text: 'Production adjustment — contractors' }), btn('Close', closeModal, 'sm')]),
      el('p', { class: 'muted small', style: 'margin:0', text: 'Change the pieces or the rate of what Production booked, for one contractor or many, or give a fixed amount. What Production booked is never changed: each difference becomes its own line in the contractor\'s ledger, with the reason and your name.' }),
      el('div', { class: 'formgrid', style: 'grid-template-columns:150px 150px 1fr 2fr' }, [field('Production from', from), field('to', to), field('Department', dept), field('Reason (one for every line; a line can have its own)', reason)]),
      el('div', { class: 'cardx row', style: 'justify-content:space-between;padding:8px 12px' }, [
        el('div', { class: 'row' }, [el('b', { class: 'muted small', text: 'Same for all ticked:' }), el('div', { style: 'width:190px' }, [dir]), el('div', { style: 'width:120px' }, [same]), pctBtn,
          btn('Fill', function(){
            var v = num(same.value); if (v === null || v !== v || v <= 0) return;
            var sg = +dir.value;
            R.forEach(function(r){ if (!r.cb.checked) return;
              if (pctOn) {                                   // a percentage of what THIS contractor had booked
                var amt = Math.round(r.c.booked_paisa * v / 100);
                r.pct = amt ? sg * v : null; r.fixed.value = amt ? (sg < 0 ? '-' : '') + plain(amt) : ''; r.fixed.title = amt ? v + '% of ' + rs(r.c.booked_paisa) : '';
              } else { r.pct = null; r.fixed.value = (sg < 0 ? '-' : '') + String(v); r.fixed.title = ''; }
              r.fixed.classList.toggle('chg', r.fixed.value !== '');
            });
            paint();
          }, 'sm'), pctHint]),
        el('div', { class: 'row' }, [btn('Tick all', function(){ var any = R.some(function(r){ return !r.cb.checked; }); R.forEach(function(r){ r.cb.checked = any; }); }, 'sm'),
          btn('Show all lines', function(){ R.forEach(function(r){ r.open = true; drawLines(r); }); }, 'sm'), btn('Hide all lines', function(){ R.forEach(function(r){ r.open = false; drawLines(r); }); }, 'sm')])]),
      holder,
      el('div', { class: 'why', style: 'background:var(--sel);border-color:#c5dbf2;border-left-color:var(--pri);color:#123d6a', text: 'Taken off (−): ' + (S.caps.direct_deduct ? 'saved at once.' : 'waits for approval.') + ' Added (+): waits for approval' + (S.caps.admin ? ' — or use "Save and approve now".' : '.') + ' A deduction can never be more than the contractor is owed.' }),
      errBox,
      el('div', { class: 'row', style: 'justify-content:space-between' }, [sumText, el('div', { class: 'row' }, [btn('Close', closeModal), save, saveNow])])], true);
    box.classList.add('xwide');
    load();
  }

  // ------------------------------------------------------- mark attendance
  /* A WEEK OF DAILY-WAGE WORKERS ON ONE SCREEN — Admin and HR. Click F, H or A
     for each day; days the gate scanner recorded, days already on a pay sheet
     and days still to come are shown but cannot be changed here. One Save
     makes an attendance entry for every marked day, with the reason. Daily
     wages then count those days exactly like scanned ones. */
  var MW = { from: '', dept: '', marks: {}, on: {}, times: null, reason: '' };
  function markWeek(){
    if (!S.caps.mark) return show([el('div', { class: 'cardx empty', text: 'Marking attendance is for Admin and HR.' })]);
    if (!MW.from) { var end = S.today; while (isoDay(end) !== S.payDay) end = addDays(end, 1); MW.from = addDays(end, -6); }
    show([el('p', { class: 'muted', text: 'Loading…' })]);
    var dept = (S.depts || []).filter(function(d){ return d.name === MW.dept; })[0];
    call('GET', '/finance/attendance', { from: MW.from, department_id: dept ? dept.id : '' }).then(function(r){
      if (!r.ok) return show([fail(r)]);
      if (!MW.times) MW.times = r.times;
      var D = r.days, W = r.workers, half = r.half_minutes;
      W.forEach(function(w){ if (MW.on[w.employee_id] === undefined) MW.on[w.employee_id] = true; });
      function key(w, d){ return w.employee_id + '|' + d; }
      function cur(w, d){ var k = key(w, d); return MW.marks[k] !== undefined ? MW.marks[k] : (w.days[d].mark || 'A'); }   // no attendance is absent
      function free(w, d){ return !w.days[d].lock; }
      function setMark(w, d, m){ var k = key(w, d); if (w.days[d].mark === m || (!w.days[d].mark && m === 'A')) delete MW.marks[k]; else MW.marks[k] = m; }
      function value(w, d){ var m = cur(w, d); return m === 'F' ? 1 : m === 'H' ? 0.5 : 0; }
      var wk = el('input', { type: 'date', value: MW.from, 'aria-label': 'First day of the week', change: function(){ if (wk.value) { MW.from = wk.value; MW.marks = {}; markWeek(); } } });
      var deptSel = el('select', { 'aria-label': 'Department', change: function(){ MW.dept = deptSel.value; MW.marks = {}; markWeek(); } }, [el('option', { value: '', text: 'All departments' })].concat((S.depts || []).map(function(d){ return el('option', { value: d.name, text: d.name }); })));
      deptSel.value = MW.dept;
      var t = {}; ['full_in', 'full_out', 'half_in', 'half_out'].forEach(function(k){ t[k] = el('input', { type: 'time', value: MW.times[k], 'aria-label': k.replace('_', ' '), change: function(){ MW.times[k] = t[k].value; } }); });
      var why = el('input', { type: 'text', maxlength: 200, value: MW.reason, placeholder: 'e.g. Gate scanner not used — from the paper register (needed)', input: function(){ MW.reason = why.value; } });
      var hrs = (half / 60).toString().replace(/\.0+$/, '');
      var head = el('div', { class: 'cardx' }, [
        el('div', { class: 'mwgrid' }, [
          field('Week (' + DAYS[isoDay(r.from)] + ' to ' + DAYS[isoDay(r.to)] + ')', el('div', { class: 'row', style: 'flex-wrap:nowrap;gap:4px' }, [btn('‹', function(){ MW.from = addDays(MW.from, -7); MW.marks = {}; markWeek(); }, 'sm', false), wk,
            btn('›', function(){ MW.from = addDays(MW.from, 7); MW.marks = {}; markWeek(); }, 'sm')])),
          field('Department', deptSel),
          field('Full day', el('div', { class: 'row', style: 'flex-wrap:nowrap;gap:4px' }, [t.full_in, el('span', { class: 'muted', text: '–' }), t.full_out])),
          field('Half day (under ' + hrs + ' h)', el('div', { class: 'row', style: 'flex-wrap:nowrap;gap:4px' }, [t.half_in, el('span', { class: 'muted', text: '–' }), t.half_out])),
          field('Reason (kept on every day you mark)', why)]),
        el('div', { class: 'row', style: 'justify-content:space-between;margin-top:10px' }, [
          el('div', { class: 'row' }, [btn('All Full for ticked', function(){ W.forEach(function(w){ if (MW.on[w.employee_id]) D.forEach(function(d){ if (free(w, d)) setMark(w, d, 'F'); }); }); draw(); }, 'sm'),
            btn('Fill days…', function(){
              /* one or several days at once, for every ticked worker */
              var boxes = D.filter(function(d){ return d <= r.today; }).map(function(d){ var c = el('input', { type: 'checkbox', value: d }); return { d: d, c: c, n: el('label', { class: 'daypick' }, [c, DAYS[isoDay(d)].slice(0, 3) + ' ' + nice(d).replace(/ \d{4}$/, '')]) }; });
              var mk = el('select', {}, [['F', 'Full day'], ['H', 'Half day'], ['A', 'Absent (e.g. holiday)']].map(function(o){ return el('option', { value: o[0], text: o[1] }); }));
              var err = el('div', { class: 'err', hidden: true });
              modal([el('h2', { text: 'Fill days' }), el('p', { class: 'muted small', style: 'margin:0', text: 'Tick one day or several. Every ticked worker gets the same mark on those days. Days scanned at the gate or already on a pay sheet stay as they are.' }),
                el('div', { class: 'row' }, boxes.map(function(b){ return b.n; })),
                el('div', { class: 'row' }, [btn('All days', function(){ boxes.forEach(function(b){ b.c.checked = true; }); }, 'sm'), btn('None', function(){ boxes.forEach(function(b){ b.c.checked = false; }); }, 'sm')]),
                el('div', { class: 'formgrid' }, [field('Mark', mk)]), err,
                el('div', { class: 'row', style: 'justify-content:flex-end' }, [btn('Close', closeModal), btn('Fill', function(){
                  var days = boxes.filter(function(b){ return b.c.checked; }).map(function(b){ return b.d; });
                  if (!days.length) { err.textContent = 'Tick at least one day.'; err.hidden = false; return; }
                  W.forEach(function(w){ if (MW.on[w.employee_id]) days.forEach(function(d){ if (free(w, d)) setMark(w, d, mk.value); }); });
                  closeModal(); draw();
                }, 'pri')])]);
            }, 'sm'),
            btn('Clear my marks', function(){ MW.marks = {}; draw(); }, 'sm')]),
          el('span', { class: 'lgtip', text: 'Blue days were scanned at the gate; grey days are already on a pay sheet. Neither is changed here.' })])]);
      var tableBox = el('div', { class: 'cardx scroll' }), foot = el('div', { class: 'row', style: 'justify-content:space-between' }), errBox = el('div', { class: 'err', hidden: true });
      function draw(){
        tableBox.textContent = '';
        if (!W.length) { tableBox.appendChild(el('div', { class: 'empty', text: 'No daily-wage workers here. Set someone to "Daily wages" in Employees first.' })); }
        else {
          var all = el('input', { type: 'checkbox', checked: W.every(function(w){ return MW.on[w.employee_id]; }), 'aria-label': 'Tick everyone', change: function(){ W.forEach(function(w){ MW.on[w.employee_id] = all.checked; }); draw(); } });
          var tb = el('tbody'), tot = { d: 0, p: 0 };
          W.forEach(function(w){
            var cb = el('input', { type: 'checkbox', checked: MW.on[w.employee_id], 'aria-label': 'Tick ' + w.full_name, change: function(){ MW.on[w.employee_id] = cb.checked; } });
            var cells = D.map(function(d){
              var x = w.days[d];
              if (x.lock === 'future') return el('td', { class: 'c' }, [el('span', { class: 'mwlock', text: '—' })]);
              if (x.lock === 'sheet') return el('td', { class: 'c' }, [el('span', { class: 'mwsheet', title: 'On pay sheet ' + x.sheet, text: x.mark ? (x.mark === 'F' ? 'Full' : 'Half') : 'Absent' }, [el('small', { text: x.sheet })])]);
              if (x.lock === 'scan') return el('td', { class: 'c' }, [el('span', { class: 'mwscan', title: 'Recorded by the gate scanner' }, ['✓ ' + (Math.round(x.minutes / 6) / 10) + 'h', el('small', { text: 'scanned' })])]);
              var m = cur(w, d), changed = MW.marks[key(w, d)] !== undefined;
              return el('td', { class: 'c' }, [el('span', { class: 'seg3' + (changed ? ' chg' : ''), role: 'group', 'aria-label': w.full_name + ' ' + d }, ['F', 'H', 'A'].map(function(k){
                return el('button', { type: 'button', class: k.toLowerCase() + (m === k ? ' on' : ''), 'aria-pressed': m === k ? 'true' : 'false', title: { F: 'Full day', H: 'Half day', A: 'Absent' }[k], text: k,
                  click: function(){ setMark(w, d, k); draw(); } }); }))]);
            });
            var days = D.reduce(function(a, d){ return a + value(w, d); }, 0), pay = Math.round(days * w.daily_rate_paisa);
            tot.d += days; tot.p += pay;
            tb.appendChild(el('tr', {}, [el('td', {}, [cb]), el('td', {}, [el('b', { text: w.full_name }), el('div', { class: 'muted small', text: w.code + ' · ' + w.department_name })])].concat(cells,
              [el('td', { class: 'num', text: String(days) }), el('td', { class: 'num', text: rs(w.daily_rate_paisa) }), el('td', { class: 'num' }, [el('b', { text: rs(pay) })])])));
          });
          tb.appendChild(el('tr', { class: 'tot' }, [el('td'), el('td', { text: 'Total — ' + W.length + (W.length === 1 ? ' worker' : ' workers') })].concat(D.map(function(){ return el('td'); }),
            [el('td', { class: 'num', text: String(tot.d) }), el('td'), el('td', { class: 'num', text: rs(tot.p) })])));
          tableBox.appendChild(el('table', { class: 't stmt lg mw' }, [el('thead', {}, [el('tr', {}, [el('th', {}, [all]), el('th', { text: 'Worker' })].concat(D.map(function(d){
            return el('th', { class: 'c' }, [DAYS[isoDay(d)].slice(0, 3), el('span', { class: 'sub', text: nice(d).replace(/ \d{4}$/, '') })]); }),
            [el('th', { style: 'text-align:right', text: 'Days' }), el('th', { style: 'text-align:right', text: 'Rate' }), el('th', { style: 'text-align:right', text: 'Wages (PKR)' })]))]), tb]));
        }
        var n = Object.keys(MW.marks).length;
        foot.textContent = '';
        foot.appendChild(el('div', { class: 'row small muted' }, [el('span', { class: 'mwkey f', text: 'F' }), 'Full day', el('span', { class: 'mwkey h', text: 'H' }), 'Half day', el('span', { class: 'mwkey a', text: 'A' }), 'Absent',
          el('span', { class: 'mwscan', style: 'padding:1px 6px', text: '✓' }), 'Scanned at the gate']));
        foot.appendChild(el('div', { class: 'row' }, [el('span', { class: 'muted small', text: n ? n + (n === 1 ? ' day changed' : ' days changed') : 'Nothing changed yet' }),
          btn('Save attendance', function(){ save(false); }, 'pri', !n),
          S.caps.wage ? btn('Save and prepare wages →', function(){ save(true); }, 'pri go', !n && !W.length) : null]));
      }
      function save(thenPrepare){
        errBox.hidden = true;
        var marks = Object.keys(MW.marks).map(function(k){ var a = k.split('|'); return { employee_id: +a[0], day: a[1], mark: MW.marks[k] }; });
        var go2 = function(){
          if (!thenPrepare) return markWeek();
          P.kind = 'daily'; P.custom = false; P.from = r.from; P.to = r.to > S.today ? S.today : r.to; P.models = {}; P.on = {}; P.open = {}; go('prepare');
        };
        if (!marks.length) return go2();
        if (!MW.reason.trim()) { errBox.textContent = 'Give the reason first (for example: from the paper register).'; errBox.hidden = false; why.focus(); return; }
        call('POST', '/finance/attendance', { marks: marks, times: MW.times, reason: MW.reason.trim() }).then(function(x){
          if (!x.ok) { errBox.textContent = x.error || 'Nothing was saved.'; errBox.hidden = false; return; }
          MW.marks = {};
          if (x.errors && x.errors.length) { toast(x.saved + ' saved, ' + x.errors.length + ' not saved — see the list.', true); markWeek(); setTimeout(function(){ var b = document.querySelector('#fin-body .err'); if (b) { b.textContent = 'Not saved: ' + x.errors.join('  ·  '); b.hidden = false; } }, 400); return; }
          toast(x.saved + (x.saved === 1 ? ' day saved.' : ' days saved.'));
          go2();
        });
      }
      show([head, errBox, tableBox, foot]);
      draw();
    });
  }

  // ------------------------------------------------------- employee ledger
  /* THE EMPLOYEE LEDGER. Without a person chosen: everybody between two dates
     (owed before, earned, deducted, paid, owed at the end). With a person: their
     statement — balance brought forward, every line with its reference, the
     running balance and the period totals. Add Earning, Add Deduction and
     Record Payment make an ENTRY that waits for a second person's approval;
     nothing reaches the ledger before that. Click a line to open the pay sheet
     or entry it came from. */
  var LG = { from: '', to: '', dept: '', q: '', type: '', emp: null };
  var PEOPLE = null;
  function loadPeople(){
    return PEOPLE ? Promise.resolve(PEOPLE) : call('GET', '/finance/people').then(function(r){ PEOPLE = r.ok ? r.people : []; return PEOPLE; });
  }
  function personLabel(p){ return p.full_name + ' (' + p.code + ')'; }
  function findPerson(text){
    var v = String(text || '').trim().toLowerCase(); if (!v || !PEOPLE) return null;
    return PEOPLE.filter(function(p){ return personLabel(p).toLowerCase() === v || String(p.code).toLowerCase() === v || p.full_name.toLowerCase() === v; })[0] || null;
  }
  function peopleList(id){
    var old = document.getElementById(id); if (old) old.remove();
    return el('datalist', { id: id }, (PEOPLE || []).filter(function(p){ return +p.active; }).map(function(p){ return el('option', { value: personLabel(p), label: p.department_name + (p.pay_type ? ' · ' + KIND1[p.pay_type] : '') }); }));
  }
  function openSheet(id){ closeModal(); S.tab = 'sheets'; drawTabs(); try { history.replaceState(null, '', '#sheets'); } catch (e) {} sheet(id); }
  function dash(v, cls){ return v ? el('td', { class: 'num ' + cls, text: rs(v) }) : el('td', { class: 'num nil', text: '—' }); }

  function ledger(){
    if (!LG.from) { LG.from = S.today.slice(0, 8) + '01'; LG.to = S.today; }
    show([el('p', { class: 'muted', text: 'Loading…' })]);
    loadPeople().then(function(){
      var c = S.caps;
      var who = el('input', { type: 'search', list: 'fin-emps', placeholder: 'Everyone — or type a name or code', value: LG.emp ? personLabel(LG.emp) : '', 'aria-label': 'Employee' });
      who.addEventListener('change', function(){ var p = findPerson(who.value); LG.emp = p; if (p || !who.value.trim()) ledger(); });
      who.addEventListener('search', function(){ if (!who.value.trim()) { LG.emp = null; ledger(); } });
      var from = el('input', { type: 'date', value: LG.from, max: S.today, 'aria-label': 'From', change: function(){ LG.from = from.value; ledger(); } });
      var to = el('input', { type: 'date', value: LG.to, max: S.today, 'aria-label': 'To', change: function(){ LG.to = to.value; ledger(); } });
      var dept = el('select', { 'aria-label': 'Department', disabled: !!LG.emp }, [el('option', { value: '', text: 'All departments' })].concat((S.depts || []).map(function(d){ return el('option', { value: d.name, text: d.name }); })));
      dept.value = LG.dept;
      var type = el('select', { 'aria-label': 'Paid as', disabled: !!LG.emp }, [['', 'Every kind of pay'], ['salary', 'Fixed salary'], ['daily', 'Daily wages'], ['contract', 'Contractors']].map(function(o){ return el('option', { value: o[0], text: o[1] }); }));
      type.value = LG.type;
      var span = function(a, b){ LG.from = a; LG.to = b; ledger(); };
      var expUrl = LG.emp ? API + encodeURIComponent('/finance/people/' + LG.emp.employee_id + '/statement/export') + '&from=' + LG.from + '&to=' + LG.to
                          : API + encodeURIComponent('/finance/ledger/export') + '&from=' + LG.from + '&to=' + LG.to;
      var filters = el('div', { class: 'cardx lgfilter' }, [
        el('div', { class: 'lggrid' }, [field('Employee', who), field('Department', dept), field('Paid as', type), field('Account', el('input', { type: 'text', value: 'Wages payable', disabled: true })),
          field('From', from), field('To', to)]),
        el('div', { class: 'row', style: 'justify-content:space-between;margin-top:10px' }, [
          el('div', { class: 'row' }, [btn('This month', function(){ span(S.today.slice(0, 8) + '01', S.today); }, 'sm'), btn('Last month', function(){ var m = monthRange(prevMonth()); span(m[0], m[1]); }, 'sm'),
            btn('This year', function(){ span(S.today.slice(0, 4) + '-01-01', S.today); }, 'sm'), btn('Everything', function(){ span('2000-01-01', S.today); }, 'sm')]),
          el('div', { class: 'row' }, [btn('Print / PDF', function(){ printLedger(); }, 'sm'), el('a', { class: 'btn sm', href: expUrl, text: 'Export Excel' })])])]);
      var mayEnter = c.salary || c.wage || c.adjust;
      var actions = el('div', { class: 'lgacts' }, [
        mayEnter ? btn('+ Add Earning', function(){ entryForm('earning'); }, 'act-earn') : null,
        mayEnter ? btn('− Add Deduction', function(){ entryForm('deduction'); }, 'act-ded') : null,
        mayEnter ? btn('Record Payment', function(){ entryForm('payment'); }, 'act-pay') : null,
        c.pay ? btn('Advance / Loan', function(){ give(LG.emp); }, 'act-adv') : null,
        (c.adjust || c.wage) ? btn('± Production adjustment', function(){ prodAdjust(); }, 'act-prod') : null,
        el('span', { class: 'lgtip', text: mayEnter ? (c.direct_deduct ? 'Earnings and payments wait for approval; a deduction is taken off at once.' : 'Entries wait for a second person\'s approval.') + ' Click any line to open where it came from.' : 'Click any line to open where it came from.' })]);
      var holder = el('div', { class: 'stack' }, [el('p', { class: 'muted', text: 'Loading…' })]);
      show([peopleList('fin-emps'), filters, actions, holder]);
      var printable = null;
      function printLedger(){
        if (!printable) return;
        var box = document.getElementById('fin-print'); box.textContent = '';
        box.appendChild(el('h1', { text: 'ZAS Textiles — ' + printable.title }));
        box.appendChild(el('div', { text: printable.sub + ' · ' + nice(LG.from) + ' to ' + nice(LG.to) + ' · Wages payable · all amounts PKR' }));
        box.appendChild(printable.table.cloneNode(true));
        window.print();
      }
      if (LG.emp) return one(LG.emp);
      dept.addEventListener('change', function(){ LG.dept = dept.value; ledger(); });
      type.addEventListener('change', function(){ LG.type = type.value; ledger(); });
      call('GET', '/finance/ledger', { from: LG.from, to: LG.to }).then(function(r){
        holder.textContent = '';
        if (!r.ok) return holder.appendChild(fail(r));
        var rows = r.rows.filter(function(x){ return (!LG.dept || x.department_name === LG.dept) && (!LG.type || x.pay_type === LG.type); });
        var t = { b: 0, e: 0, d: 0, p: 0, c: 0 };
        rows.forEach(function(x){ t.b += x.before_paisa; t.e += x.earned; t.d += x.deducted; t.p += x.paid; t.c += x.closing_paisa; });
        holder.appendChild(cards([['Owed before ' + nice(LG.from), t.b, 'k-open'], ['Total earnings', t.e, 'k-earn'], ['Total deductions', t.d, 'k-ded'], ['Total payments', t.p, 'k-pay'], ['Owed on ' + nice(LG.to), t.c, 'k-bal']]));
        if (!rows.length) return holder.appendChild(el('div', { class: 'cardx empty', text: 'Nothing in the ledger for these dates.' }));
        var tb = el('tbody');
        rows.forEach(function(x){
          tb.appendChild(el('tr', { class: 'click', tabindex: '0', click: function(){ pick(x); }, keydown: function(e){ if (e.key === 'Enter') pick(x); } }, [
            el('td', { 'data-sort': x.full_name }, [el('b', { text: x.full_name }), el('div', { class: 'muted small', text: x.code + ' · ' + x.department_name + (x.pay_type ? ' · ' + KIND1[x.pay_type] : '') })]),
            el('td', { class: 'num', text: rs(x.before_paisa) }), dash(x.earned, 'c-earn'), dash(x.deducted, 'c-ded'), dash(x.paid, 'c-pay'), el('td', { class: 'num' }, [el('b', { text: rs(x.closing_paisa) })])]));
        });
        tb.appendChild(el('tr', { class: 'tot' }, [el('td', {}, [el('b', { text: 'Total — ' + rows.length + (rows.length === 1 ? ' person' : ' people') })]), el('td', { class: 'num', text: rs(t.b) }),
          el('td', { class: 'num c-earn', text: rs(t.e) }), el('td', { class: 'num c-ded', text: rs(t.d) }), el('td', { class: 'num c-pay', text: rs(t.p) }), el('td', { class: 'num', text: rs(t.c) })]));
        var table = el('table', { class: 't stmt lg sortable' }, [el('thead', {}, [el('tr', {}, ['Employee', 'Owed before (PKR)', 'Earnings (PKR)', 'Deductions (PKR)', 'Payments (PKR)', 'Owed at end (PKR)'].map(function(h, i){ return el('th', { style: i ? 'text-align:right' : null, text: h }); }))]), tb]);
        printable = { title: 'Employee ledger — everyone', sub: rows.length + (rows.length === 1 ? ' person' : ' people'), table: table };
        holder.appendChild(el('div', { class: 'cardx scroll' }, [table]));
      });
      function pick(x){ LG.emp = (PEOPLE || []).filter(function(p){ return +p.employee_id === +x.employee_id; })[0] || { employee_id: x.employee_id, full_name: x.full_name, code: x.code, department_name: x.department_name, pay_type: x.pay_type, active: 1 }; ledger(); }
      function one(p){
        call('GET', '/finance/people/' + p.employee_id + '/statement', { from: LG.from, to: LG.to }).then(function(r){
          holder.textContent = '';
          if (!r.ok) return holder.appendChild(fail(r));
          holder.appendChild(el('div', { class: 'row lghead', style: 'justify-content:space-between' }, [
            el('div', { class: 'row' }, [el('div', { class: 'av', text: initials(p.full_name) }), el('div', { class: 'nm' }, [el('b', { text: p.full_name }),
              el('span', { text: p.code + ' · ' + p.department_name + (p.pay_type ? ' · ' + KIND1[p.pay_type] : '') + ' · Wages payable' })])]),
            btn('← Everyone', function(){ LG.emp = null; ledger(); }, 'sm')]));
          holder.appendChild(cards([['Opening balance', r.opening_paisa, 'k-open'], ['Total earnings', r.totals.earned, 'k-earn'], ['Total deductions', r.totals.deducted, 'k-ded'], ['Total payments', r.totals.paid, 'k-pay'], ['Closing balance', r.closing_paisa, 'k-bal']]));
          var tb = el('tbody');
          tb.appendChild(el('tr', { class: 'open' }, [el('td', { text: nice(r.from) }), el('td', { text: 'OPENING' }), el('td', {}, [el('b', { text: 'Balance brought forward' })]),
            dash(0), dash(0), dash(0), el('td', { class: 'num' }, [el('b', { text: rs(r.opening_paisa) })])]));
          r.rows.forEach(function(x){
            var go2 = x.sheet_id ? function(){ openSheet(x.sheet_id); } : null;
            tb.appendChild(el('tr', { class: go2 ? 'click' : null, tabindex: go2 ? '0' : null, title: go2 ? 'Open the pay sheet or entry this came from' : null, click: go2, keydown: go2 ? function(e){ if (e.key === 'Enter') go2(); } : null }, [
              el('td', { text: nice(x.date) }), el('td', { text: x.ref }), el('td', { text: x.label + (x.kind === 'payment' && x.method ? ' · ' + (x.method === 'bank' ? 'bank' : 'cash') : '') }),
              dash(x.earned, 'c-earn'), dash(x.deducted, 'c-ded'), dash(x.paid, 'c-pay'), el('td', { class: 'num' }, [el('b', { text: rs(x.balance) })])]));
          });
          if (!r.rows.length) tb.appendChild(el('tr', {}, [el('td', { colspan: '7', class: 'muted', text: 'No entries between these dates.' })]));
          tb.appendChild(el('tr', { class: 'tot' }, [el('td', {}, [el('b', { text: 'TOTAL' })]), el('td', { text: 'PERIOD TOTALS' }), el('td', { class: 'muted', text: r.rows.length + (r.rows.length === 1 ? ' entry' : ' entries') }),
            el('td', { class: 'num c-earn', text: rs(r.totals.earned) }), el('td', { class: 'num c-ded', text: rs(r.totals.deducted) }), el('td', { class: 'num c-pay', text: rs(r.totals.paid) }), el('td', { class: 'num', text: rs(r.closing_paisa) })]));
          var table = el('table', { class: 't stmt lg' }, [el('thead', {}, [el('tr', {}, ['Date', 'Reference No.', 'Transaction description', 'Earnings (PKR)', 'Deductions (PKR)', 'Payments (PKR)', 'Running balance (PKR)'].map(function(h, i){ return el('th', { style: i > 2 ? 'text-align:right' : null, text: h }); }))]), tb]);
          printable = { title: 'Employee ledger — ' + p.full_name + ' (' + p.code + ')', sub: p.department_name + (p.pay_type ? ' · ' + KIND1[p.pay_type] : ''), table: table };
          holder.appendChild(el('div', { class: 'cardx scroll' }, [table]));
        });
      }
    });
  }
  function cards(list){
    return el('div', { class: 'lgcards' }, list.map(function(x){ return el('div', { class: 'lgcard ' + x[2] }, [el('span', { text: x[0] }), el('b', { text: 'PKR ' + rs(x[1]) })]); }));
  }
  /* Kept for the old way in: a person's statement in a dialog. */
  function statement(x){
    modal([el('div', { class: 'row', style: 'justify-content:space-between' }, [el('div', { class: 'nm' }, [el('b', { text: x.full_name + ' — ledger' }), el('span', { text: x.code + ' · ' + x.department_name + ' · Wages payable · all amounts PKR' })]), btn('Close', closeModal, 'sm')]),
      statementView(x.employee_id, LG.from || S.today.slice(0, 8) + '01', LG.to || S.today)], true);
  }

  /* ADD EARNING / ADD DEDUCTION / RECORD PAYMENT — one person or many.
     One line per person (an earning or deduction may have several lines for
     the same person). Sent as one entry, which waits for approval. */
  var ETYPES = {
    earning: [['overtime', 'Overtime'], ['production', 'Production incentive'], ['bonus', 'Bonus'], ['allowance', 'Allowance'], ['other_earning', 'Other earning']],
    deduction: [['leave', 'Unpaid leave'], ['advance', 'Advance recovery'], ['loan', 'Loan recovery'], ['deduction', 'Other deduction']]
  };
  function entryForm(mode, preset){
    loadPeople().then(function(){
      var title = { earning: 'Add earning', deduction: 'Add deduction', payment: 'Record payment' }[mode];
      var note = { earning: 'Overtime, an incentive, a bonus or any other earning — for one person or many. It waits for approval; once approved it is in their ledger and is paid with their next pay, or by Record payment. Overtime that Attendance already has is added to the pay sheet by itself: enter here only what Attendance does not know.',
                   deduction: 'A penalty, unpaid leave, advance or loan recovery, or any other deduction — for one person or many. ' + (S.caps.direct_deduct ? 'It is an adjustment, not a payment, so it is taken off at once, with your name and the reason.' : 'It waits for approval.') + ' It can never take off more than is owed to the person. Absences that Attendance already has are taken off the pay sheet by itself.',
                   payment: 'Pay part or all of what a person is owed, by cash or bank. It waits for approval like a pay sheet; then Accounts records the payment.' }[mode];
      var date = el('input', { type: 'date', value: S.today, max: S.today });
      var reason = el('input', { type: 'text', maxlength: 255, placeholder: mode === 'payment' ? 'e.g. Salary balance for October (optional)' : 'e.g. Eid bonus, Sunday overtime on order 5236 (needed)' });
      var defType = el('select', {}, (ETYPES[mode] || []).map(function(o){ return el('option', { value: o[0], text: o[1] }); }));
      var tb = el('tbody'), errBox = el('div', { class: 'err', hidden: true }), totalB = el('b', { text: '0' });
      var lines = [];
      function addLine(p, pre){
        var L = { p: p || null, info: null };
        var who = el('input', { type: 'text', list: 'fin-emps2', placeholder: 'Name or code', value: p ? personLabel(p) : '', 'aria-label': 'Employee' });
        var amt = el('input', { type: 'text', inputmode: 'decimal', class: 'n', placeholder: '0', 'aria-label': 'Amount' });
        var tr = el('tr');
        L.amt = amt; L.who = who; L.tr = tr;
        var cells = [el('td', {}, [who])];
        if (mode === 'payment') {
          L.owed = el('td', { class: 'num muted', text: '—' });
          L.method = el('select', { 'aria-label': 'Paid by' }, [el('option', { value: 'cash', text: 'Cash' }), el('option', { value: 'bank', text: 'Bank' })]);
          cells.push(L.owed, el('td', {}, [amt]), el('td', {}, [L.method]));
        } else {
          L.type = el('select', { 'aria-label': 'Type' }, ETYPES[mode].map(function(o){ return el('option', { value: o[0], text: o[1] }); }));
          L.type.value = pre && pre.type ? pre.type : defType.value;
          L.label = el('input', { type: 'text', maxlength: 100, placeholder: 'Description (optional)', 'aria-label': 'Description' });
          L.adv = el('select', { 'aria-label': 'Which advance or loan', hidden: true });
          cells.push(el('td', {}, [L.type]), el('td', {}, [L.label, L.adv]), el('td', {}, [amt]));
          L.type.addEventListener('change', function(){ info(); });
        }
        cells.push(el('td', {}, [el('button', { type: 'button', class: 'x', title: 'Remove this line', text: '×', click: function(){ lines.splice(lines.indexOf(L), 1); tr.remove(); sum(); } })]));
        cells.forEach(function(td){ tr.appendChild(td); });
        function info(){
          if (L.adv) L.adv.hidden = true;
          if (!L.p) { if (L.owed) L.owed.textContent = '—'; return; }
          var need = mode === 'payment' || (L.type && (L.type.value === 'advance' || L.type.value === 'loan'));
          if (!need) return;
          (L.info ? Promise.resolve({ ok: true, x: L.info }) : call('GET', '/finance/people/' + L.p.employee_id + '/entry-info').then(function(x){ if (x.ok) L.info = x; return { ok: x.ok, x: x }; })).then(function(res){
            if (!res.ok) return;
            var x = res.x;
            if (mode === 'payment') {
              L.owed.textContent = rs(Math.max(0, x.free_paisa));
              if (!amt.value) amt.value = x.free_paisa > 0 ? plain(x.free_paisa) : '';
              L.method.value = x.method || 'cash'; sum();
            } else {
              var k = L.type.value, list = x.advances.filter(function(a){ return a.kind === k; });
              L.adv.textContent = '';
              L.adv.appendChild(el('option', { value: '', text: list.length ? 'Which ' + (k === 'loan' ? 'loan' : 'advance') + '?' : 'No open ' + (k === 'loan' ? 'loan' : 'advance') }));
              list.forEach(function(a){ L.adv.appendChild(el('option', { value: String(a.id), text: 'Given ' + nice(a.given_on) + ' · ' + rs(a.left_paisa) + ' still owed' })); });
              var want = pre && pre.advance_id ? list.filter(function(a){ return +a.id === +pre.advance_id; })[0] : (list.length === 1 ? list[0] : null);
              if (want) { L.adv.value = String(want.id); if (!amt.value) amt.value = plain(Math.min(want.per_pay_paisa || want.left_paisa, want.left_paisa)); sum(); }
              L.adv.hidden = false;
            }
          });
        }
        who.addEventListener('change', function(){ L.p = findPerson(who.value); L.info = null; who.classList.toggle('bad', !!who.value.trim() && !L.p); info(); sum(); });
        amt.addEventListener('input', sum);
        lines.push(L); tb.appendChild(tr);
        if (p) info();
        return L;
      }
      function sum(){
        var t = 0; lines.forEach(function(L){ var v = toPaisa(L.amt.value); if (v) t += v; });
        totalB.textContent = 'Rs ' + rs(t) + ' · ' + lines.filter(function(L){ return L.p; }).length + ' line' + (lines.filter(function(L){ return L.p; }).length === 1 ? '' : 's');
      }
      var dsel = el('select', { 'aria-label': 'Department to add' }, [el('option', { value: '', text: 'Add a whole department…' })].concat((S.depts || []).map(function(d){ return el('option', { value: d.name, text: d.name }); })));
      dsel.addEventListener('change', function(){
        var d = dsel.value; dsel.value = ''; if (!d) return;
        var have = {}; lines.forEach(function(L){ if (L.p) have[+L.p.employee_id] = 1; });
        var add = PEOPLE.filter(function(p){ return +p.active && p.pay_type && p.department_name === d && !have[+p.employee_id]; });
        lines.filter(function(L){ return !L.p && !L.who.value.trim(); }).forEach(function(L){ lines.splice(lines.indexOf(L), 1); L.tr.remove(); });
        add.forEach(function(p){ addLine(p); });
        toast(add.length ? add.length + (add.length === 1 ? ' person added.' : ' people added.') : 'Nobody new in ' + d + '.'); sum();
      });
      var same = el('input', { type: 'text', inputmode: 'decimal', placeholder: 'Same amount for all', 'aria-label': 'Same amount for all' });
      var fill = btn('Fill', function(){ if (toPaisa(same.value) === null) return; lines.forEach(function(L){ if (L.p) L.amt.value = same.value; }); sum(); }, 'sm');
      var head = mode === 'payment' ? ['Employee', 'Owed now', 'Amount to pay', 'Paid by', ''] : ['Employee', 'Type', 'Description', 'Amount', ''];
      var table = el('table', { class: 't entry' }, [el('thead', {}, [el('tr', {}, head.map(function(h, i){ return el('th', { style: (mode === 'payment' && i === 1) || (mode !== 'payment' && i === 3) || (mode === 'payment' && i === 2) ? 'text-align:right' : null, text: h }); }))]), tb]);
      var direct = mode === 'deduction' && S.caps.direct_deduct;
      var now = false;                                   // the administrator's one step
      var save = btn(direct ? 'Save deduction' : (mode === 'payment' ? 'Send payment for approval' : 'Send for approval'), function(){ now = false; send(); }, 'pri');
      var saveNow = S.caps.admin && !direct ? btn(mode === 'payment' ? 'Approve and pay now' : 'Save and approve now', function(){ now = true; send(); }, 'pri go') : null;
      function send(){
        errBox.hidden = true;
        var rows = [], bad = null;
        lines.forEach(function(L){
          if (!L.p && !L.who.value.trim() && !L.amt.value.trim()) return;
          if (!L.p) { bad = bad || 'Choose the employee on every line (pick a name from the list).'; return; }
          var a = toPaisa(L.amt.value);
          if (a === null || a <= 0) { bad = bad || L.p.full_name + ': give the amount.'; return; }
          var row = { employee_id: L.p.employee_id, amount_paisa: a };
          if (mode === 'payment') row.method = L.method.value;
          else {
            row.type = L.type.value; row.label = L.label.value.trim();
            if (row.type === 'advance' || row.type === 'loan') { if (!L.adv.value) { bad = bad || L.p.full_name + ': choose which ' + (row.type === 'loan' ? 'loan' : 'advance') + '.'; return; } row.advance_id = +L.adv.value; }
          }
          rows.push(row);
        });
        if (!bad && !rows.length) bad = 'Add at least one person.';
        if (!bad && mode !== 'payment' && !reason.value.trim()) bad = 'Say what it is for (the reason is kept on every line).';
        if (bad) { errBox.textContent = bad; errBox.hidden = false; return; }
        save.disabled = true; if (saveNow) saveNow.disabled = true;
        call('POST', '/finance/entries', { mode: mode, date: date.value, reason: reason.value.trim(), rows: rows, approve_now: now }).then(function(x){
          save.disabled = false; if (saveNow) saveNow.disabled = false;
          if (!x.ok) { errBox.textContent = x.error || 'That could not be saved.'; errBox.hidden = false; return; }
          closeModal(); refreshCounts();
          var what = { posted: 'posted to the ledger', paid: 'approved and paid', approved: 'approved — ready to pay' }[x.status] || 'sent for approval';
          toast('Entry ' + x.ref + ' ' + what + ' — ' + x.people + (x.people === 1 ? ' person' : ' people') + ', Rs ' + rs(Math.abs(x.total_paisa)) + '.');
          if (S.tab === 'ledger') ledger(); else if (S.tab === 'advances') advances();
        });
      }
      var box = modal([peopleList('fin-emps2'),
        el('div', { class: 'row', style: 'justify-content:space-between' }, [el('h2', { text: title }), btn('Close', closeModal, 'sm')]),
        el('p', { class: 'muted small', style: 'margin:0', text: note }),
        el('div', { class: 'formgrid' }, [field('Date', date), mode === 'payment' ? null : field('Type for new lines', defType), field(mode === 'payment' ? 'Note' : 'Reason / what it is for', reason)].filter(Boolean)),
        el('div', { class: 'scroll', style: 'max-height:46vh' }, [table]),
        el('div', { class: 'row', style: 'justify-content:space-between' }, [el('div', { class: 'row' }, [btn('+ Add line', function(){ addLine(null).who.focus(); sum(); }, 'sm'), dsel]),
          el('div', { class: 'row' }, [el('div', { style: 'width:170px' }, [same]), fill])]),
        errBox,
        el('div', { class: 'row', style: 'justify-content:space-between' }, [el('span', { class: 'muted small' }, ['Total: ', totalB]), el('div', { class: 'row' }, [btn('Close', closeModal), save, saveNow])])], true);
      box.classList.add('entrybox');
      if (preset) { if (preset.type) defType.value = preset.type; addLine(preset.p, preset); }
      else {
        addLine(LG.emp && S.tab === 'ledger' ? LG.emp : null);
        if (!LG.emp || S.tab !== 'ledger') addLine(null);
      }
      sum();
      if (preset) reason.value = 'Recovery taken early';
      setTimeout(function(){ (lines[0].p ? lines[0].amt : lines[0].who).focus(); }, 30);
    });
  }

  // ------------------------------------------------------------ advances
  function advances(){
    show([el('p', { class: 'muted', text: 'Loading…' })]);
    call('GET', '/finance/advances').then(function(r){
      if (!r.ok) return show([fail(r)]);
      var live = r.advances.filter(function(a){ return a.status === 'active'; }), done = r.advances.filter(function(a){ return a.status !== 'active'; });
      var out = live.reduce(function(t, a){ return t + a.left_paisa; }, 0);
      show([el('div', { class: 'cardx row', style: 'justify-content:space-between' }, [el('div', {}, [el('div', { class: 'muted small', text: 'Still to recover from ' + live.length + (live.length === 1 ? ' advance or loan' : ' advances and loans') }), el('b', { style: 'font-size:22px', text: 'Rs ' + rs(out) })]),
              S.caps.pay ? btn('Give advance or loan', function(){ give(null); }, 'pri') : null]),
            el('div', { class: 'cardx' }, live.length ? live.map(function(a){ return advRow(a, true); }) : [el('div', { class: 'empty', text: 'No advance or loan is outstanding.' })]),
            done.length ? el('div', { class: 'cardx' }, [el('h3', { text: 'Cleared' })].concat(done.map(function(a){ return advRow(a, true); }))) : null]);
    });
  }

  // ------------------------------------------------------------ start
  refreshCounts().then(function(r){
    if (!r.ok) return show([fail(r)]);
    var h = (location.hash || '').slice(1);
    go(TABS.some(function(x){ return x[0] === h; }) && (h !== 'prepare' || S.caps.salary || S.caps.wage) && (h !== 'mark' || S.caps.mark) ? h : 'home');
  });
})();
</script>
<?php page_footer(); ?>
