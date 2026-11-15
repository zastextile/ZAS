/*
  SORTABLE COLUMNS — click a column title to sort ▲ (A→Z, small→large),
  click again for ▼, a third time for the original order.

  Opt-in: only <table class="sortable"> is touched. Tables drawn later by a
  screen's own script are picked up by themselves (MutationObserver), and a
  table redrawn with the same column titles keeps the sort that was chosen,
  so changing a filter does not lose it.

  What is compared, per cell:
    · data-sort="…" on the <td> when the screen gives one (a date, a number)
    · otherwise the text, understood as: hours "7h 40m" / "30m", money and
      counts "1,234" / "Rs 1,234" / "(1,234)", percent "83.3%", hours "0.75 h";
      anything else as words, ignoring case.
  Empty cells ("", "—") always go last, whichever way.
  Rows marked class="tot" (a total inside the body) stay at the bottom.
  A column whose title is empty, or th[data-nosort], cannot be sorted.

  Read-only on the page: it moves rows, never changes them.
*/
(function(){
  'use strict';
  if (window.ZSort) return;
  var memory = {};

  var css = document.createElement('style');
  css.textContent =
    'table.sortable th.zs{cursor:pointer;user-select:none;-webkit-user-select:none;padding-right:15px!important}' +
    'table.sortable th.zs:hover{filter:brightness(1.18)}' +
    'table.sortable th.zs .zsi{position:absolute;right:4px;bottom:50%;transform:translateY(50%);font-size:9px;line-height:1;opacity:.45;font-style:normal}' +
    'table.sortable th.zs[aria-sort] .zsi{opacity:1}' +
    'table.sortable th.zs:focus-visible{outline:2px solid #7fb3ea;outline-offset:-2px}' +
    '@media print{table.sortable th.zs .zsi{display:none}}';
  (document.head || document.documentElement).appendChild(css);

  function value(td){
    if (!td) return null;
    var s = td.getAttribute('data-sort');
    if (s !== null) { if (s === '') return null; var n = Number(s); return isNaN(n) ? s.toLowerCase() : n; }
    var t = (td.textContent || '').replace(/\s+/g, ' ').trim();
    if (t === '' || t === '—' || t === '-') return null;
    var m = t.match(/^(?:(\d+)h)?\s*(?:(\d+)m)?$/);
    if (m && (m[1] || m[2])) return (+m[1] || 0) * 60 + (+m[2] || 0);
    var neg = /^\(.*\)$/.test(t) || /^[−-]/.test(t);
    var c = t.replace(/^(rs|pkr|usd|\$)\s*/i, '').replace(/[(),\s−-]/g, '').replace(/(%|h|rs)$/i, '');
    if (/^\d+(\.\d+)?$/.test(c)) return (neg ? -1 : 1) * parseFloat(c);
    return t.toLowerCase();
  }
  function cmp(a, b){
    if (a === null && b === null) return 0;
    if (a === null) return 1;
    if (b === null) return -1;
    if (typeof a === 'number' && typeof b === 'number') return a - b;
    return String(a).localeCompare(String(b), undefined, { numeric: true });
  }
  function keyOf(table){
    return Array.prototype.map.call(table.tHead ? table.tHead.rows[0].cells : [], function(th){ return th.getAttribute('data-title') || th.textContent; }).join('|');
  }
  function paint(table, col, dir){
    var ths = table.tHead.rows[0].cells;
    Array.prototype.forEach.call(ths, function(th, i){
      var ic = th.querySelector('.zsi');
      if (!ic) return;
      if (i === col && dir) { th.setAttribute('aria-sort', dir > 0 ? 'ascending' : 'descending'); ic.textContent = dir > 0 ? '▲' : '▼'; }
      else { th.removeAttribute('aria-sort'); ic.textContent = '⇅'; }
    });
  }
  function sort(table, col, dir){
    var tb = table.tBodies[0];
    if (!tb) return;
    var rows = Array.prototype.slice.call(tb.rows);
    rows.forEach(function(r, i){ if (r._zsi === undefined) r._zsi = i; });
    var body = rows.filter(function(r){ return !r.classList.contains('tot'); });
    var tail = rows.filter(function(r){ return r.classList.contains('tot'); });
    body.sort(function(a, b){
      if (!dir) return a._zsi - b._zsi;
      var va = value(a.cells[col]), vb = value(b.cells[col]);
      if (va === null || vb === null) { var e = cmp(va, vb); return e || a._zsi - b._zsi; }
      return dir * cmp(va, vb) || a._zsi - b._zsi;
    });
    body.concat(tail).forEach(function(r){ tb.appendChild(r); });
    paint(table, col, dir);
    table._zs = { col: col, dir: dir };
    memory[keyOf(table)] = table._zs;
  }
  function enhance(table){
    if (table._zsReady || !table.tHead || !table.tHead.rows.length) return;
    table._zsReady = true;
    var ths = table.tHead.rows[0].cells;
    Array.prototype.forEach.call(ths, function(th, i){
      if (th.hasAttribute('data-nosort') || th.colSpan > 1 || !(th.textContent || '').trim() || th.querySelector('input,select,button')) return;
      th.classList.add('zs');
      /* the arrow sits in the title's right padding, so it never drops onto a line of its own */
      var cs = getComputedStyle(th);
      if (cs.position === 'static') th.style.position = 'relative';
      th.setAttribute('tabindex', '0');
      th.setAttribute('data-title', th.textContent);
      th.title = 'Sort by ' + th.textContent.trim().toLowerCase() + ' (click again to reverse)';
      var ic = document.createElement('i'); ic.className = 'zsi'; ic.setAttribute('aria-hidden', 'true'); ic.textContent = '⇅';
      /* titles that sit at the bottom of a tall header row: the arrow sits on the same last line */
      if (cs.verticalAlign === 'bottom') { ic.style.bottom = (parseFloat(cs.paddingBottom) + 3) + 'px'; ic.style.transform = 'none'; }
      th.appendChild(ic);
      function go(){
        var s = table._zs || { col: -1, dir: 0 };
        var dir = s.col !== i ? 1 : (s.dir === 1 ? -1 : s.dir === -1 ? 0 : 1);
        sort(table, i, dir);
      }
      th.addEventListener('click', go);
      th.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
    });
    var kept = memory[keyOf(table)];
    if (kept && kept.dir) sort(table, kept.col, kept.dir);
  }
  function scan(root){
    if (!root || !root.querySelectorAll) return;
    if (root.matches && root.matches('table.sortable')) enhance(root);
    Array.prototype.forEach.call(root.querySelectorAll('table.sortable'), enhance);
  }
  window.ZSort = { scan: scan, value: value };
  function start(){
    scan(document.body);
    new MutationObserver(function(list){
      list.forEach(function(m){ Array.prototype.forEach.call(m.addedNodes, function(n){ if (n.nodeType === 1) scan(n); }); });
    }).observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
