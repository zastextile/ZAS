/* ZPREP — Production Dashboard & Reports, worked out in the browser.
   The page hands over one period of entries (includes/zp_report.php) and
   every filter, tab, sort, drill-down, flip card and chart below is computed
   here. Nothing is sent back to the server until the period changes — and
   then only that period's rows are fetched, the page itself does not reload
   (owner, 28 Nov 2026: "show reports within page without refresh"). */
(function () {
  'use strict';
  var D = window.ZPR_DATA; if (!D) return;
  var PAGE = D.page, MONEY = !!D.money;
  var root = document.getElementById('zpr');
  var $ = function (s, el) { return (el || document).querySelector(s); };
  var $$ = function (s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function fq(v) { return Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 }); }
  function fm(v) { return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function f0(v) { return Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }); }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], WD = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  function dt(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function fd(s, wd) { var d = dt(s); return (wd ? WD[d.getDay()] + ' ' : '') + d.getDate() + ' ' + MON[d.getMonth()]; }
  function store(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } }

  /* ---------------- the rows ----------------
     r = [day, worker, product, op, order, line, stage, qty, amount, rate, cancelled, reason, id, entered-by] */
  var R, W, P, O, F, L, S, X, U, DAYS, SORD, SCOL;
  var PAL = ['#0a6ed1', '#e9730c', '#1a9898', '#7c48c4', '#d0406a', '#5b8a12', '#925ace', '#3d7dd6', '#a36a00', '#2f6f8f'];
  var WBY = {}, PBY = {}, FBY = {}, UBY = {};   // names for the filter chips — kept across periods
  var SCOLN = {};                             // a stage keeps its colour across periods
  function setData(nd) {
    var orders = D.orders, fin = D.fin; D = nd; if (!D.orders) D.orders = orders; if (!D.fin) D.fin = fin;   // open orders do not depend on the period
    R = D.R; W = D.W; P = D.P; O = D.O; F = D.F; L = D.L; S = D.S; X = D.X; U = D.U || []; DAYS = D.days;
    U.forEach(function (u) { UBY[String(u[1])] = u; });
    SORD = S.map(function (s, i) { return i; }).sort(function (a, b) { return (D.stageOrder[a] - D.stageOrder[b]) || (S[a][0] < S[b][0] ? -1 : 1); });
    SCOL = {}; SORD.forEach(function (si) { var nm = S[si][0]; if (!SCOLN[nm]) SCOLN[nm] = PAL[Object.keys(SCOLN).length % PAL.length]; SCOL[si] = SCOLN[nm]; });
    W.forEach(function (w) { WBY[String(w[3])] = w; }); P.forEach(function (p) { PBY[String(p[1])] = p; }); F.forEach(function (f) { FBY[String(f[2])] = f; });
  }
  setData(D);

  var DIM = {
    dept:  { t: 'Department', key: function (r) { return W[r[1]][2]; }, lbl: function (k) { return k; } },
    stage: { t: 'Stage',      key: function (r) { return S[r[6]][0]; }, lbl: function (k) { return k; } },
    w:     { t: 'Worker',     key: function (r) { return String(W[r[1]][3]); }, lbl: function (k) { var w = WBY[k]; return w ? w[1] + (w[0] ? ' (' + w[0] + ')' : '') : k; } },
    p:     { t: 'Product',    key: function (r) { return String(P[r[2]][1]); }, lbl: function (k) { var p = PBY[k]; return p ? p[0] : k; } },
    f:     { t: 'Order',      key: function (r) { return String(F[r[4]][2]); }, lbl: function (k) { var f = FBY[k]; return f ? f[0] + (f[1] ? ' · ' + f[1] : '') : k; } }
  };
  DIM.u = { t: 'Entered by', key: function (r) { return String(U[r[13]] ? U[r[13]][1] : 0); }, lbl: function (k) { var u = UBY[k]; return u ? u[0] : k; } };
  var DORD = ['dept', 'stage', 'w', 'p', 'f', 'u'];

  /* ---------------- state, kept in the address (#…) so a view can be shared ---------------- */
  var ST = { f: { dept: [], stage: [], w: [], p: [], f: [], u: [] }, day: '', q: '', tab: D.tab || 'worker', sort: null, open: {}, status: 'active', page: 1 };
  (function readHash() {
    var h = location.hash.replace(/^#/, ''); if (!h) return;
    h.split('&').forEach(function (kv) {
      var i = kv.indexOf('='); if (i < 0) return; var k = kv.slice(0, i), v = decodeURIComponent(kv.slice(i + 1));
      if (ST.f[k]) ST.f[k] = v ? v.split('|') : [];
      else if (k === 'day') ST.day = v; else if (k === 'q') ST.q = v; else if (k === 't') ST.tab = v; else if (k === 'st') ST.status = v;
    });
  })();
  function hashStr() {
    var a = [];
    if (PAGE === 'rep') a.push('t=' + ST.tab);
    DORD.forEach(function (k) { if (ST.f[k].length) a.push(k + '=' + encodeURIComponent(ST.f[k].join('|'))); });
    if (ST.day) a.push('day=' + ST.day); if (ST.q) a.push('q=' + encodeURIComponent(ST.q));
    if (PAGE === 'rep' && ST.tab === 'entries' && ST.status !== 'active') a.push('st=' + ST.status);
    return a.join('&');
  }
  function writeHash() {
    var h = hashStr();
    try { history.replaceState(history.state, '', location.pathname + location.search + (h ? '#' + h : '')); } catch (e) {}
    $$('a[data-carry]').forEach(function (a) { var base = a.getAttribute('href').split('#')[0]; a.setAttribute('href', base + (h ? '#' + h : '')); });
  }
  function floorNote() { return D.floors && D.floors.length ? ' · <span style="color:#107e3e;font-weight:600">your floors: ' + esc(D.floors.join(', ')) + '</span>' : ''; }
  function nFilters() { var n = ST.day ? 1 : 0; DORD.forEach(function (k) { n += ST.f[k].length; }); return n + (ST.q ? 1 : 0); }

  /* ---------------- filtering ---------------- */
  function qHit(r, q) {
    var w = W[r[1]], o = O[r[3]], f = F[r[4]];
    return (w[0] + ' ' + w[1] + ' ' + (U[r[13]] ? U[r[13]][0] : '') + ' ' + P[r[2]][0] + ' ' + o[0] + ' ' + o[1] + ' ' + f[0] + ' ' + f[1] + ' ' + L[r[5]][1]).toLowerCase().indexOf(q) >= 0;
  }
  /* every filter but `skip` — so a value list counts what the other filters leave */
  function filtered(opts) {
    opts = opts || {};
    var sets = {}; DORD.forEach(function (k) { if (k !== opts.skip && ST.f[k].length) { var s = {}; ST.f[k].forEach(function (v) { s[v] = 1; }); sets[k] = s; } });
    var dayIx = ST.day && !opts.noDay ? DAYS.indexOf(ST.day) : -1, q = ST.q.toLowerCase().trim();
    return R.filter(function (r) {
      if (!opts.all && r[10]) return false;
      if (dayIx >= 0 && r[0] !== dayIx) return false;
      for (var k in sets) if (!sets[k][DIM[k].key(r)]) return false;
      if (q && !qHit(r, q)) return false;
      return true;
    });
  }

  /* ---------------- grouping ---------------- */
  function group(rows, keyFn) {
    var m = new Map();
    rows.forEach(function (r) {
      var k = keyFn(r), g = m.get(k);
      if (!g) { g = { k: k, q: 0, a: 0, n: 0, w: new Set(), d: new Set(), f: new Set(), l: new Set(), rows: [] }; m.set(k, g); }
      g.q += r[7]; g.a += r[8]; g.n++; g.w.add(r[1]); g.d.add(r[0]); g.f.add(r[4]); g.l.add(r[5]); g.rows.push(r);
    });
    return Array.from(m.values());
  }
  function sum(rows) { var q = 0, a = 0; rows.forEach(function (r) { q += r[7]; a += r[8]; }); return { q: q, a: a }; }
  function measure(g) { return MONEY ? g.a : g.q; }
  function top(gs, n) { return gs.sort(function (a, b) { return measure(b) - measure(a); }).slice(0, n); }
  var NAME = {
    w: function (g) { var w = W[g.k]; return w[1] + (w[0] ? ' (' + w[0] + ')' : ''); },
    p: function (g) { return P[g.k][0]; },
    o: function (g) { var o = O[g.k]; return o[0] + ' · ' + o[1]; },
    f: function (g) { var f = F[g.k]; return f[0] + (f[1] ? ' · ' + f[1] : ''); },
    l: function (g) { var l = L[g.k]; return P[l[2]][0] + (l[1] ? ' · ' + l[1] : ''); },
    s: function (g) { return S[g.k][0]; },
    d: function (g) { return fd(DAYS[g.k], true); },
    dept: function (g) { return g.k; },
    u: function (g) { return U[g.k] ? U[g.k][0] : '(not recorded)'; }
  };
  var KEY = { w: function (r) { return r[1]; }, p: function (r) { return r[2]; }, o: function (r) { return r[3]; }, f: function (r) { return r[4]; },
              l: function (r) { return r[5]; }, s: function (r) { return r[6]; }, d: function (r) { return r[0]; }, dept: function (r) { return W[r[1]][2]; }, u: function (r) { return r[13]; } };

  /* ---------------- flip cards ---------------- */
  function spark(vals, col) {
    if (!vals.length) return '';
    var mx = Math.max.apply(null, vals) || 1, w = 92, h = 30, n = vals.length, step = n > 1 ? w / (n - 1) : w;
    var pts = vals.map(function (v, i) { return (n > 1 ? i * step : w / 2).toFixed(1) + ',' + (h - 2 - v / mx * (h - 5)).toFixed(1); });
    return '<svg class="sp" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none"><polyline fill="none" stroke="' + col + '" stroke-width="1.8" stroke-linejoin="round" points="' + pts.join(' ') + '"/>'
      + '<polygon fill="' + col + '" opacity=".12" points="0,' + h + ' ' + pts.join(' ') + ' ' + w + ',' + h + '"/></svg>';
  }
  function delta(now, was) {
    if (!was) return now ? '<span class="dl up">new</span>' : '';
    var p = (now - was) / was * 100, cls = Math.abs(p) < 0.5 ? 'eq' : p > 0 ? 'up' : 'dn';
    return '<span class="dl ' + cls + '" title="against the ' + esc(fd(D.prev.from) + ' – ' + fd(D.prev.to)) + ' before">' + (p > 0 ? '▲ ' : p < 0 ? '▼ ' : '') + Math.abs(p).toFixed(Math.abs(p) < 10 ? 1 : 0) + '%</span>';
  }
  function backList(items, fmt) {
    if (!items.length) return '<div class="empty">Nothing in this view.</div>';
    var mx = Math.max.apply(null, items.map(function (x) { return x.v; })) || 1;
    return '<ul>' + items.map(function (x) { return '<li><span title="' + esc(x.n) + '">' + esc(x.n) + '</span><b>' + (fmt || fq)(x.v) + '</b><i><em style="width:' + (x.v / mx * 100).toFixed(1) + '%"></em></i></li>'; }).join('') + '</ul>';
  }
  function card(c) {
    return '<div class="fc' + (c.flip ? ' flip' : '') + '" tabindex="0" role="button" aria-label="' + esc(c.label) + ' — click to turn the card" data-card="' + esc(c.id) + '" style="--k:' + (c.col || '#0a6ed1') + '"><div class="in">'
      + '<div class="face front"><div class="lbl">' + esc(c.label) + '<span class="hint" title="Click to see the detail">⟲</span></div>'
      + '<div class="big">' + c.big + (c.unit ? '<small>' + esc(c.unit) + '</small>' : '') + '</div>'
      + '<div class="foot"><span>' + (c.foot || '') + '</span>' + (c.spark || '') + '</div></div>'
      + '<div class="face back"><div class="lbl">' + esc(c.backT || c.label) + '<span class="hint">⟲</span></div>' + c.back + '</div>'
      + '</div></div>';
  }
  var FLIPPED = {};
  function bindCards(el) {
    el.addEventListener('click', function (e) { var c = e.target.closest('.fc'); if (!c) return; c.classList.toggle('flip'); FLIPPED[c.getAttribute('data-card')] = c.classList.contains('flip'); });
    el.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('fc')) { e.preventDefault(); e.target.click(); } });
  }
  function kpiCards(rows, opts) {
    opts = opts || {};
    var t = sum(rows), byDay = new Array(DAYS.length).fill(0), byDayA = new Array(DAYS.length).fill(0);
    rows.forEach(function (r) { byDay[r[0]] += r[7]; byDayA[r[0]] += r[8]; });
    var workDays = byDay.filter(function (v) { return v > 0; }).length, ws = group(rows, KEY.w), fs = group(rows, KEY.f);
    var plain = !nFilters() && D.prev && !opts.noPrev;
    var out = [];
    out.push({ id: 'pcs', label: 'Pieces made', col: '#0a6ed1', big: fq(t.q), unit: 'pcs',
      foot: plain ? delta(t.q, D.prev.qty) + ' vs before' : rows.length + ' entries', spark: spark(byDay, '#0a6ed1'),
      backT: 'By stage', back: backList(SORD.map(function (si) { var q = 0; rows.forEach(function (r) { if (r[6] === si) q += r[7]; }); return { n: S[si][0], v: q }; }).filter(function (x) { return x.v; })) });
    if (MONEY) out.push({ id: 'pkr', label: 'Wage bill', col: '#107e3e', big: f0(t.a), unit: 'PKR',
      foot: plain ? delta(t.a, D.prev.amt) + ' vs before' : 'PKR ' + f0(workDays ? t.a / workDays : 0) + ' a working day', spark: spark(byDayA, '#107e3e'),
      backT: 'By department', back: backList(top(group(rows, KEY.dept), 8).map(function (g) { return { n: g.k, v: g.a }; }), f0) });
    out.push({ id: 'wk', label: 'Workers', col: '#7c48c4', big: f0(ws.length), unit: 'people',
      foot: ws.length ? fq(Math.round(t.q / ws.length)) + ' pcs each' : '—',
      backT: 'Top workers (' + (MONEY ? 'PKR' : 'pcs') + ')', back: backList(top(ws, 8).map(function (g) { return { n: NAME.w(g), v: measure(g) }; }), MONEY ? f0 : fq) });
    var bestDay = -1, lowDay = -1; byDay.forEach(function (v, i) { if (v > 0 && (bestDay < 0 || v > byDay[bestDay])) bestDay = i; if (v > 0 && (lowDay < 0 || v < byDay[lowDay])) lowDay = i; });
    out.push({ id: 'avg', label: 'Average a working day', col: '#e9730c', big: fq(workDays ? Math.round(t.q / workDays) : 0), unit: 'pcs',
      foot: workDays + ' working day' + (workDays === 1 ? '' : 's') + ' of ' + DAYS.length, spark: spark(byDay.slice(-14), '#e9730c'),
      backT: 'Days', back: workDays ? backList([
        { n: 'Best · ' + fd(DAYS[bestDay], true), v: byDay[bestDay] }, { n: 'Quietest · ' + fd(DAYS[lowDay], true), v: byDay[lowDay] }]
        .concat(byDay.map(function (v, i) { return { n: fd(DAYS[i], true), v: v, i: i }; }).filter(function (x) { return x.v > 0; }).slice(-5).reverse())) + '<div style="font-size:11px;opacity:.7;margin-top:4px">' + (DAYS.length - workDays) + ' day(s) with nothing booked</div>' : '<div class="empty">Nothing booked.</div>' });
    out.push({ id: 'ord', label: 'Orders worked', col: '#1a9898', big: f0(fs.length), unit: 'PIs',
      foot: group(rows, KEY.l).length + ' order lines',
      backT: 'Biggest orders (pcs)', back: backList(fs.sort(function (a, b) { return b.q - a.q; }).slice(0, 8).map(function (g) { return { n: NAME.f(g), v: g.q }; })) });
    if (!opts.short) {
      var canc = filtered({ all: true }).filter(function (r) { return r[10]; }), why = group(canc, function (r) { return r[11]; });
      out.push({ id: 'ent', label: 'Entries', col: '#5b6f86', big: f0(rows.length), unit: 'active',
        foot: canc.length ? '<span style="color:#bb0000">' + canc.length + ' cancelled</span>' : 'none cancelled',
        backT: 'Cancelled — why', back: canc.length ? backList(why.sort(function (a, b) { return b.n - a.n; }).slice(0, 8).map(function (g) { return { n: X[g.k] ? X[g.k][0] : '(no reason given)', v: g.n }; }), f0) : '<div class="empty">No entry was cancelled in this view.</div>' });
    }
    return out;
  }

  /* ---------------- the filter bar ---------------- */
  var FB = $('#zprF');
  function fieldLabel(k) { var n = ST.f[k].length; if (!n) return 'All'; if (n === 1) return DIM[k].lbl(ST.f[k][0]); return n + ' chosen'; }
  function drawFilterBar() {
    if (!FB) return;
    var flds = DORD.map(function (k) {
      return '<div class="fld"><span>' + DIM[k].t + '</span><button type="button" class="vh' + (ST.f[k].length ? ' set' : '') + '" data-vh="' + k + '" aria-haspopup="listbox">' + esc(fieldLabel(k)) + '</button></div>';
    }).join('') + '<div class="fld" style="max-width:300px;flex-basis:220px"><span>Search</span><input class="q" id="zprQ" type="search" placeholder="Name, code, PI, product, operation…" value="' + esc(ST.q) + '"></div>';
    $('#zprFB').innerHTML = flds;
    drawTokensOnly();
  }
  var POP = null;
  function closePop() { if (POP) { POP.remove(); POP = null; $$('.vh.open').forEach(function (b) { b.classList.remove('open'); }); } }
  function openPop(btn, k) {
    closePop(); btn.classList.add('open');
    var cnt = {}; filtered({ skip: k }).forEach(function (r) { var v = DIM[k].key(r); cnt[v] = (cnt[v] || 0) + (MONEY && k !== 'stage' ? r[8] : r[7]); });
    ST.f[k].forEach(function (v) { if (!(v in cnt)) cnt[v] = 0; });
    var opts = Object.keys(cnt).map(function (v) { return { v: v, l: DIM[k].lbl(v), n: cnt[v] }; }).sort(function (a, b) { return b.n - a.n || (a.l < b.l ? -1 : 1); });
    var p = document.createElement('div'); p.className = 'pop'; p.setAttribute('role', 'listbox');
    p.innerHTML = '<input type="search" placeholder="Find…"><div class="lst"></div><div class="pf"><button type="button" class="btn sm ghost" data-a="none">Clear</button><button type="button" class="btn sm pri" data-a="ok">Done</button></div>';
    var on = {}; ST.f[k].forEach(function (v) { on[v] = 1; });
    function list(q) {
      q = (q || '').toLowerCase();
      $('.lst', p).innerHTML = opts.filter(function (o) { return !q || o.l.toLowerCase().indexOf(q) >= 0; }).slice(0, 400).map(function (o) {
        return '<label><input type="checkbox" value="' + esc(o.v) + '"' + (on[o.v] ? ' checked' : '') + '> <span>' + esc(o.l) + '</span><small>' + (MONEY && k !== 'stage' ? f0(o.n) : fq(o.n)) + '</small></label>';
      }).join('') || '<div class="empty" style="padding:12px">Nothing matches.</div>';
    }
    list('');
    btn.parentNode.appendChild(p); POP = p;
    var inp = $('input[type=search]', p); inp.focus();
    inp.addEventListener('input', function () { list(inp.value); });
    p.addEventListener('change', function (e) { var c = e.target; if (c.type !== 'checkbox') return; if (c.checked) on[c.value] = 1; else delete on[c.value]; ST.f[k] = Object.keys(on); refresh(true); });
    p.addEventListener('click', function (e) {
      var a = e.target.closest('[data-a]'); if (!a) return;
      if (a.getAttribute('data-a') === 'none') { on = {}; ST.f[k] = []; list(inp.value); refresh(true); } else { closePop(); drawFilterBar(); }
    });
  }
  if (FB) {
    $('#zprFH').addEventListener('click', function () { FB.classList.toggle('closed'); store('zpr_fbar', FB.classList.contains('closed') ? '0' : '1'); });
    var fpref = store('zpr_fbar');
    if (fpref === '0' || (fpref === null && window.innerWidth < 700 && !nFilters())) FB.classList.add('closed');   // a phone opens on the cards
    FB.addEventListener('click', function (e) {
      var b = e.target.closest('[data-vh]'); if (b) { e.stopPropagation(); if (b.classList.contains('open')) { closePop(); drawFilterBar(); } else openPop(b, b.getAttribute('data-vh')); return; }
      var rm = e.target.closest('[data-rm]'); if (!rm) return;
      var k = rm.getAttribute('data-rm');
      if (k === 'all') { DORD.forEach(function (x) { ST.f[x] = []; }); ST.day = ''; ST.q = ''; }
      else if (k === 'day') ST.day = ''; else if (k === 'q') ST.q = '';
      else ST.f[k] = ST.f[k].filter(function (v) { return v !== rm.getAttribute('data-v'); });
      refresh();
    });
    var qt = null;
    FB.addEventListener('input', function (e) { if (e.target.id !== 'zprQ') return; clearTimeout(qt); qt = setTimeout(function () { ST.q = e.target.value; refresh(true, true); }, 220); });
    document.addEventListener('click', function (e) { if (POP && !e.target.closest('.pop') && !e.target.closest('[data-vh]')) { closePop(); drawFilterBar(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { if (POP) { closePop(); drawFilterBar(); } else closeSide(); } });
  }
  function toggleFilter(k, v) { var i = ST.f[k].indexOf(v); if (i < 0) ST.f[k].push(v); else ST.f[k].splice(i, 1); refresh(); }

  /* ---------------- sections that open and close (remembered) ---------------- */
  root.addEventListener('click', function (e) {
    var h = e.target.closest('.sec>.sh'); if (!h || e.target.closest('.tools') || h.closest('.side')) return;
    var s = h.parentNode; s.classList.toggle('closed'); store('zpr_sec_' + s.id, s.classList.contains('closed') ? '0' : '1');
  });
  $$('.sec[id]').forEach(function (s) { if (store('zpr_sec_' + s.id) === '0') s.classList.add('closed'); });

  /* ---------------- horizontal bars ---------------- */
  function hbars(items, fmt, cls) {
    if (!items.length) return '<div class="empty">Nothing in this view.</div>';
    var mx = Math.max.apply(null, items.map(function (x) { return x.v; })) || 1, tot = items.reduce(function (s, x) { return s + x.v; }, 0) || 1;
    return '<div class="hb">' + items.map(function (x) {
      return '<div class="nm" data-' + cls + '="' + esc(x.k) + '" title="' + esc(x.n) + '">' + esc(x.n) + '</div><div class="tr" data-' + cls + '="' + esc(x.k) + '"><em data-w="' + (x.v / mx * 100).toFixed(1) + '"' + (x.c ? ' style="--k:' + x.c + '"' : '') + '></em></div>'
        + '<div class="v">' + (fmt || fq)(x.v) + '<small>' + (x.v / tot * 100).toFixed(0) + '%</small></div>';
    }).join('') + '</div>';
  }
  function grow(el) { requestAnimationFrame(function () { requestAnimationFrame(function () { $$('em[data-w]', el).forEach(function (e) { e.style.width = e.getAttribute('data-w') + '%'; }); }); }); }

  /* ---------------- the day chart ---------------- */
  var HIDE = {};
  function chart(el, rows) {
    var wrap = el.querySelector('.chart'), legend = el.querySelector('.legend');
    var stacks = DAYS.map(function () { return {}; }), tot = new Array(DAYS.length).fill(0), amt = new Array(DAYS.length).fill(0);
    rows.forEach(function (r) { if (HIDE[r[6]]) return; stacks[r[0]][r[6]] = (stacks[r[0]][r[6]] || 0) + r[7]; tot[r[0]] += r[7]; amt[r[0]] += r[8]; });
    var mx = Math.max.apply(null, tot) || 1, nice = Math.pow(10, Math.floor(Math.log10(mx))), top = Math.ceil(mx / nice) * nice;
    if (top / nice <= 2) top = Math.ceil(mx / (nice / 4)) * (nice / 4);
    var Wd = Math.max(320, wrap.clientWidth || 800), H = 230, pl = 46, pr = 8, pt = 10, pb = 26, cw = (Wd - pl - pr) / DAYS.length, bw = Math.max(2, Math.min(34, cw * 0.66));
    var y = function (v) { return pt + (H - pt - pb) * (1 - v / top); };
    var s = '<svg viewBox="0 0 ' + Wd + ' ' + H + '" role="img" aria-label="Pieces per day">';
    for (var g = 0; g <= 4; g++) { var v = top * g / 4, yy = y(v).toFixed(1); s += '<line x1="' + pl + '" x2="' + (Wd - pr) + '" y1="' + yy + '" y2="' + yy + '" stroke="#eef1f4"/><text x="' + (pl - 6) + '" y="' + (+yy + 4) + '" text-anchor="end" font-size="10.5" fill="#8396ab">' + (v >= 1000 ? (v / 1000).toLocaleString('en-US', { maximumFractionDigits: 1 }) + 'k' : f0(v)) + '</text>'; }
    var every = Math.ceil(DAYS.length / Math.max(1, Math.floor((Wd - pl) / 62)));
    DAYS.forEach(function (d, i) {
      var x = pl + i * cw + (cw - bw) / 2, acc = 0, sel = ST.day === d, dd = dt(d), sun = dd.getDay() === 0;
      s += '<g class="col' + (sel ? ' sel' : '') + '" data-i="' + i + '"><rect class="hit" x="' + (pl + i * cw) + '" y="' + pt + '" width="' + cw + '" height="' + (H - pt - pb) + '" fill="' + (sun ? 'rgba(0,0,0,.025)' : 'transparent') + '"/>';
      SORD.forEach(function (si) { var q = stacks[i][si]; if (!q) return; var y0 = y(acc + q), hgt = y(acc) - y0; acc += q;
        s += '<rect class="c grow" style="animation-delay:' + Math.min(i * 12, 500) + 'ms" x="' + x.toFixed(1) + '" y="' + y0.toFixed(1) + '" width="' + bw.toFixed(1) + '" height="' + Math.max(0.5, hgt).toFixed(1) + '" fill="' + SCOL[si] + '" rx="1.5"/>'; });
      if (!tot[i]) s += '<rect x="' + x.toFixed(1) + '" y="' + (y(0) - 2) + '" width="' + bw.toFixed(1) + '" height="2" fill="#e3e8ee"/>';
      s += '</g>';
      if (i % every === 0 || i === DAYS.length - 1) s += '<text x="' + (pl + i * cw + cw / 2).toFixed(1) + '" y="' + (H - 8) + '" text-anchor="middle" font-size="10.5" fill="' + (sel ? '#0a6ed1' : '#8396ab') + '">' + dd.getDate() + ' ' + MON[dd.getMonth()] + '</text>';
    });
    s += '</svg><div class="tip"></div>';
    wrap.innerHTML = s;
    legend.innerHTML = SORD.filter(function (si) { return rows.some(function (r) { return r[6] === si; }) || HIDE[si]; })
      .map(function (si) { return '<button type="button" data-hide="' + si + '" class="' + (HIDE[si] ? 'off' : '') + '"><i style="background:' + SCOL[si] + '"></i>' + esc(S[si][0]) + '</button>'; }).join('')
      + '<span style="margin-left:auto;font-size:11.5px;color:#8396ab">Click a day to see only that day · peak ' + fq(mx) + ' pcs</span>';
    var tip = wrap.querySelector('.tip');
    wrap.onmousemove = function (e) {
      var gg = e.target.closest('g.col'); if (!gg) { tip.classList.remove('on'); return; }
      var i = +gg.getAttribute('data-i'), h = '<b>' + fd(DAYS[i], true) + '</b><br>';
      SORD.forEach(function (si) { if (stacks[i][si]) h += '<i style="background:' + SCOL[si] + '"></i>' + esc(S[si][0]) + ': ' + fq(stacks[i][si]) + '<br>'; });
      h += '<b>' + fq(tot[i]) + ' pcs</b>' + (MONEY ? ' · PKR ' + f0(amt[i]) : '') + (tot[i] ? '' : ' — nothing booked');
      tip.innerHTML = h; tip.classList.add('on');
      var b = wrap.getBoundingClientRect(), x = e.clientX - b.left + 14; if (x + tip.offsetWidth > b.width) x = e.clientX - b.left - tip.offsetWidth - 14;
      tip.style.left = x + 'px'; tip.style.top = Math.max(0, e.clientY - b.top - 20) + 'px';
    };
    wrap.onmouseleave = function () { tip.classList.remove('on'); };
    wrap.onclick = function (e) { var gg = e.target.closest('g.col'); if (!gg) return; var d = DAYS[+gg.getAttribute('data-i')]; ST.day = ST.day === d ? '' : d; refresh(); };
    legend.onclick = function (e) { var b = e.target.closest('[data-hide]'); if (!b) return; var si = +b.getAttribute('data-hide'); if (HIDE[si]) delete HIDE[si]; else HIDE[si] = 1; chart(el, rows); };
  }

  /* ---------------- the side panel: one worker, order, product… in detail ---------------- */
  var SIDE = $('#zprSide'), OV = $('#zprOv');
  function closeSide() { if (SIDE) { SIDE.classList.remove('on'); OV.classList.remove('on'); } }
  if (OV) OV.addEventListener('click', closeSide);
  function miniTable(gs, nameFn, cols) {
    if (!gs.length) return '<div class="empty">Nothing.</div>';
    return '<div class="tw" style="max-height:none;border-top:0"><table class="rt"><thead><tr><th>' + esc(cols[0]) + '</th><th class="num">Pieces</th>' + (MONEY ? '<th class="num">PKR</th>' : '') + '<th class="num">Entries</th></tr></thead><tbody>'
      + gs.map(function (g) { return '<tr><td>' + esc(nameFn(g)) + '</td><td class="num">' + fq(g.q) + '</td>' + (MONEY ? '<td class="num">' + fm(g.a) + '</td>' : '') + '<td class="num">' + g.n + '</td></tr>'; }).join('')
      + '</tbody></table></div>';
  }
  function openSide(kind, k) {
    if (!SIDE) return;
    var rows = filtered().filter(function (r) { return String(KEY[kind](r)) === String(k); });
    var title, sub, parts;
    if (kind === 'w') { var w = W[k]; title = w[1]; sub = (w[0] ? 'Code ' + w[0] + ' · ' : '') + w[2]; parts = [['d', 'Day by day', 'Day'], ['o', 'Operations', 'Part · operation'], ['f', 'Orders', 'Order']]; }
    else if (kind === 'f') { var f = F[k]; title = f[0]; sub = f[1] || ''; parts = [['l', 'Lines', 'Product · size'], ['o', 'Operations', 'Part · operation'], ['w', 'Workers', 'Worker']]; }
    else if (kind === 'p') { title = P[k][0]; sub = 'Product'; parts = [['o', 'Operations', 'Part · operation'], ['f', 'Orders', 'Order'], ['w', 'Workers', 'Worker']]; }
    else if (kind === 'o') { var o = O[k]; title = o[1]; sub = o[0] + ' · ' + S[o[2]][0]; parts = [['w', 'Workers', 'Worker'], ['f', 'Orders', 'Order'], ['d', 'Day by day', 'Day']]; }
    else if (kind === 'u') { title = U[k][0]; sub = 'Data entry (who typed it)'; parts = [['w', 'Workers', 'Worker'], ['d', 'Day by day', 'Day'], ['o', 'Operations', 'Part · operation']]; }
    else { title = k; sub = 'Department'; parts = [['w', 'Workers', 'Worker'], ['o', 'Operations', 'Part · operation'], ['d', 'Day by day', 'Day']]; }
    var t = sum(rows), days = new Set(rows.map(function (r) { return r[0]; })).size, byDay = new Array(DAYS.length).fill(0); rows.forEach(function (r) { byDay[r[0]] += r[7]; });
    var cs = [{ id: 's1', label: 'Pieces', col: '#0a6ed1', big: fq(t.q), unit: 'pcs', foot: rows.length + ' entries', spark: spark(byDay, '#0a6ed1'), backT: 'By stage',
                back: backList(SORD.map(function (si) { var q = 0; rows.forEach(function (r) { if (r[6] === si) q += r[7]; }); return { n: S[si][0], v: q }; }).filter(function (x) { return x.v; })) }];
    if (MONEY) cs.push({ id: 's2', label: kind === 'w' ? 'Earned' : 'Wages', col: '#107e3e', big: f0(t.a), unit: 'PKR', foot: days ? 'PKR ' + f0(t.a / days) + ' a day' : '', backT: 'Rates paid',
      back: backList(group(rows, function (r) { return r[9]; }).sort(function (a, b) { return b.q - a.q; }).slice(0, 8).map(function (g) { return { n: 'PKR ' + fm(g.k) + ' / pc', v: g.q }; })) });
    cs.push({ id: 's3', label: 'Days worked', col: '#e9730c', big: f0(days), unit: 'of ' + DAYS.length, foot: days ? fq(Math.round(t.q / days)) + ' pcs a day' : '', backT: 'Last days',
      back: backList(byDay.map(function (v, i) { return { n: fd(DAYS[i], true), v: v }; }).filter(function (x) { return x.v; }).slice(-8).reverse()) });
    var people = group(rows, KEY.w);
    cs.push(kind === 'w'
      ? { id: 's4', label: 'Orders', col: '#1a9898', big: f0(group(rows, KEY.f).length), unit: 'PIs', foot: group(rows, KEY.o).length + ' operations', backT: 'Orders (pcs)', back: backList(group(rows, KEY.f).sort(function (a, b) { return b.q - a.q; }).slice(0, 8).map(function (g) { return { n: NAME.f(g), v: g.q }; })) }
      : { id: 's4', label: 'Workers', col: '#7c48c4', big: f0(people.length), unit: 'people', foot: people.length ? fq(Math.round(t.q / people.length)) + ' pcs each' : '', backT: 'Top workers', back: backList(top(people, 8).map(function (g) { return { n: NAME.w(g), v: measure(g) }; }), MONEY ? f0 : fq) });
    SIDE.innerHTML = '<div class="sh2"><div><h3>' + esc(title) + '</h3><div class="s">' + esc(sub) + ' · ' + esc(fd(D.from) + ' – ' + fd(D.to)) + (nFilters() ? ' · filters on' : '') + '</div></div><button type="button" title="Close (Esc)">✕</button></div>'
      + '<div class="sb2"><div class="cards">' + cs.map(card).join('') + '</div>'
      + parts.map(function (p, i) {
          var gs = group(rows, KEY[p[0]]); gs.sort(p[0] === 'd' ? function (a, b) { return b.k - a.k; } : function (a, b) { return measure(b) - measure(a); });
          return '<div class="sec' + (i ? ' closed' : '') + '"><div class="sh"><span class="chev">▾</span><h2>' + p[1] + '</h2><span class="s2">' + gs.length + '</span></div><div class="fold"><div><div class="sb">' + miniTable(gs, NAME[p[0]], [p[2]]) + '</div></div></div></div>';
        }).join('') + '</div>';
    $('.sh2 button', SIDE).onclick = closeSide;
    SIDE.classList.add('on'); OV.classList.add('on');
  }
  if (SIDE) { bindCards(SIDE); SIDE.addEventListener('click', function (e) { var h = e.target.closest('.sec>.sh'); if (h) h.parentNode.classList.toggle('closed'); }); }

  /* ======================= THE DASHBOARD ======================= */
  function drawDash() {
    var rows = filtered(), rowsAllDays = filtered({ noDay: true });
    var cs = kpiCards(rows);
    if (D.fin && D.fin.length) { var fc = finCards(finList())[0]; fc.id = 'fin'; fc.label = 'Finished products'; fc.backT = 'Whole products — done / ordered'; cs.splice(1, 0, fc); }
    var kc = $('#zprK'); kc.innerHTML = cs.map(function (c) { c.flip = FLIPPED[c.id]; return card(c); }).join('');
    /* one card per stage */
    var tq = sum(rows).q || 1, sc = SORD.map(function (si) {
      var sr = rows.filter(function (r) { return r[6] === si; }); if (!sr.length) return null;
      var t = sum(sr), ops = top(group(sr, KEY.o), 7);
      return card({ id: 'st' + si, flip: FLIPPED['st' + si], label: S[si][0], col: SCOL[si], big: fq(t.q), unit: 'pcs', foot: (t.q / tq * 100).toFixed(0) + '% of all pieces' + (MONEY ? ' · PKR ' + f0(t.a) : ''),
        spark: spark(DAYS.map(function (d, i) { var q = 0; sr.forEach(function (r) { if (r[0] === i) q += r[7]; }); return q; }), SCOL[si]),
        backT: 'Top operations', back: backList(ops.map(function (g) { return { n: NAME.o(g), v: g.q }; })) });
    }).filter(Boolean);
    $('#zprSC').innerHTML = sc.join('') || '<div class="empty" style="grid-column:1/-1">Nothing booked in this view.</div>';
    chart($('#secDay'), rowsAllDays);
    var dep = group(rows, KEY.dept).map(function (g) { return { k: g.k, n: g.k, v: measure(g) }; }).sort(function (a, b) { return b.v - a.v; });
    $('#dDept').innerHTML = hbars(dep, MONEY ? f0 : fq, 'dept'); grow($('#dDept'));
    var wk = top(group(rows, KEY.w), 12).map(function (g) { return { k: g.k, n: NAME.w(g), v: measure(g) }; });
    $('#dWk').innerHTML = hbars(wk, MONEY ? f0 : fq, 'wk'); grow($('#dWk'));
    var pr = top(group(rows, KEY.p), 10).map(function (g) { return { k: P[g.k][1], n: P[g.k][0], v: measure(g), c: '#1a9898' }; });
    $('#dPr').innerHTML = hbars(pr, MONEY ? f0 : fq, 'pr'); grow($('#dPr'));
    var op = top(group(rows, KEY.o), 10).map(function (g) { return { k: g.k, n: NAME.o(g), v: measure(g), c: SCOL[O[g.k][2]] }; });
    $('#dOp').innerHTML = hbars(op, MONEY ? f0 : fq, 'op'); grow($('#dOp'));
    drawOrders();
    $('#zprSub').innerHTML = '<b>' + esc(fd(D.from, true) + ' – ' + fd(D.to, true)) + '</b> · ' + rows.length + ' entries' + (nFilters() ? ' · <span style="color:#0a6ed1">' + nFilters() + ' filter(s) on</span>' : '') + (MONEY ? '' : ' · wages hidden for your login') + floorNote();
  }
  var ORDALL = false;
  function drawOrders() {
    var el = $('#dOrd'); if (!el) return;
    var list = (D.orders || []).filter(function (o) { return !ST.f.f.length || ST.f.f.indexOf(String(o.proforma_id)) >= 0; });
    var q = ST.q.toLowerCase().trim(); if (q) list = list.filter(function (o) { return (o.pi_no + ' ' + o.customer + ' ' + o.product + ' ' + o.size).toLowerCase().indexOf(q) >= 0; });
    var n = ORDALL ? list.length : Math.min(6, list.length);
    el.innerHTML = list.length ? list.slice(0, n).map(function (o, i) {
      return '<a class="ord' + (i >= 6 ? ' extra' : '') + '" href="production_orders.php?order=' + o.proforma_id + '"><div class="top"><div><div class="nm">' + esc(o.product) + (o.size ? ' · ' + esc(o.size) : '') + '</div><div class="s">' + esc(o.pi_no) + ' · ' + esc(o.customer || '—') + ' · ' + fq(o.ordered) + ' ordered</div></div><div class="pc">' + Number(o.pct).toFixed(1) + '%</div></div>'
        + '<div class="bar"><em data-w="' + Math.max(0, Math.min(100, o.pct)) + '" class="' + (o.pct < 50 ? 'late' : '') + '"></em></div>' + (o.behind && o.behind.length ? '<div class="w">Waiting on ' + esc(o.behind.slice(0, 3).join(', ')) + (o.behind.length > 3 ? ' and ' + (o.behind.length - 3) + ' more' : '') + '</div>' : '') + '</a>';
    }).join('') + (list.length > 6 ? '<button type="button" class="btn sm more" id="ordMore">' + (ORDALL ? 'Show fewer' : 'Show all ' + list.length + ' lines') + '</button>' : '')
      : '<div class="empty">' + ((D.orders || []).length ? 'No open order matches the filter.' : 'No order is switched on for production yet.') + '</div>';
    grow(el);
    var m = $('#ordMore'); if (m) m.onclick = function () { ORDALL = !ORDALL; drawOrders(); };
  }
  if (PAGE === 'dash') {
    bindCards($('#zprK')); bindCards($('#zprSC'));
    root.addEventListener('click', function (e) {
      var d = e.target.closest('[data-dept]'); if (d) { toggleFilter('dept', d.getAttribute('data-dept')); return; }
      var w = e.target.closest('[data-wk]'); if (w) { openSide('w', +w.getAttribute('data-wk')); return; }
      var p = e.target.closest('[data-pr]'); if (p) { toggleFilter('p', p.getAttribute('data-pr')); return; }
      var o = e.target.closest('[data-op]'); if (o) { openSide('o', +o.getAttribute('data-op')); return; }
    });
    var flipAll = $('#zprFlip'); if (flipAll) flipAll.onclick = function () { var any = $$('#zprK .fc:not(.flip), #zprSC .fc:not(.flip)').length; $$('#zprK .fc, #zprSC .fc').forEach(function (c) { c.classList.toggle('flip', !!any); FLIPPED[c.getAttribute('data-card')] = !!any; }); };
    var rt = null; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(function () { chart($('#secDay'), filtered({ noDay: true })); }, 150); });
  }

  /* ======================= THE REPORTS ======================= */
  var VIEWS = {
    worker:  { t: 'By worker',     g: 'w',    drill: ['o'], side: 'w' },
    dept:    { t: 'By department', g: 'dept', drill: ['w'], side: 'dept' },
    product: { t: 'By product',    g: 'p',    drill: ['o', 'w'], side: 'p' },
    order:   { t: 'By order',      g: 'f',    drill: ['l', 'o'], side: 'f' },
    op:      { t: 'By operation',  g: 'o',    drill: ['w'], side: 'o' },
    daily:   { t: 'Day by day',    g: 'd',    drill: ['s', 'w'] },
    user:    { t: 'By data entry', g: 'u',    drill: ['w', 'd'], side: 'u' },
    entries: { t: 'Every entry' },
    fin:     { t: 'Finished products' }
  };
  function colsFor(tab) {
    var C = [];
    var c = function (k, t, o) { o = o || {}; o.k = k; o.t = t; C.push(o); };
    if (tab === 'user') { c('name', 'Entered by'); c('wk', 'Workers', { n: 1, d: 1 }); c('days', 'Days', { n: 1, d: 1 }); }
    else if (tab === 'worker') { c('name', 'Worker'); c('code', 'Code'); c('dept', 'Department'); c('days', 'Days', { n: 1, d: 1 }); }
    else if (tab === 'dept') { c('name', 'Department'); c('wk', 'Workers', { n: 1, d: 1 }); c('days', 'Days', { n: 1, d: 1 }); }
    else if (tab === 'product') { c('name', 'Product'); c('ord', 'Orders', { n: 1, d: 1 }); c('wk', 'Workers', { n: 1, d: 1 }); }
    else if (tab === 'order') { c('name', 'Order (PI)'); c('cust', 'Customer'); c('lines', 'Lines', { n: 1, d: 1 }); c('wk', 'Workers', { n: 1, d: 1 }); }
    else if (tab === 'op') { c('name', 'Operation'); c('part', 'Part'); c('stage', 'Stage'); c('wk', 'Workers', { n: 1, d: 1 }); }
    else if (tab === 'daily') { c('name', 'Date'); SORD.forEach(function (si) { c('s' + si, S[si][0], { n: 1, s: 1 }); }); c('wk', 'Workers', { n: 1, d: 1 }); }
    c('n', 'Entries', { n: 1, s: 1 }); c('q', 'Pieces', { n: 1, s: 1 });
    if (MONEY) { c('a', 'Wages (PKR)', { n: 1, s: 1, m: 1 }); if (tab === 'op' || tab === 'product') c('rate', 'Avg rate', { n: 1, m: 1 }); if (tab === 'worker' || tab === 'dept') c('avg', tab === 'worker' ? 'Avg / day' : 'Avg / worker', { n: 1, m: 1 }); }
    c('share', 'Share', { bar: 1 });
    return C;
  }
  /* ======================= FINISHED PRODUCTS =======================
     Whole products, not operations: a duvet set of 300 is 300, however many
     operations it went through. Built from D.fin (includes/zp_report.php). */
  var FING = 'line';
  function madeIn(item) { var s = 0; (D.A || []).forEach(function (a) { if (a[1] === item && (!ST.day || DAYS[a[0]] === ST.day)) s += a[2]; }); return s; }
  function finList() {
    var q = (ST.q + ' ' + TQ).toLowerCase().trim().split(/\s+/).filter(Boolean), pS = ST.f.p, fS = ST.f.f;
    return (D.fin || []).filter(function (o) {
      if (pS.length && pS.indexOf(String(o.pid)) < 0) return false;
      if (fS.length && fS.indexOf(String(o.f)) < 0) return false;
      var t = (o.pi + ' ' + o.cust + ' ' + o.prod + ' ' + o.size + ' ' + o.desc).toLowerCase();
      return q.every(function (w) { return t.indexOf(w) >= 0; });
    });
  }
  function finStageNames(list) {
    var names = []; list.forEach(function (o) { o.stages.forEach(function (s) { if (names.indexOf(s[0]) < 0) names.push(s[0]); }); });
    var ord = D.stageNames || []; return names.sort(function (a, b) { var x = ord.indexOf(a), y = ord.indexOf(b); return (x < 0 ? 99 : x) - (y < 0 ? 99 : y); });
  }
  function finGroup(list, by) {
    if (by === 'line') return list.map(function (o) { var c = Object.assign({}, o); c.made = madeIn(o.item); c.key = 'l' + o.item; c.kids = null; return c; });
    var m = {}, out = [];
    list.forEach(function (o) {
      var k = by === 'prod' ? 'p' + o.pid : 'f' + o.f, g = m[k];
      if (!g) { g = m[k] = { key: k, kids: [], pi: o.pi, cust: o.cust, prod: o.prod, pid: o.pid, f: o.f, size: '', desc: '', ord: 0, ready: 0, sets: 0, done: 0, left: 0, made: 0, set: false, stages: [], behind: '', lines: 0 }; out.push(g); }
      g.ord += o.ord; g.ready += o.ready; g.sets += o.sets; g.done += o.done; g.left += o.left; g.made += madeIn(o.item); g.set = g.set || o.set; g.lines++;
      o.stages.forEach(function (s) { var x = null; g.stages.forEach(function (y) { if (y[0] === s[0]) x = y; }); if (!x) { x = [s[0], 0, 0]; g.stages.push(x); } x[1] += s[1]; x[2] += s[2]; });
      if (!g.behind && o.behind) g.behind = o.behind;
      g.kids.push(o);
    });
    out.forEach(function (g) { g.pct = g.ord ? Math.min(100, g.done / g.ord * 100) : 0; });
    return out;
  }
  function stCell(st, name) {
    var x = null; (st || []).forEach(function (y) { if (y[0] === name) x = y; });
    if (!x || !x[1]) return '<td class="num" style="color:#c2cbd6">—</td>';
    var p = Math.min(100, x[2] / x[1] * 100);
    return '<td class="num fst"><div class="fbx"><em style="width:' + p.toFixed(1) + '%;background:' + (p >= 99.95 ? '#107e3e' : p < 50 ? '#e9730c' : '#0a6ed1') + '"></em></div><b>' + p.toFixed(p >= 99.95 || p === 0 ? 0 : 1) + '%</b><small>' + fq(x[2]) + ' / ' + fq(x[1]) + '</small></td>';
  }
  function finCards(list) {
    var t = { ord: 0, done: 0, ready: 0, sets: 0, made: 0, behind: 0, extra: 0 }, byP = {};
    list.forEach(function (o) { var mi = madeIn(o.item); t.ord += o.ord; t.done += o.done; t.made += mi; if (o.pct < 50) t.behind++; if (o.set) t.extra += Math.max(0, o.ready - o.sets);
      var b = byP[o.prod] || (byP[o.prod] = { n: o.prod, a: 0, b: 0, m: 0, x: 0 }); b.a += o.done; b.b += o.ord; b.m += mi; if (o.set) b.x += Math.max(0, o.ready - o.sets); });
    var prods = Object.keys(byP).map(function (k) { return byP[k]; }).sort(function (a, b) { return b.b - a.b; });
    var pctList = function (items) { return items.length ? '<ul>' + items.slice(0, 8).map(function (x) { var p = x.b ? x.a / x.b * 100 : 0; return '<li><span title="' + esc(x.n) + '">' + esc(x.n) + '</span><b>' + fq(x.a) + ' / ' + fq(x.b) + '</b><i><em style="width:' + Math.min(100, p).toFixed(1) + '%"></em></i></li>'; }).join('') + '</ul>' : '<div class="empty">Nothing in this view.</div>'; };
    var worst = list.slice().sort(function (a, b) { return a.pct - b.pct; });
    return [
      { id: 'f1', label: 'Finished (whole products)', col: '#107e3e', big: fq(t.done), unit: 'of ' + fq(t.ord), foot: (t.ord ? (t.done / t.ord * 100).toFixed(1) : '0') + '% of what is ordered · ' + fq(Math.max(0, t.ord - t.done)) + ' left',
        backT: 'By product (done / ordered)', back: pctList(prods.map(function (p) { return { n: p.n, a: p.a, b: p.b }; })) },
      { id: 'f2', label: 'Ready to make into sets', col: '#0a6ed1', big: fq(t.extra), unit: 'sets', foot: 'every part through its last stage, not yet on Make Sets',
        backT: 'Ready, by product', back: backList(prods.filter(function (p) { return p.x > 0; }).map(function (p) { return { n: p.n, v: p.x }; })) },
      { id: 'f3', label: 'Made in this period', col: '#1a9898', big: fq(t.made), unit: 'sets', foot: esc(fd(D.from) + ' – ' + fd(D.to)) + ' · Make Sets',
        backT: 'Made, by product', back: backList(prods.filter(function (p) { return p.m > 0; }).map(function (p) { return { n: p.n, v: p.m }; })) },
      { id: 'f4', label: 'Lines under 50%', col: '#e9730c', big: f0(t.behind), unit: 'of ' + list.length + ' lines', foot: 'open order lines, worst first on the back',
        backT: 'Furthest behind', back: pctList(worst.slice(0, 8).map(function (o) { return { n: o.prod + (o.size ? ' · ' + o.size : '') + ' — ' + o.pi, a: o.done, b: o.ord }; })) }
    ];
  }
  function drawFinished() {
    var list = finList();
    $('#zprK').innerHTML = finCards(list).map(function (c) { c.flip = FLIPPED[c.id]; return card(c); }).join('');
    $('#zprSub').innerHTML = '<b>Whole products on open orders</b> · order to date · "Made in this period" uses ' + esc(fd(D.from, true) + ' – ' + fd(D.to, true)) + (ST.f.p.length || ST.f.f.length || ST.q ? ' · <span style="color:#0a6ed1">filtered</span>' : '');
    $('#zprStat').hidden = true;
    var names = finStageNames(list), gs = finGroup(list, FING);
    var so = ST.sort && ST.sort.tab === 'fin' ? ST.sort : { k: 'pct', dir: 1 };
    var val = function (g, k) { if (k.indexOf('st:') === 0) { var x = null; g.stages.forEach(function (y) { if (y[0] === k.slice(3)) x = y; }); return x && x[1] ? x[2] / x[1] : -1; }
      return k === 'name' ? (FING === 'ord' ? g.pi : g.prod + ' ' + g.size) : g[k]; };
    gs.sort(function (a, b) { var x = val(a, so.k), y = val(b, so.k); return so.dir * (typeof x === 'string' ? x.localeCompare(y) : (x || 0) - (y || 0)); });
    var cols = [['name', FING === 'ord' ? 'Order (PI)' : FING === 'prod' ? 'Product' : 'Product · size'], ['ord', 'Ordered', 1]]
      .concat(names.map(function (n) { return ['st:' + n, n, 1]; }))
      .concat([['ready', 'Ready', 1], ['sets', 'Sets made', 1], ['made', 'Made in period', 1], ['left', 'Left', 1], ['pct', 'Done', 1], ['behind', 'Waiting on']]);
    function label(g, lvl) {
      if (FING === 'ord' && lvl === 0) return '<span class="lk" data-fside="f|' + g.f + '">' + esc(g.pi) + '</span><small class="fsub">' + esc(g.cust || '') + ' · ' + g.lines + ' line' + (g.lines === 1 ? '' : 's') + '</small>';
      if (FING === 'prod' && lvl === 0) return '<b>' + esc(g.prod) + '</b><small class="fsub">' + g.lines + ' line' + (g.lines === 1 ? '' : 's') + '</small>';
      return '<b>' + esc(g.prod) + (g.size ? ' · ' + esc(g.size) : '') + '</b><small class="fsub">' + esc(g.pi) + (g.cust ? ' · ' + esc(g.cust) : '') + (g.desc ? ' · ' + esc(g.desc) : '') + '</small>';
    }
    function row(g, lvl, key, hasKids) {
      var open = ST.open['fin:' + key], pct = g.pct;
      var tg = hasKids ? '<button type="button" class="tg' + (open ? ' open' : '') + '" data-ftg="' + esc(key) + '" title="Show the detail">▸</button>' : '<span class="sp0"></span>';
      var h = '<tr class="' + (lvl ? 'g' + lvl : '') + '"><td>' + tg + '<span style="display:inline-block;vertical-align:middle">' + label(g, lvl) + '</span></td><td class="num">' + fq(g.ord) + '</td>'
        + names.map(function (n) { return stCell(g.stages, n); }).join('')
        + '<td class="num">' + fq(g.ready) + '</td><td class="num">' + (g.set ? fq(g.sets) : '<span style="color:#c2cbd6" title="one-part product: finished at its last stage">—</span>') + '</td><td class="num">' + (g.made ? fq(g.made) : '') + '</td>'
        + '<td class="num"><b>' + fq(g.left) + '</b></td><td class="num"><span class="pill" style="' + (pct >= 99.95 ? 'background:#e6f4ea;color:#107e3e' : pct < 50 ? 'background:#fff1e2;color:#9a4a00' : 'background:#e8f1fc;color:#0a4f96') + '">' + pct.toFixed(1) + '%</span></td>'
        + '<td style="color:#9a5710;font-size:12px">' + esc(g.nosize || g.behind || '') + '</td></tr>';
      return h;
    }
    function partRows(o, lvl) {
      return (o.parts || []).map(function (p) {
        return '<tr class="g' + lvl + '"><td><span class="sp0"></span>' + esc(p[0]) + ' <small class="fsub" style="display:inline">× ' + fq(p[1]) + ' a set</small></td><td class="num">' + fq(o.ord * p[1]) + '</td>'
          + names.map(function (n) { return stCell(p[2], n); }).join('') + '<td class="num" title="whole sets this part is good for">' + fq(p[3]) + '</td><td></td><td></td><td></td><td></td><td></td></tr>';
      }).join('');
    }
    var body = [];
    gs.forEach(function (g) {
      if (FING === 'line') { body.push(row(g, 0, g.key, (g.parts || []).length > 0)); if (ST.open['fin:' + g.key]) body.push(partRows(g, 1)); return; }
      body.push(row(g, 0, g.key, true));
      if (!ST.open['fin:' + g.key]) return;
      g.kids.forEach(function (o) { var c = Object.assign({}, o); c.made = madeIn(o.item); var k2 = g.key + '/l' + o.item;
        body.push(row(c, 1, k2, (o.parts || []).length > 0)); if (ST.open['fin:' + k2]) body.push(partRows(o, 2)); });
    });
    var T = { ord: 0, ready: 0, sets: 0, made: 0, left: 0, done: 0 }; gs.forEach(function (g) { for (var k in T) T[k] += g[k] || 0; });
    var tst = []; gs.forEach(function (g) { g.stages.forEach(function (s) { var x = null; tst.forEach(function (y) { if (y[0] === s[0]) x = y; }); if (!x) { x = [s[0], 0, 0]; tst.push(x); } x[1] += s[1]; x[2] += s[2]; }); });
    var foot = '<tr><td>Total · ' + gs.length + '</td><td class="num">' + fq(T.ord) + '</td>' + names.map(function (n) { return stCell(tst, n); }).join('')
      + '<td class="num">' + fq(T.ready) + '</td><td class="num">' + fq(T.sets) + '</td><td class="num">' + fq(T.made) + '</td><td class="num">' + fq(T.left) + '</td><td class="num">' + (T.ord ? (T.done / T.ord * 100).toFixed(1) : '0.0') + '%</td><td></td></tr>';
    $('#zprT').innerHTML = gs.length ? '<table class="rt fin"><thead><tr>' + cols.map(function (c) { return '<th class="' + (c[2] ? 'num' : '') + '" data-fsort="' + esc(c[0]) + '">' + esc(c[1]) + (so.k === c[0] ? '<span class="ar">' + (so.dir > 0 ? '▲' : '▼') + '</span>' : '') + '</th>'; }).join('') + '</tr></thead><tbody>' + body.join('') + '</tbody><tfoot>' + foot + '</tfoot></table>'
      : '<div class="empty">No open order line matches.<br>Only orders switched on for production are counted here.</div>';
    $('#zprCnt').innerHTML = gs.length + ' row' + (gs.length === 1 ? '' : 's') + ' · <span title="Department, stage, worker and day filters do not apply: a finished set belongs to an order, not to a floor">product, order and search filters apply</span>';
    TBL = { tab: 'fin', csv: function () {
      var out = [cols.map(function (c) { return c[1]; })];
      gs.forEach(function (g) { var stv = names.map(function (n) { var x = null; g.stages.forEach(function (y) { if (y[0] === n) x = y; }); return x ? fq(x[2]) + ' / ' + fq(x[1]) : ''; });
        out.push([FING === 'ord' ? g.pi + (g.cust ? ' · ' + g.cust : '') : FING === 'prod' ? g.prod : g.prod + (g.size ? ' · ' + g.size : '') + ' — ' + g.pi + (g.desc ? ' — ' + g.desc : ''), g.ord].concat(stv).concat([g.ready, g.set ? g.sets : '', g.made, g.left, Math.round(g.pct * 10) / 10 + '%', g.nosize || g.behind || ''])); });
      return out; } };
    drawColsPicker([], {});
  }
  if (PAGE === 'rep') {
    var finG = $('#zprFinG');
    if (finG) finG.addEventListener('click', function (e) { var b = e.target.closest('[data-fg]'); if (!b) return; FING = b.getAttribute('data-fg'); $$('[data-fg]', finG).forEach(function (x) { x.classList.toggle('on', x === b); }); ST.open = {}; drawReport(); });
    $('#zprT').addEventListener('click', function (e) {
      var th = e.target.closest('th[data-fsort]');
      if (th) { var k = th.getAttribute('data-fsort'), cur = ST.sort && ST.sort.tab === 'fin' && ST.sort.k === k ? ST.sort.dir : 0; ST.sort = { tab: 'fin', k: k, dir: cur ? -cur : (k === 'name' || k === 'behind' || k === 'pct' ? 1 : -1) }; drawReport(); return; }
      var t = e.target.closest('[data-ftg]'); if (t) { var key = 'fin:' + t.getAttribute('data-ftg'); if (ST.open[key]) delete ST.open[key]; else ST.open[key] = 1; drawReport(); return; }
      var fs = e.target.closest('[data-fside]'); if (fs) { var q = fs.getAttribute('data-fside').split('|'); location.href = 'production_orders.php?order=' + encodeURIComponent(q[1]); }
    });
  }
  var ECOLS = [['date', 'Date'], ['worker', 'Worker'], ['code', 'Code'], ['dept', 'Department'], ['order', 'Order'], ['product', 'Product'], ['size', 'Size'], ['part', 'Part'], ['op', 'Operation'], ['stage', 'Stage'], ['q', 'Qty', 1]]
    .concat(MONEY ? [['rate', 'Rate', 1], ['a', 'Amount', 1]] : []).concat([['user', 'Entered by'], ['status', 'Status'], ['why', 'Reason']]);
  function rowVals(tab, g, level, kind) {
    var v = { n: g.n, q: g.q, a: g.a, days: g.d.size, wk: g.w.size, ord: g.f.size, lines: g.l.size };
    v.name = NAME[kind](g);
    if (kind === 'w') { var w = W[g.k]; v.name = w[1]; v.code = w[0]; v.dept = w[2]; v.avg = g.d.size ? g.a / g.d.size : 0; }
    if (kind === 'dept') v.avg = g.w.size ? g.a / g.w.size : 0;
    if (kind === 'f') { v.name = F[g.k][0]; v.cust = F[g.k][1]; }
    if (kind === 'o') { var o = O[g.k]; v.name = o[1]; v.part = o[0]; v.stage = S[o[2]][0]; v.rate = g.q ? g.a / g.q : 0; }
    if (kind === 'p') v.rate = g.q ? g.a / g.q : 0;
    if (kind === 'd') { v.name = fd(DAYS[g.k], true); SORD.forEach(function (si) { v['s' + si] = 0; }); g.rows.forEach(function (r) { v['s' + r[6]] += r[7]; }); }
    if (level && kind !== 'w') { /* a child row: show what it is in the first column */ }
    return v;
  }
  function hidden(tab) { try { return JSON.parse(store('zpr_cols_' + tab) || '{}') || {}; } catch (e) { return {}; } }
  var TQ = '', TBL = null;
  function drawReport() {
    var tab = VIEWS[ST.tab] ? ST.tab : 'worker'; ST.tab = tab;
    $$('#zprTabs button').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === tab); });
    var fg = $('#zprFinG'); if (fg) fg.hidden = tab !== 'fin';
    if (tab === 'fin') return drawFinished();
    var rows = filtered({ all: tab === 'entries' });
    $('#zprK').innerHTML = kpiCards(tab === 'entries' ? rows.filter(function (r) { return !r[10]; }) : rows, { short: true }).map(function (c) { c.flip = FLIPPED[c.id]; return card(c); }).join('');
    $('#zprSub').innerHTML = '<b>' + esc(fd(D.from, true) + ' – ' + fd(D.to, true)) + '</b> · ' + VIEWS[tab].t + (nFilters() ? ' · <span style="color:#0a6ed1">' + nFilters() + ' filter(s) on</span>' : '') + (MONEY ? '' : ' · wages hidden for your login') + floorNote();
    $('#zprStat').hidden = tab !== 'entries';
    if (tab === 'entries') return drawEntries(rows);
    var V = VIEWS[tab], C = colsFor(tab), H = hidden(tab), vis = C.filter(function (c) { return !H[c.k]; });
    var gs = group(rows, KEY[V.g]);
    if (tab === 'daily') { var have = {}; gs.forEach(function (g) { have[g.k] = 1; }); DAYS.forEach(function (d, i) { if (!have[i]) gs.push({ k: i, q: 0, a: 0, n: 0, w: new Set(), d: new Set(), f: new Set(), l: new Set(), rows: [] }); }); }
    var list = gs.map(function (g) { return { g: g, v: rowVals(tab, g, 0, V.g) }; });
    var tq = TQ.toLowerCase().trim();
    if (tq) list = list.filter(function (x) { return [x.v.name, x.v.code, x.v.dept, x.v.cust, x.v.part, x.v.stage].join(' ').toLowerCase().indexOf(tq) >= 0; });
    var so = ST.sort && ST.sort.tab === tab ? ST.sort : { k: tab === 'daily' ? 'name' : (MONEY ? 'a' : 'q'), dir: tab === 'daily' ? 1 : -1 };
    list.sort(function (a, b) {
      var x = so.k === 'name' && tab === 'daily' ? a.g.k : a.v[so.k], y = so.k === 'name' && tab === 'daily' ? b.g.k : b.v[so.k];
      if (so.k === 'share') { x = measure(a.g); y = measure(b.g); }
      if (typeof x === 'string' || typeof y === 'string') return so.dir * String(x || '').localeCompare(String(y || ''));
      return so.dir * ((x || 0) - (y || 0));
    });
    var tot = sum(rows), mxShare = Math.max.apply(null, list.map(function (x) { return measure(x.g); }).concat([1]));
    function cell(c, v, g, lvl, kind) {
      if (c.bar) { var m = measure(g), all = MONEY ? tot.a : tot.q; return '<td style="min-width:120px"><span class="pill">' + (all ? (m / all * 100).toFixed(1) : '0.0') + '%</span><span class="mini" style="width:' + Math.round(m / mxShare * 70) + 'px"></span></td>'; }
      var x = v[c.k];
      if (c.k === 'name') {
        var open = ST.open[tab + ':' + lvl + ':' + g.k + ':' + (g.pk || '')];
        var tg = (lvl < V.drill.length && g.n) ? '<button type="button" class="tg' + (open ? ' open' : '') + '" data-tg="' + lvl + '|' + g.k + '|' + (g.pk || '') + '" title="Show the detail">▸</button>' : (lvl === 0 ? '<span class="sp0"></span>' : '');
        var side = lvl === 0 && V.side && g.n ? ' class="lk" data-side="' + V.side + '|' + esc(g.k) + '"' : (lvl > 0 && (kind === 'w' || kind === 'o') ? ' class="lk" data-side="' + kind + '|' + g.k + '"' : '');
        return '<td>' + tg + '<span' + side + '>' + esc(x) + '</span></td>';
      }
      if (c.n) return '<td class="num">' + (x === undefined || x === '' ? '' : c.m ? fm(x) : fq(x)) + '</td>';
      return '<td>' + esc(x == null ? '' : x) + '</td>';
    }
    var body = [];
    list.forEach(function (x) {
      body.push('<tr' + (x.g.n ? '' : ' class="zero"') + '>' + vis.map(function (c) { return cell(c, x.v, x.g, 0, V.g); }).join('') + '</tr>');
      (function kids(g, lvl, pk) {
        if (lvl >= V.drill.length || !ST.open[tab + ':' + lvl + ':' + g.k + ':' + pk]) return;
        var kind = V.drill[lvl], sub = group(g.rows, KEY[kind]).sort(function (a, b) { return measure(b) - measure(a); });
        sub.forEach(function (sg) {
          sg.pk = pk + '/' + g.k;
          var v = rowVals(tab, sg, lvl + 1, kind), nm = NAME[kind](sg);
          v.name = nm; if (kind === 'w') { v.code = W[sg.k][0]; v.dept = W[sg.k][2]; v.avg = sg.d.size ? sg.a / sg.d.size : 0; }
          body.push('<tr class="g' + (lvl + 1) + '">' + vis.map(function (c) { return c.bar ? '<td></td>' : cell(c, v, sg, lvl + 1, kind); }).join('') + '</tr>');
          kids(sg, lvl + 1, sg.pk);
        });
      })(x.g, 0, '');
    });
    var foot = '<tr>' + vis.map(function (c, i) {
      if (i === 0) return '<td>Total · ' + list.length + '</td>';
      if (c.s) { var s = 0; list.forEach(function (x) { s += x.v[c.k] || 0; }); return '<td class="num">' + (c.m ? fm(s) : fq(s)) + '</td>'; }
      if (c.d) { var all = new Set(); list.forEach(function (x) { x.g.rows.forEach(function (r) { all.add(c.k === 'days' ? r[0] : c.k === 'wk' ? r[1] : c.k === 'ord' ? r[4] : r[5]); }); }); return '<td class="num">' + all.size + '</td>'; }
      return '<td></td>';
    }).join('') + '</tr>';
    TBL = { tab: tab, cols: vis, list: list };
    $('#zprT').innerHTML = list.length ? '<table class="rt"><thead><tr>' + vis.map(function (c) { return '<th class="' + (c.n ? 'num' : '') + '" data-sort="' + c.k + '">' + esc(c.t) + (so.k === c.k ? '<span class="ar">' + (so.dir > 0 ? '▲' : '▼') + '</span>' : '') + '</th>'; }).join('') + '</tr></thead><tbody>' + body.join('') + '</tbody><tfoot>' + foot + '</tfoot></table>'
      : '<div class="empty">Nothing booked for this view.<br>Try a wider period or clear a filter.</div>';
    $('#zprCnt').textContent = list.length + ' row' + (list.length === 1 ? '' : 's');
    drawColsPicker(C, H);
  }
  function drawEntries(rows) {
    var st = ST.status; rows = rows.filter(function (r) { return st === 'all' ? true : st === 'cancelled' ? r[10] : !r[10]; });
    var tq = TQ.toLowerCase().trim(); if (tq) rows = rows.filter(function (r) { return qHit(r, tq); });
    var so = ST.sort && ST.sort.tab === 'entries' ? ST.sort : { k: 'date', dir: -1 };
    var val = function (r, k) { var w = W[r[1]], o = O[r[3]];
      return k === 'date' ? r[0] * 1e9 + r[12] : k === 'worker' ? w[1] : k === 'code' ? w[0] : k === 'dept' ? w[2] : k === 'order' ? F[r[4]][0] : k === 'product' ? P[r[2]][0] : k === 'size' ? L[r[5]][1]
        : k === 'part' ? o[0] : k === 'op' ? o[1] : k === 'stage' ? S[r[6]][0] : k === 'q' ? r[7] : k === 'rate' ? r[9] : k === 'a' ? r[8] : k === 'status' ? r[10] : k === 'why' ? (X[r[11]] || [''])[0] : k === 'user' ? (U[r[13]] ? U[r[13]][0] : '') : 0; };
    rows = rows.slice().sort(function (a, b) { var x = val(a, so.k), y = val(b, so.k); return so.dir * (typeof x === 'string' ? x.localeCompare(y) : x - y); });
    var H = hidden('entries'), vis = ECOLS.filter(function (c) { return !H[c[0]]; }), shown = rows.slice(0, ST.page * 300);
    var t = sum(rows.filter(function (r) { return !r[10]; }));
    $('#zprT').innerHTML = rows.length ? '<table class="rt"><thead><tr>' + vis.map(function (c) { return '<th class="' + (c[2] ? 'num' : '') + '" data-sort="' + c[0] + '">' + c[1] + (so.k === c[0] ? '<span class="ar">' + (so.dir > 0 ? '▲' : '▼') + '</span>' : '') + '</th>'; }).join('') + '</tr></thead><tbody>'
      + shown.map(function (r) {
        var w = W[r[1]], o = O[r[3]];
        var cells = { date: fd(DAYS[r[0]], true), worker: '<span class="lk" data-side="w|' + r[1] + '">' + esc(w[1]) + '</span>', code: esc(w[0]), dept: esc(w[2]), order: '<span class="lk" data-side="f|' + r[4] + '">' + esc(F[r[4]][0]) + '</span>', product: esc(P[r[2]][0]), size: esc(L[r[5]][1]),
          part: esc(o[0]), op: esc(o[1]), stage: esc(S[r[6]][0]), q: fq(r[7]), rate: fm(r[9]), a: fm(r[8]), user: U[r[13]] ? '<span class="lk" data-side="u|' + r[13] + '">' + esc(U[r[13]][0]) + '</span>' : '', status: r[10] ? '<b style="color:#bb0000">Cancelled</b>' : 'Active', why: esc((X[r[11]] || [''])[0]) };
        return '<tr class="' + (r[10] ? 'canc' : '') + '">' + vis.map(function (c) { return '<td class="' + (c[2] ? 'num' : '') + (c[0] === 'why' || c[0] === 'status' ? ' why' : '') + '">' + cells[c[0]] + '</td>'; }).join('') + '</tr>';
      }).join('') + '</tbody><tfoot><tr>' + vis.map(function (c, i) { return '<td class="' + (c[2] ? 'num' : '') + '">' + (i === 0 ? 'Active total' : c[0] === 'q' ? fq(t.q) : c[0] === 'a' ? fm(t.a) : '') + '</td>'; }).join('') + '</tr></tfoot></table>'
      + (rows.length > shown.length ? '<div style="padding:10px 14px"><button type="button" class="btn" id="zprMore">Show 300 more (' + (rows.length - shown.length) + ' left)</button></div>' : '')
      : '<div class="empty">No entries in this view.</div>';
    $('#zprCnt').textContent = rows.length + ' entr' + (rows.length === 1 ? 'y' : 'ies') + (rows.length > shown.length ? ' · showing ' + shown.length : '');
    TBL = { tab: 'entries', cols: vis, rows: rows };
    var m = $('#zprMore'); if (m) m.onclick = function () { ST.page++; drawReport(); };
    drawColsPicker(ECOLS.map(function (c) { return { k: c[0], t: c[1] }; }), H);
  }
  function drawColsPicker(C, H) {
    var b = $('#zprColsL'); if (!b) return;
    b.innerHTML = C.map(function (c, i) { return i === 0 ? '' : '<label><input type="checkbox" data-col="' + c.k + '"' + (H[c.k] ? '' : ' checked') + '> <span>' + esc(c.t) + '</span></label>'; }).join('');
  }
  /* CSV of exactly what is on screen (the expanded detail too). Names that start
     with = + - @ are written with a ' in front so Excel never runs them. */
  function csvCell(v) { if (typeof v === 'number') return String(Math.round(v * 100) / 100); v = String(v == null ? '' : v); if (/^[=+\-@\t\r']/.test(v)) v = "'" + v; return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }
  function exportCsv() {
    if (!TBL) return;
    var out = [[VIEWS[TBL.tab].t + ' — ' + D.from + ' to ' + D.to + (nFilters() ? ' (filtered)' : '')], []];
    if (TBL.csv) { out = out.concat(TBL.csv()); }
    else if (TBL.tab === 'entries') {
      out.push(TBL.cols.map(function (c) { return c[1]; }));
      TBL.rows.forEach(function (r) { var w = W[r[1]], o = O[r[3]];
        var v = { date: DAYS[r[0]], worker: w[1], code: w[0], dept: w[2], order: F[r[4]][0], product: P[r[2]][0], size: L[r[5]][1], part: o[0], op: o[1], stage: S[r[6]][0], q: r[7], rate: r[9], a: r[8], status: r[10] ? 'CANCELLED' : 'Active', why: (X[r[11]] || [''])[0], user: U[r[13]] ? U[r[13]][0] : '' };
        out.push(TBL.cols.map(function (c) { return v[c[0]]; })); });
    } else {
      var cols = TBL.cols.filter(function (c) { return !c.bar; });
      out.push(cols.map(function (c) { return c.t; }));
      $$('#zprT tbody tr').forEach(function (tr) { out.push(Array.prototype.map.call(tr.children, function (td, i) { if (TBL.cols[i] && TBL.cols[i].bar) return null; var t = td.textContent.replace(/^[▸\s]+/, '').trim(), c = TBL.cols[i], lv = /\bg(\d)\b/.exec(tr.className);
        return c && c.n && t !== '' ? Number(t.replace(/,/g, '')) : (lv && i === 0 ? new Array(+lv[1] + 1).join('    ') + t : t); }).filter(function (x) { return x !== null; })); });
      var f = $('#zprT tfoot tr'); if (f) out.push(Array.prototype.map.call(f.children, function (td, i) { return TBL.cols[i] && TBL.cols[i].bar ? null : td.textContent.trim(); }).filter(function (x) { return x !== null; }));
    }
    var csv = '﻿' + out.map(function (r) { return r.map(csvCell).join(','); }).join('\r\n');
    var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    a.download = 'production_' + TBL.tab + '_' + D.from + '_to_' + D.to + '.csv'; document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  }
  if (PAGE === 'rep') {
    bindCards($('#zprK'));
    $('#zprTabs').addEventListener('click', function (e) { var b = e.target.closest('[data-tab]'); if (!b) return; ST.tab = b.getAttribute('data-tab'); ST.page = 1; TQ = ''; $('#zprTQ').value = ''; refresh(); });
    $('#zprT').addEventListener('click', function (e) {
      var th = e.target.closest('th[data-sort]');
      if (th) { var k = th.getAttribute('data-sort'), cur = ST.sort && ST.sort.tab === ST.tab && ST.sort.k === k ? ST.sort.dir : 0; ST.sort = { tab: ST.tab, k: k, dir: cur ? -cur : (/^(name|code|dept|cust|part|stage|worker|order|product|size|op|status|why)$/.test(k) ? 1 : -1) }; drawReport(); return; }
      var tg = e.target.closest('[data-tg]');
      if (tg) { var p = tg.getAttribute('data-tg').split('|'), key = ST.tab + ':' + p[0] + ':' + p[1] + ':' + p[2]; if (ST.open[key]) delete ST.open[key]; else ST.open[key] = 1; drawReport(); return; }
      var sd = e.target.closest('[data-side]'); if (sd) { var q = sd.getAttribute('data-side').split('|'); openSide(q[0], q[0] === 'dept' ? q.slice(1).join('|') : +q[1]); }
    });
    var tqT = null; $('#zprTQ').addEventListener('input', function () { var v = this.value; clearTimeout(tqT); tqT = setTimeout(function () { TQ = v; ST.page = 1; drawReport(); }, 180); });
    $('#zprCsv').onclick = exportCsv;
    $('#zprPrint').onclick = function () { window.print(); };
    $('#zprExpand').onclick = function () {
      if (TBL && TBL.tab === 'fin') { var anyF = Object.keys(ST.open).some(function (k) { return k.indexOf('fin:') === 0; });
        if (anyF) Object.keys(ST.open).forEach(function (k) { if (k.indexOf('fin:') === 0) delete ST.open[k]; });
        else $$('#zprT [data-ftg]').forEach(function (b) { ST.open['fin:' + b.getAttribute('data-ftg')] = 1; });
        return drawReport(); }
      if (!TBL || !TBL.list) return; var V = VIEWS[TBL.tab], any = Object.keys(ST.open).some(function (k) { return k.indexOf(TBL.tab + ':0:') === 0; });
      if (any) Object.keys(ST.open).forEach(function (k) { if (k.indexOf(TBL.tab + ':') === 0) delete ST.open[k]; });
      else if (V.drill) TBL.list.forEach(function (x) { if (x.g.n) ST.open[TBL.tab + ':0:' + x.g.k + ':'] = 1; });
      drawReport();
    };
    $('#zprStatS').value = ST.status; $('#zprStatS').onchange = function () { ST.status = this.value; ST.page = 1; refresh(); };
    var cb = $('#zprColsB'), cp = $('#zprColsP');
    cb.onclick = function (e) { e.stopPropagation(); cp.hidden = !cp.hidden; };
    document.addEventListener('click', function (e) { if (!cp.hidden && !e.target.closest('.cols')) cp.hidden = true; });
    cp.addEventListener('change', function (e) { var k = e.target.getAttribute('data-col'); if (!k) return; var H = hidden(ST.tab); if (e.target.checked) delete H[k]; else H[k] = 1; store('zpr_cols_' + ST.tab, JSON.stringify(H)); drawReport(); });
    $('#zprColsR').onclick = function () { store('zpr_cols_' + ST.tab, '{}'); drawReport(); };
  }

  /* ---------------- one refresh for every change ---------------- */
  function refresh(keepPop, keepFocus) {
    if (!keepPop) closePop();
    /* while a value list is open or somebody is typing in Search, only the tokens are redrawn — the box keeps its focus */
    if (FB) { if (POP || keepFocus) drawTokensOnly(); else drawFilterBar(); }
    ST.page = 1; writeHash();
    if (PAGE === 'dash') drawDash(); else drawReport();
  }
  function drawTokensOnly() {
    var toks = [];
    DORD.forEach(function (k) { ST.f[k].forEach(function (v) { toks.push('<span class="tok"><b>' + DIM[k].t + ':</b> ' + esc(DIM[k].lbl(v)) + '<button type="button" title="Remove" data-rm="' + k + '" data-v="' + esc(v) + '">×</button></span>'); }); });
    if (ST.day) toks.push('<span class="tok"><b>Day:</b> ' + esc(fd(ST.day, true)) + '<button type="button" data-rm="day">×</button></span>');
    if (ST.q) toks.push('<span class="tok"><b>Search:</b> “' + esc(ST.q) + '”<button type="button" data-rm="q">×</button></span>');
    var n = nFilters();
    $('#zprFT').innerHTML = toks.join('') + (n ? '<button type="button" class="btn sm ghost" data-rm="all">Clear all</button>' : '');
    $('#zprFT').hidden = !n; $('#zprFN').textContent = n; $('#zprFN').hidden = !n;
    DORD.forEach(function (k) { var b = $('[data-vh="' + k + '"]'); if (b) { b.classList.toggle('set', !!ST.f[k].length); if (!b.classList.contains('open')) b.textContent = fieldLabel(k); } });
  }
  /* ---------------- another period, without reloading the page ----------------
     The chips and the date boxes fetch only the rows of that period (one read
     on the server, no menu, no page). A period already seen comes from memory.
     Filters, the tab and the opened cards stay as they are. */
  var CACHE = {}, SELF = location.pathname;
  CACHE[D.from + '|' + D.to] = D;
  function showPeriod(from, to, push) {
    var key = from + '|' + to, done = function (nd) {
      CACHE[key] = nd; setData(nd);
      if (ST.day && DAYS.indexOf(ST.day) < 0) ST.day = '';
      ST.open = {}; ST.page = 1; root.classList.remove('loading');
      $$('.per a').forEach(function (a) { var u = a.getAttribute('href').split('#')[0]; a.classList.toggle('on', u.indexOf('from=' + from) >= 0 && u.indexOf('to=' + to) >= 0); });
      var fi = $('.per input[name=from]'), ti = $('.per input[name=to]'); if (fi) fi.value = from; if (ti) ti.value = to;
      var qs = '?from=' + from + '&to=' + to + (PAGE === 'rep' ? '&r=' + ST.tab : '');
      try { history[push ? 'pushState' : 'replaceState']({ from: from, to: to }, '', SELF + qs + location.hash); } catch (e) {}
      $$('.per a').forEach(function (a) { a.setAttribute('href', a.getAttribute('href').split('#')[0] + location.hash); });
      refresh();
    };
    if (CACHE[key]) return done(CACHE[key]);
    root.classList.add('loading');
    fetch(SELF + '?json=1&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (nd) { nd.page = PAGE; nd.tab = ST.tab; done(nd); })
      .catch(function () { location.href = SELF + '?from=' + from + '&to=' + to + location.hash; });   // fall back to a normal page load
  }
  $$('.per a').forEach(function (a) {
    a.addEventListener('click', function (e) {
      if (e.ctrlKey || e.metaKey || e.shiftKey) return;               // a new tab still works
      var u = a.getAttribute('href'), f = /from=([\d-]+)/.exec(u), t = /to=([\d-]+)/.exec(u); if (!f || !t) return;
      e.preventDefault(); showPeriod(f[1], t[1], true);
    });
  });
  var pf = $('.per form');
  if (pf) pf.addEventListener('submit', function (e) {
    var f = pf.querySelector('[name=from]').value, t = pf.querySelector('[name=to]').value; if (!f || !t) return;
    e.preventDefault(); if (f > t) { var x = f; f = t; t = x; } showPeriod(f, t, true);
  });
  window.addEventListener('popstate', function (e) { if (e.state && e.state.from) showPeriod(e.state.from, e.state.to, false); });
  try { history.replaceState({ from: D.from, to: D.to }, '', location.href); } catch (e) {}

  drawFilterBar(); writeHash();
  if (PAGE === 'dash') drawDash(); else drawReport();
  window.ZPR = { refresh: refresh, state: ST, open: openSide };
})();
