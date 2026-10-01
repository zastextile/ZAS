function n(v){return parseFloat(String(v||'0').replace(/,/g,''))||0}
/* Quantity/weight display: trims pointless trailing zeros (0.400 -> 0.4). */
function fmtNum(v,maxDec){return n(v).toLocaleString('en-US',{minimumFractionDigits:0,maximumFractionDigits:maxDec===undefined?3:maxDec})}
/* Money display: always exactly 2 decimals (standard currency format) + thousands separators. */
function fmtMoney(v){return n(v).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}

function recalcInvoice(){
  let qtyTotal=0, sub=0;
  document.querySelectorAll('#invoiceRows tr.item-row').forEach((tr,i)=>{
    if(tr.cells[0]){
      /* Sr number lives in a wrapper <div> on the current row markup;
         fall back to a bare text node for any older row structure. */
      var numEl = tr.cells[0].querySelector('div');
      if(numEl) numEl.textContent = (i+1);
      else if(tr.cells[0].childNodes[0]) tr.cells[0].childNodes[0].nodeValue=(i+1);
    }
    const qty=n(tr.querySelector('[name="qty[]"]')?.value);
    const rate=n(tr.querySelector('[name="rate[]"]')?.value);
    const amt=qty*rate;
    qtyTotal+=qty; sub+=amt;
    const cell=tr.querySelector('.amount-cell'); if(cell) cell.textContent=fmtMoney(amt);
  });
  let charges=0;
  document.querySelectorAll('.charge-amount').forEach(el=>charges+=n(el.value));
  const q=document.getElementById('invoiceQtyTotal'); if(q) q.textContent=fmtNum(qtyTotal,3);
  const s=document.getElementById('invoiceSubtotal'); if(s) s.textContent=fmtMoney(sub);
  const f=document.getElementById('finalValue'); if(f) f.textContent=fmtMoney(sub+charges);
}

/* addInvoiceRow() lives inline in shipment_view.php (it needs to know
   optOn/showRates and match the current Dept/zin-styled row markup exactly).
   It used to also be defined here — since this script loads after that
   inline one (page_footer() prints it last), this copy was silently
   winning and replacing the correct row with an old unstyled one missing
   the Dept field, which also misaligned every department[] value saved
   after it. Removed; do not re-add a same-named function here. */

function recalcPacking(){
  let pkgTotal=0, qtyTotal=0, net=0, gross=0;
  document.querySelectorAll('#packingRows tr.pack-row').forEach((tr,i)=>{
    if(tr.cells[0]) tr.cells[0].childNodes[0].nodeValue=(i+1);
    const from=n(tr.querySelector('[name="carton_from[]"]')?.value);
    const to=n(tr.querySelector('[name="carton_to[]"]')?.value);
    const qpc=n(tr.querySelector('[name="qty_per_carton[]"]')?.value);
    const pkg=Math.max(0,to-from+1);
    const tq=pkg*qpc;
    pkgTotal+=pkg; qtyTotal+=tq;
    net+=n(tr.querySelector('[name="net_weight[]"]')?.value);
    gross+=n(tr.querySelector('[name="gross_weight[]"]')?.value);
    const pc=tr.querySelector('.pkg-cell'); if(pc) pc.textContent=fmtNum(pkg,3);
    const tc=tr.querySelector('.totalqty-cell'); if(tc) tc.textContent=fmtNum(tq,3);
  });
  const p=document.getElementById('packPkgTotal'); if(p) p.textContent=fmtNum(pkgTotal,3);
  const q=document.getElementById('packQtyTotal'); if(q) q.textContent=fmtNum(qtyTotal,3);
  const ne=document.getElementById('packNetTotal'); if(ne) ne.textContent=fmtNum(net,3);
  const gr=document.getElementById('packGrossTotal'); if(gr) gr.textContent=fmtNum(gross,3);
}

function buildInvoiceItemSelect(){
  let opts='';
  (window.invoiceItemOptions || []).forEach(o=>{
    opts += `<option value="${o.id}">${escapeHtml(o.label)}</option>`;
  });
  return `<select name="invoice_item_id[]">${opts}</select>`;
}

function addPackingSelectRow(){
  const tbody=document.querySelector('#packingRows');
  if(!tbody) return;
  if(!window.invoiceItemOptions || window.invoiceItemOptions.length===0){
    alert('Please add commercial invoice items first.');
    return;
  }
  const nrows=tbody.querySelectorAll('tr.pack-row').length+1;
  const tr=document.createElement('tr');
  tr.className='pack-row';
  tr.innerHTML=`<td>${nrows}</td>
<td>${buildInvoiceItemSelect()}</td>
<td><input name="carton_from[]" value="0" oninput="recalcPacking()"></td>
<td><input name="carton_to[]" value="0" oninput="recalcPacking()"></td>
<td class="num pkg-cell">0</td>
<td><input name="qty_per_carton[]" value="0" oninput="recalcPacking()"></td>
<td class="num totalqty-cell">0</td>
<td><input name="net_weight[]" value="0" oninput="recalcPacking()"></td>
<td><input name="gross_weight[]" value="0" oninput="recalcPacking()"></td>
<td><button type="button" class="btn red" onclick="this.closest('tr').remove();recalcPacking()">X</button></td>`;
  tbody.appendChild(tr); recalcPacking();
}

function confirmLockedEdit(){
  const reason=document.querySelector('[name="amendment_reason"]');
  if(reason && reason.offsetParent!==null && !reason.value.trim()){
    alert('Admin amendment reason is required for locked records.');
    return false;
  }
  return true;
}

function toggleBox(id){
  const el=document.getElementById(id);
  if(el) el.style.display = el.style.display === 'none' ? '' : 'none';
}

function escapeHtml(s){
  return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}

document.addEventListener('DOMContentLoaded',()=>{recalcInvoice();recalcPacking();});

/* Click/tab into any qty, rate, weight or charge amount field and its current
   value is fully selected, so typing replaces it instead of appending to it. */
document.addEventListener('focusin',function(e){
  var t=e.target;
  if(!t||t.tagName!=='INPUT') return;
  if(t.type==='number'){ t.select(); return; }
  var nm=t.getAttribute('name')||'';
  if(/^(qty|rate|net_weight|gross_weight|carton_from|carton_to|qty_per_carton|charge_amount)(\[\])?$/.test(nm)) t.select();
});

/* ============================================================
   Intergalactic UI V2.0 — lightweight vanilla effects
   Starfield (one canvas) · Web Audio sound · Excel-style grid nav
   ============================================================ */
(function(){
  var RM = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- Starfield (single canvas, mobile-reduced, pauses when hidden) ---- */
  function starfield(){
    var cv=document.getElementById('zstar'); if(!cv) return;
    var ctx=cv.getContext('2d'), dpr=Math.min(window.devicePixelRatio||1,2), w,h,stars=[],raf;
    function count(){return Math.min(160,Math.round(innerWidth*innerHeight/(innerWidth<760?16000:9000)));}
    function make(){stars=[];var n=count();for(var i=0;i<n;i++)stars.push({x:Math.random()*w,y:Math.random()*h,z:Math.random()*0.8+0.2,r:(Math.random()*1.2+0.3)*dpr,t:Math.random()*6.28,s:Math.random()*0.02+0.005});}
    function resize(){w=cv.width=innerWidth*dpr;h=cv.height=innerHeight*dpr;cv.style.width=innerWidth+'px';cv.style.height=innerHeight+'px';make();}
    function draw(){raf=requestAnimationFrame(draw);ctx.clearRect(0,0,w,h);for(var i=0;i<stars.length;i++){var st=stars[i];st.t+=st.s;var tw=RM?0.85:(0.5+0.5*Math.sin(st.t));ctx.globalAlpha=tw*st.z;ctx.fillStyle=st.z>0.7?'#bcdcff':'#8ea2ff';ctx.beginPath();ctx.arc(st.x,st.y,st.r,0,6.28);ctx.fill();if(!RM){st.y+=st.z*0.14*dpr;if(st.y>h)st.y=0;}}ctx.globalAlpha=1;}
    resize();addEventListener('resize',resize);
    var stop=function(){cancelAnimationFrame(raf);},start=function(){cancelAnimationFrame(raf);raf=requestAnimationFrame(draw);};
    if(!RM)start();else{draw();stop();}
    document.addEventListener('visibilitychange',function(){document.hidden?stop():(!RM&&start());});
  }

  /* ---- Web Audio sound engine (muted by default, saved to localStorage) ---- */
  var AC=null, soundOn=false;
  function ac(){if(!AC){try{AC=new (window.AudioContext||window.webkitAudioContext)();}catch(e){}}if(AC&&AC.state==='suspended')AC.resume();return AC;}
  function click(){if(!soundOn)return;var c=ac();if(!c)return;var o=c.createOscillator(),g=c.createGain();o.type='square';o.frequency.value=520;var t=c.currentTime;g.gain.setValueAtTime(0.0001,t);g.gain.exponentialRampToValueAtTime(0.05,t+0.005);g.gain.exponentialRampToValueAtTime(0.0001,t+0.09);o.connect(g).connect(c.destination);o.start(t);o.stop(t+0.1);}
  function whoosh(){if(!soundOn)return;var c=ac();if(!c)return;var b=c.createBufferSource(),buf=c.createBuffer(1,Math.floor(c.sampleRate*0.5),c.sampleRate),d=buf.getChannelData(0);for(var i=0;i<d.length;i++)d[i]=(Math.random()*2-1)*Math.pow(1-i/d.length,2);b.buffer=buf;var f=c.createBiquadFilter();f.type='bandpass';var t=c.currentTime;f.frequency.setValueAtTime(300,t);f.frequency.exponentialRampToValueAtTime(1900,t+0.4);var g=c.createGain();g.gain.value=0.12;b.connect(f).connect(g).connect(c.destination);b.start();}
  function initSound(){
    try{soundOn=localStorage.getItem('zas_sound')==='on';}catch(e){}
    var btn=document.getElementById('zsound'); if(!btn)return;
    btn.textContent=soundOn?'🔊':'🔇';
    btn.addEventListener('click',function(){soundOn=!soundOn;try{localStorage.setItem('zas_sound',soundOn?'on':'off');}catch(e){}btn.textContent=soundOn?'🔊':'🔇';if(soundOn){ac();click();}});
    document.addEventListener('click',function(e){var t=e.target;if(t.closest&&t.closest('a,button')&&t.id!=='zsound')click();},true);
    ['submit'].forEach(function(ev){document.addEventListener(ev,whoosh,true);});
  }

  /* ---- Excel-style keyboard navigation on any table input grid ----
     Works generically on existing PHP tables (no markup change):
     ↑/↓ same column prev/next row · Enter next row · Shift+Enter prev row
     ←/→ move columns only at caret edge · Tab/mouse/touch untouched
     skips hidden/disabled/readonly cells; typed data preserved. */
  function gridNav(){
    document.addEventListener('keydown',function(e){
      var t=e.target;
      if(!t||!t.matches||!t.matches('td input,td select,td textarea'))return;
      var key=e.key;
      if(key!=='ArrowUp'&&key!=='ArrowDown'&&key!=='ArrowLeft'&&key!=='ArrowRight'&&key!=='Enter')return;
      if(key==='Enter'&&t.tagName==='TEXTAREA'&&!e.shiftKey&&!e.ctrlKey)return; /* let textarea newline unless shift */
      var td=t.closest('td'), tr=t.closest('tr'); if(!td||!tr)return;
      var col=Array.prototype.indexOf.call(tr.children,td);
      var rows=Array.prototype.slice.call(tr.parentNode.children).filter(function(r){return r.querySelector('td input,td select,td textarea');});
      var ri=rows.indexOf(tr);
      var isText=(t.tagName==='INPUT'&&t.type!=='number'&&t.type!=='checkbox')||t.tagName==='TEXTAREA';
      var atStart=!isText||(t.selectionStart===0&&t.selectionEnd===0);
      var atEnd=!isText||(t.selectionStart===t.value.length&&t.selectionEnd===t.value.length);
      var targetRow=ri, dirCol=0;
      if(key==='ArrowUp')targetRow=ri-1;
      else if(key==='ArrowDown')targetRow=ri+1;
      else if(key==='Enter')targetRow=e.shiftKey?ri-1:ri+1;
      else if(key==='ArrowLeft'){if(!atStart)return;dirCol=-1;}
      else if(key==='ArrowRight'){if(!atEnd)return;dirCol=1;}
      function focusable(el){return el&&!el.disabled&&!el.readOnly&&el.offsetParent!==null;}
      var next=null;
      if(dirCol===0){ /* vertical: same column, nearest focusable field */
        var rr=rows[targetRow]; if(!rr)return;
        var cell=rr.children[col], f=cell&&cell.querySelector('input,select,textarea');
        if(focusable(f))next=f;
        else{ var all=rr.querySelectorAll('input,select,textarea'),best=null,bd=99;
          all.forEach(function(x){if(focusable(x)){var xc=Array.prototype.indexOf.call(x.closest('td').parentNode.children,x.closest('td'));var d=Math.abs(xc-col);if(d<bd){bd=d;best=x;}}});
          next=best; }
      } else { /* horizontal within same row */
        var fields=Array.prototype.slice.call(tr.querySelectorAll('input,select,textarea')).filter(focusable);
        var idx=fields.indexOf(t); next=fields[idx+dirCol];
      }
      if(next){e.preventDefault();next.focus();if(next.tagName==='INPUT'&&next.type!=='number'&&next.type!=='checkbox'){try{var n=next.value.length;next.setSelectionRange(n,n);}catch(_){}}}
    },true);
  }

  starfield();
  document.addEventListener('DOMContentLoaded',function(){initSound();gridNav();});
  if(document.readyState!=='loading'){initSound();gridNav();}
})();
