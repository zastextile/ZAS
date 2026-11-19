/* ZFList — one small list engine for the Shipments and Proforma lists
   (owner, 19 Nov 2026). The page prints its rows once; searching, the status
   tabs, the drop-downs, dates, KPI cards, sorting, totals and the Excel file
   are all done here, in the browser. Nothing is asked of the server. */
(function(){
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function n0(v){ return Math.round(+v || 0).toLocaleString('en-US'); }
  function n2(v){ return (+v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function nice(d){ if (!d) return ''; var x = new Date(String(d).slice(0, 10) + 'T00:00:00'); return isNaN(x) ? d : x.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }); }
  function byCur(rows, cur, amt){ var o = {}; rows.forEach(function(r){ var c = r[cur] || '—'; o[c] = (o[c] || 0) + (+(typeof amt === 'function' ? amt(r) : r[amt]) || 0); }); return o; }
  function curText(o, short, sep){ var k = Object.keys(o).filter(function(c){ return Math.abs(o[c]) > 0.004; }).sort(); return k.length ? k.map(function(c){ return c + ' ' + (short ? n0(o[c]) : n2(o[c])); }).join(sep || ' · ') : '—'; }
  /* one currency per line, for totals and cards that hold several */
  function curLines(o, short){ return esc(curText(o, short, '\n')).replace(/\n/g, '<br>'); }

  window.ZF = { esc: esc, n0: n0, n2: n2, nice: nice, byCur: byCur, curText: curText, curLines: curLines };

  window.ZFList = function(o){
    var root = document.getElementById(o.root);
    var st = { q: '', seg: '', sel: {}, from: '', to: '', kpi: '', sort: o.sort || '', dir: o.dir || -1 };
    var picked = {};                       /* ticked rows, kept across filtering */
    try { var h = (location.hash || '').slice(1); if (h) h.split('&').forEach(function(p){ var kv = p.split('='); if (kv[0] === 'seg') st.seg = decodeURIComponent(kv[1] || ''); if (kv[0] === 'kpi') st.kpi = decodeURIComponent(kv[1] || ''); if (kv[0] === 'q') st.q = decodeURIComponent(kv[1] || ''); }); } catch (e) {}

    root.innerHTML = '<div class="zf-kpis" data-r="kpis"></div><div class="zf-card"><div class="zf-bar" data-r="bar"></div>'
      + (o.select ? '<div class="zf-sel" data-r="selbar" hidden></div>' : '')
      + '<div class="zf-tw"><table class="zf-t"><thead><tr data-r="head"></tr></thead><tbody data-r="body"></tbody><tfoot data-r="foot"></tfoot></table></div></div>';
    var R = function(k){ return root.querySelector('[data-r="' + k + '"]'); };

    /* the filter bar */
    var bar = '';
    if (o.segs) bar += '<div class="zf-seg" data-r="seg"></div>';
    bar += '<input type="search" class="zf-in q" data-f="q" placeholder="' + esc(o.searchHint || 'Search…') + '" value="' + esc(st.q) + '">';
    (o.selects || []).forEach(function(s){
      var vals = {}; o.rows.forEach(function(r){ var v = r[s.k]; if (v) vals[String(v).trim()] = 1; });
      bar += '<select class="zf-in" data-s="' + s.k + '" title="' + esc(s.label) + '"><option value="">' + esc(s.label) + ': all</option>'
        + Object.keys(vals).sort(function(a, b){ return a.localeCompare(b); }).map(function(v){ return '<option>' + esc(v) + '</option>'; }).join('') + '</select>';
    });
    if (o.dateKey) bar += '<input type="date" class="zf-in" data-f="from" title="From"><input type="date" class="zf-in" data-f="to" title="To">';
    bar += '<button type="button" class="zf-btn sm" data-f="clear" hidden>Clear filters</button><span class="zf-cnt" data-r="cnt"></span>';
    if (o.csv) bar += '<button type="button" class="zf-btn sm" data-f="csv">Export Excel</button>';
    R('bar').innerHTML = bar;

    function base(skipSeg){
      var w = st.q.toLowerCase().split(/\s+/).filter(Boolean);
      var kp = st.kpi && (o.kpis || []).filter(function(k){ return k.id === st.kpi; })[0];
      return o.rows.filter(function(r){
        if (!skipSeg && st.seg && o.segs && !o.segs.filter(function(s){ return s[0] === st.seg; })[0][2](r)) return false;
        for (var k in st.sel) if (st.sel[k] && String(r[k] || '').trim() !== st.sel[k]) return false;
        if (o.dateKey) { var d = String(r[o.dateKey] || '').slice(0, 10); if (st.from && (!d || d < st.from)) return false; if (st.to && (!d || d > st.to)) return false; }
        if (kp && !kp.test(r)) return false;
        if (w.length) { var h = o.hay(r).toLowerCase(); for (var i = 0; i < w.length; i++) if (h.indexOf(w[i]) < 0) return false; }
        return true;
      });
    }
    function kpis(){
      if (!o.kpis) { R('kpis').hidden = true; return; }
      R('kpis').innerHTML = o.kpis.map(function(k){
        var v = k.value(o.rows), many = String(v[0]).indexOf(' · ') > 0;
        /* several currencies: one per line, smaller, never cut off */
        return '<button type="button" class="zf-kpi ' + (k.cls || '') + (st.kpi === k.id ? ' on' : '') + '" data-k="' + k.id + '" title="' + esc(String(v[0]) + ' — ' + (k.tip || 'click to show these below')) + '"><span>' + esc(k.label) + '</span><b' + (many ? ' class="m"' : '') + '>' + (many ? esc(v[0]).split(' · ').join('<br>') : esc(v[0])) + '</b><small>' + esc(v[1] || '') + '</small></button>';
      }).join('');
    }
    function segs(){
      if (!o.segs) return;
      var b = base(true);
      R('seg').innerHTML = o.segs.map(function(s){ var n = s[0] ? b.filter(s[2]).length : b.length;
        return '<button type="button" data-g="' + s[0] + '" class="' + (st.seg === s[0] ? 'on' : '') + '">' + esc(s[1]) + '<b>' + n + '</b></button>'; }).join('');
    }
    function head(){
      R('head').innerHTML = (o.select ? '<th style="width:30px;cursor:default"><input type="checkbox" data-all aria-label="Tick all shown"></th>' : '')
        + o.cols.map(function(c){ return '<th data-c="' + (c.sort ? c.k : '') + '" class="' + (c.num ? 'n' : '') + '"' + (c.w ? ' style="width:' + c.w + '"' : '') + '>' + esc(c.label) + (st.sort === c.k && c.sort ? (st.dir > 0 ? ' ▲' : ' ▼') : '') + '</th>'; }).join('');
    }
    var shown = [];
    function body(){
      shown = base(false);
      var c = (o.cols.filter(function(x){ return x.k === st.sort; })[0] || {}).sort;
      if (c) shown.sort(function(a, b){ var x = c(a), y = c(b); return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y))) * st.dir; });
      R('body').innerHTML = shown.length ? shown.slice(0, 3000).map(function(r){
        return '<tr data-href="' + esc(o.href(r)) + '">' + (o.select ? '<td style="cursor:default"><input type="checkbox" data-pick="' + r[o.select.key] + '"' + (picked[r[o.select.key]] ? ' checked' : '') + '></td>' : '')
          + o.cols.map(function(cl){ return '<td class="' + (cl.num ? 'n' : '') + '">' + cl.html(r) + '</td>'; }).join('') + '</tr>';
      }).join('') : '<tr><td colspan="' + (o.cols.length + (o.select ? 1 : 0)) + '" class="zf-none">' + esc(o.rows.length ? 'Nothing matches these filters.' : (o.empty || 'Nothing yet.')) + '</td></tr>';
      R('foot').innerHTML = shown.length && o.foot ? '<tr>' + (o.select ? '<td></td>' : '') + o.foot(shown) + '</tr>' : '';
      R('cnt').textContent = shown.length + ' of ' + o.rows.length;
      var any = st.q || st.seg || st.from || st.to || st.kpi || Object.keys(st.sel).some(function(k){ return st.sel[k]; });
      R('bar').querySelector('[data-f="clear"]').hidden = !any;
      selbar();
      try { var hs = []; if (st.seg) hs.push('seg=' + encodeURIComponent(st.seg)); if (st.kpi) hs.push('kpi=' + encodeURIComponent(st.kpi)); history.replaceState(null, '', hs.length ? '#' + hs.join('&') : location.pathname + location.search); } catch (e) {}
    }
    function selbar(){
      if (!o.select) return;
      var ids = Object.keys(picked).filter(function(k){ return picked[k]; });
      R('selbar').hidden = !ids.length;
      R('selbar').innerHTML = ids.length ? '<span>' + ids.length + ' ticked</span>' + o.select.bar(ids) + '<button type="button" class="zf-btn sm" data-unpick>Untick all</button>' : '';
      var all = R('head').querySelector('[data-all]'); if (all) all.checked = shown.length > 0 && shown.every(function(r){ return picked[r[o.select.key]]; });
    }
    function draw(){ kpis(); segs(); head(); body(); }

    root.addEventListener('input', function(e){ if (e.target.getAttribute('data-f') === 'q') { st.q = e.target.value; segs(); body(); } });
    root.addEventListener('change', function(e){
      var t = e.target, s = t.getAttribute('data-s'), f = t.getAttribute('data-f');
      if (s) { st.sel[s] = t.value; draw(); }
      else if (f === 'from' || f === 'to') { st[f] = t.value; draw(); }
      else if (t.hasAttribute('data-pick')) { picked[t.getAttribute('data-pick')] = t.checked; selbar(); }
      else if (t.hasAttribute('data-all')) { shown.forEach(function(r){ picked[r[o.select.key]] = t.checked; }); body(); }
    });
    root.addEventListener('click', function(e){
      var t = e.target;
      var k = t.closest('[data-k]'); if (k) { st.kpi = st.kpi === k.getAttribute('data-k') ? '' : k.getAttribute('data-k'); draw(); return; }
      var g = t.closest('[data-g]'); if (g) { st.seg = g.getAttribute('data-g'); draw(); return; }
      var th = t.closest('th[data-c]'); if (th && th.getAttribute('data-c')) { var c = th.getAttribute('data-c'); st.dir = st.sort === c ? -st.dir : (c === o.dateKey ? -1 : 1); st.sort = c; head(); body(); return; }
      if (t.closest('[data-unpick]')) { picked = {}; body(); return; }
      if (t.getAttribute('data-f') === 'clear') { st = { q: '', seg: '', sel: {}, from: '', to: '', kpi: '', sort: o.sort || '', dir: o.dir || -1 }; root.querySelectorAll('[data-s]').forEach(function(x){ x.value = ''; }); root.querySelectorAll('[data-f="q"],[data-f="from"],[data-f="to"]').forEach(function(x){ x.value = ''; }); draw(); return; }
      if (t.getAttribute('data-f') === 'csv') { csv(); return; }
      if (t.closest('a,button,input,select,label,textarea')) return;
      var tr = t.closest('tbody tr[data-href]'); if (tr && tr.getAttribute('data-href')) { if (e.ctrlKey || e.metaKey) window.open(tr.getAttribute('data-href')); else location.href = tr.getAttribute('data-href'); }
    });
    function csv(){
      var cols = o.csv, q = function(v){ v = v == null ? '' : String(v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
      var txt = '﻿' + cols.map(function(c){ return q(c[0]); }).join(',') + '\n' + shown.map(function(r){ return cols.map(function(c){ return q(c[1](r)); }).join(','); }).join('\n');
      var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([txt], { type: 'text/csv;charset=utf-8' })); a.download = (o.csvName || 'list') + '-' + new Date().toISOString().slice(0, 10) + '.csv';
      document.body.appendChild(a); a.click(); setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }
    draw();
    return { picked: function(){ return Object.keys(picked).filter(function(k){ return picked[k]; }); } };
  };
})();
