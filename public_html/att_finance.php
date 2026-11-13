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
    People       how each person is paid, their statement, opening balance
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
.fin{--bg:#f2f6f4;--card:#fff;--ink:#173a33;--muted:#667b75;--line:#dfe8e4;--soft:#e9f1ed;--accent:#17624d;--accent2:#22836a;--earn:#2a9278;--paid:#6d66c4;--owed:#c97a22;
  --warn-bg:#fdf3e3;--warn-ink:#7a4a00;--bad:#b3261e;--bad-bg:#fbe9e7;color:var(--ink);font-size:14.5px;line-height:1.45;max-width:1180px}
.fin *{box-sizing:border-box}
.fin [hidden]{display:none!important}
.fin h2{font-size:17px;margin:0;letter-spacing:-.01em}
.fin h3{font-size:14px;margin:0}
.fin .muted{color:var(--muted)}.fin .small{font-size:12.5px}
/* width:0 + min-width:100%: a row that scrolls sideways must not widen the page on a phone */
.fin .tabs{width:0;min-width:100%;display:flex;gap:4px;overflow-x:auto;scrollbar-width:none;background:var(--soft);padding:4px;border-radius:14px;margin:0 0 14px;position:sticky;top:0;z-index:5}
.fin .tabs::-webkit-scrollbar{display:none}
.fin .tabs button{flex:1 0 auto;border:0;background:transparent;color:var(--muted);font:600 13.5px/1 inherit;font-family:inherit;padding:10px 14px;border-radius:10px;cursor:pointer;white-space:nowrap;transition:background .15s,color .15s}
.fin .tabs button[aria-selected=true]{background:var(--card);color:var(--ink);box-shadow:0 1px 3px rgba(0,0,0,.12)}
.fin .tabs .dot{display:inline-block;min-width:18px;padding:2px 5px;margin-left:6px;border-radius:99px;background:var(--owed);color:#fff;font-size:11px;font-weight:700}
.fin .cardx{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px 16px;min-width:0}
.fin .stack{display:grid;gap:12px}
.fin .hero{background:linear-gradient(140deg,var(--accent2),var(--accent));color:#fff;border-radius:20px;padding:18px 20px;display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between}
.fin .hero .k{font-size:11.5px;letter-spacing:.08em;text-transform:uppercase;opacity:.85}
.fin .hero .v{font-size:clamp(30px,6vw,42px);font-weight:700;line-height:1.05;font-variant-numeric:tabular-nums;letter-spacing:-.02em}
.fin .hero .n{font-size:13px;opacity:.9}
.fin .steps{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.fin .stepc{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:12px 14px;text-align:left;font:inherit;color:inherit;cursor:pointer;transition:transform .12s,border-color .12s}
.fin .stepc:hover{border-color:var(--accent);transform:translateY(-1px)}
.fin .stepc i{display:block;width:26px;height:4px;border-radius:9px;margin-bottom:8px;background:var(--owed)}
.fin .stepc.s2 i{background:var(--earn)}.fin .stepc.s3 i{background:var(--paid)}
.fin .stepc b{display:block;font-size:24px;line-height:1.1;font-variant-numeric:tabular-nums}
.fin .stepc span{font-size:12.5px;color:var(--muted)}
.fin .grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;align-items:start}
.fin .kv{display:grid;grid-template-columns:1fr auto;gap:6px 14px;font-variant-numeric:tabular-nums}
.fin .who{display:grid;grid-template-columns:1fr repeat(3,56px);gap:7px 6px;font-size:13px;align-items:center}
.fin .who .hd{font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);text-align:center}
.fin .who .y{color:var(--earn);font-weight:700;text-align:center}.fin .who .n{color:#c5d3cd;text-align:center}
.fin .btn{border:1px solid var(--line);background:var(--card);color:var(--ink);font:600 13.5px/1.2 inherit;font-family:inherit;padding:9px 14px;border-radius:11px;cursor:pointer;transition:transform .1s;white-space:nowrap}
.fin .btn:active{transform:scale(.97)}
.fin .btn.pri{background:var(--accent);border-color:var(--accent);color:#fff}
.fin .btn.light{background:#fff;border-color:#fff;color:var(--accent)}
.fin .btn.danger{color:var(--bad);border-color:#ecc8c4}
.fin .btn.sm{padding:6px 10px;font-size:12.5px;border-radius:9px}
.fin .btn:disabled{opacity:.5;cursor:default}
.fin .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.fin .seg{display:inline-flex;background:var(--soft);padding:3px;border-radius:12px;gap:3px;flex-wrap:wrap}
.fin .seg button{border:0;background:transparent;color:var(--muted);font:600 13px/1 inherit;font-family:inherit;padding:8px 13px;border-radius:9px;cursor:pointer}
.fin .seg button[aria-pressed=true]{background:var(--card);color:var(--ink);box-shadow:0 1px 3px rgba(0,0,0,.12)}
.fin .seg.mini button{padding:5px 10px;font-size:12px}
.fin .fld{display:grid;gap:4px;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:var(--muted);min-width:0}
.fin input[type=text],.fin input[type=date],.fin input[type=month],.fin input[type=search],.fin select,.fin textarea{font:500 14px/1.3 inherit;font-family:inherit;letter-spacing:0;text-transform:none;color:var(--ink);background:var(--card);border:1.5px solid var(--line);border-radius:10px;padding:8px 10px;width:100%;min-width:0}
.fin input:focus-visible,.fin select:focus-visible,.fin button:focus-visible{outline:2px solid var(--owed);outline-offset:1px}
.fin .formgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;align-items:end}
.fin .pill{display:inline-block;font-size:11px;font-weight:700;padding:3px 9px;border-radius:99px;background:var(--soft);color:var(--accent);white-space:nowrap}
.fin .pill.waiting,.fin .pill.amend{background:var(--warn-bg);color:var(--warn-ink)}
.fin .pill.paid{background:#eceafb;color:#4a43a6}
.fin .pill.returned,.fin .pill.cancelled,.fin .pill.none{background:var(--bad-bg);color:var(--bad)}
.fin .av{width:38px;height:38px;border-radius:12px;background:var(--soft);color:var(--accent);font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex:0 0 auto}
.fin .nm b{display:block;font-size:14.5px}.fin .nm span{font-size:12px;color:var(--muted)}
.fin .num{font-variant-numeric:tabular-nums;text-align:right;white-space:nowrap}
.fin .err{background:var(--bad-bg);color:var(--bad);border-radius:12px;padding:10px 13px;font-weight:600;font-size:13.5px}
.fin .why{background:var(--warn-bg);color:var(--warn-ink);border-radius:12px;padding:10px 13px;font-size:13px}
.fin .empty{text-align:center;color:var(--muted);padding:26px 10px}

/* one person on a sheet */
.fin .prow{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:10px 12px;display:grid;grid-template-columns:auto auto minmax(0,1.1fr) minmax(0,2fr) auto auto;grid-template-areas:"cb av nm sum net act";gap:6px 12px;align-items:center;transition:opacity .2s,border-color .2s}
.fin .prow.off{opacity:.55}
.fin .prow.open{border-color:var(--accent)}
.fin .prow>input[type=checkbox]{grid-area:cb;width:20px;height:20px;accent-color:var(--accent);margin:0}
.fin .prow .av{grid-area:av}.fin .prow .nm{grid-area:nm;min-width:0}.fin .prow .sum{grid-area:sum;font-size:12.5px;color:var(--muted);font-variant-numeric:tabular-nums;min-width:0}
.fin .prow .net{grid-area:net;text-align:right;font-variant-numeric:tabular-nums}
.fin .prow .net b{display:block;font-size:16px}
.fin .prow .net s{display:block;font-size:11.5px;color:var(--muted)}
.fin .prow .act{grid-area:act}
.fin .prow .lines{grid-column:1/-1;border-top:1px dashed var(--line);margin-top:4px;padding-top:10px;display:grid;gap:6px}
.fin .plist{display:grid;gap:8px}
.fin .phead{display:grid;grid-template-columns:20px 38px minmax(0,1.1fr) minmax(0,2fr) 110px 78px;gap:12px;padding:0 13px;font-size:10.5px;letter-spacing:.07em;text-transform:uppercase;color:var(--muted)}
.fin .phead.nocb{grid-template-columns:38px minmax(0,1.1fr) minmax(0,2fr) 110px 78px}
.fin .prow.nocb{grid-template-columns:auto minmax(0,1.1fr) minmax(0,2fr) auto auto;grid-template-areas:"av nm sum net act"}

/* one line of one person's pay */
.fin .ln{display:grid;grid-template-columns:minmax(0,1.5fr) 96px 130px minmax(0,1.6fr) 28px;gap:4px 10px;align-items:center;padding:6px 8px;border-radius:10px;transition:background .15s}
.fin .ln.hd{font-size:10.5px;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);padding-block:0}
.fin .ln .lb b{font-weight:600}.fin .ln .lb small{display:block;color:var(--muted);font-size:11.5px}
.fin .ln .calc{text-align:right;font-variant-numeric:tabular-nums;color:var(--muted);font-size:13px}
.fin .ln .amt{display:flex;align-items:center;gap:4px}
.fin .ln .amt i{font-style:normal;font-weight:700;width:10px;color:var(--muted)}
.fin .ln.deduct .amt i{color:var(--bad)}
.fin .ln input.n{text-align:right;font-weight:700;font-variant-numeric:tabular-nums;padding:6px 8px}
.fin .ln input.n:disabled{background:var(--soft);border-color:transparent;color:var(--muted)}
.fin .ln.chg{background:var(--warn-bg)}
.fin .ln.chg input.n{border-color:var(--owed)}
.fin .ln.bad input.n,.fin .ln.bad input.r{border-color:var(--bad)}
.fin .ln .fixed{font-size:12px;color:var(--muted)}
.fin .ln .x{border:0;background:none;color:var(--bad);font-size:18px;line-height:1;cursor:pointer;padding:2px 6px}
.fin .ln.ro{grid-template-columns:minmax(0,1.5fr) 96px 110px minmax(0,1.6fr)}
.fin .ln.ro .now{text-align:right;font-weight:700;font-variant-numeric:tabular-nums}
.fin .ln.ro.deduct .now{color:var(--bad)}
.fin .ln.tot{border-top:1px solid var(--line);border-radius:0;margin-top:2px;padding-top:9px;font-weight:700}
.fin .sumbar{position:sticky;bottom:10px;background:var(--ink);color:#fff;border-radius:16px;padding:11px 14px;display:flex;gap:12px;align-items:center;justify-content:space-between;z-index:4;box-shadow:0 10px 26px rgba(0,0,0,.2);margin-top:4px}
.fin .sumbar b{font-size:19px;display:block;font-variant-numeric:tabular-nums}
.fin .sumbar small{font-size:12px;opacity:.8}
.fin .sumbar .btn{background:#fff;color:var(--ink);border-color:#fff}

.fin .scard{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:4px 12px;align-items:center;width:100%;text-align:left;font:inherit;color:inherit;cursor:pointer;transition:transform .12s,border-color .12s}
.fin .scard:hover{border-color:var(--accent);transform:translateY(-1px)}
.fin .scard .amt b{font-size:16px;display:block;text-align:right;font-variant-numeric:tabular-nums}
.fin .track3{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
.fin .track3 div{font-size:12px;padding:8px 10px;border-radius:11px;background:var(--card);border:1px solid var(--line);color:var(--muted)}
.fin .track3 div.done{background:var(--soft);border-color:transparent;color:var(--accent)}
.fin .track3 div b{display:block;font-size:12.5px;color:inherit}
.fin table.t{width:100%;border-collapse:collapse;font-size:13.5px;min-width:0}
.fin table.t th{text-align:left;font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);padding:6px 8px;border-bottom:1px solid var(--line);white-space:nowrap}
.fin table.t td{padding:8px;border-bottom:1px solid var(--soft);vertical-align:top}
.fin table.t tr.click{cursor:pointer}.fin table.t tr.click:hover td{background:var(--soft)}
.fin table.t tr.dead td{text-decoration:line-through;color:var(--muted)}
.fin .scroll{overflow:auto;width:0;min-width:100%}
.fin .bar-r{height:6px;border-radius:99px;background:var(--soft);overflow:hidden;min-width:70px}
.fin .bar-r i{display:block;height:100%;background:var(--earn);border-radius:99px;transition:width .8s cubic-bezier(.2,.8,.2,1)}

.fin-shade{position:fixed;inset:0;background:rgba(10,30,25,.45);z-index:900;display:flex;align-items:center;justify-content:center;padding:14px}
.fin-shade[hidden]{display:none}
.fin-shade .box{background:#fff;color:#173a33;width:100%;max-width:560px;max-height:92vh;overflow:auto;border-radius:20px;padding:18px;display:grid;gap:12px}
.fin-shade .box.wide{max-width:860px}
.fin-toast{position:fixed;left:50%;transform:translateX(-50%);bottom:26px;background:#173a33;color:#fff;padding:11px 16px;border-radius:13px;font-weight:600;font-size:14px;max-width:calc(100% - 32px);z-index:950;box-shadow:0 10px 26px rgba(0,0,0,.25)}
.fin-toast[hidden]{display:none}
.fin-toast.bad{background:#b3261e}
@media (prefers-reduced-motion:no-preference){
  .fin #fin-body>*{animation:finrise .3s ease both}
  .fin .plist>*{animation:finrise .25s ease both}
  .fin-shade .box{animation:finpop .22s cubic-bezier(.2,.8,.2,1)}
  .fin-toast{animation:finpop .22s ease}
}
@keyframes finrise{from{transform:translateY(6px);opacity:.3}to{transform:none;opacity:1}}
@keyframes finpop{from{transform:translateY(12px) scale(.98);opacity:0}to{opacity:1}}
.fin-toast{animation-name:fintoast}
@keyframes fintoast{from{transform:translate(-50%,10px);opacity:0}to{transform:translate(-50%,0);opacity:1}}

@media (max-width:820px){
  .fin .phead{display:none}
  .fin .prow,.fin .prow.nocb{grid-template-columns:auto auto minmax(0,1fr) auto;grid-template-areas:"cb av nm net" "sum sum sum act"}
  .fin .prow.nocb{grid-template-columns:auto minmax(0,1fr) auto;grid-template-areas:"av nm net" "sum sum act"}
  .fin .ln,.fin .ln.ro{grid-template-columns:minmax(0,1fr) 120px 28px;grid-template-areas:"lb amt x" "calc calc calc" "r r r"}
  .fin .ln.ro{grid-template-columns:minmax(0,1fr) auto;grid-template-areas:"lb now" "calc calc" "r r"}
  .fin .ln.hd{display:none}
  .fin .ln .lb{grid-area:lb}.fin .ln .calc{grid-area:calc;text-align:left;font-size:12px}.fin .ln .amt{grid-area:amt}.fin .ln .rs{grid-area:r}.fin .ln .x{grid-area:x}.fin .ln .now{grid-area:now}
  .fin .ln .calc:empty,.fin .ln .rs:empty{display:none}
  .fin .steps{grid-template-columns:1fr}
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
  #fin-print h1{font-size:15px;margin:0 0 2px}#fin-print .sig{display:flex;justify-content:space-between;margin-top:22px;font-size:11px}
}
#fin-print{display:none}
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

<script>
(function(){
  'use strict';
  var API = 'api/v1/index.php?route=';
  var CSRF = <?= json_encode(csrf_token()) ?>;
  var body = document.getElementById('fin-body'), shade = document.getElementById('fin-shade'), toastEl = document.getElementById('fin-toast');
  var S = { caps: {}, depts: [], today: '', payDay: 4, tab: 'home', sheetFilter: 'waiting', counts: {} };
  var KIND = { salary: 'Fixed salary', daily: 'Daily wages', contract: 'Contractors' };
  var KIND1 = { salary: 'Fixed salary', daily: 'Daily wages', contract: 'Contractor' };
  var STATUS = { waiting: 'Waiting for approval', approved: 'Approved — ready to pay', paid: 'Paid', returned: 'Sent back', cancelled: 'Cancelled' };
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
  var TABS = [['home', 'Home'], ['prepare', 'Prepare pay'], ['sheets', 'Pay sheets'], ['people', 'People'], ['advances', 'Advances & loans']];
  function drawTabs(){
    var t = document.getElementById('fin-tabs'); t.textContent = '';
    TABS.forEach(function(x){
      if (x[0] === 'prepare' && !S.caps.salary && !S.caps.wage) return;
      var n = x[0] === 'sheets' ? (S.caps.approve ? S.counts.waiting : 0) + (S.caps.pay ? S.counts.approved : 0) : 0;
      t.appendChild(el('button', { type: 'button', role: 'tab', 'aria-selected': S.tab === x[0] ? 'true' : 'false', click: function(){ go(x[0]); } },
        [x[1], n ? el('span', { class: 'dot', text: String(n) }) : null]));
    });
  }
  function go(tab, arg){
    S.tab = tab; drawTabs();
    try { history.replaceState(null, '', '#' + tab); } catch (e) {}
    ({ home: home, prepare: prepare, sheets: sheets, people: people, advances: advances })[tab](arg);
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
        (c.salary || c.wage) ? btn('Prepare pay', function(){ go('prepare'); }, 'light') : null]);
      countUp(heroV, r.owed_paisa);
      var step = function(cls, n, label, filter){ return el('button', { type: 'button', class: 'stepc ' + cls, click: function(){ S.sheetFilter = filter; go('sheets'); } }, [el('i'), el('b', { text: String(n) }), el('span', { text: label })]); };
      var steps = el('div', { class: 'steps' }, [step('s1', r.sheets.waiting, 'sheets waiting for approval', 'waiting'), step('s2', r.sheets.approved, 'approved, ready to pay', 'approved'), step('s3', r.sheets.paid, 'sheets paid', 'paid')]);
      var facts = el('div', { class: 'cardx' }, [el('h3', { text: 'At a glance' }), el('div', { class: 'kv', style: 'margin-top:10px' }, [
        el('span', { text: 'Advances and loans still to recover' }), el('b', { text: rs(r.advances_out_paisa) }),
        el('span', { text: 'Earned so far (all time)' }), el('b', { text: rs(r.earned_paisa) }),
        el('span', { text: 'Paid so far (all time)' }), el('b', { text: rs(r.paid_paisa) })]),
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
      show([hero, steps, move, el('div', { class: 'grid2' }, [facts, who])]);
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
      btn('Add a line to everyone ticked', bulk, 'sm')]);
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
      var chips = el('div', { class: 'seg', role: 'group', 'aria-label': 'Which sheets' }, [['waiting', 'Waiting'], ['approved', 'To pay'], ['paid', 'Paid'], ['closed', 'Sent back / cancelled'], ['all', 'All']].map(function(x){
        var n = all.filter(function(s){ return match(s, x[0]); }).length;
        return el('button', { type: 'button', 'aria-pressed': f === x[0] ? 'true' : 'false', text: x[1] + ' ' + n, click: function(){ S.sheetFilter = x[0]; sheets(); } }); }));
      function match(s, k){ return k === 'all' || (k === 'closed' ? (s.status === 'returned' || s.status === 'cancelled') : s.status === k); }
      var list = all.filter(function(s){ return match(s, f); });
      var cards = el('div', { class: 'plist' }, list.map(function(s){
        return el('button', { type: 'button', class: 'cardx scard', click: function(){ sheet(s.id); } }, [
          el('div', { class: 'av', text: s.kind === 'salary' ? 'S' : s.kind === 'daily' ? 'D' : 'C' }),
          el('div', { class: 'nm' }, [el('b', { text: KIND[s.kind] + ' · ' + period(s.kind, s.period_from, s.period_to) }),
            el('span', { text: s.ref + ' · ' + s.people + (s.people === 1 ? ' person' : ' people') + ' · prepared by ' + (s.prepared_by_name || 'someone') + (s.amended ? ' · ' + s.amended + ' changed by hand' : '') })]),
          el('div', { class: 'amt' }, [el('b', { text: rs(s.total_paisa) }), el('span', { class: 'pill ' + s.status, text: STATUS[s.status] })])]);
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
      var mayAmend = s.status === 'waiting' && (c.approve || r.own);
      var mayApprove = s.status === 'waiting' && c.approve && (!r.own || c.admin);
      var head = el('div', { class: 'cardx stack' }, [
        el('div', { class: 'row', style: 'justify-content:space-between' }, [
          el('div', {}, [el('h2', { text: KIND[s.kind] + ' · ' + period(s.kind, s.period_from, s.period_to) }), el('div', { class: 'muted small', text: s.ref + ' · ' + r.items.length + (r.items.length === 1 ? ' person' : ' people') })]),
          el('span', { class: 'pill ' + s.status, text: STATUS[s.status] })]),
        el('div', { class: 'track3' }, [
          el('div', { class: 'done' }, [el('b', { text: '1 · Prepared' }), nice(s.prepared_at)]),
          el('div', { class: s.approved_at ? 'done' : '' }, [el('b', { text: '2 · Approved' }), s.approved_at ? nice(s.approved_at) : (s.status === 'waiting' ? 'waiting' : '—')]),
          el('div', { class: s.status === 'paid' ? 'done' : '' }, [el('b', { text: '3 · Paid' }), s.pay_date && s.status === 'paid' ? nice(s.pay_date) : (s.status === 'approved' ? 'ready' : '—')])]),
        (s.status === 'returned' || s.status === 'cancelled') && s.return_reason ? el('div', { class: 'err', text: (s.status === 'returned' ? 'Sent back: ' : 'Cancelled: ') + s.return_reason }) : null,
        s.off_day_reason ? el('div', { class: 'why', text: 'Paid on a ' + DAYS[isoDay(s.pay_date)] + ', not the usual pay day: ' + s.off_day_reason }) : null,
        s.status === 'waiting' && r.own && c.approve && !c.admin ? el('div', { class: 'why', text: 'You prepared this sheet, so someone else must approve it.' }) : null,
        el('div', { class: 'kv' }, [el('span', { text: 'By bank' }), el('b', { text: rs(r.bank_paisa) }), el('span', { text: 'In cash' }), el('b', { text: rs(r.cash_paisa) }), el('span', { text: 'Total to pay' }), el('b', { style: 'font-size:18px', text: 'Rs ' + rs(r.total_paisa) })])]);
      var listBox = el('div', { class: 'plist' }), bar = el('div', { class: 'sumbar', hidden: true });
      function drawList(){
        listBox.textContent = '';
        r.items.forEach(function(i){
          var m = models[i.employee_id], id = i.employee_id;
          var sum = el('div', { class: 'sum' }), netB = el('b'), netS = el('s'), pill = el('span', { class: 'pill amend', hidden: true });
          var row = el('div', { class: 'prow nocb' + (open[id] ? ' open' : '') }, [el('div', { class: 'av', text: initials(i.full_name) }),
            el('div', { class: 'nm' }, [el('b', { text: i.full_name }), el('span', { text: i.code + ' · ' + i.department_name + ' · ' + (m.method === 'bank' ? 'bank' : 'cash') + ' · ' }), pill]), sum,
            el('div', { class: 'net' }, [netB, netS]),
            el('div', { class: 'act' }, [btn(open[id] ? 'Close' : (editing ? 'Amend' : 'Lines'), function(){ open[id] = !open[id]; drawList(); }, 'sm')])]);
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
          modal([el('h2', { text: 'Approve this pay sheet?' }), el('p', { class: 'muted', text: r.items.length + (r.items.length === 1 ? ' person' : ' people') + ' · Rs ' + rs(r.total_paisa) + '. Once approved it cannot be amended; the earnings and deductions go into each person\'s statement and advances are recovered.' }),
            el('div', { class: 'row', style: 'justify-content:flex-end' }, [btn('Not yet', closeModal), btn('Approve', function(e){ e.target.disabled = true; act('approve', {}, 'Approved. Accounts can now pay it.').then(function(x){ if (x && !x.ok) { closeModal(); toast(x.error, true); } }); }, 'pri')])]);
        }, 'pri'));
        if (mayAmend) acts.appendChild(btn('Send back', function(){
          ask('Send this sheet back', 'It is closed, and the people on it can be prepared again.', [{ k: 'reason', label: 'Why', wide: true }], 'Send back', function(v){ return act('return', v, 'Sent back.'); });
        }, 'danger'));
        if (s.status === 'approved' && c.pay) acts.appendChild(btn('Record payment', function(){
          var inputs = ask('Record the payment', 'Rs ' + rs(r.total_paisa) + ' — ' + rs(r.bank_paisa) + ' by bank, ' + rs(r.cash_paisa) + ' in cash.',
            [{ k: 'pay_date', label: 'Paid on', type: 'date', value: S.today }, { k: 'reason', label: 'Reason (only if not ' + DAYS[S.payDay] + ')', wide: true, placeholder: 'e.g. Eid holiday on ' + DAYS[S.payDay] }], 'Paid', function(v){ return act('pay', v, 'Payment recorded.'); });
          inputs.pay_date.max = S.today;
        }, 'pri'));
        if ((s.status === 'approved' && c.approve) || (s.status === 'paid' && c.admin)) acts.appendChild(btn('Cancel sheet', function(){
          ask('Cancel this ' + (s.status === 'paid' ? 'PAID ' : '') + 'sheet', 'Nothing is deleted: every line stays in the statements, struck through with your reason, and recovered advances are put back. The people can then be prepared again.',
            [{ k: 'reason', label: 'Why', wide: true }], 'Cancel the sheet', function(v){ return act('cancel', v, 'Sheet cancelled.'); });
        }, 'danger'));
        acts.appendChild(btn('Print pay slips', function(){ printSlips(r); }));
        acts.appendChild(el('a', { class: 'btn', href: API + encodeURIComponent('/finance/sheets/' + id + '/export'), text: 'Download (Excel)' }));
        acts.appendChild(btn('Show all lines', function(){ var any = r.items.some(function(i){ return !open[i.employee_id]; }); r.items.forEach(function(i){ open[i.employee_id] = any; }); drawList(); }, 'sm'));
      } else r.items.forEach(function(i){ open[i.employee_id] = r.items.length <= 3; });
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
  var PF = { type: '', dept: '', q: '' };
  function people(type){
    if (typeof type === 'string') PF.type = type;
    show([el('p', { class: 'muted', text: 'Loading…' })]);
    call('GET', '/finance/people').then(function(r){
      if (!r.ok) return show([fail(r)]);
      var seg = el('div', { class: 'seg', role: 'group', 'aria-label': 'Kind of pay' }, [['', 'Everyone'], ['salary', 'Fixed salary'], ['daily', 'Daily wages'], ['contract', 'Contractors'], ['none', 'Not set up']].map(function(x){
        var n = r.people.filter(function(p){ return x[0] === '' || (x[0] === 'none' ? !p.pay_type && +p.active : p.pay_type === x[0]); }).length;
        return el('button', { type: 'button', 'aria-pressed': PF.type === x[0] ? 'true' : 'false', text: x[1] + ' ' + n, click: function(){ PF.type = x[0]; people(); } }); }));
      var dept = el('select', { 'aria-label': 'Department', change: function(){ PF.dept = dept.value; draw(); } }, [el('option', { value: '', text: 'All departments' })].concat(
        Object.keys(r.people.reduce(function(a, p){ a[p.department_name] = 1; return a; }, {})).sort().map(function(d){ return el('option', { value: d, text: d }); })));
      dept.value = PF.dept;
      var search = el('input', { type: 'search', placeholder: 'Search name or code', value: PF.q, 'aria-label': 'Search', input: function(){ PF.q = search.value.toLowerCase(); draw(); } });
      var box = el('div', { class: 'cardx scroll' });
      function draw(){
        var rows = r.people.filter(function(p){ return (PF.type === '' || (PF.type === 'none' ? !p.pay_type && +p.active : p.pay_type === PF.type)) && (!PF.dept || p.department_name === PF.dept) && (!PF.q || (p.full_name + ' ' + p.code).toLowerCase().indexOf(PF.q) >= 0); });
        box.textContent = '';
        if (!rows.length) return box.appendChild(el('div', { class: 'empty', text: 'Nobody here.' }));
        var tb = el('tbody');
        rows.forEach(function(p){
          var rate = p.pay_type === 'salary' ? (p.basic_paisa === null ? 'hidden' : rs(p.basic_paisa) + ' a month' + (p.allowance_paisa ? ' + ' + rs(p.allowance_paisa) : ''))
            : p.pay_type === 'daily' ? rs(p.daily_rate_paisa) + ' a day' : p.pay_type === 'contract' ? 'by production' : '';
          tb.appendChild(el('tr', { class: 'click', tabindex: '0', click: function(){ person(p); }, keydown: function(e){ if (e.key === 'Enter') person(p); } }, [
            el('td', {}, [el('b', { text: p.full_name }), el('div', { class: 'muted small', text: p.code + ' · ' + p.department_name + (+p.active ? '' : ' · left') })]),
            el('td', {}, [el('span', { class: 'pill ' + (p.pay_type ? '' : 'none'), text: p.pay_type ? KIND1[p.pay_type] : 'Not set up' })]),
            el('td', { text: rate }), el('td', { class: 'num' }, [el('b', { text: rs(p.balance) }), p.on_sheet ? el('div', {}, [el('span', { class: 'pill waiting', text: 'on a sheet' })]) : null])]));
        });
        box.appendChild(el('table', { class: 't' }, [el('thead', {}, [el('tr', {}, [el('th', { text: 'Employee' }), el('th', { text: 'Paid as' }), el('th', { text: 'Rate' }), el('th', { style: 'text-align:right', text: 'Owed now' })])]), tb]));
      }
      show([seg, el('div', { class: 'row' }, [el('div', { style: 'flex:1 1 160px' }, [dept]), el('div', { style: 'flex:2 1 220px' }, [search])]), box]);
      draw();
    });
  }
  function person(p){
    var c = S.caps, id = p.employee_id;
    var stmt = el('div', { class: 'scroll', style: 'max-height:38vh' }, [el('p', { class: 'muted', text: 'Loading the statement…' })]);
    var advBox = el('div');
    var kids = [el('div', { class: 'row', style: 'justify-content:space-between' }, [el('div', { class: 'row' }, [el('div', { class: 'av', text: initials(p.full_name) }), el('div', { class: 'nm' }, [el('b', { text: p.full_name }), el('span', { text: p.code + ' · ' + p.department_name })])]), btn('Close', closeModal, 'sm')])];
    // pay setup
    if (c.setup) {
      var type = el('select', {}, [['salary', 'Fixed salary (monthly)'], ['daily', 'Daily wages (weekly)'], ['contract', 'Contractor (by production)']].map(function(o){ return el('option', { value: o[0], text: o[1] }); }));
      type.value = p.pay_type || 'daily';
      var f = { basic: el('input', { type: 'text', inputmode: 'decimal', value: p.basic_paisa ? plain(p.basic_paisa) : '' }), allow: el('input', { type: 'text', inputmode: 'decimal', value: p.allowance_paisa ? plain(p.allowance_paisa) : '' }),
        daily: el('input', { type: 'text', inputmode: 'decimal', value: p.daily_rate_paisa ? plain(p.daily_rate_paisa) : '' }), ot: el('input', { type: 'text', inputmode: 'decimal', value: p.ot_rate_paisa ? plain(p.ot_rate_paisa) : '' }),
        method: el('select', {}, [el('option', { value: 'cash', text: 'Cash' }), el('option', { value: 'bank', text: 'Bank' })]), bank: el('input', { type: 'text', maxlength: 60, value: p.bank_account || '' }), reason: el('input', { type: 'text', maxlength: 255, placeholder: p.pay_type ? 'Why is the pay changing? (needed)' : 'Optional the first time' }) };
      f.method.value = p.pay_method || 'cash';
      var w = { basic: field('Salary per month', f.basic), allow: field('Allowance per month', f.allow), daily: field('Rate per day', f.daily), ot: field('Overtime rate per hour', f.ot), bank: field('Bank account', f.bank) };
      var vis = function(){ var t = type.value; w.basic.hidden = w.allow.hidden = t !== 'salary'; w.daily.hidden = t !== 'daily'; w.ot.hidden = t === 'contract'; w.bank.hidden = f.method.value !== 'bank'; };
      type.addEventListener('change', vis); f.method.addEventListener('change', vis); vis();
      var e1 = el('div', { class: 'err', hidden: true });
      var note = el('p', { class: 'muted small', style: 'margin:0', text: 'The overtime rate is used only for approved, checked overtime that Accounts has not already priced on the Overtime screen. A contractor needs the same code in Production › Workers' + (p.worker_code ? ' (linked: ' + p.worker_code + ').' : ' — their employee code is used.') });
      kids.push(el('div', { class: 'cardx stack' }, [el('h3', { text: 'How they are paid' }), el('div', { class: 'formgrid' }, [field('Paid as', type), w.basic, w.allow, w.daily, w.ot, field('Paid by', f.method), w.bank, field('Reason', f.reason)]), note, e1,
        el('div', { class: 'row', style: 'justify-content:flex-end' }, [btn('Save pay setup', function(ev){
          var m = function(i){ var v = i.value.trim(); return v === '' ? 0 : toPaisa(v); };
          var d = { pay_type: type.value, basic_paisa: m(f.basic), allowance_paisa: m(f.allow), daily_rate_paisa: m(f.daily), ot_rate_paisa: m(f.ot), pay_method: f.method.value, bank_account: f.bank.value.trim(), reason: f.reason.value.trim() };
          if ([d.basic_paisa, d.allowance_paisa, d.daily_rate_paisa, d.ot_rate_paisa].indexOf(null) >= 0) { e1.textContent = 'Amounts are numbers like 45000 or 1200.50.'; e1.hidden = false; return; }
          ev.target.disabled = true;
          call('POST', '/finance/people/' + id + '/profile', d).then(function(x){ ev.target.disabled = false; if (!x.ok) { e1.textContent = x.error; e1.hidden = false; return; } closeModal(); toast('Pay setup saved for ' + p.full_name + '.'); people(); });
        }, 'pri')])]));
    }
    kids.push(el('div', { class: 'cardx stack' }, [el('div', { class: 'row', style: 'justify-content:space-between' }, [el('h3', { text: 'Statement' }),
      c.pay ? btn('Opening balance', function(){ ask('Opening balance for ' + p.full_name, 'What the company owed this person on the day you start using this screen. Entered again, it replaces the old figure. Money the person owes is an advance instead.',
        [{ k: 'amt', label: 'Amount owed to them', money: true }, { k: 'reason', label: 'Where the figure comes from', wide: true, placeholder: 'e.g. wage register, page 14' }], 'Save', function(v){
          var a = toPaisa(v.amt); if (a === null) return { ok: false, error: 'Give the amount.' };
          return call('POST', '/finance/people/' + id + '/opening', { amount_paisa: a, reason: v.reason }).then(function(x){ if (x.ok) { closeModal(); toast('Opening balance saved.'); people(); } return x; }); }); }, 'sm') : null]), stmt]));
    kids.push(el('div', { class: 'cardx stack' }, [el('div', { class: 'row', style: 'justify-content:space-between' }, [el('h3', { text: 'Advances & loans' }), c.pay ? btn('Give advance or loan', function(){ give(p); }, 'sm') : null]), advBox]));
    modal(kids, true);
    call('GET', '/finance/people/' + id + '/ledger').then(function(r){
      stmt.textContent = '';
      if (!r.ok) return stmt.appendChild(fail(r));
      if (!r.lines.length) stmt.appendChild(el('div', { class: 'empty', text: 'Nothing yet.' }));
      else {
        var tb = el('tbody');
        r.lines.slice().reverse().forEach(function(l){
          var plus = l.kind === 'opening' || l.kind === 'earning';
          tb.appendChild(el('tr', { class: l.cancelled_at ? 'dead' : '' }, [el('td', { style: 'white-space:nowrap', text: nice(l.entry_date) }),
            el('td', {}, [l.label, l.sheet_ref ? el('div', { class: 'muted small', text: l.sheet_ref }) : null, l.cancelled_at ? el('div', { class: 'small', style: 'text-decoration:none;color:var(--bad)', text: 'cancelled: ' + (l.cancel_reason || '') }) : null]),
            el('td', { class: 'num', style: 'color:var(--earn)', text: plus ? rs(l.amount_paisa) : '' }), el('td', { class: 'num', style: 'color:var(--bad)', text: l.kind === 'deduction' ? rs(l.amount_paisa) : '' }),
            el('td', { class: 'num', style: 'color:var(--paid)', text: l.kind === 'payment' ? rs(l.amount_paisa) + (l.method ? ' ' + l.method : '') : '' }), el('td', { class: 'num' }, [el('b', { text: rs(l.balance_paisa) })])]));
        });
        stmt.appendChild(el('table', { class: 't' }, [el('thead', {}, [el('tr', {}, ['Date', 'What', 'Earned', 'Deducted', 'Paid', 'Owed'].map(function(h, i){ return el('th', { style: i > 1 ? 'text-align:right' : null, text: h }); }))]), tb]));
      }
      advBox.textContent = '';
      if (!r.advances.length) advBox.appendChild(el('div', { class: 'muted small', text: 'None.' }));
      r.advances.forEach(function(a){ advBox.appendChild(advRow(a, false)); });
    });
  }
  function advRow(a, withName){
    var pct = a.amount_paisa ? Math.round(a.recovered_paisa * 100 / a.amount_paisa) : 0;
    return el('div', { class: 'row', style: 'justify-content:space-between;padding:8px 0;border-top:1px solid var(--soft)' }, [
      el('div', { class: 'nm', style: 'flex:1 1 200px' }, [el('b', { text: (withName ? a.full_name + ' · ' : '') + (a.kind === 'loan' ? 'Loan' : 'Advance') + ' ' + rs(a.amount_paisa) }),
        el('span', { text: (withName ? a.code + ' · ' + a.department_name + ' · ' : '') + 'given ' + nice(a.given_on) + ' · ' + rs(a.per_pay_paisa) + ' from each pay' + (a.note ? ' · ' + a.note : '') })]),
      el('div', { style: 'flex:1 1 120px' }, [el('div', { class: 'bar-r' }, [el('i', { style: 'width:' + pct + '%' })]), el('div', { class: 'small muted', text: rs(a.recovered_paisa) + ' recovered' })]),
      el('div', { class: 'num' }, [el('b', { text: rs(a.left_paisa) }), el('div', { class: 'small muted', text: a.status === 'closed' ? 'cleared' : 'still owed' })]),
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
    go(TABS.some(function(x){ return x[0] === h; }) && (h !== 'prepare' || S.caps.salary || S.caps.wage) ? h : 'home');
  });
})();
</script>
<?php page_footer(); ?>
