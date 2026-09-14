/* Product Costing — the behaviour of the screen.
   Moved out of product_costing.php unchanged: this file is byte-for-byte
   the function declarations that used to sit inline, so the browser caches
   it once instead of re-reading ~430 lines on every page load.

   LOAD ORDER MATTERS. This file only DECLARES; it runs nothing at load.
   The page still renders its own data constants (STATUSES, INV_ITEMS,
   sizes, profiles, the CAN_* permissions) in an inline block AFTER this
   one, and calls renderAll() there — because those values come from PHP
   and cannot be cached. Keep this <script src> BEFORE that inline block. */
function clone(v){return JSON.parse(JSON.stringify(v))}
function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,s=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[s]))}
function n(v){return parseFloat(v)||0}
function fmtNum(v,maxDec){return n(v).toLocaleString('en-US',{minimumFractionDigits:0,maximumFractionDigits:maxDec})}
function showMsg(t,ok){const el=document.getElementById('msg');el.className='msg '+(ok?'ok':'err');el.textContent=t;window.scrollTo({top:0,behavior:'smooth'})}

function addSize(){if(!CAN_EDIT)return;const i=document.getElementById('newSize');const name=i.value.trim();if(!name)return;if(sizes.some(s=>s.name.toLowerCase()===name.toLowerCase())){showMsg('That size already exists.',false);return}sizes.push({id:nextTempSid--,name});i.value='';renderAll()}
function editSize(id){const s=sizes.find(x=>x.id===id);const name=prompt('Edit size name:',s.name);if(!name||!name.trim())return;s.name=name.trim();renderAll()}
function removeSize(id){const used=profiles.some(p=>p.sizeIds.includes(id));if(used&&!confirm('This size is used in a costing version. Remove it here?'))return;sizes=sizes.filter(s=>s.id!==id);profiles.forEach(p=>p.sizeIds=p.sizeIds.filter(x=>x!==id));renderAll()}
function renderSizes(){document.getElementById('sizeChips').innerHTML=sizes.length?sizes.map(s=>`<span class="chip"><strong>${esc(s.name)}</strong>${CAN_EDIT?`<button class="edit" type="button" onclick="editSize(${s.id})">✎</button><button type="button" onclick="removeSize(${s.id})">×</button>`:''}</span>`).join(''):'<span class="empty">No sizes yet.</span>'}

function addProfile(){const name=prompt('Costing version name:','New Costing Version');if(!name)return;profiles.push({id:nextTempPid--,costing_no:'(new)',name:name.trim(),currency:'PKR',status:'draft',remarks:'',suggested_price:0,sizeIds:[],lines:clone(starterLines)});activeProfileId=profiles[profiles.length-1].id;renderAll()}
function duplicateActive(){const src=profiles.find(p=>p.id===activeProfileId)||profiles[0];if(!src)return;const name=prompt('Name for copied costing (new version):',src.name+' v'+(profiles.length+1));if(!name)return;const c=clone(src);c.id=nextTempPid--;c.costing_no='(new)';c.name=name.trim();c.status='draft';profiles.push(c);activeProfileId=c.id;renderAll()}
function renameProfile(id){const p=profiles.find(x=>x.id===id);const name=prompt('Rename costing version:',p.name);if(name&&name.trim()){p.name=name.trim();renderAll()}}
function deleteProfile(id){if(profiles.length===1){showMsg('Keep at least one costing version.',false);return}if(!confirm('Delete this costing version?'))return;
  selectedForProforma.delete(id);
  if(id>0){fetch(location.pathname+'?product_id='+PRODUCT_ID,{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'costing'},body:JSON.stringify({_csrf:CSRF,op:'delete_version',version_id:id})}).then(r=>r.json()).then(d=>{if(!d.success){showMsg(d.message,false);return}profiles=profiles.filter(p=>p.id!==id);activeProfileId=profiles[0].id;renderAll();showMsg(d.message,true)}).catch(()=>showMsg('Delete failed.',false));}
  else{profiles=profiles.filter(p=>p.id!==id);activeProfileId=profiles[0].id;renderAll()}}
function setSelect(id,k,v){const p=profiles.find(x=>x.id===id);p[k]=v;renderProfiles()}
function setNum(id,k,v){const p=profiles.find(x=>x.id===id);p[k]=n(v)}
function setText(id,k,v){const p=profiles.find(x=>x.id===id);p[k]=v}
function setActive(id){activeProfileId=id;document.querySelectorAll('.profile').forEach(el=>el.classList.toggle('active',el.id==='pf'+id))}
function updateSummary(pid){const p=profiles.find(x=>x.id===pid);if(!p)return;const t=totals(p);const root=document.getElementById('pf'+pid);if(!root)return;const set=(s,v)=>{const el=root.querySelector(s);if(el)el.textContent=v};set('.sm-cost',fmtNum(t.cost,2));set('.sm-wt',fmtNum(t.wt,3))}
function toggleSize(pid,sid,ch){const p=profiles.find(x=>x.id===pid);if(ch&&!p.sizeIds.includes(sid))p.sizeIds.push(sid);if(!ch)p.sizeIds=p.sizeIds.filter(x=>x!==sid);activeProfileId=pid;renderAll()}

/* The Item cell, and BESIDE IT the Code cell. It is one box: type to search
   the Item Master list, or type a name that isn't there yet. Three states,
   always visible:
     linked        the item exists in the Item Master (its code is shown)
     not in list   typed name with no match — one click adds it
     free text     no Item Master installed, or an old line never linked
   The Group is never asked for here: a linked item carries its own.

   THE CODE USED TO SIT UNDER THE ITEM BOX, on its own line inside the same
   cell — which made every row in this grid two lines tall (58px against the
   30px every other grid in the app uses). A second line in a cell is a second
   line in the row, and no amount of padding fixes that. It is now its own
   column, which is both shorter and easier to read: run your eye down the
   Code column and the lines that are NOT linked to the Item Master stand out
   at a glance, instead of having to be found chip by chip. */
function itemChipHtml(p,l,i){
  const linked=l.material_id>0?INV_BY_ID[l.material_id]:null;
  const typed=(l.item||'').trim();
  const noList=INV_ITEMS.length===0;
  if(linked) return `<span class="itag ok" title="${esc(linked.group)} · ${esc(linked.uom)}">✓ ${esc(linked.code)}</span>`;
  if(noList)  return `<span class="itag" title="No Item Master installed — this line is priced by hand">free text</span>`;
  if(typed==='') return '';
  return CAN_EDIT&&CAN_CREATE_ITEM
    /* the label is short because the column is narrow; the full sentence
       moved into the tooltip rather than being dropped */
    ? `<button type="button" class="itag add" title="Add “${esc(typed)}” to the Item Master and link it" onclick="createItemFor(${p.id},${i})">+ add</button>`
    : `<span class="itag warn" title="Not in the Item Master — an admin can add it">not in list</span>`;
}
function itemCellHtml(p,l,i){
  const ro=CAN_EDIT?'':'disabled';
  const noList=INV_ITEMS.length===0;
  return `<td><input class="zin" ${ro} list="invItems" autocomplete="off" placeholder="${noList?'':'Type to search items…'}" value="${esc(l.item)}" onchange="setItem(${p.id},${i},this.value)"></td>`
       + `<td class="codecell">${itemChipHtml(p,l,i)}</td>`;
}
function rowHtml(p,l,i){const ro=CAN_EDIT?'':'disabled';return `<tr>
${itemCellHtml(p,l,i)}
<td><input class="zin" ${ro} style="min-width:150px" value="${esc(l.desc||'')}" onchange="setLine(${p.id},${i},'desc',this.value)"></td>
<td class="c-qty"><div class="qtywrap"><input class="zin mini" ${ro} type="number" step="0.001" value="${l.qty}" oninput="setLine(${p.id},${i},'qty',this.value,1)">${CAN_EDIT?`<button class="shbtn ${l.shared?'on':''}" type="button" title="${l.shared?'Shared: amount = rate ÷ qty':'Normal: amount = qty × rate. Click for shared (÷).'}" onclick="toggleShared(${p.id},${i})">÷</button>`:''}</div></td>
<td class="c-unit"><input class="zin mini" ${ro} value="${esc(l.unit||'')}" onchange="setLine(${p.id},${i},'unit',this.value)"></td>
<td class="c-wt n"><input class="zin mini" ${ro} type="number" step="0.001" value="${l.weight}" oninput="setLine(${p.id},${i},'weight',this.value,1)"></td>
<td class="c-rate n"><input class="zin mini" ${ro} type="number" step="0.01" value="${l.rate}" oninput="setLine(${p.id},${i},'rate',this.value,1)"></td>
<td class="num amt c-amt">${fmtNum(lineAmt(l),2)}${l.shared?' <span style="color:#6d5bd0;font-weight:400">÷</span>':''}</td>
<td class="c-x">${CAN_EDIT?`<button class="rowx zbtn red sm" type="button" title="Remove this cost line" onclick="removeLine(${p.id},${i})">×</button>`:''}</td></tr>`}
function workmanshipRowHtml(p){
  const rate = computeWorkmanship(p.sizeIds);
  const singleSize = (p.sizeIds && p.sizeIds.length===1) ? p.sizeIds[0] : null;
  const sizeOverridden = singleSize!==null && Object.keys(WORKMANSHIP_COMPONENT_RATES).some(function(comp){
    return Object.keys(WORKMANSHIP_COMPONENT_RATES[comp]||{}).some(function(opName){
      return singleSize in (WORKMANSHIP_COMPONENT_RATES[comp][opName]||{});
    });
  });
  const qtyScaled = singleSize!==null && Object.keys(WORKMANSHIP_COMPONENT_RATES).some(c=>c!=='');
  let src;
  if (rate<=0) {
    src = `No active operations set for this product yet. <a class="wmlink" href="product_master.php?edit=${PRODUCT_ID}#sec-production-ops">Set up rates →</a>`;
  } else {
    const bits=[];
    if (sizeOverridden) bits.push(`this size's own rate`);
    if (qtyScaled) bits.push(`its component quantities`);
    src = bits.length
      ? `Sum of active operation rates for this product, using ${bits.join(' and ')}. <a class="wmlink" href="product_master.php?edit=${PRODUCT_ID}#sec-production-ops">Manage rates →</a>`
      : `Sum of active operation rates for this product. <a class="wmlink" href="product_master.php?edit=${PRODUCT_ID}#sec-production-ops">Manage rates →</a>`;
  }
  /* colspan is 7, not 6: the Code column added one. It stays a locked summary
     line and stays visually distinct, but on ONE line — the label and its
     source sit side by side instead of stacked. */
  return `<tr class="wmrow"><td colspan="7"><span class="wmlabel">Workmanship — from Production Operations</span><span class="wmsrc">${src}</span></td><td class="num amt c-amt">${fmtNum(rate,2)}</td><td class="rm c-x">🔒</td></tr>`;
}
function lineAmt(l){return l.shared ? (n(l.qty)>0? n(l.rate)/n(l.qty):0) : n(l.qty)*n(l.rate)}
function lineWt(l){return l.shared ? (n(l.qty)>0? n(l.weight)/n(l.qty):n(l.weight)) : n(l.qty)*n(l.weight)}
function totals(p){let cost=0,wt=0;p.lines.forEach(l=>{cost+=lineAmt(l);wt+=lineWt(l)});cost+=computeWorkmanship(p.sizeIds);return{cost,wt}}
function renderProfiles(){document.getElementById('profiles').innerHTML=profiles.map(p=>{const t=totals(p);const cur=p.currency||'PKR';const saved=p.id>0;const ro=CAN_EDIT?'':'disabled';return `<div class="profile ${activeProfileId===p.id?'active':''}" id="pf${p.id}" onclick="setActive(${p.id})">
<div class="profilehead"><div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><h3>${esc(p.name)}</h3><span class="badge ${p.status}">${STATUSES[p.status]||p.status}</span><small>${esc(p.costing_no||'(new)')} · ${p.sizeIds.length} size${p.sizeIds.length===1?'':'s'}</small></div>
<div style="display:flex;gap:8px;flex-wrap:wrap" onclick="event.stopPropagation()">
<select class="zin sm" ${ro} style="width:auto;padding:6px 8px" onchange="setSelect(${p.id},'currency',this.value)">${['PKR','USD','EUR','GBP'].map(c=>`<option ${c===cur?'selected':''}>${c}</option>`).join('')}</select>
<select class="zin sm" ${ro} style="width:auto;padding:6px 8px" onchange="setSelect(${p.id},'status',this.value)">${Object.keys(STATUSES).map(k=>`<option value="${k}" ${k===p.status?'selected':''}>${STATUSES[k]}</option>`).join('')}</select>
${CAN_EDIT?`<button class="zbtn sec sm" type="button" onclick="renameProfile(${p.id})">Rename</button><button class="zbtn sec sm" type="button" onclick="activeProfileId=${p.id};duplicateActive()">Duplicate</button><button class="zbtn red sm" type="button" onclick="deleteProfile(${p.id})">Delete</button>`:''}</div></div>
<label class="zlabel" style="margin-top:12px">Applies to sizes</label>
<div class="sizechecks" onclick="event.stopPropagation()">${sizes.length?sizes.map(s=>`<label class="sizecheck ${p.sizeIds.includes(s.id)?'active':''}"><input type="checkbox" ${ro} ${p.sizeIds.includes(s.id)?'checked':''} onchange="toggleSize(${p.id},${s.id},this.checked)">${esc(s.name)}</label>`).join(''):'<span class="empty">Add sizes above first.</span>'}</div>
<div class="tablewrap" onclick="event.stopPropagation()"><table class="ct ct-edit"><thead><tr><th>Item</th><th class="codecell">Code</th><th>Description</th><th class="c-qty">Qty</th><th class="c-unit">Unit</th><th class="c-wt">Alloc. Wt</th><th class="c-rate">Rate</th><th class="c-amt">Amount</th><th class="c-x"></th></tr></thead><tbody>${p.lines.map((l,i)=>rowHtml(p,l,i)).join('')}${workmanshipRowHtml(p)}</tbody></table></div>
${CAN_EDIT?`<button class="zbtn sec sm" style="margin-top:10px" type="button" onclick="event.stopPropagation();addLine(${p.id})">+ Add Cost Line</button>`:''}
<div class="summary"><div class="box"><span>Total Cost / Unit</span><b>${cur} <span class="sm-cost">${fmtNum(t.cost,2)}</span></b></div><div class="box"><span>Total Weight</span><b><span class="sm-wt">${fmtNum(t.wt,3)}</span> kg</b></div><div class="box"><span>Suggested Price</span><b>${cur} <input class="zin" ${ro} style="width:90px;display:inline-block;padding:4px 6px" type="number" step="0.01" value="${p.suggested_price||0}" onclick="event.stopPropagation()" oninput="setNum(${p.id},'suggested_price',this.value)"></b></div></div>
<label class="zlabel" style="margin-top:12px">Remarks</label><input class="zin" ${ro} value="${esc(p.remarks||'')}" onclick="event.stopPropagation()" oninput="setText(${p.id},'remarks',this.value)">
<div class="vactions" onclick="event.stopPropagation()">
${saved&&CAN_PRINT?`<a class="zbtn sec sm" target="_blank" href="costing_print.php?version_id=${p.id}">Print / PDF</a>`:''}
${saved&&CAN_IMPORT?`<a class="zbtn sec sm" href="costing_import.php?version_id=${p.id}">Import Detail Lines</a>`:''}
${saved&&CAN_PROFORMA?`<label class="zchk sm" style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#5a6b82;cursor:pointer"><input type="checkbox" ${selectedForProforma.has(p.id)?'checked':''} onchange="toggleProformaSelect(${p.id},this.checked)"> Select for Proforma</label>`:''}
${!saved?'<span class="empty">Save first to enable print, import &amp; proforma.</span>':''}
</div></div>`}).join('')}
function setLine(pid,i,k,v,num){const p=profiles.find(x=>x.id===pid);p.lines[i][k]=num?n(v):v;const root=document.getElementById('pf'+pid);if(root&&(k==='qty'||k==='rate')){const row=root.querySelectorAll('table.ct-edit tbody tr')[i];if(row){const a=row.querySelector('.amt');if(a)a.innerHTML=fmtNum(lineAmt(p.lines[i]),2)+(p.lines[i].shared?' <span style="color:#6d5bd0;font-weight:400">÷</span>':'')}}updateSummary(pid)}
function toggleShared(pid,i){const p=profiles.find(x=>x.id===pid);p.lines[i].shared=!p.lines[i].shared;activeProfileId=pid;renderAll()}
function addLine(pid){const p=profiles.find(x=>x.id===pid);p.lines.push({group:'Fabric',item:'',desc:'',qty:1,unit:'Pc',weight:0,rate:0,shared:false,material_id:0});activeProfileId=pid;renderAll()}

/* Typing or picking in the Item box. Picking a known item links the line
   and fills Unit and Rate ONLY where they are still empty — a rate you
   negotiated for this quote is never overwritten by the standard rate. */
function setItem(pid,i,val){
  const p=profiles.find(x=>x.id===pid); const l=p.lines[i];
  l.item=val;
  const hit=INV_BY_NAME[(val||'').trim().toLowerCase()]||null;
  const was=l.material_id||0;
  l.material_id=hit?hit.id:0;
  if(hit&&hit.id!==was){
    l.item=hit.name;                                   // use the master's spelling
    if(!l.unit||!String(l.unit).trim())l.unit=hit.uom;
    if(!n(l.rate)&&hit.rate>0)l.rate=hit.rate;
  }
  activeProfileId=pid;renderAll();
}
function guessGroup(name){
  const s=(name||'').toLowerCase();
  if(/fabric|greige|grey cloth|cloth|yarn|poplin|percale|sateen|twill/.test(s))return'Fabric';
  return'Accessories';   // packing materials are Accessories too — the Packing group was retired
}
/* Add the typed name to the Item Master without leaving the costing sheet.
   The group is asked for once, because that is the one thing the name
   cannot be trusted to tell us — that guess is exactly what put fabrics
   into Accessories in the first place. */
function createItemFor(pid,i){
  const p=profiles.find(x=>x.id===pid); const l=p.lines[i];
  const name=(l.item||'').trim();
  if(!name){showMsg('Type the item name first.',false);return}
  const g=prompt('Group for "'+name+'"?\n\nType one of:  Fabric   Accessories   Other',guessGroup(name));
  if(!g)return;
  const group=['fabric','accessories','other'].indexOf(g.trim().toLowerCase());
  if(group<0){showMsg('Group must be Fabric, Accessories or Other.',false);return}
  const groupName=['Fabric','Accessories','Other'][group];
  fetch(location.pathname+'?product_id='+PRODUCT_ID,{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'costing'},
    body:JSON.stringify({_csrf:CSRF,op:'create_item',name:name,group:groupName,uom:(l.unit||'PCS'),rate:n(l.rate)})})
   .then(r=>r.json()).then(d=>{
     if(!d.success){showMsg(d.message,false);return}
     INV_ITEMS.push(d.item); INV_BY_ID[d.item.id]=d.item;
     const k=d.item.name.trim().toLowerCase(); if(!(k in INV_BY_NAME))INV_BY_NAME[k]=d.item;
     l.material_id=d.item.id; l.item=d.item.name;
     if(!l.unit||!String(l.unit).trim())l.unit=d.item.uom;
     renderAll(); renderItemList(); showMsg(d.message,true);
   }).catch(()=>showMsg('Could not reach the server.',false));
}
function renderItemList(){
  const dl=document.getElementById('invItems'); if(!dl)return;
  dl.innerHTML=INV_ITEMS.map(i=>`<option value="${esc(i.name)}" label="${esc(i.code)} · ${esc(i.group)}"></option>`).join('');
}
function removeLine(pid,i){profiles.find(x=>x.id===pid).lines.splice(i,1);renderAll()}
function toggleProformaSelect(id,checked){if(checked)selectedForProforma.add(id);else selectedForProforma.delete(id);updateProformaBar()}
function clearProformaSelection(){selectedForProforma.clear();updateProformaBar();renderProfiles()}
function updateProformaBar(){const bar=document.getElementById('proformaBulkBar');if(!bar)return;const c=selectedForProforma.size;document.getElementById('proformaBulkCount').textContent=c;bar.style.display=c>0?'flex':'none'}
function convertSelectedToProforma(){if(!selectedForProforma.size)return;const ids=Array.from(selectedForProforma).join(',');location.href='proforma.php?from_versions='+ids}
function renderAll(){renderSizes();renderProfiles();renderCompareOptions();updateProformaBar()}
let cmpSelected=new Set();
function renderCompareOptions(){
  const box=document.getElementById('cmpPicker');
  if(!box)return;
  const saved=profiles.filter(p=>p.id>0);
  cmpSelected=new Set(Array.from(cmpSelected).filter(id=>saved.some(p=>p.id===id)));
  box.innerHTML=saved.length?saved.map(p=>`<label class="zchk" style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;border:1px solid #cbd5e3;border-radius:20px;padding:6px 12px;cursor:pointer;background:${cmpSelected.has(p.id)?'rgba(14,168,201,.1)':'#fff'}">
    <input type="checkbox" ${cmpSelected.has(p.id)?'checked':''} ${!cmpSelected.has(p.id)&&cmpSelected.size>=6?'disabled':''} onchange="toggleCmpSelect(${p.id},this.checked)">${esc(p.name)} (${esc(p.costing_no)})</label>`).join('')
    :'<span class="empty">No saved versions yet.</span>';
  const c=document.getElementById('cmpSelCount'); if(c) c.textContent=cmpSelected.size;
}
function toggleCmpSelect(id,checked){
  if(checked){ if(cmpSelected.size>=6){showMsg('Compare up to 6 versions at a time.',false);return} cmpSelected.add(id); }
  else cmpSelected.delete(id);
  renderCompareOptions();
}
function cmpFlagPills(flags){return (flags||[]).map(f=>`<span class="flagpill ${f.type}">${esc(f.text)}</span>`).join('')}
async function aiCompareVersions(forceRefreshAi){
  const out=document.getElementById('cmpResult');
  const ids=Array.from(cmpSelected);
  if(ids.length<2){out.innerHTML='<span class="empty">Select at least 2 saved versions above first.</span>';return}
  out.innerHTML='<span class="empty">Comparing…</span>';
  try{
    const r=await fetch('costing_ai_extract.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({_csrf:CSRF,action:'compare_multi',version_ids:ids,force_refresh:!!forceRefreshAi})});
    const d=await r.json();
    if(!d.success){out.innerHTML='<span class="empty">'+esc(d.message||'Compare failed.')+'</span>';return}
    const c=d.comparison;
    const cols=c.columns;

    const tiles=`<div class="cmp-tiles" style="grid-template-columns:repeat(${Math.min(cols.length+1,6)},1fr)">
      ${cols.map(v=>`<div class="cmp-tile"><b>${esc(v.version_name)}${v.sizes.length?' ('+esc(v.sizes.join('/'))+')':''}</b><div class="v">${v.currency} ${fmtNum(v.total_cost,2)}</div></div>`).join('')}
      <div class="cmp-tile"><b>Issues Detected</b><div class="v" style="color:${c.issue_count>0?'#a25c04':'#16a34a'}">${c.issue_count}</div></div>
    </div>`;

    const aiBox=`<div class="cmp-ai-box"><div class="head"><span class="t">✨ AI-Generated Comparison Summary</span>
      <button class="zbtn sec sm" type="button" onclick="aiCompareVersions(true)" title="Regenerate the written summary — the numbers above are already live">↻ Refresh AI Analysis</button></div>
      ${d.ai_summary?`<pre>${esc(d.ai_summary)}</pre><div style="font-size:10px;color:#8a97ab;margin-top:8px">${d.ai_summary_cached?'From cache — click Refresh for a new one.':'Freshly generated.'}</div>`:'<span class="empty">AI summary unavailable right now — table below is still fully accurate.</span>'}
    </div>`;

    const head=`<tr><th>Group</th><th>Item</th>${cols.map(v=>`<th class="num">${esc(v.version_name)}</th>`).join('')}<th>Flags</th></tr>`;
    const rows=c.rows.map(r=>{
      const cells=cols.map(v=>{
        const val=r.values[v.id];
        return val?`<td class="num">${fmtNum(val.qty,3)} ${esc(val.unit)} @ ${fmtNum(val.rate,2)}</td>`:'<td class="num" style="color:#b8283f">—</td>';
      }).join('');
      return `<tr${r.flags.length?' class="rowflagged"':''}><td>${esc(r.group)}</td><td>${esc(r.item)}</td>${cells}<td>${cmpFlagPills(r.flags)}</td></tr>`;
    }).join('');
    const table=`<div class="tablewrap"><table class="ct"><thead>${head}</thead><tbody>${rows||'<tr><td colspan="'+(cols.length+3)+'" style="text-align:center;color:#8a97ab">No line items.</td></tr>'}</tbody></table></div>`;

    const header=cols.map(v=>`<strong>${esc(v.version_name)}</strong> (${esc(v.costing_no)}, ${esc(v.status)})`).join(' &nbsp;·&nbsp; ');
    out.innerHTML=`<div class="note" style="margin-bottom:12px">${esc(cols[0].product_name)} — ${header}</div>
      ${tiles}${aiBox}<h3 style="font-size:13px;margin:14px 0 8px">Full Line-by-Line Comparison</h3>${table}`;
  }catch(e){out.innerHTML='<span class="empty">Compare failed.</span>'}
}
function buildPayload(){return{_csrf:CSRF,product_id:PRODUCT_ID,product_sizes:sizes.map((s,idx)=>({temp_id:s.id,size_name:s.name,sort_order:idx+1})),costing_versions:profiles.map(p=>({id:p.id>0?p.id:0,name:p.name,currency:p.currency||'PKR',status:p.status||'draft',remarks:p.remarks||'',suggested_price:p.suggested_price||0,applies_to_size_ids:p.sizeIds,cost_lines:p.lines,ai_generated:p.ai_generated?1:0}))}}
async function saveCosting(){const b=document.getElementById('saveBtn');b.disabled=true;try{const r=await fetch(location.pathname+'?product_id='+PRODUCT_ID,{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'costing'},body:JSON.stringify(buildPayload())});const d=await r.json();if(!d.success)throw new Error(d.message||'Save failed.');showMsg(d.message,true);setTimeout(()=>location.reload(),800);}catch(e){showMsg(e.message,false)}finally{b.disabled=false}}

/* ---- AI-Assisted Costing: extracts + calculates via costing_ai_extract.php,
   applies into the SAME client-side draft model as "+ New Costing Version" —
   nothing is saved here; Save Costing (above) is still the only save path. ---- */
let aiLastResult=null, aiSelectedSizes=new Set(), aiOpenDetails=new Set();
function aiShowMsg(t,ok){const el=document.getElementById('aiMsg');if(!el)return;el.style.display='block';el.style.padding='12px 14px';el.style.borderRadius='10px';el.style.fontSize='12.5px';
  if(ok){el.style.background='rgba(22,163,74,.1)';el.style.border='1px solid rgba(22,163,74,.28)';el.style.color='#127a3f'}
  else{el.style.background='rgba(224,67,93,.1)';el.style.border='1px solid rgba(224,67,93,.28)';el.style.color='#b8283f'}
  el.textContent=t}
async function aiSearchExisting(){
  const txt=document.getElementById('aiText').value.trim();const box=document.getElementById('aiSearchResults');
  if(!txt){aiShowMsg('Type a description or product name first.',false);return}
  box.innerHTML='<span class="empty">Searching…</span>';
  try{
    const r=await fetch('costing_ai_extract.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({_csrf:CSRF,action:'search',text:txt})});
    const d=await r.json();
    if(!d.success){box.innerHTML='<span class="empty">'+esc(d.message||'Search failed.')+'</span>';return}
    if(!d.matches.length){box.innerHTML='<span class="empty">No matching products found — this looks like a new product.</span>';return}
    box.innerHTML='<div class="note" style="background:rgba(217,119,6,.08);border-color:rgba(217,119,6,.25);color:#a25c04">Found existing product(s) — check before creating a duplicate:</div>'+
      d.matches.map(m=>`<div class="chip" style="display:block;margin-top:6px"><strong>${esc(m.name)}</strong> ${m.product_code?'('+esc(m.product_code)+')':''} — ${(m.approved_costings||[]).length} approved costing(s) <a class="zbtn sec sm" style="margin-left:10px" href="product_costing.php?product_id=${m.id}">Open</a></div>`).join('');
  }catch(e){box.innerHTML='<span class="empty">Search failed.</span>'}
}
async function aiGenerateDraft(){
  const txt=document.getElementById('aiText').value.trim();const btn=document.getElementById('aiGenBtn');const msg=document.getElementById('aiMsg');
  msg.style.display='none';
  if(!txt){aiShowMsg('Please describe the costing first.',false);return}
  btn.disabled=true;btn.textContent='Generating…';
  try{
    const r=await fetch('costing_ai_extract.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({_csrf:CSRF,action:'generate',text:txt})});
    const d=await r.json();
    if(!d.success){aiShowMsg(d.message||'Could not generate a draft.',false);return}
    aiLastResult=d;aiSelectedSizes=new Set(d.sizes.map(s=>s.size_name));aiOpenDetails=new Set();
    aiRenderPreview(d);
    document.getElementById('aiReviewBtn').disabled=false;
  }catch(e){aiShowMsg('AI request failed — you can still use the costing form manually.',false)}
  finally{btn.disabled=false;btn.textContent='Generate Draft'}
}
function aiScrollToPreview(){const el=document.getElementById('aiPreview');if(el)el.scrollIntoView({behavior:'smooth',block:'start'})}
function aiToggleSize(name,checked){if(checked)aiSelectedSizes.add(name);else aiSelectedSizes.delete(name)}
function aiLineAmount(l){return l.shared?(l.quantity>0?l.rate/l.quantity:0):l.quantity*l.rate}
function aiLineBadges(l,si,li){
  let b='';
  if(l.suggested_rate) b+=` <button type="button" class="zbtn sec sm" style="padding:2px 8px;font-size:10.5px" title="From ${esc(l.suggested_rate.source)}" onclick="aiUseSuggestedRate(${si},${li})">Use ${esc(l.suggested_rate.rate)} (from history)</button>`;
  if(l.anomaly) b+=` <span style="font-size:10.5px;color:#a25c04;font-weight:700">⚠ ${l.anomaly.deviation_pct>0?'+':''}${l.anomaly.deviation_pct}% vs your avg ${fmtNum(l.anomaly.avg_rate,2)}</span>`;
  return b;
}
function aiRecalcDiffsAndPricing(d){
  let prevCost=null;
  d.sizes.forEach(s=>{
    if(prevCost===null){s.diff_prev=null;s.diff_prev_pct=null}
    else{const diff=s.total_cost-prevCost;s.diff_prev=Math.round(diff*100)/100;s.diff_prev_pct=prevCost>0?Math.round(diff/prevCost*1000)/10:null}
    prevCost=s.total_cost;
    const m=(d.margin_pct||18)/100;
    s.suggested_price=d.margin_type==='markup'?Math.round(s.total_cost*(1+m)*100)/100:(m<1?Math.round(s.total_cost/(1-m)*100)/100:null);
  });
}
function aiUseSuggestedRate(si,li){
  const s=aiLastResult.sizes[si];const l=s.lines[li];
  l.rate=l.suggested_rate.rate; delete l.suggested_rate; delete l.anomaly;
  l.amount=aiLineAmount(l);
  s.total_cost=s.lines.reduce((t,x)=>t+x.amount,0);
  s.group_totals={Fabric:0,Accessories:0,Packing:0,Workmanship:0,Other:0};
  s.lines.forEach(x=>{s.group_totals[x.group]=(s.group_totals[x.group]||0)+x.amount});
  aiRecalcDiffsAndPricing(aiLastResult);
  aiOpenDetails.add(si);
  aiRenderPreview(aiLastResult);
}
function aiDetailRows(s,si){
  return s.lines.map((l,li)=>`<tr><td>${esc(l.group)}</td><td>${esc(l.item)}</td><td class="num">${fmtNum(l.quantity,3)}</td><td>${esc(l.unit||'')}</td><td class="num">${fmtNum(l.rate,2)}${aiLineBadges(l,si,li)}</td><td class="num">${fmtNum(l.amount,2)}</td></tr>`).join('');
}
function aiComparisonHtml(s,cur,si){
  if(s.comparison){
    const diff=s.comparison.total_cost>0 ? ((s.total_cost-s.comparison.total_cost)/s.comparison.total_cost*100) : 0;
    return `<div class="note" style="margin-top:10px">Compared with approved ${esc(s.comparison.costing_no)} (${esc(s.comparison.version_name)}): existing total ${cur} ${fmtNum(s.comparison.total_cost,2)} vs this draft ${cur} ${fmtNum(s.total_cost,2)} (${diff>=0?'+':''}${diff.toFixed(1)}%)
    <button type="button" class="zbtn sec sm" style="margin-left:8px" onclick='aiExplain(this,${JSON.stringify({size:s.size_name,draft_total:s.total_cost,approved_costing_no:s.comparison.costing_no,approved_total:s.comparison.total_cost,draft_group_totals:s.group_totals,approved_group_totals:s.comparison.group_totals}).replace(/'/g,"&#39;")})'>Explain</button>
    <div class="ai-explain" style="margin-top:6px;font-size:12px;color:#33415c"></div></div>`;
  }
  if(!aiLastResult.matched_product_id) return '';
  return `<div class="note" style="margin-top:10px">No approved costing at this exact size yet. <button type="button" class="zbtn sec sm" onclick="aiEstimateSize(${si})">Estimate New Size</button> — interpolates from your product's nearest approved sizes above and below.
  <div id="aiEstimate${si}" style="margin-top:6px"></div></div>`;
}
async function aiEstimateSize(si){
  const s=aiLastResult.sizes[si];
  const out=document.getElementById('aiEstimate'+si);
  out.textContent='Estimating…';
  try{
    const r=await fetch('costing_ai_extract.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({_csrf:CSRF,action:'estimate_size',product_id:aiLastResult.matched_product_id,size_name:s.size_name})});
    const d=await r.json();
    if(!d.success||!d.estimate){out.innerHTML='<span style="color:#8a97ab">'+esc(d.message||'Not enough approved reference sizes to estimate.')+'</span>';return}
    const e=d.estimate;
    const cur=aiLastResult.product.currency||'PKR';
    const refText=e.method==='interpolated'?`interpolated between ${esc(e.ref_smaller)} and ${esc(e.ref_larger)}`:`copied from the single nearest approved size, ${esc(e.reference)} (no bracket available to interpolate)`;
    out.innerHTML=`Estimated total: <strong>${cur} ${fmtNum(e.total_cost,2)}</strong> — ${refText}. <button type="button" class="zbtn sec sm" onclick='aiUseEstimate(${si},${JSON.stringify(e).replace(/'/g,"&#39;")})'>Use this estimate</button>`;
  }catch(err){out.textContent='Estimate failed.'}
}
function aiUseEstimate(si,estimate){
  const s=aiLastResult.sizes[si];
  s.lines=estimate.lines.map(l=>({group:l.group,item:l.item,desc:'',quantity:l.quantity,unit:l.unit,weight:0,rate:l.rate,shared:l.shared,amount:l.amount}));
  s.total_cost=estimate.total_cost;
  s.group_totals={Fabric:0,Accessories:0,Packing:0,Workmanship:0,Other:0};
  s.lines.forEach(l=>{s.group_totals[l.group]=(s.group_totals[l.group]||0)+l.amount});
  s.ok=true; s.missing=[]; s.confidence='low'; // interpolated/copied, never treated as a real approved match
  aiRecalcDiffsAndPricing(aiLastResult);
  aiOpenDetails.add(si);
  aiRenderPreview(aiLastResult);
}
async function aiExplain(btn,context){
  btn.disabled=true;const orig=btn.textContent;btn.textContent='Explaining…';
  const out=btn.parentElement.querySelector('.ai-explain');
  try{
    const r=await fetch('costing_ai_extract.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({_csrf:CSRF,action:'explain',context})});
    const d=await r.json();
    if(out) out.textContent=d.success?d.text:(d.message||'Explanation unavailable.');
  }catch(e){ if(out) out.textContent='Explanation unavailable right now.'; }
  finally{ btn.disabled=false; btn.textContent=orig; }
}
function aiToggleDetails(i){if(aiOpenDetails.has(i))aiOpenDetails.delete(i);else aiOpenDetails.add(i);const el=document.getElementById('aiDetail'+i);if(el)el.style.display=aiOpenDetails.has(i)?'table-row':'none'}
function aiConfidencePill(c){
  const m={high:['High','rgba(22,163,74,.14)','#16a34a'],medium:['Medium','rgba(217,119,6,.14)','#a25c04'],low:['Low','rgba(224,67,93,.14)','#b8283f']}[c]||['—','#eef1f6','#8a97ab'];
  return `<span style="font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;background:${m[1]};color:${m[2]};white-space:nowrap">${m[0]}</span>`;
}
function aiRenderPreview(d){
  const box=document.getElementById('aiPreview');box.style.display='block';
  let warn='';
  const notes=(d.warnings||[]).concat(d.missing_fields||[]);
  if(notes.length) warn='<div class="note" style="background:rgba(217,119,6,.08);border-color:rgba(217,119,6,.25);color:#a25c04">'+notes.map(w=>esc(w)).join('<br>')+'</div>';
  const cur=d.product.currency||'PKR';
  const rows=d.sizes.map((s,i)=>{
    const fab=s.lines.find(l=>(l.item||'').toLowerCase().indexOf('fabric')!==-1)||s.lines[0]||{quantity:0,rate:0,amount:0};
    const checked=aiSelectedSizes.has(s.size_name)?'checked':'';
    let flags='';
    if(s.nearest_size) flags+=`<div style="font-size:11px;color:#8a97ab;margin-top:3px">No exact size on file — nearest is "${esc(s.nearest_size)}"</div>`;
    if(s.duplicate) flags+=`<div style="font-size:11px;color:#a25c04;margin-top:3px">⚠ A ${esc(s.duplicate.status)} costing already exists for this size (${esc(s.duplicate.costing_no)}) — applying adds another draft, it won't overwrite it.</div>`;
    if(!s.ok) flags+=`<div style="font-size:11px;color:#b8283f;margin-top:3px">${esc((s.missing||[]).join(' '))}</div>`;
    const diffCell = s.diff_prev==null ? '—' : `<span style="color:${s.diff_prev>=0?'#a25c04':'#16a34a'};font-weight:700">${s.diff_prev>=0?'+':''}${fmtNum(s.diff_prev,2)}</span>`;
    const diffPctCell = s.diff_prev_pct==null ? '—' : `<span style="color:${s.diff_prev_pct>=0?'#a25c04':'#16a34a'};font-weight:700">${s.diff_prev_pct>=0?'+':''}${s.diff_prev_pct}%</span>`;
    return `<tr>
<td><label style="display:flex;align-items:center;gap:6px;cursor:pointer"><input type="checkbox" ${checked} onchange="aiToggleSize('${esc(s.size_name)}',this.checked)"><strong>${esc(s.size_name)}</strong></label>${flags}<button type="button" class="zbtn sec sm" style="margin-top:5px;padding:2px 8px;font-size:10.5px" onclick="aiToggleDetails(${i})">Details</button></td>
<td class="num">${fmtNum(fab.quantity,3)}</td>
<td class="num">${fmtNum(fab.rate,2)}</td>
<td class="num">${fmtNum(fab.amount,2)}</td>
<td class="num">${fmtNum(s.group_totals.Workmanship,2)}</td>
<td class="num">${fmtNum(s.group_totals.Accessories,2)}</td>
<td class="num">${fmtNum(s.group_totals.Packing,2)}</td>
<td class="num" style="font-weight:700">${cur} ${fmtNum(s.total_cost,2)}</td>
<td class="num">${diffCell}</td>
<td class="num">${diffPctCell}</td>
<td class="num">${cur} ${fmtNum(s.suggested_price,2)}</td>
<td>${aiConfidencePill(s.confidence)}</td>
</tr>
<tr id="aiDetail${i}" style="display:${aiOpenDetails.has(i)?'table-row':'none'}"><td colspan="12" style="background:#f6f8fc">
<div class="tablewrap"><table class="ct"><thead><tr><th>Group</th><th>Item</th><th class="num">Qty</th><th>Unit</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead><tbody>${aiDetailRows(s,i)}</tbody></table></div>
${aiComparisonHtml(s,cur,i)}
</td></tr>`}).join('');
  let matchesHtml='';
  if(d.existing_matches&&d.existing_matches.length) matchesHtml='<div class="note" style="margin-top:10px">Possible existing product match — check before creating a duplicate: '+d.existing_matches.map(m=>esc(m.name)+(m.product_code?' ('+esc(m.product_code)+')':'')).join(', ')+'</div>';
  else if(d.similar_products&&d.similar_products.length) matchesHtml='<div class="note" style="margin-top:10px">No exact match, but similar existing products found (AI similarity search) — check before creating a duplicate: '+d.similar_products.map(m=>esc(m.name)+' ('+Math.round(m.similarity*100)+'% similar)').join(', ')+'</div>';
  let missingCostHtml='';
  if(d.missing_cost_suggestions&&d.missing_cost_suggestions.length) missingCostHtml='<div class="note" style="margin-top:10px">Your other approved costings for this product usually include: '+d.missing_cost_suggestions.map(m=>esc(m.item)+' (seen in '+m.seen_in+' of '+m.of+')').join(', ')+' — not in this draft. Review before applying.</div>';
  let standardizeHtml='';
  if(d.standardize_suggestions&&d.standardize_suggestions.length) standardizeHtml='<div class="note" style="margin-top:10px">Item name suggestions: '+d.standardize_suggestions.map(s=>'"'+esc(s.original)+'" → <strong>'+esc(s.suggested.standard_item)+'</strong>').join(', ')+' — shown for reference, rename manually in Details if you agree.</div>';
  box.innerHTML=`<h2 style="font-size:14px">Preview — ${esc(d.product.product_name||'')}${d.product.composition?' · '+esc(d.product.composition):''}</h2>
${warn}${matchesHtml}${missingCostHtml}${standardizeHtml}
<div class="tablewrap"><table class="ct"><thead><tr><th>Size</th><th class="num">Fabric Qty</th><th class="num">Fabric Rate</th><th class="num">Fabric Amt</th><th class="num">Workmanship</th><th class="num">Accessories</th><th class="num">Packing</th><th class="num">Total Cost</th><th class="num">Diff vs Prev</th><th class="num">Diff %</th><th class="num">Sugg. Price</th><th>Confidence</th></tr></thead><tbody>${rows}</tbody></table></div>
<div style="font-size:11px;color:#8a97ab;margin-top:6px">Suggested price at ${d.margin_pct}% ${d.margin_type==='markup'?'markup':'gross margin'} — change the default in config, or edit per-version after applying.</div>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
<button class="zbtn" type="button" onclick="aiApply(false)">Apply Selected Sizes</button>
<button class="zbtn sec" type="button" onclick="aiApply(true)">Apply All Sizes</button>
</div>
<div style="margin-top:16px;padding-top:14px;border-top:1px solid #e3e9f2">
<label class="zlabel">Ask AI About This Costing</label>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
<input id="aiAskInput" class="zin" style="flex:1;min-width:240px" placeholder="e.g. Explain the difference between Queen and King" onkeydown="if(event.key==='Enter'){event.preventDefault();aiAsk()}">
<button class="zbtn sec sm" type="button" onclick="aiAsk()">Ask</button>
</div>
<div id="aiAskAnswer" style="font-size:12.5px;color:#33415c"></div>
</div>`;
}
async function aiAsk(){
  const input=document.getElementById('aiAskInput');const out=document.getElementById('aiAskAnswer');
  const q=input.value.trim();
  if(!q||!aiLastResult)return;
  out.textContent='Thinking…';
  try{
    const context={product:aiLastResult.product,sizes:aiLastResult.sizes.map(s=>({size_name:s.size_name,total_cost:s.total_cost,diff_prev:s.diff_prev,diff_prev_pct:s.diff_prev_pct,confidence:s.confidence,group_totals:s.group_totals}))};
    const r=await fetch('costing_ai_extract.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({_csrf:CSRF,action:'ask',question:q,context})});
    const d=await r.json();
    out.textContent=d.success?d.text:(d.message||'Unavailable.');
  }catch(e){ out.textContent='Unavailable right now.'; }
}
function aiApply(all){
  if(!aiLastResult)return;
  const d=aiLastResult;
  const pick=d.sizes.filter(s=>all||aiSelectedSizes.has(s.size_name));
  if(!pick.length){aiShowMsg('Select at least one size to apply.',false);return}
  // an untouched auto-seeded "Base Costing" starter (brand-new product, nothing saved yet)
  // would otherwise sit alongside the AI drafts and get saved too — clear it first.
  if(profiles.length===1&&profiles[0].id===-1&&profiles[0].name==='Base Costing') profiles.length=0;
  pick.forEach(s=>{
    let sizeObj=sizes.find(x=>x.name.toLowerCase()===s.size_name.toLowerCase());
    if(!sizeObj){sizeObj={id:nextTempSid--,name:s.size_name};sizes.push(sizeObj)}
    const lines=s.lines.map(l=>({group:l.group,item:l.item,desc:l.desc||'',qty:n(l.quantity),unit:l.unit||'',weight:0,rate:n(l.rate),shared:!!l.shared}));
    profiles.push({id:nextTempPid--,costing_no:'(new)',name:(d.product.product_name?d.product.product_name+' — ':'')+s.size_name,currency:d.product.currency||'PKR',status:'draft',remarks:'AI-assisted draft — review before approving.',suggested_price:0,sizeIds:[sizeObj.id],lines,ai_generated:true});
  });
  activeProfileId=profiles[profiles.length-1].id;
  renderAll();
  aiShowMsg(pick.length+' size(s) added as new draft costing version(s) below. Nothing is saved yet — review, then click Save Costing.',true);
  const pr=document.getElementById('profiles');if(pr)pr.scrollIntoView({behavior:'smooth',block:'start'});
}
function aiCancel(){
  document.getElementById('aiText').value='';
  document.getElementById('aiPreview').style.display='none';document.getElementById('aiPreview').innerHTML='';
  document.getElementById('aiSearchResults').innerHTML='';
  document.getElementById('aiMsg').style.display='none';
  document.getElementById('aiReviewBtn').disabled=true;
  aiLastResult=null;aiSelectedSizes=new Set();
}
