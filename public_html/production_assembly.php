<?php
/*
  ASSEMBLY — MAKE SETS
  ====================

  Asked for in these words:
    "we have to combine pack the goods as well based on parts produced on
     floor as to convert stock of parts"
    "allow parts to be used with any other PO ... we should not waste that
     leftover but would use it for any po whatever"

  THE POOL IS THE WHOLE IDEA. A finished part is not owned by the order
  that made it. A King bed sheet is a King bed sheet — so if PI-2291
  finished with forty spare, PI-2304 packs them. Parts are pooled by PART
  and SIZE LABEL across every order, oldest order taken first, and every
  piece records the order line it came from so nothing loses its trail.

  ONE REFUSAL, AND ONLY ONE. You cannot assemble more sets than the pool
  has parts for. Everything else in this app warns and lets you through,
  because everything else is a FACT being written down — goods really did
  leave the gate, a worker really was paid an advance. Sets that no parts
  exist for are not a fact; they would invent finished stock and every
  figure downstream would be wrong with nothing on screen to say why.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin() && !is_colleague()) { http_response_code(403); exit('Production access required.'); }
require_once __DIR__ . '/includes/zprod.php';
zp_ensure_schema();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$msg = ''; $err = '';
$pid   = (int)($_GET['p'] ?? $_POST['product_id'] ?? 0);
$size  = trim((string)($_GET['s'] ?? $_POST['size_label'] ?? ''));
$sets  = (float)str_replace(',', '', (string)($_GET['n'] ?? $_POST['sets'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'make') {
        $r = zp_assembly_save((string)($_POST['made_date'] ?? ''), $pid, $size, $sets,
                              (int)($_POST['proforma_item_id'] ?? 0) ?: null,
                              (string)($_POST['note'] ?? ''), (int)(current_user()['id'] ?? 0));
        if ($r['ok']) {
            $_SESSION['zp_msg'] = rtrim(rtrim(number_format($sets, 2), '0'), '.') . ' set(s) of ' . $size . ' assembled.';
            redirect('production_assembly.php?p=' . $pid . '&s=' . urlencode($size));
        }
        $err = $r['error'];
    } elseif ($a === 'cancel') {
        $r = zp_assembly_cancel((int)($_POST['aid'] ?? 0), (string)($_POST['reason'] ?? ''), (int)(current_user()['id'] ?? 0));
        $_SESSION['zp_msg'] = $r['ok'] ? 'Assembly cancelled. The parts are back in the pool.' : '';
        if (!$r['ok']) $_SESSION['error'] = $r['error'];
        redirect('production_assembly.php');
    }
}
if (!empty($_SESSION['zp_msg'])) { $msg = $_SESSION['zp_msg']; unset($_SESSION['zp_msg']); }
if (!empty($_SESSION['error']))  { $err = $_SESSION['error'];  unset($_SESSION['error']); }

$products = zp_products(true);
$sizes    = $pid > 0 ? zp_assembly_sizes($pid) : [];
$plan     = ($pid > 0 && $size !== '') ? zp_assembly_plan($pid, $size, $sets) : null;
$recent   = zp_assembly_list(40);

/* Orders this could be credited to — optional, and it stays optional:
   assembling to stock with no order at all is the leftover rule working. */
$orders = [];
foreach (zp_open_lines() as $l)
    if ((int)$l['product_id'] === $pid)
        $orders[] = ['id' => (int)$l['item_id'], 'pi' => short_ref((string)$l['pi_no']),
                     'cust' => (string)($l['customer_name'] ?? ''), 'size' => (string)($l['size'] ?? ''),
                     'qty' => (float)$l['ordered_qty']];

function nq($v) { return rtrim(rtrim(number_format((float)$v, 2), '0'), '.'); }

page_header('Assembly — Make Sets');
?>
<style>
.zp-card{background:#fff;border:1px solid #e6ebf2;border-radius:13px;padding:16px 17px;margin-bottom:16px;box-shadow:0 1px 2px rgba(20,40,80,.04)}
.zp-card h2{margin:0 0 3px;font-size:15.5px;color:#152033}
.zp-card p.sub{margin:0 0 12px;font-size:11.5px;color:#8a97ab}
.zin{width:100%;padding:6px 8px;border:1px solid #d9e0ea;border-radius:8px;font-size:12.5px;background:#fff;color:#152033;font-family:inherit}
.zin:focus{outline:none;border-color:#0ea8c9;box-shadow:0 0 0 3px rgba(14,168,201,.14)}
.zin.num{text-align:right;font-variant-numeric:tabular-nums}
.lab{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:800;margin-bottom:4px}
table.zp-t{width:100%;border-collapse:collapse;font-size:12.5px}
table.zp-t th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;padding:6px 7px;border-bottom:1px solid #e6ebf2;background:#fbfcfe;white-space:nowrap}
table.zp-t td{padding:4px 7px;border-bottom:1px solid #f1f4f9;vertical-align:middle}
table.zp-t td.r,table.zp-t th.r{text-align:right;font-variant-numeric:tabular-nums}
.zp-b{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:9px;border:1px solid #d9e0ea;background:#fff;color:#33445c;font-size:12.5px;cursor:pointer;font-family:inherit;text-decoration:none}
.zp-b.pri{background:#1d76e2;border-color:#1d76e2;color:#fff}
.zp-b.ok{background:#16a34a;border-color:#16a34a;color:#fff}
.zp-b.sm{padding:4px 10px;font-size:11.5px}
.zp-b.red{background:#e0435d;border-color:#e0435d;color:#fff}
.hdr{display:grid;grid-template-columns:120px minmax(190px,1.2fr) 130px 110px minmax(180px,1fr);gap:10px}
.strip{display:flex;flex-wrap:wrap;gap:10px;margin:12px 0}
.tile{flex:1 1 160px;border:1px solid #e6ebf2;border-radius:11px;padding:9px 12px;background:#fbfcfe}
.tile .k{font-size:9.5px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:800}
.tile .v{font-size:20px;font-weight:800;font-variant-numeric:tabular-nums;margin-top:2px}
.tile.good{background:#f4fbf6;border-color:#cfe9d8}.tile.bad{background:#fff7f8;border-color:#f3ccd4}
.note{padding:11px 13px;border-radius:10px;font-size:12.5px;line-height:1.55;background:#eef6ff;border:1px solid #cfe3fb;color:#28527d;margin-bottom:14px}
.note.ok{background:#f4fbf6;border-color:#cfe9d8;color:#1d6b46}
.note.bad{background:#fff7f8;border-color:#f3ccd4;color:#9a2740}
.src{font-size:10.5px;color:#8a97ab;white-space:nowrap}.src b{color:#5a6b82;font-weight:600}
.short{color:#c0293f;font-weight:700}.okv{color:#16a34a;font-weight:700}
.gone td{color:#b6c0cf;text-decoration:line-through}.gone td.keep{text-decoration:none;color:#8a97ab}
</style>
<div class="zskin">

<div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:10px;margin-bottom:14px">
  <div><h1 style="margin:0;font-size:21px">Assembly — Make Sets</h1>
    <p style="margin:3px 0 0;font-size:12.5px;color:#8a97ab">Turns finished parts into finished sets. Parts come from the pool at that size, <b>whichever PO made them</b>.</p></div>
  <div style="display:flex;gap:8px">
    <a class="zp-b" href="production_entry.php">Daily Entry</a>
    <a class="zp-b" href="product_master.php">Product Master</a>
  </div>
</div>

<?php if ($msg): ?><div class="note ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="note bad"><?= e($err) ?></div><?php endif; ?>

<div class="zp-card">
  <h2>What are we making?</h2>
  <p class="sub">Pick the product, then the size. The size list tells you how many sets the pool can already make at each one.</p>

  <form method="get" class="hdr">
    <div><label class="lab">Date</label><input class="zin" type="date" value="<?= e(date('Y-m-d')) ?>" disabled></div>
    <div><label class="lab">Product</label>
      <select class="zin" name="p" onchange="this.form.s.value=''; this.form.submit()">
        <option value="0">— choose —</option>
        <?php foreach ($products as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $pid ? 'selected' : '' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label class="lab">Size</label>
      <select class="zin" name="s" onchange="this.form.submit()" <?= $pid > 0 ? '' : 'disabled' ?>>
        <option value="">— choose —</option>
        <?php foreach ($sizes as $sz): ?>
          <option value="<?= e($sz['label']) ?>" <?= $sz['label'] === $size ? 'selected' : '' ?>>
            <?= e($sz['label']) ?> — pool allows <?= nq($sz['can']) ?><?= $sz['by'] !== '' && $sz['can'] <= 0 ? ' (' . e($sz['by']) . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      </select></div>
    <div><label class="lab">Sets to make</label>
      <input class="zin num" name="n" value="<?= $sets > 0 ? e(nq($sets)) : '' ?>" placeholder="0"
             onchange="this.form.submit()" <?= $size !== '' ? '' : 'disabled' ?>></div>
    <div style="display:flex;align-items:flex-end;font-size:11.5px;color:#8a97ab">
      <?php if ($pid > 0 && !$sizes): ?>This product has no sizes yet — add them in Product Master.<?php endif; ?>
    </div>
  </form>
</div>

<?php if ($plan && $plan['ok']): ?>
  <?php $can = $plan['can']; $tooMany = $sets > $can + 0.0001; ?>
  <div class="zp-card">
    <h2>The parts this set is made of</h2>
    <p class="sub">Per set comes from Product Master, at this size. Available is every finished piece at this size <b>across all orders</b>, less what earlier assemblies took.</p>

    <div class="strip">
      <div class="tile"><div class="k">Pool can make</div><div class="v"><?= nq($can) ?></div>
        <div class="k" style="text-transform:none;letter-spacing:0;font-weight:400"><?= $plan['limit_by'] !== '' ? 'limited by ' . e($plan['limit_by']) : 'nothing to make yet' ?></div></div>
      <div class="tile <?= $tooMany ? 'bad' : 'good' ?>"><div class="k">Making now</div><div class="v"><?= nq($sets) ?></div>
        <div class="k" style="text-transform:none;letter-spacing:0;font-weight:400">sets</div></div>
      <div class="tile"><div class="k">Parts consumed</div>
        <div class="v"><?= nq(array_sum(array_column($plan['parts'], 'need'))) ?></div>
        <div class="k" style="text-transform:none;letter-spacing:0;font-weight:400">pieces, off the pool</div></div>
    </div>

    <?php if ($tooMany): ?>
      <div class="note bad"><b>The pool cannot make <?= nq($sets) ?> sets — only <?= nq($can) ?>.</b>
        <?= $plan['limit_by'] !== '' ? e($plan['limit_by']) . ' runs out first. ' : '' ?>
        Assembling more would create finished stock out of parts that were never produced, so this one is refused rather than warned about.</div>
    <?php elseif ($sets > 0): ?>
      <div class="note">Parts are taken <b>oldest order first</b> across every PO at this size — leftovers from a finished order are used before new pieces, and each piece records which order it came from.</div>
    <?php endif; ?>

    <div style="overflow-x:auto">
    <table class="zp-t"><thead><tr>
      <th style="width:26px">#</th><th>Part</th><th class="r" style="width:70px">Per set</th>
      <th class="r" style="width:88px">Need</th><th class="r" style="width:96px">Available</th>
      <th class="r" style="width:92px">Short</th><th>Where the pieces are</th>
    </tr></thead><tbody>
    <?php foreach ($plan['parts'] as $i => $p): ?>
      <tr>
        <td style="color:#8a97ab;font-size:11px"><?= $i + 1 ?></td>
        <td style="font-weight:600"><?= e($p['name']) ?></td>
        <td class="r"><?= nq($p['per']) ?></td>
        <td class="r"><?= nq($p['need']) ?></td>
        <td class="r <?= $p['left'] >= $p['need'] && $p['left'] > 0 ? 'okv' : ($p['left'] <= 0 ? 'short' : 'short') ?>"><?= nq($p['left']) ?></td>
        <td class="r"><?= $p['shortfall'] > 0 ? '<span class="short">' . nq($p['shortfall']) . '</span>' : '<span style="color:#b6c0cf">—</span>' ?></td>
        <td class="src">
          <?php if (!$p['by']): ?><span class="short">nothing finished at this size</span>
          <?php else: foreach ($p['by'] as $b): ?><b><?= e($b['pi']) ?></b> <?= nq($b['left']) ?> &nbsp;<?php endforeach; endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>

    <?php if ($sets > 0 && !$tooMany): ?>
      <form method="post" style="margin-top:13px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="make">
        <input type="hidden" name="product_id" value="<?= (int)$pid ?>">
        <input type="hidden" name="size_label" value="<?= e($size) ?>">
        <input type="hidden" name="sets" value="<?= e(nq($sets)) ?>">
        <input type="hidden" name="made_date" value="<?= e(date('Y-m-d')) ?>">
        <?php /* OPTIONAL ON PURPOSE. Assembling to stock with no order at
                 all is the leftover rule working — you make the sets, you
                 sell them later. */ ?>
        <div style="min-width:260px"><label class="lab">Credit to an order (optional)</label>
          <select class="zin" name="proforma_item_id">
            <option value="0">— no order, to stock —</option>
            <?php foreach ($orders as $o): ?>
              <option value="<?= (int)$o['id'] ?>"><?= e($o['pi']) ?> · <?= e($o['cust']) ?> · <?= e($o['size']) ?> — <?= nq($o['qty']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div style="min-width:200px"><label class="lab">Note</label>
          <input class="zin" name="note" maxlength="255" placeholder="optional"></div>
        <button class="zp-b ok" type="submit">Make <?= nq($sets) ?> set(s) of <?= e($size) ?></button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="zp-card">
  <h2>Assembled so far</h2>
  <p class="sub">Newest first. Cancelling one puts its parts straight back in the pool.</p>
  <?php if (!$recent): ?>
    <div class="note">Nothing assembled yet.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="zp-t"><thead><tr>
    <th style="width:100px">Date</th><th>Product</th><th style="width:90px">Size</th>
    <th class="r" style="width:80px">Sets</th><th>Parts taken from</th><th style="width:90px"></th>
  </tr></thead><tbody>
  <?php foreach ($recent as $a): $live = $a['status'] === 'active'; ?>
    <tr class="<?= $live ? '' : 'gone' ?>">
      <td class="keep"><?= e($a['made_date']) ?></td>
      <td><?= e($a['product_name'] ?? '') ?></td>
      <td><?= e($a['size_label']) ?></td>
      <td class="r"><?= nq($a['sets_made']) ?></td>
      <td class="src">
        <?php
          $agg = [];
          foreach ($a['parts'] as $pp) $agg[(string)$pp['part_name']] = ($agg[(string)$pp['part_name']] ?? 0) + (float)$pp['qty'];
          $bits = []; foreach ($agg as $n2 => $q2) $bits[] = e($n2) . ' ' . nq($q2);
          echo implode(' &middot; ', $bits);
        ?>
      </td>
      <td class="keep">
        <?php if ($live && is_admin()): ?>
          <form method="post" style="display:inline"
                onsubmit="var r=prompt('Why is this assembly being cancelled?'); if(!r||!r.trim()) return false; this.reason.value=r; return true;">
            <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
            <input type="hidden" name="aid" value="<?= (int)$a['id'] ?>">
            <input type="hidden" name="reason" value="">
            <button class="zp-b sm red" type="submit">Cancel</button>
          </form>
        <?php elseif (!$live): ?>
          <span style="font-size:10.5px;color:#9a2740">cancelled</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>

</div>
<?php page_footer(); ?>
