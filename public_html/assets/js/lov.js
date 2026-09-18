/* LOV — List of Values.
   ---------------------------------------------------------------------
   One implementation, used by Gate passes, Consumption and Store issues.
   It was written three times before this file existed, and three copies
   of anything drift: the gate screen had balances and the consumption
   screen did not, and neither behaved the same on the keyboard.

   What it is, and what it is not. It is NOT a search box that shows
   suggestions after you type. Entering the cell opens the list with the
   valid rows already on screen, with headings and numbers; typing only
   reduces what you are already reading. That is the difference between
   an Oracle-style LOV and an autocomplete, and it is the whole point.

     arrows      move          Enter   take the highlighted row
     Home/End    jump          Tab     take it and move on
     Esc         close, change nothing

   A page registers one provider per kind of field, marks its fields with
   data-lov="<kind>", and calls LOV.attach(container). Everything else —
   the panel, the keyboard, the positioning, the fuzzy matching — is here.

   The panel is position:fixed because these fields live inside tables
   that scroll sideways; an absolutely positioned one is clipped by the
   scroll container. */
window.LOV = (function () {
  'use strict';

  var PROV = {};
  var el = null;
  var S = { open: false, f: null, kind: '', rows: [], idx: 0, showAll: false, hidden: 0, busy: false, hold: false };

  /* ---------------------------------------------------------- helpers */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function norm(s) { return String(s == null ? '' : s).toLowerCase().replace(/[^a-z0-9]/g, ''); }

  /* Every letter present, in order — and how tightly packed. "btn" is in
     BUTTON and also in COTTON GREIGE, so presence alone is not enough:
     the tighter run is the one you meant. */
  function tight(q, h) {
    var best = 0;
    for (var st = 0; st < h.length; st++) {
      if (h[st] !== q[0]) continue;
      var i = 1, j = st + 1;
      for (; j < h.length && i < q.length; j++) if (h[j] === q[i]) i++;
      if (i === q.length) best = Math.max(best, q.length / (j - st));
    }
    return best;
  }
  /* Letter-pair overlap — this is what turns "thred" into THREAD. */
  function dice(a, b) {
    if (a.length < 2 || b.length < 2) return 0;
    var pa = [], pb = [], i;
    for (i = 0; i < a.length - 1; i++) pa.push(a.substr(i, 2));
    for (i = 0; i < b.length - 1; i++) pb.push(b.substr(i, 2));
    var hit = 0, used = {};
    pa.forEach(function (p) {
      for (var k = 0; k < pb.length; k++) if (pb[k] === p && !used[k]) { used[k] = 1; hit++; return; }
    });
    return (2 * hit) / (pa.length + pb.length);
  }
  /* Four rules, in order of confidence. 0 means "not close enough" and
     the row is dropped; with no search text everything ties at 50 so the
     list keeps whatever order the provider gave it. */
  function score(raw, code, name, extra) {
    var q = norm(raw); if (!q) return 50;
    var c = norm(code), n = norm(name), all = norm(String(code) + name + (extra || ''));
    if (c && c === q) return 100;
    if (c && c.indexOf(q) === 0) return 92;
    if (n.indexOf(q) === 0) return 88;
    if (all.indexOf(q) >= 0) return 78;
    var t = tight(q, all); if (t) return 50 + Math.round(t * 25);
    var d = Math.max(dice(q, n), dice(q, all));
    if (d >= 0.34) return Math.round(d * 45);
    return 0;
  }
  /* Highlight the letters that matched, in the order they matched. */
  function hl(txt, raw) {
    txt = String(txt == null ? '' : txt);
    var q = norm(raw); if (!q) return esc(txt);
    var out = '', qi = 0;
    for (var i = 0; i < txt.length; i++) {
      var ch = txt[i], n = norm(ch);
      if (n && qi < q.length && n === q[qi]) { out += '<mark>' + esc(ch) + '</mark>'; qi++; }
      else out += esc(ch);
    }
    return out;
  }
  function q3(v) { return Number(v).toLocaleString('en-US', { maximumFractionDigits: 3 }); }
  function m2(v) { return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  /* ------------------------------------------------------------ panel */
  function build() {
    if (el) return;
    el = document.createElement('div');
    el.className = 'lov';
    el.innerHTML =
      '<div class="lov-t"><span class="ttl">Select</span><span class="cnt"></span></div>' +
      '<div class="lov-h"></div><div class="lov-b"></div>' +
      '<div class="lov-f"><span><kbd>&uarr;</kbd><kbd>&darr;</kbd> move &nbsp;<kbd>Enter</kbd> choose' +
      ' &nbsp;<kbd>Esc</kbd> close</span><span class="xtra"></span></div>';
    document.body.appendChild(el);

    el.addEventListener('mousedown', function (e) {
      var a = e.target.closest('a[data-more]');
      if (a) { e.preventDefault(); S.showAll = a.dataset.more === '1'; S.idx = 0; load(); return; }
      var row = e.target.closest('.lov-r');
      if (!row) return;
      e.preventDefault(); S.idx = +row.dataset.i; take();
    });
    window.addEventListener('resize', close);
    window.addEventListener('scroll', function () { if (S.open) place(); }, true);
  }

  function tracks(p) { return p.cols.map(function (c) { return c.w || '1fr'; }).join(' '); }

  function place() {
    if (!S.f || !el) return;
    var r = S.f.getBoundingClientRect(), h = el.offsetHeight || 260;
    var below = window.innerHeight - r.bottom;
    el.style.left = Math.max(8, Math.min(r.left, window.innerWidth - el.offsetWidth - 8)) + 'px';
    if (below < h + 10 && r.top > below) {
      el.style.top = 'auto'; el.style.bottom = (window.innerHeight - r.top + 4) + 'px';
    } else {
      el.style.bottom = 'auto'; el.style.top = (r.bottom + 4) + 'px';
    }
  }

  function open(field, kind) {
    var p = PROV[kind]; if (!p) return;
    build();
    S.open = true; S.f = field; S.kind = kind; S.idx = 0; S.showAll = false; S.rows = []; S.hidden = 0;
    el.classList.add('open');
    var h = el.querySelector('.lov-h');
    h.style.gridTemplateColumns = tracks(p);
    h.innerHTML = p.cols.map(function (c) {
      return '<span' + (c.align === 'r' ? ' style="text-align:right"' : '') + '>' + esc(c.label) + '</span>';
    }).join('');
    load();
  }
  function close() { S.open = false; if (el) el.classList.remove('open'); }

  /* The provider may answer at once or fetch; either way it calls back. */
  function load() {
    if (!S.open) return;
    var p = PROV[S.kind], q = S.f ? (S.f.value || '') : '';
    S.busy = true;
    p.rows(S.f, q, S.showAll, function (rows, hidden) {
      S.busy = false;
      if (!S.open) return;
      S.rows = rows || []; S.hidden = hidden || 0;
      if (S.idx >= S.rows.length) S.idx = Math.max(0, S.rows.length - 1);
      /* A list that OPENS with the cursor on its own heading would book
         nothing on the first Enter. */
      S.idx = skipIdx(S.idx, 1);
      draw();
      place();
    });
    if (S.busy) {                       // something to look at while it fetches
      el.querySelector('.lov-b').innerHTML = '<div class="lov-none">Reading the ledger…</div>';
      place();
    }
  }

  function draw() {
    var p = PROV[S.kind], q = S.f ? (S.f.value || '') : '';
    var body = el.querySelector('.lov-b'), cols = tracks(p);

    el.querySelector('.ttl').textContent = p.title ? p.title(S.f) : 'Select';
    var total = S.rows.length + S.hidden;
    el.querySelector('.cnt').textContent =
      S.rows.length + (S.hidden ? ' of ' + total : '') + ' shown';

    if (!S.rows.length) {
      body.innerHTML = '<div class="lov-none">' + (p.empty ? p.empty(S.f, q) : 'Nothing matches.') + '</div>';
    } else {
      body.innerHTML = S.rows.map(function (r, i) {
        /* A SECTION HEADING IS A ROW THAT CANNOT BE CHOSEN.
           One list can now hold two kinds of thing — contract lines and
           free stock, say — with a heading between them. It carries no
           data-i, so a click cannot take it; skipIdx() steps the arrow
           keys past it; and take() refuses it. Three guards, because a
           list that books a heading as an item would be worse than no
           heading at all. */
        if (r && r.__sep) return '<div class="lov-sep">' + esc(r.__sep) + '</div>';
        return '<div class="lov-r' + (i === S.idx ? ' on' : '') + '" data-i="' + i +
          '" style="grid-template-columns:' + cols + '">' +
          p.cols.map(function (c) {
            return '<span class="' + (c.cls || '') + '"' +
              (c.style ? ' style="' + c.style(r) + '"' : '') + '>' + c.get(r, q) + '</span>';
          }).join('') + '</div>';
      }).join('');
    }

    var x = el.querySelector('.xtra');
    if (S.hidden > 0 && !S.showAll)
      x.innerHTML = '<a href="#" data-more="1">show ' + S.hidden + ' ' + (p.moreLabel || 'not available here') + '</a>';
    else if (S.showAll && p.lessLabel)
      x.innerHTML = '<a href="#" data-more="0" style="color:#0b5f8a">' + p.lessLabel + '</a>';
    else x.textContent = q ? 'filtered by "' + q + '"' : '';

    var on = body.querySelector('.lov-r.on'); if (on) on.scrollIntoView({ block: 'nearest' });
  }

  /* Move the cursor from `from` in direction `step`, stopping on the first
     row that is not a heading. Returns where it landed, or where it started
     when there is nowhere to go — so holding an arrow at the end of a list
     cannot park the cursor on a heading. */
  function skipIdx(from, step) {
    var i = from;
    while (i >= 0 && i < S.rows.length && S.rows[i] && S.rows[i].__sep) i += step;
    if (i < 0 || i >= S.rows.length) {
      /* ran off the end — come back the other way rather than stop on a heading */
      i = from;
      while (i >= 0 && i < S.rows.length && S.rows[i] && S.rows[i].__sep) i -= step;
    }
    return (i >= 0 && i < S.rows.length) ? i : from;
  }

  function take() {
    if (!S.rows.length || !S.f) return;
    var p = PROV[S.kind], row = S.rows[S.idx], f = S.f;
    if (!row || row.__sep) return;      // a heading is not an answer
    close();
    p.pick(f, row);
  }

  /* --------------------------------------------------------- wiring */
  function attach(root) {
    build();
    root.addEventListener('focusin', function (e) {
      var t = e.target;
      /* Moving to something that is NOT a picker closes the list — and has
         to put a half-typed search back, exactly as the focusout timer
         does. It used to close() and return, which skipped the revert and
         also left S.open false so the timer skipped it too. Nothing put it
         back.

         That is not cosmetic. Type "sate" in a gate pass item box, then
         click or tab straight into Quantity without choosing: the box
         still reads "sate", the hidden <select> behind it holds nothing,
         the line looks filled in, and on save it is dropped for having no
         item. Silently. Reverting here makes the box go empty, which is
         the truth — nothing was chosen. */
      if (!t.dataset || !t.dataset.lov || !PROV[t.dataset.lov]) {
        if (S.open) {
          var was = S.f, wasKind = S.kind;
          close();
          if (PROV[wasKind] && PROV[wasKind].revert) PROV[wasKind].revert(was);
        }
        return;
      }
      if (t.select) t.select();
      open(t, t.dataset.lov);
    });
    root.addEventListener('input', function (e) {
      if (!S.open || e.target !== S.f) return;
      S.idx = 0; load();
    });
    root.addEventListener('keydown', function (e) {
      /* ENTER OPENS THE LIST WHEN IT IS SHUT — asked for directly: "I don't
         want to use the mouse".
         Focus already opens it, so this is the way back in after Escape, or
         after a pick, without reaching for the mouse. Down-arrow does the
         same, because that is what a combo box does everywhere else.

         stopImmediatePropagation because the spreadsheet keys live on this
         SAME element: without it, Enter would open the list AND move the
         cursor down a row, and the list would then belong to the cell above.
         The grid's own guard (window.LOV.isOpen) covers the case where its
         listener runs first, so between the two the order cannot matter. */
      if (!S.open) {
        var t0 = e.target;
        if ((e.key === 'Enter' || e.key === 'ArrowDown')
            && t0 && t0.dataset && t0.dataset.lov && PROV[t0.dataset.lov]) {
          e.preventDefault();
          e.stopImmediatePropagation();
          if (t0.select) t0.select();
          open(t0, t0.dataset.lov);
        }
        return;
      }
      if (e.target !== S.f) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); S.idx = skipIdx(Math.min(S.idx + 1, S.rows.length - 1), 1); draw(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); S.idx = skipIdx(Math.max(S.idx - 1, 0), -1); draw(); }
      else if (e.key === 'Home') { e.preventDefault(); S.idx = skipIdx(0, 1); draw(); }
      else if (e.key === 'End') { e.preventDefault(); S.idx = skipIdx(S.rows.length - 1, -1); draw(); }
      else if (e.key === 'Enter') {
        /* A PROVIDER MAY ASK TO STAY PUT.
           take() closes the panel BEFORE the page's other keydown handlers
           run, so a spreadsheet grid on the same element asks "is a list
           open?", is told no, and moves the cursor down a row as well. For
           most fields that is wanted — choose, and on to the next line. For
           a field that holds a LIST, it is not: you have just added one
           stage and want to add another, and the cursor has left the cell.
           Measured, not guessed: the cell kept its value and focus was two
           rows away.

           stopImmediatePropagation alone did NOT fix it — it only silences
           listeners registered after ours on this same element, and that
           ordering is not ours to guarantee. So instead the answer to "is a
           list open?" is held true for the rest of THIS key press. Whoever
           asks, wherever their listener sits, gets told yes and keeps its
           hands off the cursor. The hold is released on the next tick. */
        var pEnter = PROV[S.kind];
        e.preventDefault();
        if (pEnter && pEnter.stayOnEnter) {
          S.hold = true;
          setTimeout(function () { S.hold = false; }, 0);
        }
        take();
        if (pEnter && pEnter.stayOnEnter) e.stopImmediatePropagation();
      }
      else if (e.key === 'Tab') { if (S.rows.length) { e.preventDefault(); take(); } else close(); }
      else if (e.key === 'Escape') { e.preventDefault(); close(); if (PROV[S.kind].revert) PROV[S.kind].revert(S.f); }
    });
    root.addEventListener('focusout', function (e) {
      if (e.target !== S.f) return;
      var f = S.f, k = S.kind;
      setTimeout(function () {
        /* S.f === f IS LOAD-BEARING. Without it this timer closed a panel
           that had nothing to do with the field it was scheduled for.

           Leaving one picker and landing on another within 140ms is not an
           edge case — it is the normal keyboard flow. "+ Add line" focuses
           the new row's picker; Enter on a quantity moves to the next
           line's picker. The new panel opens, then this timer, armed by
           the OLD field, fires and finds S.open true and activeElement not
           equal to the old field, so it closed the new one. The list
           vanished a fraction of a second after appearing, and the next
           Enter chose nothing.

           The timer now only acts if the panel is still the one it was
           armed for. Reproduced with two plain inputs and the real file
           before the fix, and again after. */
        if (S.open && S.f === f && document.activeElement !== f) {
          close();
          // a half-typed search must not be mistaken for a choice
          if (PROV[k] && PROV[k].revert) PROV[k].revert(f);
        }
      }, 140);
    });
  }

  return {
    register: function (name, p) { PROV[name] = p; },
    attach: attach,
    close: close,
    /* S.hold keeps this true for the rest of a key press in which a
       stay-put provider just took a row — see the Enter branch. */
    isOpen: function () { return S.open || S.hold; },
    // exposed so a provider can rank and highlight the same way
    score: score, hl: hl, esc: esc, norm: norm, q3: q3, m2: m2
  };
})();
