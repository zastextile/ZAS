/* Final Costing — the amount maths and the row behaviour.
   Moved out of final_costing.php unchanged, so the browser caches it
   once instead of re-reading it on every load.

   Declarations only: nothing here runs at load. The LOV/GRID wiring that
   follows it in the page stays inline, because it carries the item list
   rendered by PHP and cannot be cached. */
/* Same amount formula as Product Costing's own lineAmt(): shared = rate ÷ qty, normal = qty × rate. */
function fcLineAmt(qty, rate, shared){ return shared ? (qty>0 ? rate/qty : 0) : qty*rate; }
function fcCalc(el){
  var tr = el.closest('tr');
  var qty = parseFloat(tr.querySelector('[name="quantity[]"]').value) || 0;
  var rate = parseFloat(tr.querySelector('[name="rate[]"]').value) || 0;
  var shared = tr.querySelector('[name="shared[]"]').value === '1';
  var amt = fcLineAmt(qty, rate, shared);
  var cell = tr.querySelector('.fc-amt');
  if (cell) cell.innerHTML = amt.toFixed(2) + (shared ? ' <span style="color:#6d5bd0;font-weight:400">÷</span>' : '');
}
function fcToggleShared(btn){
  var tr = btn.closest('tr');
  var hidden = tr.querySelector('[name="shared[]"]');
  var on = hidden.value !== '1';
  hidden.value = on ? '1' : '0';
  btn.classList.toggle('on', on);
  btn.title = on ? 'Shared: amount = rate ÷ qty' : 'Normal: amount = qty × rate. Click for shared (÷).';
  fcCalc(btn);
}
/* A new row must be byte-identical in shape to a PHP-rendered one, or the
   spreadsheet keys skip it — GRID finds rows by their data-c cells. */
function fcAddRow(itemId, nativeCur){
  var tbl = document.getElementById('fcTable'+itemId).querySelector('tbody');
  var tr = document.createElement('tr');
  tr.innerHTML = '<td><select class="fc-in" data-c="grp" name="line_group[]"><option>Fabric</option><option>Accessories</option><option>Packing</option><option>Workmanship</option><option selected>Other</option></select></td>'
    + '<td><input class="fc-in lovf" data-lov="fcitem" data-c="item" name="item_name[]" autocomplete="off" value=""></td>'
    + '<td><input class="fc-in" data-c="desc" name="description[]" value=""></td>'
    + '<td><div class="qtywrap"><input class="fc-in mini" data-c="qty" type="number" step="0.001" name="quantity[]" value="0" oninput="fcCalc(this)"><button type="button" class="shbtn" tabindex="-1" title="Normal: amount = qty × rate. Click for shared (÷)." onclick="fcToggleShared(this)">÷</button><input type="hidden" name="shared[]" value="0"></div></td>'
    + '<td><input class="fc-in mini" data-c="unit" name="unit[]" value=""></td>'
    + '<td><input class="fc-in mini" data-c="wt" type="number" step="0.001" name="weight_kg[]" value="0"></td>'
    + '<td><input class="fc-in mini" data-c="rate" type="number" step="0.01" name="rate[]" value="0" oninput="fcCalc(this)"><input type="hidden" name="native_currency[]" value="'+nativeCur+'"></td>'
    + '<td class="num fc-amt">0.00</td>'
    + '<td><button type="button" class="zbtn red sm" tabindex="-1" onclick="this.closest(\'tr\').remove();fcRecalcTotal(this)">✕</button></td>';
  tbl.appendChild(tr);
  return tr;
}

/* The draft total under the grid, kept live so you are not saving to find
   out what the line came to. */
function fcRecalcTotal(el){
  var card = el.closest ? el.closest('[id^=fcCard]') : null;
  if(!card) return;
  var tb = card.querySelector('tbody[data-fcgrid]');
  var out = card.querySelector('[id^=fcTotal]');
  if(!tb || !out) return;
  var sum = 0;
  [].slice.call(tb.children).forEach(function(tr){
    var q = tr.querySelector('[data-c="qty"]'), r = tr.querySelector('[data-c="rate"]'),
        s = tr.querySelector('[name="shared[]"]');
    if(!q || !r) return;
    var qty = parseFloat(q.value)||0, rate = parseFloat(r.value)||0;
    sum += (s && s.value==='1') ? (qty>0 ? rate/qty : 0) : qty*rate;
  });
  var cur = (out.textContent.trim().split(/\s+/)[0]) || '';
  out.textContent = cur + ' ' + sum.toFixed(2);
}

/* Every per-line form (Save Draft, Confirm match, Link & Pull Costing, per-line
   Re-sync, Lock, Reopen) carries onsubmit="return fcAjaxSubmit(event,this)" —
   this is what makes the whole page stop reloading on every click. The server
   handler runs exactly the same logic as before, but when it sees ajax=1 it
   returns {success,message,html} for just this one card instead of redirecting
   the whole page; html is a fresh fc_render_line_card() render, swapped in via
   outerHTML so it's always byte-identical to what a full reload would show. */
function fcAjaxSubmit(event, form){
  event.preventDefault();
  var confirmMsg = form.dataset.confirm;
  if (confirmMsg && !confirm(confirmMsg)) return false;
  var itemId = form.dataset.itemId;
  var fd = new FormData(form);
  fd.append('ajax', '1');
  var btn = form.querySelector('button[type=submit], button:not([type])');
  if (btn) btn.disabled = true;
  fetch(form.action, {method:'POST', body:fd}).then(function(r){ return r.json(); }).then(function(d){
    if (d.html && itemId){
      var card = document.getElementById('fcCard'+itemId);
      if (card) {
        card.outerHTML = d.html;
        /* outerHTML replaces the element, so everything bound to the OLD
           tbody is gone with it. Inline handlers (fcCalc, fcToggleShared)
           re-bind themselves; the spreadsheet keys do not, because they were
           attached in JS. Re-wire the card that just arrived or Tab and Enter
           quietly stop working on it after the first save. */
        if (window.fcWire) window.fcWire(document.getElementById('fcCard'+itemId));
      }
    } else if (btn) btn.disabled = false;
    fcToast(d.message || (d.success ? 'Saved.' : 'Something went wrong.'), !!d.success);
  }).catch(function(){
    if (btn) btn.disabled = false;
    fcToast('Network error — please try again.', false);
  });
  return false;
}
