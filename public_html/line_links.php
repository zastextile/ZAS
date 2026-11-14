<?php
/*
  LINK LATER — every invoice and proforma line typed without a link.
  ==================================================================

  A line typed freely ("Thermal blanket 150x200 grey") is kept and printed
  exactly as typed, but nothing knows WHAT it is: the dashboard cannot group
  it with the product, production has to guess. This page lists every such
  line, newest first, with the closest product or part already suggested, so
  the office can link them all in one sitting instead of opening each
  document.

    · A suggestion is only OFFERED. Nothing is linked until a person ticks it
      and presses Link.
    · Linking changes the link only. The printed name, the quantity and the
      price stay exactly as they are, on every document.
    · A PART link is for naming and reports only — production does not plan
      from it. A PRODUCT link is the same link the line picker makes.
    · Only a line that is still unlinked is changed (includes/linelinks.php,
      ll_link), so two people working the list at once cannot overwrite each
      other.

  Also answers ?last=1 for the invoice and proforma screens: what this buyer
  was last charged for each product and part, in this currency.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/linelinks.php';

/* ---------------------------------------------- last price to this buyer (JSON) */
if (isset($_GET['last'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!zu_can('ship', 'v') && !zu_can('proforma', 'v')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No access.']);
        exit;
    }
    /* prices are only for people who may see them */
    if (function_exists('can_see_rates') && !can_see_rates() && !is_admin()) {
        echo json_encode(['ok' => true, 'prices' => []]);
        exit;
    }
    $buyer = mb_substr(trim((string)($_GET['buyer'] ?? '')), 0, 200);
    $cur = strtoupper(mb_substr(trim((string)($_GET['cur'] ?? '')), 0, 8));
    echo json_encode(['ok' => true, 'prices' => ll_last_prices($buyer, $cur, (int)($_GET['ship'] ?? 0), (int)($_GET['pf'] ?? 0))]);
    exit;
}

/* --------------------------------------------------------------- the page */
if (!is_admin() && !is_colleague()) { http_response_code(403); exit('Product Master access required.'); }

$products = [];
try { $products = db()->query("SELECT id, name FROM products WHERE is_active = 1 ORDER BY name LIMIT 5000")->fetchAll(); } catch (Throwable $e) {}
$parts = ll_parts();
/* what a person types or picks in the "Link to" box, and what it means */
$labelOf = [];
foreach ($products as $p) { $labelOf['p' . (int)$p['id']] = 'Product — ' . $p['name']; }
foreach ($parts as $p) { $labelOf['k' . $p['id']] = 'Part — ' . $p['name']; }
$targetOf = [];
foreach ($labelOf as $t => $l) { $targetOf[mb_strtolower($l)] = $t; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $done = 0; $same = 0; $bad = [];
    foreach ((array)($_POST['pick'] ?? []) as $key => $on) {
        if (!$on || !preg_match('/^(inv|pf)-(\d+)$/', (string)$key, $m)) continue;
        $label = trim((string)($_POST['to'][$key] ?? ''));
        $target = $targetOf[mb_strtolower($label)] ?? null;
        if ($target === null) { $bad[] = ($label === '' ? 'a ticked line had nothing chosen' : '"' . $label . '" is not in the list'); continue; }
        $may = $m[1] === 'inv' ? zu_can('ship', 'u') : zu_can('proforma', 'u');
        if (!$may) { $bad[] = 'you cannot change ' . ($m[1] === 'inv' ? 'invoices' : 'proformas'); continue; }
        $r = ll_link($m[1], (int)$m[2], $target);
        if (!$r['ok']) { $bad[] = $r['error']; continue; }
        if ($r['changed']) $done++; else $same++;
    }
    $msg = $done . ($done === 1 ? ' line linked.' : ' lines linked.') . ($same ? ' ' . $same . ' had been linked by someone else meanwhile.' : '');
    if ($bad) $_SESSION['error'] = 'Not linked: ' . implode('; ', array_unique($bad)) . '. ' . $msg;
    else $_SESSION['flash'] = $msg;
    redirect('line_links.php' . (isset($_GET['kind']) ? '?kind=' . urlencode((string)$_GET['kind']) : ''));
}

$kind = in_array($_GET['kind'] ?? '', ['inv', 'pf'], true) ? $_GET['kind'] : '';
$q = trim((string)($_GET['q'] ?? ''));
$lines = array_values(array_filter(ll_unlinked(600), function ($l) use ($kind, $q) {
    if ($kind !== '' && $l['kind'] !== $kind) return false;
    if ($q !== '' && stripos($l['name'] . ' ' . $l['doc_no'] . ' ' . $l['party'], $q) === false) return false;
    return true;
}));
$suggested = 0;
foreach ($lines as &$l) {
    $s = ll_suggest($l['name'], $products, $parts);
    $l['suggest'] = $s ? $labelOf[$s[0] . $s[1]] ?? '' : '';
    if ($l['suggest'] !== '') $suggested++;
}
unset($l);

page_header('Link lines to products');
?>
<style>
.ll{background:#fff;border:1px solid #d5dbe3;border-radius:6px;padding:14px 16px;display:grid;gap:12px}
.ll .bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.ll .bar input,.ll .bar select{border:1px solid #b9c4d0;border-radius:4px;padding:6px 8px;font-size:13px;min-height:30px}
.ll .tip{background:#e1edf9;border:1px solid #c5dbf2;color:#123d6a;border-radius:4px;padding:8px 12px;font-size:12.5px}
.ll table{width:100%;border-collapse:separate;border-spacing:0;border:1px solid #d5dbe3;border-radius:4px;overflow:hidden;font-size:13px}
.ll th{background:#0f2742;color:#fff;font-size:12px;font-weight:600;text-align:left;padding:8px 10px;white-space:nowrap}
.ll td{padding:6px 10px;border-bottom:1px solid #e7ebf0;vertical-align:middle}
.ll td.n{text-align:right;white-space:nowrap}
.ll .doc{font-weight:600;color:#0a5fb4;text-decoration:none}
.ll .kind{display:inline-block;font-size:11px;font-weight:700;border-radius:3px;padding:1px 6px;margin-right:6px}
.ll .kind.inv{background:#e1edf9;color:#0a5fb4}.ll .kind.pf{background:#f1ecfb;color:#5a37a8}
.ll .to{width:100%;min-width:240px;border:1px solid #b9c4d0;border-radius:4px;padding:5px 8px;font-size:13px}
.ll .to.sug{border-color:#1f7a3a;background:#f4faf5}
.ll .m{color:#5b6b7c;font-size:12px}
.ll .btnp{background:#0a5fb4;color:#fff;border:0;border-radius:4px;padding:8px 16px;font-weight:600;cursor:pointer}
.ll .btns{background:#fff;color:#0a5fb4;border:1px solid #b9c4d0;border-radius:4px;padding:6px 12px;font-weight:600;cursor:pointer}
.ll .empty{text-align:center;color:#5b6b7c;padding:28px}
.ll .count{margin-left:auto;color:#5b6b7c;font-size:12.5px}
</style>
<div class="topbar"><div><h1>Link lines to products</h1>
  <p class="lead">Invoice and proforma lines typed without picking from Product Master. Link them once, so the dashboard and production know what each line is.</p></div></div>
<?php flash(); ?>
<div class="ll">
  <form class="bar" method="get">
    <select name="kind" onchange="this.form.submit()">
      <option value="">Invoices and proformas</option>
      <option value="inv"<?= $kind === 'inv' ? ' selected' : '' ?>>Invoices only</option>
      <option value="pf"<?= $kind === 'pf' ? ' selected' : '' ?>>Proformas only</option>
    </select>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search line, document or buyer">
    <button class="btns" type="submit">Search</button>
    <span class="count"><?= count($lines) ?> unlinked line<?= count($lines) === 1 ? '' : 's' ?> · <?= $suggested ?> with a suggestion</span>
  </form>
  <div class="tip">A suggestion (green box) is only offered — tick the lines you agree with and press <b>Link ticked lines</b>. Change the box to pick another product or part.
    The printed name, quantity and price never change. A <b>Part</b> link is for naming and reports only; production does not plan from it.</div>
  <?php if (!$lines): ?>
    <div class="empty">Every line is linked. Nothing to do.</div>
  <?php else: ?>
  <form method="post" id="llForm">
    <?= csrf_field() ?>
    <datalist id="llTargets"><?php foreach ($labelOf as $l): ?><option value="<?= e($l) ?>"><?php endforeach; ?></datalist>
    <div class="bar" style="margin-bottom:8px">
      <button class="btns" type="button" onclick="llTick(true)">Tick all with a suggestion</button>
      <button class="btns" type="button" onclick="llTick(false)">Untick all</button>
      <span class="count"></span>
      <button class="btnp" type="submit">Link ticked lines</button>
    </div>
    <div style="overflow:auto">
    <table>
      <thead><tr><th style="width:28px"></th><th>Date</th><th>Document</th><th>Buyer</th><th>Line as typed</th><th style="text-align:right">Qty</th><th>Link to</th></tr></thead>
      <tbody>
      <?php foreach ($lines as $l): $key = $l['kind'] . '-' . $l['id'];
        $href = $l['kind'] === 'inv' ? 'shipment_view.php?id=' . $l['doc_id'] : 'proforma.php?id=' . $l['doc_id']; ?>
        <tr>
          <td><input type="checkbox" name="pick[<?= e($key) ?>]" value="1"<?= $l['suggest'] !== '' ? ' data-sug="1"' : '' ?>></td>
          <td class="m" style="white-space:nowrap"><?= e($l['dt']) ?></td>
          <td style="white-space:nowrap"><span class="kind <?= $l['kind'] ?>"><?= $l['kind'] === 'inv' ? 'Invoice' : 'Proforma' ?></span><a class="doc" href="<?= e($href) ?>" target="_blank"><?= e($l['doc_no']) ?></a></td>
          <td><?= e($l['party']) ?></td>
          <td><b><?= e($l['name']) ?></b></td>
          <td class="n"><?= e(rtrim(rtrim(number_format($l['qty'], 3, '.', ','), '0'), '.')) ?> <span class="m"><?= e($l['unit']) ?></span></td>
          <td><input class="to<?= $l['suggest'] !== '' ? ' sug' : '' ?>" name="to[<?= e($key) ?>]" list="llTargets" value="<?= e($l['suggest']) ?>" placeholder="Type to find a product or part" autocomplete="off"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </form>
  <script>
  function llTick(on){ document.querySelectorAll('#llForm input[type=checkbox]').forEach(function(c){ c.checked = on && (c.getAttribute('data-sug') === '1' || c.closest('tr').querySelector('.to').value.trim() !== ''); }); }
  /* typing or picking a target ticks the line */
  document.querySelectorAll('#llForm .to').forEach(function(i){ i.addEventListener('change', function(){ var c = i.closest('tr').querySelector('input[type=checkbox]'); c.checked = i.value.trim() !== ''; i.classList.remove('sug'); }); });
  </script>
  <?php endif; ?>
</div>
<?php page_footer(); ?>
