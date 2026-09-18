/* GRID — spreadsheet keys for a table of inputs.
   ---------------------------------------------------------------------
   Shared by the Proforma and the Commercial Invoice so the two cannot
   drift apart, the same way assets/js/lov.js is shared by the stock
   screens. It handles ONLY movement and bulk entry; what a cell means is
   the page's business.

     Tab / Shift+Tab   across the row, wrapping to the next or previous
     Enter             DOWN one row, same column — Excel's behaviour, and
                       the one people miss most in web forms
     Arrow up / down   same, without leaving the cell you are typing in
     Arrow left/right  ONLY AT THE EDGE OF THE TEXT. Inside a value the
                       arrows move the caret, exactly as they always have;
                       press left with the caret already at the start, or
                       right with it already at the end, and you step to the
                       next cell. This is how a spreadsheet's formula bar
                       behaves, and it costs nothing to learn because it only
                       ever happens where the old behaviour had nowhere left
                       to go. A grid that grabbed the arrows outright would
                       make correcting a typo impossible without the mouse.
     Home / End        first and last cell of the row
     Delete            with the cell selected but NOT being typed in, clears
                       it. Backspace is deliberately NOT bound: it means
                       "rub out the last character" to everybody, and
                       stealing it would delete a whole cell by surprise.
     Ctrl+D            copy the cell directly above
     paste             a tab-separated block from Excel fills across and
                       down, adding rows as it needs them
     Ctrl+S            save
     Alt+N             new row, cursor in its first cell

   Tab off the last cell of the last row adds a row rather than jumping
   to the buttons — on a fourteen-line invoice that is thirteen fewer
   trips to the mouse.

   The page supplies:
     cols      the data-c names, in Tab order
     addRow()  make one row and return it (already appended)
     onEdit(tr, col, value)   a cell changed
     onPaste(tr, col, value)  optional; same but during a block paste, so
                              a page can link a pasted product name
     afterChange()            optional; recalc totals, mark dirty */
window.GRID = (function () {
  'use strict';

  function cellsOf(tr, cols) {
    return cols.map(function (c) { return tr.querySelector('[data-c="' + c + '"]'); });
  }

  /* ---- reading what Excel actually puts on the clipboard -------------
     Excel does NOT hand over plain tab-separated text. A cell containing
     a line break (Alt+Enter), a tab, or a quote is wrapped in double
     quotes, and any quote inside it is doubled. Splitting such a block on
     "\n" tears that cell in half and shifts every column after it — one
     description with a line break in it silently corrupts the rest of the
     paste. So the block is parsed properly, the way a CSV reader would. */
  function parseBlock(txt) {
    var rows = [], row = [], cell = '', i = 0, inQ = false;
    txt = String(txt).replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    for (; i < txt.length; i++) {
      var ch = txt[i];
      if (inQ) {
        if (ch === '"') {
          if (txt[i + 1] === '"') { cell += '"'; i++; }   // "" is one quote
          else inQ = false;
        } else cell += ch;
        continue;
      }
      if (ch === '"' && cell === '') { inQ = true; continue; }
      if (ch === '\t') { row.push(cell); cell = ''; continue; }
      if (ch === '\n') { row.push(cell); rows.push(row); row = []; cell = ''; continue; }
      cell += ch;
    }
    row.push(cell);
    rows.push(row);
    // Excel adds a trailing newline when whole rows are copied
    while (rows.length > 1) {
      var last = rows[rows.length - 1];
      if (last.length === 1 && last[0].trim() === '') rows.pop(); else break;
    }
    return rows;
  }

  /* ---- numbers as Excel writes them ----------------------------------
     A qty cell shows 1,200 and a price cell shows $2.40 or PKR 1 234,00.
     Writing "1,200" straight into an <input type="number"> does not store
     1200 and does not store "1,200" — the browser stores NOTHING, and the
     cell goes blank without a word. That is the worst possible outcome on
     an invoice, so formatted numbers are cleaned before they are written.

     Conservative on purpose: a comma is only removed when it is a real
     thousands separator (followed by exactly three digits). "1,5" is left
     alone rather than guessed at, and reported instead. */
  function looksNumeric(el) {
    return !!el && (el.type === 'number' || (el.className || '').indexOf('num') >= 0);
  }
  function cleanNum(raw) {
    var s = String(raw).trim();
    if (s === '') return { value: '', ok: true };

    var neg = false;
    if (/^\(.*\)$/.test(s)) { neg = true; s = s.slice(1, -1).trim(); }  // (250)

    /* Currency marks and spaces are removed because they are decoration.
       NOTHING else is. The old version stripped every character it did
       not recognise, which turned "2-3" into 23 — a silent corruption far
       worse than refusing to read it. What is left must now BE a number,
       or the cell is reported instead of guessed at. */
    s = s.replace(/[$£€¥₨]/g, '')
         .replace(/\b(PKR|USD|EUR|GBP|AED|RS|INR)\b/gi, '')
         .replace(/[\s ']/g, '')
         .trim();

    if (/^[+-]/.test(s)) { if (s[0] === '-') neg = !neg; s = s.slice(1); }
    else if (/-$/.test(s)) { neg = !neg; s = s.slice(0, -1); }          // 250-

    var grouped = /^\d{1,3}(,\d{3})+(\.\d+)?$/;   // 1,200  1,234,567.50
    var plain = /^\d+(\.\d+)?$|^\.\d+$/;          // 1200   2.40   .5
    if (grouped.test(s)) s = s.replace(/,/g, '');
    else if (!plain.test(s)) return { value: raw, ok: false };

    return { value: (neg ? '-' : '') + s, ok: true };
  }

  function attach(root, opt) {
    var cols = opt.cols;

    function rows() {
      return [].slice.call(root.children).filter(function (tr) {
        return tr.querySelector('[data-c="' + cols[0] + '"]');
      });
    }
    function rowIndex(tr) { return rows().indexOf(tr); }
    function cellAt(ri, ci) {
      var rs = rows();
      if (ri < 0 || ri >= rs.length || ci < 0 || ci >= cols.length) return null;
      return rs[ri].querySelector('[data-c="' + cols[ci] + '"]');
    }
    function colOf(el) { return cols.indexOf(el.dataset.c); }

    /* Move by a column and/or a row delta, wrapping at the ends. Past the
       last row it makes one, so Tab and Enter both keep working forever. */
    function move(el, dc, dr) {
      var tr = el.closest('tr'), ri = rowIndex(tr), ci = colOf(el);
      var ni = ri + dr, nc = ci + dc;
      if (nc < 0) { nc = cols.length - 1; ni--; }
      if (nc >= cols.length) { nc = 0; ni++; }
      if (ni < 0) return;
      if (ni >= rows().length) {
        if (!opt.addRow) return;
        opt.addRow();
      }
      var t = cellAt(ni, nc);
      if (t) { t.focus(); if (t.select) t.select(); }
    }

    root.addEventListener('keydown', function (e) {
      var el = e.target;
      if (!el.dataset || colOf(el) < 0) return;

      /* An open LOV owns the arrows and Enter — it is choosing a row, not
         moving between cells. The page tells us by leaving the panel open. */
      var lovOpen = window.LOV && window.LOV.isOpen && window.LOV.isOpen();

      if (e.key === 'Tab') {
        if (lovOpen) return;                 // the LOV takes the row first
        e.preventDefault(); move(el, e.shiftKey ? -1 : 1, 0); return;
      }
      /* ENTER GOES TO THE NEXT FIELD, AND OFF THE END OF A LINE IT STARTS
         THE NEXT LINE. Asked for in those words: "when enter so go to next
         field and even line complete so go next line automatically".

         It used to go DOWN a row, which is Excel's rule. This is not a
         spreadsheet — it is a document being filled in, and on a document
         the thing after "Quantity" is "Rate", not the next line's quantity.
         move() already wraps past the last column and makes a row when it
         runs out, so the whole document is one long Enter.

         DOWN IS NOT LOST: the down arrow still does it, and now that is the
         only thing it means. Shift+Enter walks back a field. */
      if (e.key === 'Enter') {
        if (lovOpen) return;
        e.preventDefault(); move(el, e.shiftKey ? -1 : 1, 0); return;
      }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (lovOpen) return;
        e.preventDefault(); move(el, 0, e.key === 'ArrowDown' ? 1 : -1); return;
      }

      /* LEFT AND RIGHT, AT THE EDGE ONLY.
         The caret keeps the arrows while there is text to move through; the
         grid only takes them once there is not. A select has no caret, so it
         steps immediately. Any selected range is left alone — Shift+Arrow is
         still selecting text, not navigating. */
      if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        if (lovOpen || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey) return;
        var isSel = el.tagName === 'SELECT';
        var atStart = isSel || (el.selectionStart === 0 && el.selectionEnd === 0);
        var atEnd   = isSel || (el.selectionStart === el.value.length &&
                                el.selectionEnd === el.value.length);
        if (e.key === 'ArrowLeft'  && !atStart) return;
        if (e.key === 'ArrowRight' && !atEnd)   return;
        e.preventDefault();
        move(el, e.key === 'ArrowRight' ? 1 : -1, 0);
        return;
      }

      if (e.key === 'Home' || e.key === 'End') {
        if (lovOpen || e.ctrlKey || e.metaKey) return;
        /* same edge rule: Home inside a value still jumps the caret */
        if (el.tagName !== 'SELECT') {
          var caretMid = el.selectionStart !== (e.key === 'Home' ? 0 : el.value.length);
          if (caretMid) return;
        }
        e.preventDefault();
        var tr0 = el.closest('tr'), r0 = rowIndex(tr0);
        var t0 = cellAt(r0, e.key === 'Home' ? 0 : cols.length - 1);
        if (t0) { t0.focus(); if (t0.select) t0.select(); }
        return;
      }

      /* DELETE CLEARS A SELECTED CELL, and only a selected one.
         "Selected" here means the whole value is highlighted — which is the
         state every move above leaves the cell in, because they all select().
         With a caret sitting in the middle of a word, Delete still removes one
         character, because that is what it has always done. */
      if (e.key === 'Delete') {
        if (lovOpen || el.tagName === 'SELECT') return;
        var whole = el.selectionStart === 0 && el.selectionEnd === el.value.length
                    && el.value.length > 0;
        if (!whole) return;
        e.preventDefault();
        el.value = '';
        if (opt.onEdit) opt.onEdit(el.closest('tr'), el.dataset.c, '');
        if (opt.afterChange) opt.afterChange();
        return;
      }
      if ((e.ctrlKey || e.metaKey) && (e.key === 'd' || e.key === 'D')) {
        e.preventDefault();
        var tr = el.closest('tr'), ri = rowIndex(tr);
        if (ri <= 0) return;
        var above = cellAt(ri - 1, colOf(el));
        if (!above) return;
        el.value = above.value;
        if (opt.onEdit) opt.onEdit(tr, el.dataset.c, el.value, above.closest('tr'));
        if (opt.afterChange) opt.afterChange();
        return;
      }
    });

    /* A block from Excel. Anything without a tab or a newline is a normal
       one-cell paste and is left to the browser. */
    root.addEventListener('paste', function (e) {
      var el = e.target;
      if (!el.dataset || colOf(el) < 0) return;
      var txt = (e.clipboardData || window.clipboardData).getData('text');
      if (txt.indexOf('\t') < 0 && txt.indexOf('\n') < 0) return;
      e.preventDefault();

      var ri0 = rowIndex(el.closest('tr')), ci0 = colOf(el);
      var block = parseBlock(txt);
      var bad = [];                       // cells that could not be read

      block.forEach(function (line, dr) {
        var ri = ri0 + dr;
        while (ri >= rows().length) { if (!opt.addRow) return; opt.addRow(); }
        var tr = rows()[ri];
        if (!tr) return;
        line.forEach(function (val, dc) {
          var ci = ci0 + dc;
          if (ci >= cols.length) return;  // extra Excel columns are dropped
          var cell = cellAt(ri, ci);
          if (!cell) return;
          var v = String(val).trim();
          if (looksNumeric(cell)) {
            var n = cleanNum(v);
            if (!n.ok) bad.push({ row: ri + 1, col: cols[ci], text: v });
            else v = n.value;
          }
          cell.value = v;
          if (opt.onPaste) opt.onPaste(tr, cols[ci], cell.value);
          else if (opt.onEdit) opt.onEdit(tr, cols[ci], cell.value);
        });
        if (opt.afterRowPaste) opt.afterRowPaste(tr);
      });
      if (opt.afterChange) opt.afterChange();
      /* Say so rather than leaving a blank cell behind. A number input
         given text it cannot read stores nothing at all, and an invoice
         line quietly worth zero is worse than a warning. */
      if (bad.length && opt.onPasteProblem) opt.onPasteProblem(bad);
      var back = cellAt(ri0, ci0); if (back) back.focus();
    });

    root.addEventListener('input', function (e) {
      var el = e.target;
      if (!el.dataset || colOf(el) < 0) return;
      if (opt.onEdit) opt.onEdit(el.closest('tr'), el.dataset.c, el.value);
      if (opt.afterChange) opt.afterChange();
    });

    return {
      rows: rows,
      focusFirst: function (tr) {
        var c = tr.querySelector('[data-c="' + cols[0] + '"]');
        if (c) { c.focus(); if (c.select) c.select(); }
      }
    };
  }

  /* exposed so the paste behaviour can be tested on its own */
  attach.parseBlock = parseBlock;
  attach.cleanNum = cleanNum;

  /* Ctrl+S, Alt+N, and an honest unsaved-changes state.

     A form you use twenty times a day should never make you hunt for the
     Save button, and should never let you close the tab on work you
     thought was saved. */
  function keys(form, opt) {
    var dirty = false;
    var badge = opt.badge ? document.querySelector(opt.badge) : null;

    function paint() {
      if (!badge) return;
      badge.textContent = dirty ? '● unsaved — Ctrl+S' : 'saved';
      badge.style.color = dirty ? '#9a5710' : '#8a97ab';
      badge.style.fontWeight = dirty ? '700' : '400';
    }
    function touch() { if (!dirty) { dirty = true; paint(); } }
    function clean() { dirty = false; paint(); }

    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
        e.preventDefault();                     // never the browser's save-page
        dirty = false;                          // the submit itself is the save
        if (opt.beforeSave) opt.beforeSave();
        form.submit();
        return;
      }
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        if (opt.savePrint) { dirty = false; opt.savePrint(); }
        return;
      }
      if (e.altKey && (e.key === 'n' || e.key === 'N')) {
        e.preventDefault();
        if (opt.addRow) { var tr = opt.addRow(); if (tr && opt.focusFirst) opt.focusFirst(tr); touch(); }
        return;
      }
      /* ESCAPE LEAVES, AND ASKS FIRST IF THERE IS ANYTHING TO LOSE.
         The same bargain every office program makes: Esc means "I am done
         here", and the only time it stops to talk is when leaving would
         throw work away.

         An open picker owns Escape first — there it means "close this
         list", which is what lov.js does with it, and it must not also
         close the whole screen. */
      if (e.key === 'Escape') {
        if (window.LOV && window.LOV.isOpen && window.LOV.isOpen()) return;
        var back = opt.escapeTo || '';
        if (!back) return;
        if (dirty && !confirm('This ' + (opt.what || 'form') + ' has changes that are not saved.\n\n'
                              + 'Leave anyway and lose them?')) { e.preventDefault(); return; }
        e.preventDefault();
        dirty = false;                        // the question has been asked and answered
        window.location.href = back;
      }
    });

    window.addEventListener('beforeunload', function (e) {
      if (!dirty) return;
      e.preventDefault(); e.returnValue = '';
    });
    if (form) form.addEventListener('submit', function () { dirty = false; });

    paint();
    return { touch: touch, clean: clean, isDirty: function () { return dirty; } };
  }

  return { attach: attach, keys: keys };
})();
