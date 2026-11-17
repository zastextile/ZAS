<?php
/*
  ATTENDANCE — MOBILE
  ===================

  The attendance reports, made for a phone held in one hand. Four tabs on a
  bottom bar:

    Live    present today (ring), inside / came / not in, the faces of who
            just came in and who just went out, everyone inside as faces
            by department
    Today   late, left early, did not come, not scanned out — as faces
    Week    the last 7 days: attendance by day, punctuality, departments,
            who was often late
    Find    any person: their face, today, and the last 30 days, day by day

  READ-ONLY, and nothing new is decided here: every figure comes from the
  same /api/v1/reports/... routes as Attendance › Reports, with the same
  department scope (a HOD sees their own departments only). Faces are the
  small copies from /reports/live/photos — same department check, every face
  audited, held in this page's memory only, never in the phone's storage.
  "Hide faces" shows initials; it is remembered on this phone.

  Fast on a phone: one request per tab, faces in batches of 40 as they come
  into view, the Week data only when Week is opened, animations on transform
  and opacity only (and none when the phone asks for reduced motion).
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
zu_require('attrep', 'v');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f2742">
<title>Attendance — Mobile</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
<style>
:root{--ff:'IBM Plex Sans','Segoe UI',system-ui,-apple-system,Roboto,Arial,sans-serif;--navy:#0f2742;--navy2:#173a63;--pri:#0a5fb4;--ink:#16202c;--muted:#64748b;--line:#e3e8ef;--bg:#f2f5f9;--card:#fff;
  --in:#16a34a;--in-bg:#e8f7ee;--out:#d97706;--out-bg:#fff4e5;--bad:#c81e1e;--bad-bg:#fdecec;--late:#b45309;--blue:#2563eb;--blue-bg:#e8f0fe;--r:18px}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
[hidden]{display:none!important}
html,body{margin:0;background:var(--bg);color:var(--ink);font:400 14px/1.4 var(--ff);overscroll-behavior-y:contain}
button{font:inherit;color:inherit}
/* header */
.top{position:sticky;top:0;z-index:20;background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;padding:calc(10px + env(safe-area-inset-top)) 16px 12px}
.top .row{display:flex;align-items:center;gap:10px}
.top .back{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:rgba(255,255,255,.12);color:#fff;text-decoration:none;font-size:18px}
.top h1{margin:0;font-size:17px;font-weight:700;letter-spacing:.1px;flex:1}
.top .iconbtn{width:34px;height:34px;border-radius:10px;border:0;background:rgba(255,255,255,.12);color:#fff;display:grid;place-items:center;font-size:16px}
.top .iconbtn[aria-pressed=true]{background:#fff;color:var(--navy)}
.top .sub{display:flex;align-items:center;gap:8px;margin-top:6px;font-size:12.5px;color:#c8d6ea}
.dot{width:8px;height:8px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 0 rgba(34,197,94,.6);animation:pulse 2s infinite}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(34,197,94,.55)}70%{box-shadow:0 0 0 8px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}}
.clock{font-variant-numeric:tabular-nums;font-weight:600;color:#fff}
/* page */
main{padding:12px 12px calc(84px + env(safe-area-inset-bottom));max-width:640px;margin:0 auto}
.card{background:var(--card);border-radius:var(--r);box-shadow:0 1px 2px rgba(15,39,66,.05),0 8px 24px rgba(15,39,66,.06);padding:14px;margin:0 0 12px}
.card h2{margin:0 0 10px;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px}
.card h2 .n{margin-left:auto;font-size:12px;font-weight:600;color:var(--muted)}
.muted{color:var(--muted)}.err{color:var(--bad);font-weight:600}
.empty{color:var(--muted);text-align:center;padding:14px 6px;font-size:13px}
/* entrance animation */
.rise{animation:rise .38s cubic-bezier(.2,.8,.2,1) both}
@keyframes rise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
/* hero */
.hero{display:grid;grid-template-columns:132px 1fr;gap:14px;align-items:center}
.ring{width:132px;height:132px}
.ring .trk{fill:none;stroke:#edf1f6;stroke-width:13}
.ring .val{fill:none;stroke:url(#rg);stroke-width:13;stroke-linecap:round;transition:stroke-dasharray 1s cubic-bezier(.2,.8,.2,1)}
.ring .big{font:700 27px var(--ff);fill:var(--navy)}.ring .sm{font:500 10.5px var(--ff);fill:var(--muted)}
.stats{display:grid;gap:8px}
.stat{display:flex;align-items:center;gap:10px;border-radius:12px;padding:8px 10px;background:var(--bg)}
.stat i{width:28px;height:28px;border-radius:9px;display:grid;place-items:center;font-style:normal;font-size:14px;font-weight:700}
.stat b{font-size:20px;font-variant-numeric:tabular-nums;margin-left:auto}
.stat span{font-size:12.5px;color:var(--muted)}
.stat.in i{background:var(--blue-bg);color:var(--blue)}.stat.came i{background:var(--in-bg);color:var(--in)}.stat.not i{background:var(--bad-bg);color:var(--bad)}
/* face strips */
.strip{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;padding:4px 2px 6px;margin:0 -2px;scrollbar-width:none}
.strip::-webkit-scrollbar{display:none}
.pf{flex:0 0 auto;width:74px;text-align:center;scroll-snap-align:start}
.pf b{display:block;font-size:12px;font-weight:600;line-height:1.2;margin-top:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pf .m{display:block;font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.av{position:relative;width:62px;height:62px;margin:0 auto;border-radius:50%;background:#e2e8f0 center 30%/cover no-repeat;display:grid;place-items:center;font-weight:700;color:#334155;font-size:18px;box-shadow:0 0 0 3px #fff,0 0 0 5px var(--line);transition:opacity .35s}
.av.has span{visibility:hidden}
.av.fade{opacity:0}
.av.g{box-shadow:0 0 0 3px #fff,0 0 0 5px var(--in)}.av.o{box-shadow:0 0 0 3px #fff,0 0 0 5px var(--out)}
.av.b{box-shadow:0 0 0 3px #fff,0 0 0 5px var(--blue)}.av.r{box-shadow:0 0 0 3px #fff,0 0 0 5px var(--bad)}.av.y{box-shadow:0 0 0 3px #fff,0 0 0 5px var(--late)}
.av.new::after{content:'';position:absolute;inset:-7px;border-radius:50%;border:2px solid var(--in);animation:ping 1.6s ease-out 3}
@keyframes ping{from{opacity:.9;transform:scale(.9)}to{opacity:0;transform:scale(1.25)}}
/* chips */
.chips{display:flex;gap:6px;overflow-x:auto;padding:2px 0 10px;scrollbar-width:none}
.chips::-webkit-scrollbar{display:none}
.chip{flex:0 0 auto;border:1px solid var(--line);background:#fff;border-radius:99px;padding:7px 12px;font-size:12.5px;font-weight:600;white-space:nowrap}
.chip .c{margin-left:6px;color:var(--muted);font-weight:500}
.chip[aria-pressed=true]{background:var(--navy);border-color:var(--navy);color:#fff}.chip[aria-pressed=true] .c{color:#c8d6ea}
/* grid of faces */
.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px 6px}
.grid .pf{width:auto}
@media (max-width:360px){.grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
/* kpi tiles */
.kpis{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0 0 12px}
.kpi{border:0;text-align:left;background:var(--card);border-radius:16px;padding:12px;box-shadow:0 1px 2px rgba(15,39,66,.05),0 8px 24px rgba(15,39,66,.06);position:relative;overflow:hidden}
.kpi::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--c,#94a3b8)}
.kpi b{display:block;font-size:26px;font-weight:700;font-variant-numeric:tabular-nums;color:var(--c,var(--ink))}
.kpi span{font-size:12.5px;color:var(--muted)}
.kpi[aria-pressed=true]{outline:2px solid var(--c);outline-offset:-2px}
/* list rows */
/* one column that may shrink: a long name is cut with "…", never pushes the row past the card */
.rows{display:grid;grid-template-columns:minmax(0,1fr);gap:2px}
.prow{display:flex;align-items:center;gap:10px;padding:8px 2px;border-bottom:1px solid #f1f4f8;min-width:0}
.prow:last-child{border-bottom:0}
.prow .av{width:44px;height:44px;font-size:14px;margin:0;flex:none}
.prow .t{flex:1;min-width:0}.prow .t b{display:block;font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.prow .t span{display:block;font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* the value on the right: a small label that always fits inside the card */
.prow .v{flex:none;max-width:44%;font-size:12px;font-weight:700;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding:5px 9px;border-radius:99px;background:#f1f4f8;color:#475569;font-variant-numeric:tabular-nums}
.v.late{color:var(--late);background:#fff4e5}.v.bad{color:var(--bad);background:var(--bad-bg)}.v.out{color:var(--out);background:var(--out-bg)}.v.in{color:var(--in);background:var(--in-bg)}
/* bars */
.bars{display:flex;align-items:flex-end;gap:8px;height:150px;padding:6px 2px 0}
.bar{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end}
.bar .col{width:100%;max-width:34px;border-radius:9px 9px 4px 4px;background:linear-gradient(180deg,#3b82f6,var(--pri));transform-origin:bottom;animation:grow .8s cubic-bezier(.2,.8,.2,1) both}
.bar .col.low{background:linear-gradient(180deg,#fb923c,#ea580c)}
@keyframes grow{from{transform:scaleY(0)}to{transform:scaleY(1)}}
.bar .p{font-size:11px;font-weight:700;font-variant-numeric:tabular-nums}
.bar .d{font-size:11px;color:var(--muted)}
.mini{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px}
.mini div{background:var(--bg);border-radius:12px;padding:8px 10px}.mini b{display:block;font-size:18px}.mini span{font-size:11.5px;color:var(--muted)}
.drow{display:grid;grid-template-columns:1fr auto;gap:4px 10px;padding:8px 0;border-bottom:1px solid #f1f4f8}
.drow:last-child{border-bottom:0}
.drow b{font-size:13.5px}.drow .pc{font-weight:700;font-variant-numeric:tabular-nums}
.meter{grid-column:1/-1;height:7px;border-radius:99px;background:#edf1f6;overflow:hidden}
.meter i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#22c55e,#16a34a);transform-origin:left;animation:fill .9s cubic-bezier(.2,.8,.2,1) both}
.meter i.mid{background:linear-gradient(90deg,#fbbf24,#d97706)}.meter i.low{background:linear-gradient(90deg,#f87171,#dc2626)}
@keyframes fill{from{transform:scaleX(0)}to{transform:scaleX(1)}}
/* find */
.search{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:4px 12px;margin:0 0 12px;box-shadow:0 1px 2px rgba(15,39,66,.04)}
.search input{flex:1;border:0;outline:0;font:500 15px var(--ff);padding:10px 0;background:transparent;min-width:0}
.person{text-align:center}
.person .av{width:112px;height:112px;font-size:34px}
.person h3{margin:12px 0 2px;font-size:19px}
.badge{display:inline-flex;align-items:center;gap:6px;border-radius:99px;padding:6px 12px;font-size:12.5px;font-weight:700;margin-top:8px}
.badge.in{background:var(--blue-bg);color:var(--blue)}.badge.done{background:var(--in-bg);color:var(--in)}.badge.not{background:var(--bad-bg);color:var(--bad)}
.cal{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-top:12px}
.cal .h{font-size:10.5px;color:var(--muted);text-align:center;font-weight:600}
.cal .c{aspect-ratio:1;border-radius:10px;display:grid;place-items:center;font-size:12px;font-weight:700;background:#f3f5f8;color:#94a3b8;position:relative}
.cal .c small{position:absolute;top:3px;left:5px;font-size:9px;font-weight:500;color:#94a3b8}
.cal .c.P{background:var(--in-bg);color:var(--in)}.cal .c.L{background:#fff4e5;color:var(--late)}.cal .c.A{background:var(--bad-bg);color:var(--bad)}.cal .c.I{background:var(--blue-bg);color:var(--blue)}
.legend{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;font-size:11.5px;color:var(--muted)}
.legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:4px;vertical-align:-1px}
/* skeleton */
.sk{background:linear-gradient(90deg,#eef2f7 25%,#f7f9fc 50%,#eef2f7 75%);background-size:200% 100%;animation:sh 1.2s infinite;border-radius:14px}
@keyframes sh{from{background-position:200% 0}to{background-position:-200% 0}}
/* bottom bar */
nav.tabs{position:fixed;left:0;right:0;bottom:0;z-index:30;background:rgba(255,255,255,.92);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-top:1px solid var(--line);display:grid;grid-template-columns:repeat(4,1fr);padding:6px 6px calc(6px + env(safe-area-inset-bottom))}
nav.tabs button{border:0;background:none;display:flex;flex-direction:column;align-items:center;gap:3px;padding:6px 0;font-size:11px;font-weight:600;color:var(--muted);border-radius:12px}
nav.tabs button svg{width:22px;height:22px}
nav.tabs button[aria-selected=true]{color:var(--pri);background:var(--blue-bg)}
.toast{position:fixed;left:50%;bottom:calc(86px + env(safe-area-inset-bottom));transform:translateX(-50%);background:var(--navy);color:#fff;padding:9px 14px;border-radius:12px;font-size:13px;z-index:40;box-shadow:0 8px 24px rgba(15,39,66,.3)}
@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important}}
</style>
</head>
<body>
<header class="top">
  <div class="row">
    <a class="back" href="att_reports.php" aria-label="Back to Reports">‹</a>
    <h1>Attendance</h1>
    <button class="iconbtn" id="m-hide" type="button" aria-pressed="false" aria-label="Hide faces" title="Hide faces"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
    <button class="iconbtn" id="m-refresh" type="button" aria-label="Refresh" title="Refresh"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 12a8 8 0 1 1-2.3-5.7M20 4v5h-5"/></svg></button>
  </div>
  <div class="sub"><span class="dot" aria-hidden="true"></span><span id="m-date"></span><span class="clock" id="m-clock"></span></div>
</header>
<main id="m-body" aria-live="polite"></main>
<nav class="tabs" id="m-tabs" role="tablist">
  <button type="button" role="tab" data-tab="live"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M5.6 5.6a9 9 0 0 0 0 12.8M18.4 5.6a9 9 0 0 1 0 12.8"/></svg>Live</button>
  <button type="button" role="tab" data-tab="today"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>Today</button>
  <button type="button" role="tab" data-tab="week"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 20V12M10 20V6M15 20v-9M20 20V9"/></svg>Week</button>
  <button type="button" role="tab" data-tab="find"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>Find</button>
</nav>
<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs><linearGradient id="rg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#22c55e"/><stop offset="1" stop-color="#0ea5e9"/></linearGradient></defs></svg>

<script>
(function(){
  'use strict';
  var API = 'api/v1/index.php?route=';
  var body = document.getElementById('m-body');
  var TAB = 'live', timer = null, LIVE = null, DASH = null, WEEK = null;
  var HIDE = false; try { HIDE = localStorage.getItem('attm-hide') === '1'; } catch (e) {}
  var FACES = {}, wait = {}, busy = 0, flushT = null, seenNew = {};

  function el(tag, a, kids){
    var n = document.createElement(tag);
    Object.keys(a || {}).forEach(function(k){ var v = a[k];
      if (k === 'text') n.textContent = v; else if (k === 'click') n.addEventListener('click', v);
      else if (v !== null && v !== undefined && v !== false) n.setAttribute(k, v); });
    (kids || []).forEach(function(c){ if (c !== null && c !== undefined && c !== false) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }
  function q(route, data){ var u = API + encodeURIComponent(route); Object.keys(data || {}).forEach(function(k){ if (data[k] !== '' && data[k] != null) u += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }); return u; }
  function get(route, data){
    return fetch(q(route, data), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function(r){ return r.json().catch(function(){ return { ok: false, error: 'The server sent something unreadable.' }; }); })
      .catch(function(){ return { ok: false, error: 'No connection.' }; });
  }
  var QUIET = false;      /* a refresh replaces the page without the entrance animation */
  function show(nodes){ var y = window.scrollY; body.textContent = ''; nodes.forEach(function(n, i){ if (!n) return; if (!QUIET) { n.classList && n.classList.add('rise'); if (n.style) n.style.animationDelay = Math.min(i * 50, 300) + 'ms'; } body.appendChild(n); }); if (QUIET) window.scrollTo(0, y); QUIET = false; }
  function hm(iso){ if (!iso) return '—'; var d = new Date(iso); return isNaN(d) ? '—' : d.toLocaleTimeString('en-GB', { timeZone: 'Asia/Karachi', hour: '2-digit', minute: '2-digit' }); }
  function mins(m){ m = parseInt(m, 10) || 0; return m >= 60 ? Math.floor(m / 60) + 'h' + (m % 60 ? ' ' + (m % 60) + 'm' : '') : m + 'm'; }
  function pct(a, b){ return b ? Math.round(100 * a / b) : null; }
  function initials(n){ return String(n || '?').trim().split(/\s+/).slice(0, 2).map(function(w){ return w.charAt(0).toUpperCase(); }).join(''); }
  function khiDate(off){ var t = new Date(Date.now() + (5 * 60 + new Date().getTimezoneOffset()) * 60000); t.setDate(t.getDate() + (off || 0)); return t.getFullYear() + '-' + ('0' + (t.getMonth() + 1)).slice(-2) + '-' + ('0' + t.getDate()).slice(-2); }
  function dayLabel(v, opt){ var d = new Date(v + 'T00:00:00Z'); return isNaN(d) ? v : d.toLocaleDateString('en-GB', Object.assign({ timeZone: 'UTC' }, opt || { weekday: 'short' })); }
  function toast(t){ var x = el('div', { class: 'toast', text: t }); document.body.appendChild(x); setTimeout(function(){ x.remove(); }, 1800); }
  function skeleton(){ return [el('div', { class: 'sk', style: 'height:170px;margin-bottom:12px' }), el('div', { class: 'sk', style: 'height:120px;margin-bottom:12px' }), el('div', { class: 'sk', style: 'height:220px' })]; }

  /* numbers count up to their value */
  function countUp(node, to){
    to = parseInt(to, 10) || 0;
    if (matchMedia('(prefers-reduced-motion: reduce)').matches || to < 3) { node.textContent = String(to); return node; }
    var t0 = null, from = 0;
    function step(t){ if (!t0) t0 = t; var p = Math.min(1, (t - t0) / 700); node.textContent = String(Math.round(from + (to - from) * (1 - Math.pow(1 - p, 3)))); if (p < 1) requestAnimationFrame(step); }
    requestAnimationFrame(step); return node;
  }

  /* ------------------------------------------------------------- faces */
  var io = 'IntersectionObserver' in window ? new IntersectionObserver(function(list){
    list.forEach(function(en){ if (!en.isIntersecting) return; io.unobserve(en.target); want(en.target); });
  }, { rootMargin: '150px' }) : null;
  function paintFace(node, url){ if (!url) return; node.classList.add('fade'); node.style.backgroundImage = 'url("' + url + '")'; node.classList.add('has'); requestAnimationFrame(function(){ node.classList.remove('fade'); }); }
  function want(node){
    var id = node.getAttribute('data-id'); if (!id) return;
    if (FACES[id] && FACES[id] !== 'loading') return paintFace(node, FACES[id]);
    if (FACES[id] === null) return;
    (wait[id] = wait[id] || []).push(node);
    if (!flushT) flushT = setTimeout(flush, 30);
  }
  function flush(){
    flushT = null;
    while (busy < 2) {
      var ids = Object.keys(wait).filter(function(id){ return FACES[id] === undefined; }).slice(0, 40);
      if (!ids.length) return;
      ids.forEach(function(id){ FACES[id] = 'loading'; });
      busy++;
      (function(ids){
        get('/reports/live/photos', { ids: ids.join(',') }).then(function(j){
          busy--;
          var got = (j && j.photos) || {};
          ids.forEach(function(id){ FACES[id] = got[id] || null; (wait[id] || []).forEach(function(n){ paintFace(n, FACES[id]); }); delete wait[id]; });
          flush();
        });
      })(ids);
    }
  }
  function face(p, ring, size){
    /* unknown (a list that does not say): ask; the server answers with nothing if there is no photo */
    var hasPhoto = p.has_photo === undefined || p.has_photo === true || p.has_photo === 1 || p.has_photo === '1';
    var av = el('div', { class: 'av ' + (ring || ''), 'data-id': p.employee_id ? String(p.employee_id) : null, style: size ? 'width:' + size + 'px;height:' + size + 'px;font-size:' + Math.round(size / 3.2) + 'px' : null, 'aria-hidden': 'true' }, [el('span', { text: initials(p.full_name) })]);
    if (!HIDE && hasPhoto && p.employee_id) {
      if (FACES[p.employee_id] && FACES[p.employee_id] !== 'loading') { av.style.backgroundImage = 'url("' + FACES[p.employee_id] + '")'; av.classList.add('has'); }
      else if (io) io.observe(av); else want(av);
    }
    return av;
  }
  function pf(p, ring, sub, isNew){ var a = face(p, ring); if (isNew) a.classList.add('new'); return el('div', { class: 'pf', title: p.full_name + ' · ' + (p.employee_code || '') }, [a, el('b', { text: p.full_name }), el('span', { class: 'm', text: sub })]); }
  function prow(p, ring, sub, val, vcls){ return el('div', { class: 'prow' }, [face(p, ring), el('div', { class: 't' }, [el('b', { text: p.full_name }), el('span', { text: sub })]), val ? el('span', { class: 'v ' + (vcls || ''), text: val }) : null]); }

  /* -------------------------------------------------------------- live */
  function ring(pctV){
    var r = 54, c = 2 * Math.PI * r, f = Math.max(0, Math.min(100, pctV || 0)) / 100, NS = 'http://www.w3.org/2000/svg';
    function s(tag, a){ var n = document.createElementNS(NS, tag); Object.keys(a).forEach(function(k){ n.setAttribute(k, a[k]); }); return n; }
    var svg = s('svg', { viewBox: '0 0 132 132', class: 'ring', role: 'img', 'aria-label': (pctV === null ? 'no one on the roll' : pctV + '% present today') });
    svg.appendChild(s('circle', { cx: 66, cy: 66, r: r, class: 'trk' }));
    var v = s('circle', { cx: 66, cy: 66, r: r, class: 'val', 'stroke-dasharray': '0 ' + c, transform: 'rotate(-90 66 66)' }); svg.appendChild(v);
    var t1 = s('text', { x: 66, y: 68, 'text-anchor': 'middle', class: 'big' }); t1.textContent = pctV === null ? '—' : pctV + '%'; svg.appendChild(t1);
    var t2 = s('text', { x: 66, y: 88, 'text-anchor': 'middle', class: 'sm' }); t2.textContent = 'present today'; svg.appendChild(t2);
    requestAnimationFrame(function(){ requestAnimationFrame(function(){ v.setAttribute('stroke-dasharray', (c * f) + ' ' + c); }); });
    return svg;
  }
  var LF = { dept: '' };
  function drawLive(again){
    if (!again) show(skeleton());
    get('/reports/live').then(function(d){
      QUIET = !!again;
      if (TAB !== 'live') return;
      if (!d.ok) return show([el('div', { class: 'card' }, [el('p', { class: 'err', text: d.error || 'Could not load.' })])]);
      if (d.empty_scope) return show([el('div', { class: 'card' }, [el('p', { class: 'muted', text: 'You are not the HOD of a department, so there is nobody to show.' })])]);
      var prevIn = LIVE ? LIVE.incoming.map(function(x){ return x.employee_id + '@' + x.at; }) : null;
      LIVE = d;
      var pres = pct(d.came_in, d.active_employees);
      var hero = el('section', { class: 'card hero', id: 'm-hero' }, [ring(pres), el('div', { class: 'stats' }, [
        el('div', { class: 'stat in' }, [el('i', { text: '◉' }), el('span', { text: 'Inside now' }), countUp(el('b', { id: 'm-inside' }), d.inside_now)]),
        el('div', { class: 'stat came' }, [el('i', { text: '✓' }), el('span', { text: 'Came today' }), countUp(el('b', { id: 'm-came' }), d.came_in)]),
        el('div', { class: 'stat not' }, [el('i', { text: '✕' }), el('span', { text: 'Not in yet' }), countUp(el('b', { id: 'm-notin' }), d.not_in)])])]);
      function strip(title, list, ringC, sub, id, empty){
        return el('section', { class: 'card', id: id }, [el('h2', {}, [title, el('span', { class: 'n', text: list.length ? list.length + ' · last ' + d.window_minutes + ' min' : 'last ' + d.window_minutes + ' min' })]),
          list.length ? el('div', { class: 'strip' }, list.map(function(p){ var k = p.employee_id + '@' + p.at; var isNew = ringC === 'g' && prevIn && prevIn.indexOf(k) < 0 && !seenNew[k]; if (isNew) seenNew[k] = 1; return pf(p, ringC, sub(p), isNew); })) : el('p', { class: 'empty', text: empty })]);
      }
      var depts = d.by_department.filter(function(x){ return x.inside; });
      if (LF.dept && !depts.some(function(x){ return x.department_name === LF.dept; })) LF.dept = '';
      var grid = el('div', { class: 'grid', id: 'm-grid' });
      function paintGrid(){
        grid.textContent = '';
        var list = d.inside.filter(function(p){ return !LF.dept || p.department_name === LF.dept; });
        if (!list.length) { grid.appendChild(el('p', { class: 'empty', style: 'grid-column:1/-1', text: 'Nobody is inside the factory now.' })); return; }
        list.forEach(function(p){ grid.appendChild(pf(p, 'b', 'since ' + hm(p.since))); });
      }
      var chips = el('div', { class: 'chips', role: 'group', 'aria-label': 'Department' });
      [['', 'All', d.inside_now]].concat(depts.map(function(x){ return [x.department_name, x.department_name, x.inside]; })).forEach(function(c){
        var b = el('button', { type: 'button', class: 'chip', 'aria-pressed': LF.dept === c[0] ? 'true' : 'false', click: function(){ LF.dept = c[0]; Array.prototype.forEach.call(chips.children, function(x){ x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); }); paintGrid(); } }, [c[1], el('span', { class: 'c', text: String(c[2]) })]);
        chips.appendChild(b);
      });
      paintGrid();
      show([hero,
        strip('Just arrived', d.incoming, 'g', function(p){ return hm(p.at); }, 'm-arrived', 'Nobody came in in the last ' + d.window_minutes + ' minutes.'),
        strip('Just left', d.outgoing, 'o', function(p){ return hm(p.at); }, 'm-left', 'Nobody went out in the last ' + d.window_minutes + ' minutes.'),
        el('section', { class: 'card', id: 'm-inside-card' }, [el('h2', {}, ['Inside the factory', el('span', { class: 'n', text: d.inside_now + (d.inside_now === 1 ? ' person' : ' people') })]), chips, grid]),
        el('section', { class: 'card' }, [el('h2', {}, ['By department']), el('div', {}, d.by_department.map(function(x){ var p = pct(x.came_in, x.active);
          return el('div', { class: 'drow' }, [el('b', { text: x.department_name }), el('span', { class: 'pc', text: (p === null ? '—' : p + '%') }),
            el('span', { class: 'muted', style: 'grid-column:1/-1;font-size:12px;margin-top:-2px', text: x.inside + ' inside · ' + x.came_in + ' of ' + x.active + ' came' }),
            el('div', { class: 'meter' }, [el('i', { class: p === null || p >= 90 ? '' : (p >= 75 ? 'mid' : 'low'), style: 'width:' + (p || 0) + '%' })])]); }))])
      ]);
    });
  }

  /* ------------------------------------------------------------- today */
  var TF = 'late';
  function drawToday(again){
    if (!again) show(skeleton());
    get('/reports/dashboard').then(function(d){
      QUIET = !!again;
      if (TAB !== 'today') return;
      if (!d.ok) return show([el('div', { class: 'card' }, [el('p', { class: 'err', text: d.error || 'Could not load.' })])]);
      if (d.empty_scope) return show([el('div', { class: 'card' }, [el('p', { class: 'muted', text: 'You are not the HOD of a department, so there is nobody to show.' })])]);
      DASH = d;
      var today = d.today, autoToday = (d.auto_out || []).filter(function(r){ return !r.work_date || r.work_date === today; });
      var defs = [
        { id: 'late', label: 'Late', n: d.late_count, c: '#b45309', list: d.late, row: function(p){ return prow(p, 'y', p.department_name + ' · in ' + hm(p.first_in), '+' + mins(p.late_minutes), 'late'); } },
        { id: 'early', label: 'Left early', n: d.left_early_count, c: '#d97706', list: d.left_early, row: function(p){ return prow(p, 'o', p.department_name + ' · out ' + hm(p.last_out), '−' + mins(p.early_minutes), 'out'); } },
        { id: 'absent', label: 'Did not come', n: d.absent_with_shift_count, c: '#c81e1e', list: d.absent_with_shift, row: function(p){ return prow(p, 'r', p.department_name + ' · shift ' + hm(p.starts_at), 'absent', 'bad'); } },
        { id: 'auto', label: 'Not scanned out', n: (d.auto_out || []).length, c: '#64748b', list: d.auto_out || [], row: function(p){ return prow(p, '', p.department_name + ' · in ' + hm(p.opened_at) + (p.work_date && p.work_date !== today ? ' · ' + dayLabel(p.work_date, { day: '2-digit', month: 'short' }) : ''), 'out ' + hm(p.closed_at), ''); } }
      ];
      var listCard = el('section', { class: 'card', id: 'm-today-list' });
      function paint(){
        var def = defs.filter(function(x){ return x.id === TF; })[0];
        listCard.textContent = '';
        listCard.appendChild(el('h2', {}, [def.label, el('span', { class: 'n', text: String(def.n) })]));
        listCard.appendChild(def.list.length ? el('div', { class: 'rows' }, def.list.map(def.row)) : el('p', { class: 'empty', text: 'Nobody.' }));
        Array.prototype.forEach.call(kpis.children, function(b){ b.setAttribute('aria-pressed', b.getAttribute('data-k') === TF ? 'true' : 'false'); });
      }
      var kpis = el('div', { class: 'kpis', id: 'm-kpis' }, defs.map(function(x){
        return el('button', { type: 'button', class: 'kpi', 'data-k': x.id, style: '--c:' + x.c, click: function(){ TF = x.id; paint(); listCard.scrollIntoView({ behavior: 'smooth', block: 'start' }); } }, [countUp(el('b'), x.n), el('span', { text: x.label })]);
      }));
      var head = el('section', { class: 'card' }, [el('h2', {}, ['Today', el('span', { class: 'n', text: dayLabel(today, { weekday: 'long', day: 'numeric', month: 'long' }) })]),
        el('div', { class: 'mini' }, [el('div', {}, [countUp(el('b'), d.came_in), el('span', { text: 'came in' })]), el('div', {}, [countUp(el('b'), d.inside_now), el('span', { text: 'inside' })]), el('div', {}, [countUp(el('b'), d.unconfirmed_entries), el('span', { text: 'HOD to confirm' })])])]);
      show([head, kpis, listCard]);
      paint();
    });
  }

  /* -------------------------------------------------------------- week */
  function drawWeek(){
    show(skeleton());
    var from = khiDate(-6), to = khiDate(0);
    (WEEK && WEEK.from === from && WEEK.to === to && WEEK.at > Date.now() - 120000 ? Promise.resolve(WEEK) : get('/reports/attendance', { from: from, to: to }).then(function(r){ if (r.ok) { r.at = Date.now(); WEEK = r; } return r; })).then(function(r){
      if (TAB !== 'week') return;
      if (!r.ok) return show([el('div', { class: 'card' }, [el('p', { class: 'err', text: r.error || 'Could not load.' })])]);
      var rows = r.rows.filter(function(x){ return x.employee_active === undefined || +x.employee_active === 1; });
      var days = {}, D = {}, P = {}, worked = 0, onTime = 0, mins_ = 0, closed = 0;
      for (var i = 6; i >= 0; i--) days[khiDate(-i)] = { n: 0, w: 0 };
      rows.forEach(function(x){
        var d = days[x.work_date] || (days[x.work_date] = { n: 0, w: 0 }); d.n++;
        var g = D[x.department_name] || (D[x.department_name] = { n: 0, w: 0 }); g.n++;
        var p = P[x.employee_code] || (P[x.employee_code] = { employee_id: x.employee_id, full_name: x.full_name, employee_code: x.employee_code, department_name: x.department_name, has_photo: true, late: 0, lateMin: 0 });
        if (x.status !== 'absent') { d.w++; g.w++; worked++; if (x.late_minutes > 0) { p.late++; p.lateMin += +x.late_minutes; } else onTime++; if (+x.present_minutes > 0) { mins_ += +x.present_minutes; closed++; } }
      });
      var keys = Object.keys(days).sort();
      var bars = el('div', { class: 'bars', id: 'm-bars' }, keys.map(function(k, i){ var v = days[k], p = pct(v.w, v.n);
        return el('div', { class: 'bar', title: dayLabel(k, { weekday: 'long', day: 'numeric', month: 'short' }) + ': ' + (p === null ? 'no shifts' : p + '% · ' + v.w + ' of ' + v.n) }, [
          el('span', { class: 'p', text: p === null ? '' : p + '%' }), el('div', { class: 'col' + (p !== null && p < 75 ? ' low' : ''), style: 'height:' + (p === null ? 2 : Math.max(4, p)) + '%;animation-delay:' + (i * 60) + 'ms' }), el('span', { class: 'd', text: dayLabel(k) })]); }));
      var att = pct(worked, rows.length), pun = pct(onTime, worked);
      var late = Object.keys(P).map(function(k){ return P[k]; }).filter(function(p){ return p.late >= 2; }).sort(function(a, b){ return b.late - a.late || b.lateMin - a.lateMin; }).slice(0, 12);
      show([
        el('section', { class: 'card' }, [el('h2', {}, ['Last 7 days', el('span', { class: 'n', text: dayLabel(from, { day: 'numeric', month: 'short' }) + ' – ' + dayLabel(to, { day: 'numeric', month: 'short' }) })]), bars,
          el('div', { class: 'mini' }, [el('div', {}, [el('b', { text: att === null ? '—' : att + '%' }), el('span', { text: 'attendance' })]), el('div', {}, [el('b', { text: pun === null ? '—' : pun + '%' }), el('span', { text: 'on time' })]), el('div', {}, [el('b', { text: closed ? mins(Math.round(mins_ / closed)) : '—' }), el('span', { text: 'average day' })])])]),
        el('section', { class: 'card' }, [el('h2', {}, ['Departments']), el('div', {}, Object.keys(D).sort().map(function(k){ var g = D[k], p = pct(g.w, g.n);
          return el('div', { class: 'drow' }, [el('b', { text: k }), el('span', { class: 'pc', text: p === null ? '—' : p + '%' }), el('div', { class: 'meter' }, [el('i', { class: p === null || p >= 90 ? '' : (p >= 75 ? 'mid' : 'low'), style: 'width:' + (p || 0) + '%' })])]); }))]),
        el('section', { class: 'card', id: 'm-often-late' }, [el('h2', {}, ['Often late', el('span', { class: 'n', text: late.length ? late.length + ' people' : '' })]),
          late.length ? el('div', { class: 'rows' }, late.map(function(p){ return prow(p, 'y', p.department_name + ' · ' + mins(p.lateMin) + ' late in all', p.late + '×', 'late'); })) : el('p', { class: 'empty', text: 'Nobody was late twice or more this week.' })])
      ]);
    });
  }

  /* -------------------------------------------------------------- find */
  var FQ = '';
  function people(){
    var m = {};
    function add(list, st){ (list || []).forEach(function(p){ if (!p.employee_code || m[p.employee_code]) return; m[p.employee_code] = { employee_id: p.employee_id, employee_code: p.employee_code, full_name: p.full_name, department_name: p.department_name, has_photo: p.has_photo, st: st, at: p.since || p.at || null }; }); }
    if (LIVE) { add(LIVE.inside, 'in'); add(LIVE.gone, 'done'); }
    if (DASH) { add(DASH.not_in, 'not'); }
    return Object.keys(m).map(function(k){ return m[k]; }).sort(function(a, b){ return a.full_name.localeCompare(b.full_name); });
  }
  function drawFind(){
    var need = [];
    if (!LIVE) need.push(get('/reports/live').then(function(d){ if (d.ok && !d.empty_scope) LIVE = d; }));
    if (!DASH) need.push(get('/reports/dashboard').then(function(d){ if (d.ok && !d.empty_scope) DASH = d; }));
    if (need.length) show(skeleton());
    Promise.all(need).then(function(){
      if (TAB !== 'find') return;
      var input = el('input', { type: 'search', id: 'm-q', placeholder: 'Name or code', value: FQ, autocomplete: 'off', 'aria-label': 'Find a person' });
      var list = el('section', { class: 'card', id: 'm-found' });
      function paint(){
        var t = FQ.trim().toLowerCase(), all = people();
        var hits = all.filter(function(p){ return !t || (p.full_name + ' ' + p.employee_code).toLowerCase().indexOf(t) >= 0; }).slice(0, 60);
        list.textContent = '';
        list.appendChild(el('h2', {}, [t ? 'Found' : 'Everyone', el('span', { class: 'n', text: hits.length + (hits.length === 60 ? '+' : '') })]));
        if (!hits.length) return list.appendChild(el('p', { class: 'empty', text: 'Nobody matches.' }));
        list.appendChild(el('div', { class: 'rows' }, hits.map(function(p){
          var r = prow(p, p.st === 'in' ? 'b' : (p.st === 'done' ? 'g' : ''), p.employee_code + ' · ' + p.department_name, p.st === 'in' ? 'inside' : (p.st === 'done' ? 'gone home' : 'not in'), p.st === 'in' ? 'in' : (p.st === 'done' ? '' : 'bad'));
          r.setAttribute('role', 'button'); r.setAttribute('tabindex', '0'); r.style.cursor = 'pointer';
          r.addEventListener('click', function(){ openPerson(p); });
          return r; })));
      }
      input.addEventListener('input', function(){ FQ = input.value; paint(); });
      show([el('div', { class: 'search' }, [el('span', { class: 'muted', text: '⌕' }), input]), list]);
      paint();
    });
  }
  function openPerson(p){
    show(skeleton());
    var from = khiDate(-29), to = khiDate(0);
    get('/reports/attendance', { from: from, to: to, employee: p.employee_code }).then(function(r){
      if (TAB !== 'find') return;
      var rows = (r.ok ? r.rows : []).filter(function(x){ return x.employee_code === p.employee_code; });
      var by = {}; rows.forEach(function(x){ var c = x.status === 'absent' ? 'A' : (x.late_minutes > 0 ? 'L' : (x.status === 'inside' ? 'I' : 'P')); if (!by[x.work_date] || by[x.work_date].c === 'A') by[x.work_date] = { c: c, x: x }; });
      var pres = 0, late = 0, ab = 0, m = 0; Object.keys(by).forEach(function(k){ var c = by[k].c; if (c === 'A') ab++; else { pres++; if (c === 'L') late++; } m += +by[k].x.present_minutes || 0; });
      var cal = el('div', { class: 'cal', id: 'm-cal' }, ['M', 'T', 'W', 'T', 'F', 'S', 'S'].map(function(h){ return el('span', { class: 'h', text: h }); }));
      var first = new Date(from + 'T00:00:00Z'), lead = (first.getUTCDay() + 6) % 7;
      for (var i = 0; i < lead; i++) cal.appendChild(el('span'));
      for (var j = 0; j < 30; j++) { var dt = new Date(first.getTime() + j * 86400000), k = dt.toISOString().slice(0, 10), v = by[k];
        cal.appendChild(el('span', { class: 'c ' + (v ? v.c : ''), title: k + (v ? ' · ' + (v.c === 'A' ? 'did not come' : 'in ' + hm(v.x.first_in) + (v.x.last_out ? ' · out ' + hm(v.x.last_out) : '')) : ' · no shift') }, [el('small', { text: String(dt.getUTCDate()) }), v ? v.c : '·'])); }
      var badge = p.st === 'in' ? el('span', { class: 'badge in', text: '◉ Inside since ' + hm(p.at) }) : (p.st === 'done' ? el('span', { class: 'badge done', text: '✓ Came today · out ' + hm(p.at) }) : el('span', { class: 'badge not', text: '✕ Not in today' }));
      show([
        el('div', {}, [el('button', { type: 'button', class: 'chip', click: drawFind, text: '‹ Back to the list' })]),
        el('section', { class: 'card person', id: 'm-person' }, [face(p, p.st === 'in' ? 'b' : (p.st === 'done' ? 'g' : 'r'), 112), el('h3', { text: p.full_name }), el('div', { class: 'muted', text: p.employee_code + ' · ' + p.department_name }), badge,
          el('div', { class: 'mini' }, [el('div', {}, [countUp(el('b'), pres), el('span', { text: 'days came' })]), el('div', {}, [countUp(el('b'), late), el('span', { text: 'late' })]), el('div', {}, [countUp(el('b'), ab), el('span', { text: 'did not come' })])])]),
        el('section', { class: 'card' }, [el('h2', {}, ['Last 30 days', el('span', { class: 'n', text: mins(m) + ' inside' })]), cal,
          el('div', { class: 'legend' }, [el('span', {}, [el('i', { style: 'background:#e8f7ee' }), 'present']), el('span', {}, [el('i', { style: 'background:#fff4e5' }), 'late']), el('span', {}, [el('i', { style: 'background:#fdecec' }), 'did not come']), el('span', {}, [el('i', { style: 'background:#f3f5f8' }), 'no shift'])])])
      ]);
    });
  }

  /* -------------------------------------------------------------- frame */
  var DRAW = { live: drawLive, today: drawToday, week: drawWeek, find: drawFind };
  function go(t){
    TAB = t; if (timer) { clearInterval(timer); timer = null; }
    Array.prototype.forEach.call(document.querySelectorAll('#m-tabs button'), function(b){ b.setAttribute('aria-selected', b.getAttribute('data-tab') === t ? 'true' : 'false'); });
    try { history.replaceState(null, '', '#' + t); } catch (e) {}
    window.scrollTo(0, 0);
    DRAW[t]();
    if (t === 'live' || t === 'today') timer = setInterval(function(){ if (!document.hidden) DRAW[t](true); }, 30000);
  }
  Array.prototype.forEach.call(document.querySelectorAll('#m-tabs button'), function(b){ b.addEventListener('click', function(){ go(b.getAttribute('data-tab')); }); });
  document.getElementById('m-refresh').addEventListener('click', function(){ if (TAB === 'week') WEEK = null; DRAW[TAB](TAB === 'live' || TAB === 'today'); toast('Updated'); });
  var hideBtn = document.getElementById('m-hide');
  function paintHide(){ hideBtn.setAttribute('aria-pressed', HIDE ? 'true' : 'false'); hideBtn.title = HIDE ? 'Show faces' : 'Hide faces'; }
  hideBtn.addEventListener('click', function(){ HIDE = !HIDE; try { localStorage.setItem('attm-hide', HIDE ? '1' : '0'); } catch (e) {} paintHide(); toast(HIDE ? 'Faces hidden — initials only' : 'Faces shown'); DRAW[TAB](true); });
  paintHide();
  function tick(){ var d = new Date(); document.getElementById('m-clock').textContent = d.toLocaleTimeString('en-GB', { timeZone: 'Asia/Karachi', hour: '2-digit', minute: '2-digit' });
    document.getElementById('m-date').textContent = d.toLocaleDateString('en-GB', { timeZone: 'Asia/Karachi', weekday: 'short', day: 'numeric', month: 'short' }) + ' ·'; }
  tick(); setInterval(tick, 15000);
  var first = (location.hash || '').slice(1);
  go(DRAW[first] ? first : 'live');
})();
</script>
</body>
</html>
