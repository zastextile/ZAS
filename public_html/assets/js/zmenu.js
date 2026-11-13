/* THE DESKTOP MENU: A SLIM RAIL, AND A BOARD ON "/".
 *
 * The menu had grown long enough that reaching a page meant scrolling a
 * sidebar that also took 262px from every screen. On a desktop it is now:
 *
 *   RAIL   a 62px strip of section icons. Point at one and its pages slide
 *          out; move away and they go. A pin at the bottom brings back the
 *          full menu, a fold button puts the rail back. The choice is kept
 *          for this person on this computer.
 *   BOARD  the whole menu on one sheet, opened with "/" or Ctrl+K or the
 *          first button on the rail: a search box, starred pages, the last
 *          few pages visited, then every section.
 *
 * IT READS THE MENU THAT IS ALREADY ON THE PAGE (includes/menu.php) and shows
 * the same links another way. It decides nothing about who may see what: a
 * link the server did not print is not here either.
 *
 * Phones and narrow windows (1100px and under) are left alone: they keep the
 * Menu button from assets/css/app.css. If this file fails to load, the small
 * script in includes/layout.php puts the full menu back.
 */
(function () {
  'use strict';
  var nav = document.getElementById('zmNav');
  var side = document.querySelector('.sidebar');
  if (!nav || !side) return;

  var root = document.documentElement;
  var wide = window.matchMedia('(min-width:1101px)');
  var K_MODE = 'zasMenuMode', K_FAV = 'zasMenuFav', K_REC = 'zasMenuRecent';
  function load(k, d) { try { var v = JSON.parse(localStorage.getItem(k) || 'null'); return v === null ? d : v; } catch (e) { return d; } }
  function save(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function mode() { try { return localStorage.getItem(K_MODE) === 'full' ? 'full' : 'rail'; } catch (e) { return 'rail'; } }

  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (k === 'text') n.textContent = v;
      else if (k === 'html') n.innerHTML = v;                       // only ever our own SVG, or an icon copied from the page
      else if (k.indexOf('on') === 0) n.addEventListener(k.slice(2), v);
      else if (v !== null && v !== undefined && v !== false) n.setAttribute(k, v);
    });
    (kids || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }
  var SVG = {
    grid: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5"/></svg>',
    pin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4h6l-1 6 3 3H7l3-3z"/><path d="M12 13v7"/></svg>',
    fold: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 6l-6 6 6 6"/><path d="M18 6l-6 6 6 6"/></svg>'
  };

  /* ---- the menu, read from the page ---- */
  var groups = [], pages = {};                 // pages[href] = { href, label, group }
  Array.prototype.forEach.call(nav.children, function (n) {
    if (n.classList.contains('zm-solo')) {
      var href = n.getAttribute('href'), label = (n.querySelector('span') || n).textContent.trim();
      var ico = n.querySelector('.zm-ico');
      groups.push({ solo: true, href: href, label: label, icon: ico ? ico.innerHTML : '', on: n.classList.contains('on'), sections: [{ label: '', items: [{ href: href, label: label, on: n.classList.contains('on') }] }] });
      pages[href] = { href: href, label: label, group: '' };
    } else if (n.classList.contains('zm-grp')) {
      var head = n.querySelector('.zm-head'), gl = head.querySelector('span').textContent.trim(), gi = head.querySelector('.zm-ico');
      var g = { solo: false, label: gl, icon: gi ? gi.innerHTML : '', on: false, sections: [] }, sec = null;
      Array.prototype.forEach.call(n.querySelector('.zm-body').children, function (c) {
        if (c.classList.contains('zm-sub')) { sec = { label: c.textContent.trim(), items: [] }; g.sections.push(sec); }
        else if (c.classList.contains('zm-item')) {
          if (!sec) { sec = { label: '', items: [] }; g.sections.push(sec); }
          var it = { href: c.getAttribute('href'), label: c.textContent.trim(), on: c.classList.contains('on') };
          sec.items.push(it); if (it.on) g.on = true;
          if (!pages[it.href]) pages[it.href] = { href: it.href, label: it.label, group: gl };
        }
      });
      if (g.sections.length) groups.push(g);
    }
  });
  if (!groups.length) return;

  /* ---- stars and recent pages (this person, this computer) ---- */
  var fav = load(K_FAV, []).filter(function (h) { return typeof h === 'string'; });
  var recent = load(K_REC, []).filter(function (h) { return typeof h === 'string'; });
  var here = null;
  groups.forEach(function (g) { g.sections.forEach(function (s) { s.items.forEach(function (i) { if (i.on && !here) here = i.href; }); }); });
  if (here) { recent = [here].concat(recent.filter(function (h) { return h !== here; })).slice(0, 7); save(K_REC, recent); }
  function isFav(h) { return fav.indexOf(h) >= 0; }
  function toggleFav(h) { if (isFav(h)) fav = fav.filter(function (x) { return x !== h; }); else fav.push(h); save(K_FAV, fav); }

  function link(it, withGroup) {
    var star = el('button', { type: 'button', class: 'zx-star' + (isFav(it.href) ? ' on' : ''), 'aria-label': (isFav(it.href) ? 'Remove the star from ' : 'Star ') + it.label, 'aria-pressed': isFav(it.href) ? 'true' : 'false', text: isFav(it.href) ? '★' : '☆',
      onclick: function (e) {
        e.preventDefault(); e.stopPropagation(); toggleFav(it.href);
        star.classList.toggle('on', isFav(it.href)); star.textContent = isFav(it.href) ? '★' : '☆'; star.setAttribute('aria-pressed', isFav(it.href) ? 'true' : 'false');
        if (boardEl) fillBoard();
      } });
    return el('a', { class: 'zx-item' + (it.on || it.href === here ? ' on' : ''), href: it.href }, [
      el('span', { class: 'zx-nm' }, [it.label, withGroup && pages[it.href] && pages[it.href].group ? el('small', { text: pages[it.href].group }) : null]), star]);
  }
  function sectionNodes(g) {
    var out = [];
    g.sections.forEach(function (s) {
      if (s.label) out.push(el('div', { class: 'zx-sub', text: s.label }));
      s.items.forEach(function (i) { out.push(link(i)); });
    });
    return out;
  }

  /* ---- A. the rail ---- */
  var rail = el('div', { class: 'zr', role: 'navigation', 'aria-label': 'Sections' });
  var fly = null, flyFor = null, hideT = null;
  function closeFly() { clearTimeout(hideT); if (fly) { fly.remove(); fly = null; } if (flyFor) { flyFor.setAttribute('aria-expanded', 'false'); flyFor = null; } }
  function softClose() { clearTimeout(hideT); hideT = setTimeout(closeFly, 320); }
  function openFly(btn, title, kids) {
    clearTimeout(hideT);
    if (flyFor === btn) return;
    closeFly();
    fly = el('div', { class: 'zr-fly', onmouseenter: function () { clearTimeout(hideT); }, onmouseleave: softClose }, [el('div', { class: 'zx-gh', text: title })].concat(kids));
    document.body.appendChild(fly);
    var r = btn.getBoundingClientRect(), h = fly.offsetHeight, top = Math.max(8, Math.min(r.top - 8, window.innerHeight - h - 8));
    fly.style.top = top + 'px'; fly.style.left = side.getBoundingClientRect().right + 'px';
    flyFor = btn; btn.setAttribute('aria-expanded', 'true');
  }
  rail.appendChild(el('button', { type: 'button', class: 'zr-b zr-all', title: 'All pages  ( / )', 'aria-label': 'All pages', html: SVG.grid, onclick: function () { closeFly(); openBoard(); }, onmouseenter: softClose }));
  groups.forEach(function (g) {
    if (g.solo) {
      rail.appendChild(el('a', { class: 'zr-b' + (g.on ? ' on' : ''), href: g.href, title: g.label, 'aria-label': g.label, html: g.icon, onmouseenter: softClose }));
      return;
    }
    var b = el('button', { type: 'button', class: 'zr-b' + (g.on ? ' on' : ''), title: g.label, 'aria-label': g.label, 'aria-expanded': 'false', html: g.icon,
      onmouseenter: function () { openFly(b, g.label, sectionNodes(g)); },
      onclick: function () { if (flyFor === b) closeFly(); else openFly(b, g.label, sectionNodes(g)); } });
    rail.appendChild(b);
  });
  rail.appendChild(el('span', { class: 'zr-sp' }));
  rail.appendChild(el('button', { type: 'button', class: 'zr-b', title: 'Show the full menu', 'aria-label': 'Show the full menu', html: SVG.pin, onmouseenter: softClose, onclick: function () { setMode('full'); } }));
  var userBox = side.querySelector('.side-user');
  if (userBox) {
    var nameEl = userBox.querySelector('strong'), uname = nameEl ? nameEl.textContent.trim() : 'You';
    var initials = uname.split(/\s+/).slice(0, 2).map(function (x) { return x.charAt(0).toUpperCase(); }).join('') || '?';
    var ub = el('button', { type: 'button', class: 'zr-b zr-user', title: uname, 'aria-label': 'Your account: ' + uname, 'aria-expanded': 'false', text: initials,
      onmouseenter: function () { openFly(ub, uname, userLinks()); }, onclick: function () { if (flyFor === ub) closeFly(); else openFly(ub, uname, userLinks()); } });
    rail.appendChild(ub);
  }
  function userLinks() {
    var out = [];
    Array.prototype.forEach.call(userBox.querySelectorAll('a[href]'), function (a) {
      var isOut = /logout\.php/.test(a.getAttribute('href'));
      out.push(el('a', { class: 'zx-item' + (isOut ? ' zx-out' : ''), href: a.getAttribute('href') }, [el('span', { class: 'zx-nm', text: isOut ? 'Logout' : 'My account' })]));
    });
    return out;
  }
  rail.addEventListener('mouseleave', softClose);
  side.insertBefore(rail, side.querySelector('.nav'));

  /* the fold button, shown beside the name while the full menu is open */
  var brand = side.querySelector('.brand');
  if (brand) brand.appendChild(el('button', { type: 'button', class: 'zr-fold', title: 'Fold the menu to icons', 'aria-label': 'Fold the menu to icons', html: SVG.fold, onclick: function () { setMode('rail'); } }));

  function apply() {
    var on = wide.matches && mode() === 'rail';
    root.classList.toggle('zm-rail', on);
    root.classList.toggle('zm-desk', wide.matches);
    if (!on) closeFly();
  }
  function setMode(m) { try { localStorage.setItem(K_MODE, m); } catch (e) {} apply(); }
  if (wide.addEventListener) wide.addEventListener('change', apply); else if (wide.addListener) wide.addListener(apply);
  apply();

  /* ---- C. the board ---- */
  var boardEl = null, shadeEl = null, bodyEl = null, inputEl = null, lastFocus = null;
  function matches(q) {
    var out = [];
    Object.keys(pages).forEach(function (h) { var p = pages[h]; if ((p.label + ' ' + p.group).toLowerCase().indexOf(q) >= 0) out.push({ href: p.href, label: p.label }); });
    return out;
  }
  function fillBoard() {
    var q = (inputEl.value || '').trim().toLowerCase();
    bodyEl.textContent = '';
    if (q) {
      var m = matches(q);
      bodyEl.appendChild(el('div', { class: 'zx-gh', text: m.length ? m.length + (m.length === 1 ? ' page' : ' pages') + ' — Enter opens the first' : 'No page with that name' }));
      bodyEl.appendChild(el('div', { class: 'zb-grid one' }, m.map(function (i) { return link(i, true); })));
      return;
    }
    var favs = fav.filter(function (h) { return pages[h]; }), recs = recent.filter(function (h) { return pages[h] && h !== here && !isFav(h); });
    if (favs.length || recs.length) {
      bodyEl.appendChild(el('div', { class: 'zx-gh', text: 'Starred and recent' }));
      bodyEl.appendChild(el('div', { class: 'zb-quick' }, favs.map(function (h) { return el('a', { class: 'zb-chip f', href: h, text: pages[h].label }); })
        .concat(recs.map(function (h) { return el('a', { class: 'zb-chip', href: h, text: pages[h].label }); }))));
    }
    var grid = el('div', { class: 'zb-grid' });
    groups.forEach(function (g) { grid.appendChild(el('div', { class: 'zb-col' }, [el('div', { class: 'zx-gh', text: g.label })].concat(sectionNodes(g)))); });
    bodyEl.appendChild(grid);
  }
  function openBoard() {
    if (boardEl) { inputEl.focus(); inputEl.select(); return; }
    closeFly();
    lastFocus = document.activeElement;
    shadeEl = el('div', { class: 'zb-shade', onclick: closeBoard });
    inputEl = el('input', { type: 'search', class: 'zb-in', placeholder: 'Type a page name…', 'aria-label': 'Search the menu', autocomplete: 'off', spellcheck: 'false',
      oninput: fillBoard,
      onkeydown: function (e) {
        if (e.key === 'Enter') { var m = matches((inputEl.value || '').trim().toLowerCase()); if (inputEl.value.trim() && m.length) window.location.href = m[0].href; }
        if (e.key === 'ArrowDown') { var f = bodyEl.querySelector('a'); if (f) { e.preventDefault(); f.focus(); } }
      } });
    bodyEl = el('div', { class: 'zb-body' });
    boardEl = el('div', { class: 'zb', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'All pages' }, [
      el('div', { class: 'zb-bar' }, [inputEl, el('button', { type: 'button', class: 'zb-close', text: 'Close (Esc)', onclick: closeBoard })]), bodyEl,
      el('div', { class: 'zb-foot', text: 'Press / on any page to open this. ☆ stars a page so it stays at the top. Stars and recent pages are kept on this computer.' })]);
    document.body.appendChild(shadeEl); document.body.appendChild(boardEl);
    fillBoard(); inputEl.focus();
  }
  function closeBoard() {
    if (!boardEl) return;
    boardEl.remove(); shadeEl.remove(); boardEl = shadeEl = bodyEl = inputEl = null;
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
  }

  /* "/" and Ctrl+K open the board on a desktop. Registered in the capture
     phase so it answers before the sidebar's older "/" shortcut, whose search
     box is hidden while the rail is showing. Typing in a field is never
     interrupted. */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { if (boardEl) { e.preventDefault(); closeBoard(); } else if (fly) closeFly(); return; }
    if (!wide.matches) return;
    var t = e.target || {}, typing = /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName || '') || t.isContentEditable;
    var slash = e.key === '/' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey;
    var ctrlK = (e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey;
    if (slash || ctrlK) { e.preventDefault(); e.stopPropagation(); openBoard(); }
  }, true);
  document.addEventListener('click', function (e) { if (fly && !fly.contains(e.target) && !rail.contains(e.target)) closeFly(); });
  window.addEventListener('scroll', function () { if (fly) closeFly(); }, { passive: true });
  window.zasMenuReady = true;
})();
