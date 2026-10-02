<?php
/*
  CUSTOMS / CHAMBER TAB — type the other two invoices.

  The commercial invoice sits at the top, read-only, as the reference. Below
  it you build this document line by line: your own wording, your own HS
  codes, your own rates, as many or as few lines as the document needs.

  Units are checked against the commercial invoice and printing is blocked
  until they agree. The value is free and the difference is shown and logged.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/export.php';

$shipment = exp_open_shipment('logistics');   /* same gate: exists + assigned */
$id       = (int)$shipment['id'];

if (!expdoc_can_view()) { http_response_code(403); exit('You do not have permission for customs documents.'); }
$canEdit = expdoc_can_edit($shipment);

$view = (string)($_GET['view'] ?? $_POST['view'] ?? 'customs');
if (!expdoc_view_ok($view)) $view = 'customs';

$cur = (string)($shipment['currency'] ?? 'USD');

/* ------------------------------------------------------------------ writes */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canEdit) { http_response_code(403); exit('You cannot edit customs documents.'); }

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save') {
            $rows = [];
            foreach (post_array('d_desc') as $i => $desc) {
                $rows[] = [
                    'description' => (string)$desc,
                    'hs_code'     => (string)(post_array('d_hs')[$i]   ?? ''),
                    'unit'        => (string)(post_array('d_unit')[$i] ?? ''),
                    'qty'         => (string)(post_array('d_qty')[$i]  ?? '0'),
                    'rate'        => (string)(post_array('d_rate')[$i] ?? '0'),
                ];
            }
            [$ok, $msg] = expdoc_save_lines($id, $view, $rows);
            if (!$ok) throw new Exception($msg);
            $_SESSION['flash'] = $msg;
            redirect('shipment_customs.php?id=' . $id . '&view=' . $view);
        }

        /* Fills the editor from the commercial invoice, with the memory
           already applied. Nothing is stored until Save. */
        if ($action === 'copy') {
            $_SESSION['expdoc_draft_' . $id . '_' . $view] = expdoc_from_invoice($id, $view);
            $_SESSION['flash'] = 'Copied ' . count(expdoc_source_lines($id))
                               . ' lines from the commercial invoice. Merge them down, then Save.';
            redirect('shipment_customs.php?id=' . $id . '&view=' . $view);
        }

        /* Chamber usually starts from what customs already says. */
        if ($action === 'copy_other') {
            $other = $view === 'customs' ? 'chamber' : 'customs';
            $src = expdoc_lines($id, $other);
            if (!$src) throw new Exception('The ' . EXPDOC_VIEWS[$other] . ' document has no lines yet.');
            $_SESSION['expdoc_draft_' . $id . '_' . $view] = $src;
            $_SESSION['flash'] = 'Copied ' . count($src) . ' lines from the '
                               . EXPDOC_VIEWS[$other] . ' document. Change what differs, then Save.';
            redirect('shipment_customs.php?id=' . $id . '&view=' . $view);
        }

        if ($action === 'clear') {
            [$ok, $msg] = expdoc_save_lines($id, $view, [], false);
            $_SESSION['flash'] = EXPDOC_VIEWS[$view] . ' document emptied. The memory is untouched.';
            redirect('shipment_customs.php?id=' . $id . '&view=' . $view);
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
        redirect('shipment_customs.php?id=' . $id . '&view=' . $view);
    }
}

/* A draft from Copy takes precedence for this one page load, then is dropped
   so a refresh shows what is actually saved. */
$draftKey = 'expdoc_draft_' . $id . '_' . $view;
$isDraft  = false;
if (!empty($_SESSION[$draftKey])) {
    $lines = $_SESSION[$draftKey];
    unset($_SESSION[$draftKey]);
    $isDraft = true;
} else {
    $lines = expdoc_lines($id, $view);
}

$lines  = expdoc_annotate($view, $lines);
$src    = expdoc_source_lines($id);
$check  = expdoc_check($id, $view, $lines);
$other  = $view === 'customs' ? 'chamber' : 'customs';
$otherN = count(expdoc_lines($id, $other));

page_header(EXPDOC_VIEWS[$view] . ' Invoice — ' . $shipment['invoice_no']);
flash();
echo exp_page_css();
exp_tab_strip($shipment, 'customs');
?>
<style>
.vtab{display:flex;gap:6px;margin-bottom:11px}
.vtab a{padding:7px 15px;border-radius:9px;border:1px solid #cbd5e3;background:#f6f8fc;color:#5a6b82;
        font-size:12px;font-weight:700;text-decoration:none}
.vtab a.on{background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;border-color:transparent}
.dtable{width:100%;border-collapse:collapse;font-size:12px;min-width:540px}
.dtable thead tr{text-align:left;color:#8a97ab;font-size:9.5px;text-transform:uppercase;letter-spacing:.05em}
.dtable th{padding:5px 5px;font-weight:700;white-space:nowrap}
.dtable td{padding:4px 5px;border-top:1px solid #f6f8fc;vertical-align:middle}
.dtable input{width:100%;padding:6px 7px;border-radius:7px;border:1px solid #cbd5e3;background:#fff;
              color:#152033;font:inherit;font-size:12px;min-width:0;box-sizing:border-box}
.dtable input:focus{outline:2px solid #0ea8c9;outline-offset:-1px;border-color:#0ea8c9}
.dtable input.n{text-align:right;font-family:'Space Grotesk',system-ui,sans-serif;font-variant-numeric:tabular-nums}
.dtable input.mem{background:rgba(22,163,74,.1);border-color:#16a34a}
.dtable td.cD{min-width:160px}.dtable td.cH{width:88px}.dtable td.cU{width:62px}
.dtable td.cQ{width:78px}.dtable td.cR{width:80px}.dtable td.cA{width:92px}
.amt{text-align:right;font-family:'Space Grotesk',system-ui,sans-serif;font-variant-numeric:tabular-nums;font-weight:600}
.delx{border:none;background:transparent;color:#b8283f;font-size:16px;cursor:pointer;padding:2px 5px;line-height:1}
.sg{margin-top:3px;font-size:10.5px;color:#9a5a06;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.sg button{border:none;background:#d97706;color:#fff;font:inherit;font-size:10px;font-weight:700;
           padding:3px 8px;border-radius:6px;cursor:pointer}
.sg .no{background:transparent;color:#9a5a06;text-decoration:underline;padding:3px 2px}
.chk2{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:12px}
@media(max-width:430px){.chk2{grid-template-columns:1fr}}
.ck{padding:10px 12px;border-radius:11px;border:1px solid #e3e9f2;background:#f6f8fc}
.ck .l{font-size:9.5px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:700}
.ck .v{font-family:'Space Grotesk',system-ui,sans-serif;font-size:15px;font-weight:700;margin-top:3px;font-variant-numeric:tabular-nums}
.ck .d{font-size:10.5px;margin-top:2px}
.ck.ok{border-color:#16a34a;background:rgba(22,163,74,.1)}.ck.ok .v,.ck.ok .d{color:#16a34a}
.ck.bad{border-color:#b8283f;background:rgba(224,67,93,.1)}.ck.bad .v,.ck.bad .d{color:#b8283f}
.ck.dif{border-color:#d97706;background:rgba(217,119,6,.1)}.ck.dif .v,.ck.dif .d{color:#9a5a06}
</style>

<div class="vtab">
  <?php foreach (EXPDOC_VIEWS as $k => $label): ?>
    <a class="<?= $k === $view ? 'on' : '' ?>" href="shipment_customs.php?id=<?= $id ?>&view=<?= e($k) ?>"><?= e($label) ?> Invoice</a>
  <?php endforeach; ?>
</div>

<?php if ($isDraft): ?>
  <div class="xwarn" style="margin-bottom:11px"><b>Not saved yet.</b> These lines were copied in for you to edit. Press Save when you are happy, or leave the page to discard them.</div>
<?php endif; ?>

<details class="xcard">
  <summary style="cursor:pointer;font-size:12.5px;font-weight:700;color:#152033">
    Commercial invoice — <?= count($src) ?> lines, <?= e(trim_num($check['src_qty'], 3)) ?> units,
    <?= e(money_fmt($check['src_value'], $cur)) ?>
  </summary>
  <div class="xwrap" style="margin-top:10px">
    <table class="xtable">
      <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($src as $s): ?>
        <tr>
          <td><?= e($s['product_name']) ?><?= $s['des_col'] ? '<br><span style="color:#8a97ab;font-size:11px">' . e($s['des_col']) . '</span>' : '' ?></td>
          <td class="num"><?= e(trim_num($s['qty'], 3)) ?></td>
          <td class="num"><?= e(num_fmt($s['rate'], 2)) ?></td>
          <td class="num"><?= e(num_fmt($s['amount'], 2)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="xnote" style="margin-top:10px">Read-only. Nothing you type below changes the buyer's invoice.</div>
</details>

<form method="post" id="docForm">
<?= csrf_field() ?>
<input type="hidden" name="action" value="save">
<input type="hidden" name="shipment_id" value="<?= $id ?>">
<input type="hidden" name="view" value="<?= e($view) ?>">

<div class="xcard">
  <h2><?= e(EXPDOC_VIEWS[$view]) ?> Invoice <span style="font-weight:400;color:#8a97ab;font-size:11px">— type as many or as few lines as this document needs</span></h2>

  <div class="xwrap">
    <table class="dtable" id="dt">
      <thead><tr>
        <th>Description</th><th>HS Code</th><th>Unit</th>
        <th style="text-align:right">Qty</th><th style="text-align:right">Rate</th>
        <th style="text-align:right">Amount</th><th></th>
      </tr></thead>
      <tbody id="tb">
      <?php if (!$lines): ?>
        <tr id="emptyRow"><td colspan="7" style="padding:20px;text-align:center;color:#8a97ab">
          No lines yet. Add one, or copy the commercial invoice and merge it down.
        </td></tr>
      <?php endif; ?>
      <?php foreach ($lines as $i => $L):
        $r = $L['recall'] ?? null;
        $exact = $r && $r['kind'] === 'exact'; ?>
        <tr>
          <td class="cD">
            <input name="d_desc[]" value="<?= e($L['description'] ?? '') ?>" placeholder="description"
                   class="<?= $exact ? 'mem' : '' ?>" <?= $canEdit ? '' : 'readonly' ?>>
            <?php if ($r && $r['kind'] === 'near'): ?>
              <div class="sg">Did you mean <b><?= e($r['row']['description']) ?></b>? <?= e(num_fmt($r['score'], 1)) ?>%
                <button type="button" class="use" data-d="<?= e($r['row']['description']) ?>" data-h="<?= e($r['row']['hs_code']) ?>">Use</button>
                <button type="button" class="no">No</button>
              </div>
            <?php endif; ?>
          </td>
          <td class="cH"><input class="n" name="d_hs[]"   value="<?= e($L['hs_code'] ?? '') ?>" placeholder="HS" <?= $canEdit ? '' : 'readonly' ?>></td>
          <td class="cU"><input       name="d_unit[]" value="<?= e($L['unit'] ?? '') ?>" placeholder="Pcs" <?= $canEdit ? '' : 'readonly' ?>></td>
          <td class="cQ"><input class="n q" name="d_qty[]"  value="<?= e(trim_num($L['qty'] ?? 0, 3)) ?>" inputmode="decimal" <?= $canEdit ? '' : 'readonly' ?>></td>
          <td class="cR"><input class="n r" name="d_rate[]" value="<?= e(trim_num($L['rate'] ?? 0, 4)) ?>" inputmode="decimal" <?= $canEdit ? '' : 'readonly' ?>></td>
          <td class="cA amt"><?= e(num_fmt(($L['qty'] ?? 0) * ($L['rate'] ?? 0), 2)) ?></td>
          <td><?php if ($canEdit): ?><button type="button" class="delx" title="remove">&times;</button><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr style="border-top:2px solid #e3e9f2;font-weight:700">
        <td colspan="3" id="lineCount"></td>
        <td class="amt" id="sumQ"></td><td></td><td class="amt" id="sumV"></td><td></td>
      </tr></tfoot>
    </table>
  </div>

  <div class="chk2">
    <div class="ck" id="ckU"><div class="l">Units</div><div class="v"></div><div class="d"></div></div>
    <div class="ck" id="ckV"><div class="l">Value (<?= e($cur) ?>)</div><div class="v"></div><div class="d"></div></div>
  </div>
  <div id="guard"></div>

  <?php if ($canEdit): ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:13px">
    <button class="xbtn">Save <?= e(EXPDOC_VIEWS[$view]) ?> Invoice</button>
    <button type="button" class="xbtn sec" id="addRow">+ Add line</button>
  </div>
  <?php endif; ?>
</div>
</form>

<?php if ($canEdit): ?>
<div class="xcard" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
  <form method="post" style="display:inline">
    <?= csrf_field() ?><input type="hidden" name="action" value="copy">
    <input type="hidden" name="shipment_id" value="<?= $id ?>"><input type="hidden" name="view" value="<?= e($view) ?>">
    <button class="xbtn sec">Copy the <?= count($src) ?> commercial lines</button>
  </form>
  <?php if ($otherN): ?>
  <form method="post" style="display:inline">
    <?= csrf_field() ?><input type="hidden" name="action" value="copy_other">
    <input type="hidden" name="shipment_id" value="<?= $id ?>"><input type="hidden" name="view" value="<?= e($view) ?>">
    <button class="xbtn sec">Copy the <?= e(EXPDOC_VIEWS[$other]) ?> document (<?= $otherN ?> lines)</button>
  </form>
  <?php endif; ?>
  <?php if ($lines): ?>
  <form method="post" style="display:inline" onsubmit="return confirm('Empty this document? The memory is not affected.')">
    <?= csrf_field() ?><input type="hidden" name="action" value="clear">
    <input type="hidden" name="shipment_id" value="<?= $id ?>"><input type="hidden" name="view" value="<?= e($view) ?>">
    <button class="xbtn red">Empty it</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="xcard">
  <h2>Print</h2>
  <?php if ($check['can_print']): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="xbtn" target="_blank" href="customs_print.php?id=<?= $id ?>&view=<?= e($view) ?>&doc=invoice"><?= e(EXPDOC_VIEWS[$view]) ?> Invoice</a>
      <a class="xbtn sec" target="_blank" href="customs_print.php?id=<?= $id ?>&view=<?= e($view) ?>&doc=packing"><?= e(EXPDOC_VIEWS[$view]) ?> Packing List</a>
    </div>
  <?php else: ?>
    <div class="xwarn">
      <?= $check['empty']
          ? 'Add some lines first.'
          : 'Printing is locked until the total units match the commercial invoice.' ?>
    </div>
  <?php endif; ?>
</div>

<script>
/* The two running checks, recalculated on every keystroke. The server works
   the same figures out again on save and on print — this is the preview, not
   the authority. */
(function () {
  var SRC_Q = <?= json_encode((float)$check['src_qty']) ?>;
  var SRC_V = <?= json_encode((float)$check['src_value']) ?>;
  var CUR   = <?= json_encode($cur) ?>;
  var EDIT  = <?= $canEdit ? 'true' : 'false' ?>;

  var tb = document.getElementById('tb');
  function num(v) { var x = parseFloat(String(v).replace(/,/g, '')); return isFinite(x) ? x : 0; }
  function fmt(v, d) { return v.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }); }

  function recalc() {
    var rows = tb.querySelectorAll('tr'), tq = 0, tv = 0, n = 0;
    rows.forEach(function (tr) {
      var q = tr.querySelector('.q'), r = tr.querySelector('.r'), a = tr.querySelector('.amt');
      if (!q || !r) return;
      n++;
      var amt = num(q.value) * num(r.value);
      if (a) a.textContent = fmt(amt, 2);
      tq += num(q.value); tv += amt;
    });

    document.getElementById('lineCount').textContent = n + ' line' + (n === 1 ? '' : 's');
    document.getElementById('sumQ').textContent = fmt(tq, 0);
    document.getElementById('sumV').textContent = fmt(tv, 2);

    var uOk = Math.abs(tq - SRC_Q) < 0.0005;
    var cu = document.getElementById('ckU');
    cu.className = 'ck ' + (uOk ? 'ok' : 'bad');
    cu.querySelector('.v').textContent = fmt(tq, 0) + ' / ' + fmt(SRC_Q, 0);
    cu.querySelector('.d').textContent = uOk ? 'matches the commercial invoice'
      : (tq > SRC_Q ? fmt(tq - SRC_Q, 0) + ' too many' : fmt(SRC_Q - tq, 0) + ' short');

    var diff = tv - SRC_V, same = Math.abs(diff) < 0.005;
    var cv = document.getElementById('ckV');
    cv.className = 'ck ' + (same ? 'ok' : 'dif');
    cv.querySelector('.v').textContent = fmt(tv, 2);
    cv.querySelector('.d').textContent = same ? 'same as the commercial invoice'
      : (diff > 0 ? '+' : '−') + fmt(Math.abs(diff), 2) +
        (SRC_V > 0 ? ' (' + (diff > 0 ? '+' : '−') + Math.abs(diff / SRC_V * 100).toFixed(1) + '%)' : '') +
        ' vs commercial';

    var g = document.getElementById('guard');
    if (n === 0) { g.innerHTML = ''; return; }
    if (!uOk) {
      g.innerHTML = '<div class="xwarn" style="margin-top:11px"><b>This document cannot be printed yet.</b> ' +
        'Total units must equal the commercial invoice — ' + fmt(SRC_Q, 0) + '. You can still save it.</div>';
    } else if (!same) {
      g.innerHTML = '<div class="xnote" style="margin-top:11px">The value differs from the commercial invoice by ' +
        (diff > 0 ? '+' : '−') + CUR + ' ' + fmt(Math.abs(diff), 2) +
        '. That is allowed. The difference is recorded in the audit log with your name when you save.</div>';
    } else {
      g.innerHTML = '<div class="xnote" style="margin-top:11px;border-color:rgba(22,163,74,.3);background:rgba(22,163,74,.08)">' +
        'Units and value both match the commercial invoice.</div>';
    }
  }

  tb.addEventListener('input', recalc);

  tb.addEventListener('click', function (e) {
    var del = e.target.closest('.delx');
    var use = e.target.closest('.use');
    var no  = e.target.closest('.no');
    if (del) {
      var tr = del.closest('tr');
      if (tr) tr.remove();
      recalc();
    } else if (use) {
      var row = use.closest('tr');
      row.querySelector('[name="d_desc[]"]').value = use.dataset.d;
      if (use.dataset.h) row.querySelector('[name="d_hs[]"]').value = use.dataset.h;
      use.closest('.sg').remove();
      recalc();
    } else if (no) {
      no.closest('.sg').remove();
    }
  });

  var add = document.getElementById('addRow');
  if (add) add.addEventListener('click', function () {
    var empty = document.getElementById('emptyRow');
    if (empty) empty.remove();
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td class="cD"><input name="d_desc[]" placeholder="description"></td>' +
      '<td class="cH"><input class="n" name="d_hs[]" placeholder="HS"></td>' +
      '<td class="cU"><input name="d_unit[]" placeholder="Pcs"></td>' +
      '<td class="cQ"><input class="n q" name="d_qty[]" value="0" inputmode="decimal"></td>' +
      '<td class="cR"><input class="n r" name="d_rate[]" value="0" inputmode="decimal"></td>' +
      '<td class="cA amt">0.00</td>' +
      '<td><button type="button" class="delx" title="remove">&times;</button></td>';
    tb.appendChild(tr);
    tr.querySelector('[name="d_desc[]"]').focus();
    recalc();
  });

  /* Office keys, as everywhere else. */
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
      e.preventDefault();
      if (EDIT) document.getElementById('docForm').submit();
    }
    if (e.key === 'Escape') window.location.href = 'shipment_view.php?id=<?= $id ?>';
  });

  recalc();
})();
</script>
<?php page_footer(); ?>
