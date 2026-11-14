/* LINE LINKS — parts in the product picker, and the last price to this buyer.
 *
 * The invoice and the proforma each have their own picker for the Product
 * Master (LOV 'prod'), written into the page. This file does not replace
 * either one. It wraps whatever the page registers and adds two things:
 *
 *   PARTS       the list also offers the Parts of Production › Parts, tagged
 *               "Part". Taking one links the line to that part instead of a
 *               product. The link is for naming and reports; production does
 *               not plan from it. A line has a product OR a part, never both.
 *   LAST PRICE  when a product or part is taken and the price box is empty,
 *               it is filled with what this buyer was last charged for it in
 *               the same currency — and says so. Typing over it is free.
 *
 * The page says where things are through window.ZLL (see includes/
 * linelinks.php, ll_script()). Every row gets one hidden part field, so the
 * posted list lines up with the row order exactly as product_id does. If this
 * file does not load, nothing is posted for parts and the server leaves every
 * existing part link alone.
 */
(function () {
  'use strict';
  var C = window.ZLL;
  if (!C || !window.LOV) return;
  var PARTS = C.parts || [], LAST = C.last || {}, ROWP = C.rowParts || {};
  var norm = LOV.norm, esc = LOV.esc;

  function partById(id) { id = +id; for (var i = 0; i < PARTS.length; i++) if (PARTS[i].id === id) return PARTS[i]; return null; }
  function partByName(v) { var n = norm(v); if (!n) return null; for (var i = 0; i < PARTS.length; i++) if (norm(PARTS[i].name) === n) return PARTS[i]; return null; }

  var css = document.createElement('style');
  css.textContent = '.zll-tag{display:inline-block;margin-left:6px;padding:1px 6px;border-radius:99px;background:#e6f4ea;color:#17683a;font-size:10px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;vertical-align:middle}'
    + '.zll-last{font-size:10.5px;color:#b45309;font-weight:600;margin-top:2px;white-space:nowrap}';
  document.head.appendChild(css);

  function tbody() { return document.querySelector(C.tbody); }
  function rows() { var tb = tbody(); return tb ? [].filter.call(tb.querySelectorAll('tr'), function (tr) { return tr.querySelector('[data-c="name"]'); }) : []; }

  /* one hidden part field per row, in the cell that already holds the product id */
  function partField(tr) {
    var el = tr.querySelector('.ptid');
    if (el) return el;
    var pid = tr.querySelector('.pid');
    if (!pid) return null;
    el = document.createElement('input');
    el.type = 'hidden'; el.className = 'ptid'; el.name = C.partField;
    var idEl = tr.querySelector('input[name="' + C.idField + '"]');
    var lineId = idEl ? +idEl.value : 0;
    el.value = lineId && ROWP[lineId] ? ROWP[lineId] : 0;
    pid.parentNode.insertBefore(el, pid.nextSibling);
    return el;
  }

  /* The chip under the name. The page paints "not linked" for any row with no
     product; when the row has a part, that is put right here. */
  function paint(tr) {
    var pt = partField(tr); if (!pt) return;
    var pidEl = tr.querySelector('.pid'), f = tr.querySelector('[data-c="name"]'), chip = tr.querySelector('.linkchip');
    var pid = pidEl ? +pidEl.value : 0, name = f ? f.value : '';
    if (pid > 0) pt.value = 0;
    else if (!name.trim()) pt.value = 0;
    else if (!+pt.value) { var hit = partByName(name); if (hit) pt.value = hit.id; }
    if (!chip || pid > 0 || !+pt.value) return;
    var p = partById(pt.value), key = pt.value + '|' + (p ? (norm(name) === norm(p.name) ? 'same' : 'reworded') : 'dead');
    var cur = chip.querySelector('.zll-part');
    if (cur && cur.getAttribute('data-k') === key) return;
    chip.innerHTML = p
      ? '<span class="ok zll-part" data-k="' + key + '">&#10003; Part: ' + esc(p.name) + '</span>' + (norm(name) !== norm(p.name) ? ' <span class="rw">printing your wording</span>' : '')
      : '<span class="un zll-part" data-k="' + key + '">&#9888; that part is no longer in Production &rsaquo; Parts &mdash; pick again</span>';
  }
  var queued = false;
  function paintAll() { queued = false; rows().forEach(paint); }
  function later() { if (queued) return; queued = true; (window.requestAnimationFrame || setTimeout)(paintAll); }

  function fillLast(tr, key) {
    var v = LAST[key]; if (!v) return;
    var r = tr.querySelector(C.rateSel);
    if (!r || r.readOnly || r.disabled || parseFloat(String(r.value).replace(/,/g, '')) > 0) return;
    r.value = v;
    r.dispatchEvent(new Event('input', { bubbles: true }));
    var old = r.parentNode.querySelector('.zll-last'); if (old) old.remove();
    var note = document.createElement('div');
    note.className = 'zll-last'; note.textContent = 'last price to ' + (C.who || 'this buyer');
    r.parentNode.appendChild(note);
    r.addEventListener('input', function gone(e) { if (e.isTrusted) { note.remove(); r.removeEventListener('input', gone); } });
  }

  /* Wrap the page's own 'prod' picker as it is registered. */
  var register = LOV.register;
  LOV.register = function (kind, prov) {
    if (kind !== 'prod' || !prov || prov.__zll) return register.call(LOV, kind, prov);
    var w = {}; Object.keys(prov).forEach(function (k) { w[k] = prov[k]; });
    w.__zll = true;
    w.cols = (prov.cols || []).map(function (c, i) {
      var n = {}; Object.keys(c).forEach(function (k) { n[k] = c[k]; });
      n.get = function (r, q) {
        if (!r.p || !r.p.zpart) return c.get(r, q);
        if (i === 0) return LOV.hl(r.p.name, q) + '<span class="zll-tag">Part</span>';
        return /unit/i.test(c.label || '') ? esc(r.p.unit) : '&mdash;';
      };
      return n;
    });
    if (PARTS.length) w.title = function () { return 'Select a product or a part'; };
    w.rows = function (f, q, showAll, cb) {
      prov.rows(f, q, showAll, function (out, hidden) {
        var extra = [];
        PARTS.forEach(function (p) {
          var sc = LOV.score(q, '', p.name, '');
          if (sc > 0) extra.push({ p: { id: 0, name: p.name, unit: p.unit, zpart: p.id, vers: 0, cost: null }, sc: sc });
        });
        extra.sort(function (a, b) { return a.sc !== b.sc ? b.sc - a.sc : a.p.name.localeCompare(b.p.name); });
        cb((out || []).concat(extra), hidden);
      });
    };
    w.pick = function (f, r) {
      var tr = f.closest('tr');
      if (r.p && r.p.zpart) {
        var pidEl = tr.querySelector('.pid'), pt = partField(tr);
        if (pidEl) pidEl.value = 0;
        if (pt) pt.value = r.p.zpart;
        /* the printed name is replaced only when it was empty or was a name this picker put there */
        if (!f.value.trim() || f.value === f.getAttribute('data-zll-auto') || partByName(f.value)) f.value = r.p.name;
        f.setAttribute('data-zll-auto', f.value);
        var u = tr.querySelector('[data-c="unit"]'); if (u && !u.value.trim()) u.value = r.p.unit;
        f.dispatchEvent(new Event('input', { bubbles: true }));       // lets the page mark the form as changed
        paint(tr);
        fillLast(tr, 'k' + r.p.zpart);
        var q = tr.querySelector('[data-c="qty"]'); if (q) { q.focus(); q.select(); }
        return;
      }
      prov.pick(f, r);
      var p2 = partField(tr); if (p2) p2.value = 0;
      f.setAttribute('data-zll-auto', f.value);
      if (r.p && r.p.id) fillLast(tr, 'p' + r.p.id);
    };
    return register.call(LOV, kind, w);
  };

  function start() {
    var tb = tbody(); if (!tb) return;
    paintAll();
    tb.addEventListener('input', later);
    if (window.MutationObserver) new MutationObserver(later).observe(tb, { childList: true, subtree: true });
    var form = tb.closest('form');
    if (form) form.addEventListener('submit', paintAll);                 // every row has its part field before it is posted
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
  window.ZLL_ready = true;
})();
