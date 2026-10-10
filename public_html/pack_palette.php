<?php
/*
  THE ORDER'S SIZES AND COLOURS — set once, at a desk.

  WHY THIS IS A DESKTOP SCREEN AND NOT A PHONE ONE.
  The customer's detail arrives by email or WhatsApp, and it arrives at
  the office. It is typed once per order and read on every carton, which
  makes it a desk job: a real keyboard, a bigger screen, and the email
  open beside it. A colour typed as "Navy Blue" on a phone when the order
  says "Navy" leaves two colours in the database for one.

  AND IT IS WHAT MAKES THE PHONE SIMPLE. Because the office has already
  decided, the packing hall never sees a wall of colours — only the two
  or three this order actually uses. That is the whole point of it.

  THE LIST OFFERED IS THIS PRODUCT'S, EXACTLY: what it has been costed
  in, and what it has been packed in before. Nothing generic, nothing
  from anybody else's shipment. Only something genuinely new is typed,
  and then it is offered from next time on.

  NOTHING HERE IS CHECKED AGAINST THE INVOICE. A colour is a property of
  what is in the carton, like the quantity, and the invoice does not
  carry it.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/packing.php';
require_login();

if (is_production_staff()) { http_response_code(403); exit('Production Staff cannot open the packing list.'); }
exp_ensure_schema();
pack_ensure_schema();

$id = (int)($_GET['id'] ?? $_POST['shipment_id'] ?? 0);
if ($id <= 0) { redirect('packing_list.php'); }

$s = db()->prepare("SELECT * FROM shipments WHERE id=?");
$s->execute([$id]);
$shipment = $s->fetch();
if (!$shipment) { http_response_code(404); exit('Shipment not found.'); }

$ids = assigned_shipment_ids();
if ($ids !== ['ALL'] && !in_array($id, $ids, true)) {
    http_response_code(403); exit('That shipment is not assigned to you.');
}
$canEdit = pack_may_edit($shipment);

$itStmt = db()->prepare("SELECT * FROM shipment_items WHERE shipment_id=? ORDER BY line_no, id");
$itStmt->execute([$id]);
$items = $itStmt->fetchAll();

/* ----------------------------------------------------------------- save */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canEdit) { http_response_code(403); exit('This packing list can no longer be changed.'); }

    $itemId = (int)($_POST['invoice_item_id'] ?? 0);
    $known = false;
    foreach ($items as $it) if ((int)$it['id'] === $itemId) $known = true;
    if (!$known) { $_SESSION['error'] = 'That line is not on this invoice.'; redirect('pack_palette.php?id=' . $id); }

    /* Ticked boxes, plus anything typed into the "not on the list" field.
       One field takes several at once, separated by commas, because a
       customer's email usually lists them that way. */
    foreach (['size', 'colour'] as $kind) {
        $picked = array_map('strval', (array)($_POST['pick_' . $kind] ?? []));
        $typed  = trim((string)($_POST['new_' . $kind] ?? ''));
        if ($typed !== '') {
            foreach (preg_split('~[,\n;]+~', $typed) as $one) $picked[] = trim($one);
        }
        pack_palette_save($id, $itemId, $kind, $picked);
    }
    $_SESSION['flash'] = 'Saved for this line.';
    redirect('pack_palette.php?id=' . $id . '&item=' . $itemId);
}

/* --------------------------------------------------------------- display */
$pick = (int)($_GET['item'] ?? 0);
if (!$pick && $items) $pick = (int)$items[0]['id'];

page_header('Order sizes & colours — ' . (string)$shipment['invoice_no']);
flash();
?>
<style>
.pcard{padding:20px;border-radius:16px;background:#fff;border:1px solid #e3e9f2;margin-bottom:16px}
.pcard h2{font-size:15px;margin:0 0 4px}
.pcard .lead{font-size:13px;color:#5a6b82;margin:0 0 16px}
.plines{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px}
.pline{padding:9px 14px;border-radius:11px;border:1px solid #cbd5e3;background:#f6f8fc;
  color:#152033;text-decoration:none;font-size:13px;font-weight:600}
.pline.on{background:#0b2a4a;color:#fff;border-color:#0b2a4a}
.pgrid{display:grid;grid-template-columns:1fr;gap:22px}
@media (min-width:820px){.pgrid{grid-template-columns:1fr 1fr}}
.flab{display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;
  color:#8a97ab;font-weight:700;margin:0 0 4px}
.hint{font-size:12.5px;color:#5a6b82;margin:0 0 10px}
.chips{display:flex;flex-wrap:wrap;gap:8px}
.chip{position:relative}
.chip input{position:absolute;opacity:0;pointer-events:none}
.chip span{display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border-radius:18px;
  border:1px solid #cbd5e3;background:#fff;font-size:13.5px;font-weight:700;color:#5a6b82;cursor:pointer}
.chip input:checked + span{background:#0ea8c9;border-color:#0ea8c9;color:#fff}
.chip input:focus-visible + span{outline:2px solid #6d5bd0;outline-offset:2px}
.chip .src{font-size:10.5px;font-weight:800;opacity:.72}
.sw{width:14px;height:14px;border-radius:50%;border:1px solid rgba(0,0,0,.2)}
.newrow{margin-top:14px}
.newrow input{width:100%;padding:10px 12px;border:1px solid #cbd5e3;border-radius:10px}
.scope{background:#f6f8fc;border:1px solid #e3e9f2;border-radius:12px;padding:14px 16px;margin-top:20px}
.scope b{font-size:15px}
.scope p{margin:4px 0 0;font-size:13px;color:#5a6b82}
.ihint{font-style:italic;font-weight:600;font-size:.82em;opacity:.75}
.pline .pcount{font-size:11.5px;font-weight:800;margin-left:6px;opacity:.75}
.invsays{font-size:13px;margin:0 0 8px;padding:8px 11px;border-radius:9px;background:#fff8e6;
  border:1px solid #f1d58a;color:#6b5310}
.warnbox{background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.3);border-radius:12px;
  padding:13px 16px;font-size:13px;color:#8a5a06;margin-bottom:16px}
</style>

<h1>Order sizes &amp; colours — <?= e((string)$shipment['invoice_no']) ?></h1>
<p class="lead"><?= e((string)$shipment['buyer_name']) ?> &middot;
  typed once from the customer's order, then the packing phones offer exactly this.</p>

<?php if (!$canEdit): ?>
  <div class="warnbox">This packing list is completed or the shipment is locked, so this is read-only.</div>
<?php endif; ?>

<?php if (!$items): ?>
  <div class="pcard"><p class="lead" style="margin:0">This invoice has no lines yet.
    Add them on the shipment first.</p></div>
<?php else: ?>

  <div class="plines">
    <?php foreach ($items as $it):
      $pal = pack_palette($id, (int)$it['id']);
      $n = count($pal['size']) + count($pal['colour']); ?>
      <?php /* Line number and the line's own wording, small and italic.
               An invoice with four "Bath Towel" lines showed four identical
               chips; now each says #1 · Royal blue, #3 · White and so on. */ ?>
      <a class="pline<?= (int)$it['id'] === $pick ? ' on' : '' ?>"
         href="pack_palette.php?id=<?= $id ?>&item=<?= (int)$it['id'] ?>">
        <?= pack_item_html($it) ?>
        <span class="pcount"><?= $n ? count($pal['size']) . '/' . count($pal['colour']) : 'not set' ?></span></a>
    <?php endforeach; ?>
  </div>

  <?php
  $item = null;
  foreach ($items as $it) if ((int)$it['id'] === $pick) $item = $it;
  if (!$item) $item = $items[0];
  $pick = (int)$item['id'];

  $choices = pack_palette_choices((string)$item['product_name'], $id, $pick);
  $pal     = pack_palette($id, $pick);
  $master  = pack_master_sizes((string)$item['product_name']);
  ?>

  <form method="post" class="pcard">
    <?= csrf_field() ?>
    <input type="hidden" name="shipment_id" value="<?= $id ?>">
    <input type="hidden" name="invoice_item_id" value="<?= $pick ?>">

    <?php $il = pack_item_label($item); ?>
    <h2><?= e($il['name']) ?> <i class="ihint">invoice line <?= e($il['no']) ?></i></h2>
    <p class="lead"><?= e((string)($item['des_col'] ?? '')) ?>
      <?= ($item['optional_value'] ?? '') !== '' ? ' &middot; ' . e((string)$item['optional_value']) : '' ?></p>

    <div class="pgrid">
      <div>
        <span class="flab">Sizes this order uses</span>
        <p class="hint"><?= $choices['size']
          ? count($choices['size']) . ' on record for this product — tick what the customer ordered'
          : 'Nothing on record for this product yet. Type what the customer asked for below.' ?></p>
        <div class="chips">
          <?php foreach ($choices['size'] as $o): ?>
            <label class="chip">
              <input type="checkbox" name="pick_size[]" value="<?= e($o) ?>"
                     <?= in_array($o, $pal['size'], true) ? 'checked' : '' ?>
                     <?= $canEdit ? '' : 'disabled' ?>>
              <span><?= e($o) ?><i class="src"><?= in_array($o, $master, true) ? 'costed' : 'packed before' ?></i></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php if ($canEdit): ?>
          <div class="newrow">
            <span class="flab">A size the customer asked for that is not listed</span>
            <input name="new_size" placeholder="one, or several separated by commas">
          </div>
        <?php endif; ?>
      </div>

      <div>
        <span class="flab">Colours this order uses</span>
        <?php /* WHAT THE INVOICE LINE ITSELF SAYS, right where the colour
                 is chosen. Three "Thermal Blanket" lines differ only by
                 White, Box White and Leno Green; the description is the
                 hint, so it is shown here and any chip it names is marked. */
              $des = trim((string)($item['des_col'] ?? '')); ?>
        <?php if ($des !== ''): ?>
          <p class="invsays">The invoice line says: <b><?= e($des) ?></b></p>
        <?php endif; ?>
        <p class="hint"><?= $choices['colour']
          ? count($choices['colour']) . ' used before for this product — tick what the customer ordered'
          : 'No colour has ever been recorded for this product. Type what the order says below.' ?></p>
        <div class="chips">
          <?php foreach ($choices['colour'] as $o): ?>
            <label class="chip">
              <input type="checkbox" name="pick_colour[]" value="<?= e($o) ?>"
                     <?= in_array($o, $pal['colour'], true) ? 'checked' : '' ?>
                     <?= $canEdit ? '' : 'disabled' ?>>
              <span><i class="sw" style="background:<?= e(pack_colour_swatch($o)) ?>"></i><?= e($o) ?><?php
                if ($des !== '' && stripos($des, $o) !== false): ?><i class="src">on invoice</i><?php endif; ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php if ($canEdit): ?>
          <div class="newrow">
            <span class="flab">A colour the customer asked for that is not listed</span>
            <?php $anyMatch = false;
                  foreach ($choices['colour'] as $o) if ($des !== '' && stripos($des, $o) !== false) $anyMatch = true; ?>
            <input name="new_colour" placeholder="<?= $des !== '' && !$anyMatch
                ? e('e.g. ' . $des . ' — as the invoice line says') : 'one, or several separated by commas' ?>">
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="scope">
      <?php $ns = count($pal['size']); $nc = count($pal['colour']); ?>
      <b><?= $ns || $nc
        ? $ns . ' size' . ($ns === 1 ? '' : 's') . ' and ' . $nc . ' colour' . ($nc === 1 ? '' : 's')
        : 'Nothing set for this line yet' ?></b>
      <p><?= $ns || $nc
        ? 'That is all the packing phone will show. Not every combination has to be packed —
           it is the range the packer may pick from.'
        : 'Until this is set, the phone has nothing to offer and the packer has to type. Set it here first.' ?></p>
    </div>

    <?php if ($canEdit): ?>
      <p style="margin:16px 0 0"><button class="btn" type="submit">Save this line</button>
        <a class="btn secondary" href="packing_list.php?id=<?= $id ?>" style="margin-left:8px">Back to packing</a></p>
    <?php endif; ?>
  </form>

<?php endif; ?>
<?php page_footer(); ?>
