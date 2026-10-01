<?php
/*
  Final (Actual) Costing — one record per shipment invoice line, separate
  from the reusable Product Master "estimate" costing. Captures what a
  shipment actually cost once it's complete, so the Costing Report can
  show real figures instead of the day-one estimate. Lockable, matching
  the same admin-lock / reopen-with-reason pattern used for shipments.
*/

require_once __DIR__ . '/ai_check_core.php'; // cvt() — fc_save_lines() converts native→working currency

function fc_ensure_schema(): void {
    try { db()->exec("CREATE TABLE IF NOT EXISTS final_costings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shipment_item_id INT NOT NULL,
        shipment_id INT NOT NULL,
        currency VARCHAR(8) NOT NULL DEFAULT 'PKR',
        status ENUM('draft','locked') NOT NULL DEFAULT 'draft',
        total_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
        locked_by INT NULL, locked_at DATETIME NULL,
        reopened_by INT NULL, reopened_at DATETIME NULL, reopen_reason TEXT NULL,
        created_by INT NULL, updated_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
        UNIQUE KEY uniq_item (shipment_item_id),
        INDEX(shipment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    try { db()->exec("CREATE TABLE IF NOT EXISTS final_costing_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        final_costing_id INT NOT NULL,
        line_group VARCHAR(40) NOT NULL,
        item_name VARCHAR(160) NULL,
        description VARCHAR(500) NULL,
        quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
        unit VARCHAR(40) NULL,
        weight_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        rate DECIMAL(16,4) NOT NULL DEFAULT 0,
        amount DECIMAL(16,2) NOT NULL DEFAULT 0,
        shared TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        rate_native DECIMAL(16,4) NOT NULL DEFAULT 0,
        native_currency VARCHAR(8) NULL,
        origin_shared TINYINT(1) NOT NULL DEFAULT 0,
        INDEX(final_costing_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    /* Additive migration for installs where the table already existed before
       rate_native/native_currency/origin_shared were introduced — safe to re-run. */
    try { db()->exec("ALTER TABLE final_costing_lines ADD COLUMN rate_native DECIMAL(16,4) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE final_costing_lines ADD COLUMN native_currency VARCHAR(8) NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE final_costing_lines ADD COLUMN origin_shared TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    // Which Product Costing version the current draft's lines were actually
    // pulled from — lets the "choose variation" picker's "currently used" tag
    // track the real draft instead of just the AI's independent size-guess
    // (pick_costing() in ai_check_core.php), which stays the same no matter
    // what an admin manually switches to. NULL until first seeded/synced.
    try { db()->exec("ALTER TABLE final_costings ADD COLUMN source_costing_version_id INT NULL"); } catch (Throwable $e) {}
    // Final Costing's price-history check searches shipment_items by product_name
    // with no index — a full table scan on every line, every page load, that
    // gets slower as shipment history grows. 191-char prefix keeps the index
    // key size under InnoDB's limit for a utf8mb4 column (191*4 = 764 bytes).
    try { db()->exec("ALTER TABLE shipment_items ADD INDEX idx_product_name (product_name(191))"); } catch (Throwable $e) {}
}

/* ---------------------------------------------------------------------
   The shipment estimate, and who is allowed to be lazy about it.

   ai_check_compute() is the expensive thing on this page: it re-reads every
   product, every costing version and every material for the WHOLE shipment,
   and makes live AI calls for any unconfirmed product match. Rendering one
   line's card needs its output, so every button on the page touches it.

   The rule, stated once here instead of copied into five handlers:

     Save Draft   reuses the cache. Saving your own quantities and rates
                  cannot change which PRODUCT a line is, and the product
                  match is the only thing in here that a save could affect.
     Re-sync      must be fresh — the fresh materials it pulls ARE this
                  computation's output. It cannot be lazy by definition.
     Link Product )  both genuinely change what the line is matched to,
     Switch Var.  )  so both recompute and refill.

   Deliberately NOT in the cache key: which lines are locked. Passing more
   locked ids only makes ai_check_compute() skip more work — the result is
   a superset, correct to render either way. Keying on it would force a
   recompute the instant you lock a line, which is the one action that is
   currently instant. Locking should stay instant. */
function fc_locked_item_ids(?array $finals = null, int $shipmentId = 0): array
{
    if ($finals === null) $finals = fc_get_for_shipment($shipmentId);
    $out = [];
    foreach ($finals as $itemId => $fc) if (($fc['status'] ?? '') === 'locked') $out[] = (int)$itemId;
    return $out;
}

function fc_estimate_key(int $shipmentId): string
{
    return 'fc_estimate:' . $shipmentId . ':v' . cache_version('fc_estimate_' . $shipmentId);
}

/* Cached read — for handlers that cannot have invalidated it. */
function fc_estimate_cached(int $shipmentId, array $lockedItemIds): ?array
{
    return cache_remember(fc_estimate_key($shipmentId), 600, function () use ($shipmentId, $lockedItemIds) {
        return ai_check_compute($shipmentId, $lockedItemIds);
    });
}

/* Recompute and refill — for handlers that changed what a line is matched
   to. Refills rather than merely invalidating, so the view that follows is
   a hit instead of paying for the same work twice. */
function fc_estimate_fresh(int $shipmentId, array $lockedItemIds): ?array
{
    $estimate = ai_check_compute($shipmentId, $lockedItemIds);
    cache_bump('fc_estimate_' . $shipmentId);
    cache_set(fc_estimate_key($shipmentId), $estimate, 600);
    return $estimate;
}

/* Store an already-computed estimate under the current key. Used by
   Re-sync, which had to compute one to do its job at all. */
function fc_estimate_store(int $shipmentId, $estimate): void
{
    cache_bump('fc_estimate_' . $shipmentId);
    cache_set(fc_estimate_key($shipmentId), $estimate, 600);
}

/* same per-line amount formula as Product Costing's own lineAmt() — shared = rate/qty, normal = qty*rate.
   Line 'quantity'/'rate'/'shared' are the RAW values exactly as entered in Product Costing (e.g. "10"
   for a carton shared by 10 pcs, not the pre-divided 0.1) — so this produces the identical per-piece
   PKR amount Product Costing itself shows, no separate "already resolved" convention to track. */
function fc_line_amount(array $l): float {
    $q = (float)$l['quantity']; $r = (float)$l['rate'];
    return !empty($l['shared']) ? ($q > 0 ? round($r / $q, 2) : 0.0) : round($q * $r, 2);
}

/* All final costings for a shipment, keyed by shipment_item_id, each with its lines. */
function fc_get_for_shipment(int $shipmentId): array {
    $out = [];
    try {
        $st = db()->prepare("SELECT * FROM final_costings WHERE shipment_id=?");
        $st->execute([$shipmentId]);
        $fcs = $st->fetchAll();
        if (!$fcs) return [];
        $ids = array_column($fcs, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $ls = db()->prepare("SELECT * FROM final_costing_lines WHERE final_costing_id IN ($in) ORDER BY sort_order,id");
        $ls->execute($ids);
        $linesByFc = [];
        foreach ($ls->fetchAll() as $l) $linesByFc[(int)$l['final_costing_id']][] = $l;
        foreach ($fcs as $fc) {
            $fc['lines'] = $linesByFc[(int)$fc['id']] ?? [];
            $out[(int)$fc['shipment_item_id']] = $fc;
        }
    } catch (Throwable $e) {}
    return $out;
}

/* Create a draft final costing for a shipment item, seeded from its estimate
   lines (from ai_check_core.php's line_costing[]['materials']), if one
   doesn't already exist. Returns the final_costing id either way. */
function fc_seed_from_estimate(int $shipmentItemId, int $shipmentId, string $currency, array $estimateMaterials, int $userId, ?int $costingVersionId = null): int {
    $st = db()->prepare("SELECT id FROM final_costings WHERE shipment_item_id=?");
    $st->execute([$shipmentItemId]);
    $existing = $st->fetchColumn();
    if ($existing) return (int)$existing;

    db()->prepare("INSERT INTO final_costings (shipment_item_id,shipment_id,currency,status,source_costing_version_id,created_by,updated_by) VALUES (?,?,?,?,?,?,?)")
        ->execute([$shipmentItemId, $shipmentId, $currency, 'draft', $costingVersionId, $userId, $userId]);
    $fcId = (int)db()->lastInsertId();

    $lines = [];
    foreach ($estimateMaterials as $m) {
        $lines[] = [
            'line_group' => $m['category'], 'item_name' => $m['material'], 'description' => '',
            // RAW values exactly as Product Costing has them — share_qty/weight_raw/rate_native are
            // the un-divided batch qty, batch weight, and native-currency rate (see ai_check_core.php).
            'quantity' => $m['share_qty'] ?? $m['consum_each'] ?? 0, 'unit' => $m['unit'], 'weight_kg' => $m['weight_raw'] ?? $m['wt_each'] ?? 0,
            'rate' => $m['rate_native'] ?? $m['rate'] ?? 0, 'shared' => !empty($m['shared']),
            'native_currency' => $m['currency_native'] ?? '',
        ];
    }
    fc_save_lines($fcId, $lines, $userId);
    return $fcId;
}

/* Replace all lines for a final costing (used by manual save AND CSV import).
   Refuses to touch a locked record.

   Per-line 'amount' is stored and shown in NATIVE currency (whatever Product
   Costing used, e.g. PKR) — exactly what's on screen matches Product Costing
   penny for penny. final_costings.total_cost stays in the shipment's own
   WORKING currency though (converted once here, not per display), because
   Dashboard and the Costing Report compare it directly against the shipment's
   own sale amount for margin % — see dashboard.php's costValSub comment. */
function fc_save_lines(int $fcId, array $lines, int $userId): array {
    $st = db()->prepare("SELECT status, currency FROM final_costings WHERE id=?");
    $st->execute([$fcId]);
    $fcRow = $st->fetch();
    if (!$fcRow) return ['ok' => false, 'message' => 'Final costing record not found.'];
    if ($fcRow['status'] === 'locked') return ['ok' => false, 'message' => 'This final costing is locked — reopen it first.'];
    $workingCur = strtoupper($fcRow['currency'] ?: 'PKR');
    $fx = fx_get_rates();

    db()->prepare("DELETE FROM final_costing_lines WHERE final_costing_id=?")->execute([$fcId]);
    $ins = db()->prepare("INSERT INTO final_costing_lines (final_costing_id,line_group,item_name,description,quantity,unit,weight_kg,rate,amount,shared,sort_order,rate_native,native_currency) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $totalNative = 0.0; $totalWorking = 0.0; $i = 0;
    foreach ($lines as $l) {
        $i++;
        $amt = fc_line_amount($l); // native-currency amount — this is what's shown on screen
        $totalNative += $amt;
        $nativeCur = strtoupper((string)($l['native_currency'] ?? '')) ?: $workingCur;
        $totalWorking += ($nativeCur === $workingCur) ? $amt : cvt($amt, $nativeCur, $workingCur, $fx);
        $ins->execute([
            $fcId, (string)($l['line_group'] ?: 'Other'), (string)($l['item_name'] ?? ''), (string)($l['description'] ?? ''),
            (float)($l['quantity'] ?? 0), (string)($l['unit'] ?? ''), (float)($l['weight_kg'] ?? 0),
            (float)($l['rate'] ?? 0), $amt, !empty($l['shared']) ? 1 : 0, $i,
            (float)($l['rate'] ?? 0), $nativeCur,
        ]);
    }
    db()->prepare("UPDATE final_costings SET total_cost=?, updated_by=?, updated_at=NOW() WHERE id=?")->execute([round($totalWorking, 2), $userId, $fcId]);
    return ['ok' => true, 'message' => 'Saved.', 'total' => round($totalNative, 2)];
}

function fc_lock(int $fcId, int $userId): array {
    $st = db()->prepare("SELECT status FROM final_costings WHERE id=?");
    $st->execute([$fcId]);
    $status = $st->fetchColumn();
    if ($status === false) return ['ok' => false, 'message' => 'Not found.'];
    if ($status === 'locked') return ['ok' => false, 'message' => 'Already locked.'];
    db()->prepare("UPDATE final_costings SET status='locked', locked_by=?, locked_at=NOW() WHERE id=?")->execute([$userId, $fcId]);
    return ['ok' => true, 'message' => 'Locked.'];
}

/* Re-pull materials from Product Costing for one shipment line and overwrite
   its (unlocked) Final Costing lines with the current numbers. Final Costing
   is a one-time snapshot by design (see file header) — this is the deliberate,
   on-demand way to refresh that snapshot after a Product Costing correction,
   without making Final Costing silently auto-sync forever. Locked records
   are left untouched (reopen first) so nothing gets overwritten by accident. */
function fc_resync_line(int $shipmentItemId, array $freshMaterials, int $userId, ?int $costingVersionId = null): array {
    $st = db()->prepare("SELECT id, status FROM final_costings WHERE shipment_item_id=?");
    $st->execute([$shipmentItemId]);
    $fc = $st->fetch();
    if (!$fc) return ['ok' => false, 'message' => 'No final costing record for this line yet.'];
    if ($fc['status'] === 'locked') return ['ok' => false, 'message' => 'Locked — reopen it first.', 'skipped' => true];

    $lines = [];
    foreach ($freshMaterials as $m) {
        $lines[] = [
            'line_group' => $m['category'], 'item_name' => $m['material'], 'description' => $m['description'] ?? '',
            'quantity' => $m['share_qty'] ?? $m['consum_each'] ?? 0, 'unit' => $m['unit'], 'weight_kg' => $m['weight_raw'] ?? $m['wt_each'] ?? 0,
            'rate' => $m['rate_native'] ?? $m['rate'] ?? 0, 'shared' => !empty($m['shared']),
            'native_currency' => $m['currency_native'] ?? '',
        ];
    }
    $result = fc_save_lines((int)$fc['id'], $lines, $userId);
    if ($result['ok']) {
        db()->prepare("UPDATE final_costings SET source_costing_version_id=? WHERE id=?")->execute([$costingVersionId, (int)$fc['id']]);
    }
    return $result;
}

function fc_reopen(int $fcId, int $userId, string $reason): array {
    if (trim($reason) === '') return ['ok' => false, 'message' => 'A reason is required to reopen a locked final costing.'];
    $st = db()->prepare("SELECT status FROM final_costings WHERE id=?");
    $st->execute([$fcId]);
    $status = $st->fetchColumn();
    if ($status === false) return ['ok' => false, 'message' => 'Not found.'];
    if ($status !== 'locked') return ['ok' => false, 'message' => 'Not currently locked.'];
    db()->prepare("UPDATE final_costings SET status='draft', reopened_by=?, reopened_at=NOW(), reopen_reason=? WHERE id=?")->execute([$userId, $reason, $fcId]);
    return ['ok' => true, 'message' => 'Reopened for correction.'];
}

/* Every Product Costing version for a product, with unit cost converted into
   the invoice's own currency — feeds the Final Costing "choose variation"
   picker. Only meaningful when a product has more than one version (that's
   the caller's job to check before showing the picker at all). */
function fc_costing_versions_for_product(int $productId, string $invoiceCur, array $fx): array {
    $st = db()->prepare("SELECT cv.*, GROUP_CONCAT(ps.size_label SEPARATOR ', ') size_labels
        FROM costing_versions cv
        LEFT JOIN costing_version_sizes cvs ON cvs.costing_version_id = cv.id
        LEFT JOIN product_sizes ps ON ps.id = cvs.product_size_id
        WHERE cv.product_id=? GROUP BY cv.id ORDER BY cv.id DESC");
    $st->execute([$productId]);
    $out = [];
    foreach ($st->fetchAll() as $cv) {
        $costCur = $cv['currency'] ?: 'PKR';
        $canConvert = isset($fx[strtoupper($costCur)]) && isset($fx[strtoupper($invoiceCur)]);
        $unitCost = $canConvert ? cvt((float)$cv['total_cost'], $costCur, $invoiceCur, $fx) : (float)$cv['total_cost'];
        $out[] = ['id' => (int)$cv['id'], 'version_name' => $cv['version_name'], 'size_labels' => $cv['size_labels'] ?: '', 'unit_cost' => round($unitCost, 4)];
    }
    return $out;
}

/* Same per-line material math as ai_check_compute()'s auto-picked-version
   loop (see includes/ai_check_core.php), but for ONE specific costing_version
   chosen manually via the "choose variation" picker instead of pick_costing()'s
   size-guess. Returns null only if the version doesn't exist. */
function fc_materials_from_version(int $costingVersionId, float $qty, string $invoiceCur, array $fx): ?array {
    $st = db()->prepare("SELECT * FROM costing_versions WHERE id=?");
    $st->execute([$costingVersionId]);
    $cv = $st->fetch();
    if (!$cv) return null;
    $costCur = $cv['currency'] ?: 'PKR';
    $ls = db()->prepare("SELECT * FROM costing_lines WHERE costing_version_id=?");
    $ls->execute([$costingVersionId]);

    $lineMats = [];
    foreach ($ls->fetchAll() as $ml) {
        $catg = $ml['line_group'] ?: 'Other'; $mn = trim((string)$ml['item_name']) ?: $catg; $unit = trim((string)$ml['unit']) ?: 'unit';
        $mq = (float)$ml['quantity']; $mrate = (float)$ml['rate']; $mwt = (float)$ml['weight_kg'];
        $isShared = !empty($ml['shared']);
        $eachQty = $isShared ? ($mq > 0 ? 1 / $mq : 0) : $mq;
        $eachCost = $isShared ? ($mq > 0 ? $mrate / $mq : 0) : ($mq * $mrate);
        $eachWt = $isShared ? ($mq > 0 ? $mwt / $mq : $mwt) : ($mq * $mwt);
        if ($eachQty <= 0 && $eachCost <= 0) continue;
        $rateConv = cvt($mrate, $costCur, $invoiceCur, $fx);
        $totalQty = $eachQty * $qty; $totalWt = $eachWt * $qty;
        $kLine = $catg . '|' . $mn . '|' . $unit . '|' . round($rateConv, 6);
        if (!isset($lineMats[$kLine])) $lineMats[$kLine] = [
            'category' => $catg, 'material' => $mn, 'unit' => $unit, 'description' => (string)($ml['description'] ?? ''),
            'rate' => $rateConv, 'rate_native' => $mrate, 'currency_native' => $costCur,
            'consum_each' => $eachQty, 'wt_each' => $eachWt, 'qty' => 0, 'wt' => 0, 'shared' => $isShared, 'share_qty' => $mq, 'weight_raw' => $mwt,
        ];
        $lineMats[$kLine]['qty'] += $totalQty; $lineMats[$kLine]['wt'] += $totalWt;
    }
    return array_values($lineMats);
}

/* The Item Master, for the picker on each costing line.

   Same query and same shape as pc_inv_items() in product_costing.php. It is
   repeated rather than shared because that function lives inside a 1,115-line
   page, not an include — pulling the page in to reach six lines of SQL would
   drag its whole render with it. If a third screen ever needs this list, move
   it to includes/ and have both call that.

   Note this picker does NOT store a material id on the line. final_costing_lines
   has no such column, and Final Costing records what you ACTUALLY bought —
   the name stays free text on purpose. The picker is here to save typing and
   to fill in the unit and standard rate, not to constrain the answer. */
function fc_inv_items(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach (db()->query("SELECT id,code,name,item_group,uom,std_rate FROM inv_materials WHERE is_active=1 ORDER BY item_group,name")->fetchAll() as $r) {
            $cache[] = [
                'id' => (int)$r['id'], 'code' => (string)$r['code'], 'name' => (string)$r['name'],
                'group' => (string)$r['item_group'], 'uom' => (string)$r['uom'], 'rate' => (float)$r['std_rate'],
            ];
        }
    } catch (Throwable $e) { $cache = []; }
    return $cache;
}

/* Qty/Weight/Rate shown and edited to max 2 decimals, trailing zeros trimmed
   (e.g. 1.6 not 1.600, 20 not 20.0000) — DB keeps full precision either way. */
function fc_disp($v): string {
    $s = number_format((float)$v, 2, '.', '');
    return rtrim(rtrim($s, '0'), '.');
}

/* One-tap "Likely matches" chips (from $lc['suggestions'] — already computed
   by pm_match() in ai_check_core.php, no extra query/AI call here) plus a
   live-filtered search box as fallback — shared by both the "Not this" fix
   box and the "no product found" box, which only differ by field-id prefix
   ('fix' vs 'link') and placeholder text. Replaces the old plain
   <input list=datalist> that only worked on an exact character match. */
function fc_render_pick_row(int $itemId, array $suggestions, string $prefix, string $placeholder): string {
    ob_start(); ?>
    <?php if ($suggestions): ?>
    <div style="width:100%;margin-bottom:2px">
      <div style="font-size:9.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:700;margin-bottom:6px">Likely matches</div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px">
        <?php foreach ($suggestions as $sug): ?>
        <div class="fc-chip" data-pid="<?= (int)$sug['id'] ?>" data-name="<?= e($sug['name']) ?>" onmousedown="fcComboPickEl('<?= $prefix ?>',<?= $itemId ?>,this)">
          <?= e($sug['name']) ?> <span class="fc-chip-pct <?= $sug['pct']>=70?'good':'mid' ?>"><?= (int)$sug['pct'] ?>%</span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="fc-combo">
      <input type="text" id="<?= $prefix ?>Input<?= $itemId ?>" class="fc-in" placeholder="<?= e($placeholder) ?>" autocomplete="off"
        oninput="fcComboFilter('<?= $prefix ?>',<?= $itemId ?>)" onfocus="fcComboFilter('<?= $prefix ?>',<?= $itemId ?>)" onblur="setTimeout(function(){fcComboHide('<?= $prefix ?>',<?= $itemId ?>)},150)">
      <div class="fc-combo-list" id="<?= $prefix ?>List<?= $itemId ?>"></div>
    </div>
    <?php
    return ob_get_clean();
}

/* Renders one line's whole card — product-match panel, line table (locked
   read-only view or editable draft form), lock/reopen form. Single source
   of truth: final_costing.php's page loop and every AJAX action handler
   (save/link/lock/resync) call this same function so an in-place update
   after an action is byte-for-byte what a full page reload would have
   shown, without the reload. Every form inside carries onsubmit="return
   fcAjaxSubmit(event,this)" (defined in final_costing.php) so it still
   works correctly after being swapped in via outerHTML — inline handler
   attributes re-bind automatically, addEventListener ones would not. */
function fc_render_line_card(array $lc, array $itemRow, ?array $fc, string $cur, int $shipmentId): string {
    $itemId = (int)$itemRow['id'];
    $locked = $fc && $fc['status'] === 'locked';
    $nativeCur = ($fc && $fc['lines']) ? (strtoupper((string)($fc['lines'][0]['native_currency'] ?? '')) ?: $cur) : $cur;
    $nativeTotal = $fc ? array_sum(array_column($fc['lines'], 'amount')) : 0;
    $fx = fx_get_rates();
    ob_start();
    ?>
<div class="zcard" id="fcCard<?= $itemId ?>">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
    <div>
      <h2>Line <?= (int)$lc['line'] ?> · <?= e($lc['product_name']) ?></h2>
      <p style="color:#8a97ab;font-size:12px;margin:4px 0 0">Estimated cost/unit: <?= e($cur) ?> <?= $lc['unit_cost']!==null ? number_format($lc['unit_cost'],4) : '—' ?> · Qty <?= e(rtrim(rtrim(number_format($lc['qty'],3),'0'),'.')) ?></p>
      <div style="display:flex;gap:22px;flex-wrap:wrap;margin-top:8px;padding:9px 12px;background:#f6f8fc;border-radius:9px;font-size:12px">
        <div><span style="display:block;font-size:9.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:700;margin-bottom:2px">Sale Price / pc</span><b style="color:#152033"><?= e($cur) ?> <?= number_format((float)$lc['rate'],4) ?></b></div>
        <div><span style="display:block;font-size:9.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:700;margin-bottom:2px">Sale Amount (line)</span><b style="color:#152033"><?= e($cur) ?> <?= number_format((float)$lc['sales_amount'],2) ?></b></div>
        <?php if (trim((string)$lc['description']) !== ''): ?><div><span style="display:block;font-size:9.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:700;margin-bottom:2px">Description / Col</span><b style="color:#152033"><?= e($lc['description']) ?></b></div><?php endif; ?>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <?php if (!$locked): ?>
      <form method="post" action="final_costing_resync.php" data-item-id="<?= $itemId ?>" data-confirm="Re-sync this line with current Product Costing? This overwrites the lines below." onsubmit="return fcAjaxSubmit(event,this)">
        <?= csrf_field() ?>
        <input type="hidden" name="shipment_id" value="<?= (int)$shipmentId ?>">
        <input type="hidden" name="item_id" value="<?= $itemId ?>">
        <button class="zbtn sec" style="padding:7px 12px;font-size:11.5px">⟳ Re-sync</button>
      </form>
      <?php endif; ?>
      <span class="badge <?= $locked?'locked':'draft' ?>"><?= $locked?'Locked · Final':'Draft' ?></span>
    </div>
  </div>

  <?php if ($lc['master_found']): ?>
    <?php if ((int)$lc['match_pct'] < 100): ?>
    <div style="padding:10px 14px;border-radius:10px;background:rgba(217,119,6,.07);border:1px solid rgba(217,119,6,.22);margin-bottom:10px;font-size:12.5px;color:#8a5a06;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <span style="flex:1;min-width:220px">Matched to <b><?= e($lc['matched_product']) ?></b> (<?= (int)$lc['match_pct'] ?>% confidence) — is this the right product?</span>
      <?php if (!$locked): ?>
      <form method="post" action="final_costing_link_product.php" data-item-id="<?= $itemId ?>" onsubmit="return fcAjaxSubmit(event,this)" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="shipment_id" value="<?= (int)$shipmentId ?>">
        <input type="hidden" name="item_id" value="<?= $itemId ?>">
        <input type="hidden" name="product_id" value="<?= (int)($lc['matched_product_id'] ?? 0) ?>">
        <button class="zbtn sec sm" style="padding:5px 11px;font-size:11.5px">✓ Confirm — remember for this buyer</button>
      </form>
      <button type="button" class="zbtn sec sm" style="padding:5px 11px;font-size:11.5px" onclick="document.getElementById('pmFix<?= $itemId ?>').style.display='block'">✗ Not this</button>
      <?php endif; ?>
    </div>
    <?php if (!$locked): ?>
    <div id="pmFix<?= $itemId ?>" style="display:none;margin:0 0 14px">
      <form method="post" action="final_costing_link_product.php" data-item-id="<?= $itemId ?>" onsubmit="return fcAjaxSubmit(event,this)">
        <?= csrf_field() ?>
        <input type="hidden" name="shipment_id" value="<?= (int)$shipmentId ?>">
        <input type="hidden" name="item_id" value="<?= $itemId ?>">
        <input type="hidden" name="product_id" id="fixPid<?= $itemId ?>" value="">
        <?= fc_render_pick_row($itemId, $lc['suggestions'] ?? [], 'fix', 'Type the correct product name…') ?>
        <button class="zbtn sm" type="submit" id="fixBtn<?= $itemId ?>" disabled style="margin-top:8px">Link &amp; Pull Costing</button>
      </form>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div style="font-size:11.5px;color:#16a34a;margin-bottom:12px;display:flex;align-items:center;gap:5px">✓ Matched to <b><?= e($lc['matched_product']) ?></b></div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!$locked && $lc['master_found'] && !empty($lc['matched_product_id'])):
    $versions = fc_costing_versions_for_product((int)$lc['matched_product_id'], $cur, $fx);
    if (count($versions) > 1): ?>
  <div style="margin:0 0 14px;padding:14px 16px;border-radius:10px;background:rgba(109,91,208,.06);border:1px solid rgba(109,91,208,.24)">
    <div style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#6d5bd0;font-weight:800;margin-bottom:8px">Costing Variation for "<?= e($lc['matched_product']) ?>"</div>
    <form method="post" action="final_costing_switch_variation.php" data-item-id="<?= $itemId ?>" onsubmit="return fcAjaxSubmit(event,this)">
      <?= csrf_field() ?>
      <input type="hidden" name="shipment_id" value="<?= (int)$shipmentId ?>">
      <input type="hidden" name="item_id" value="<?= $itemId ?>">
      <?php
        // Prefer what the draft was actually built from over the AI's
        // independent size-guess ($lc['costing_version_id']) — pick_costing()
        // in ai_check_core.php recomputes that fresh every time regardless of
        // any manual override, so once a draft exists it's the fc row (which
        // fc_resync_line() updates on every seed/resync/link/switch) that
        // reflects what's really in the table below.
        $activeVersionId = ($fc && $fc['source_costing_version_id']) ? (int)$fc['source_costing_version_id'] : (int)($lc['costing_version_id'] ?? 0);
      ?>
      <?php
        /* The question you are actually asking when you look at this panel is
           "what does switching cost me?" — so the answer is on the row rather
           than left as two numbers to subtract in your head.

           Every figure is stated against the version currently in use: per
           piece, and multiplied out by this line's real quantity, which is
           the number that reaches the margin. */
        $baseCost = null;
        foreach ($versions as $v) if ($activeVersionId && $activeVersionId === $v['id']) $baseCost = (float)$v['unit_cost'];
        $lineQty = (float)($lc['qty'] ?? 0);
      ?>
      <div style="display:flex;flex-direction:column;gap:7px">
        <?php foreach ($versions as $v):
          $isCurrent = $activeVersionId && $activeVersionId === $v['id'];
          $d = ($baseCost === null) ? null : ((float)$v['unit_cost'] - $baseCost);
          $dLine = ($d === null) ? null : $d * $lineQty;
          $col = ($d === null || abs($d) < 0.00005) ? '#8a97ab' : ($d > 0 ? '#b8283f' : '#127a3f');
        ?>
        <label style="display:flex;align-items:center;gap:10px;padding:8px 11px;border-radius:9px;border:1px solid <?= $isCurrent?'#0ea8c9':'#e3e9f2' ?>;background:<?= $isCurrent?'rgba(14,168,201,.06)':'#ffffff' ?>;cursor:pointer">
          <input type="radio" name="costing_version_id" value="<?= $v['id'] ?>" <?= $isCurrent?'checked':'' ?> style="accent-color:#0ea8c9">
          <span style="flex:1;font-size:12.5px;font-weight:700;min-width:0">
            <?= e($v['version_name']) ?><?php if($isCurrent): ?><span style="font-size:9px;font-weight:800;color:#0ea8c9;background:rgba(14,168,201,.12);padding:2px 6px;border-radius:5px;margin-left:8px">in use now</span><?php endif; ?>
            <small style="display:block;font-weight:500;color:#8a97ab;font-size:10.5px"><?= e($v['size_labels'] ?: 'no size set') ?></small>
          </span>
          <span style="text-align:right;line-height:1.35;white-space:nowrap">
            <b style="font-size:12.5px;color:#152033;font-family:'Space Grotesk',monospace"><?= e($cur) ?> <?= number_format($v['unit_cost'],4) ?></b>
            <small style="display:block;font-size:10px;font-weight:800;color:<?= $col ?>">
              <?php if ($isCurrent): ?>in use now
              <?php elseif ($d === null): ?>&nbsp;
              <?php elseif (abs($d) < 0.00005): ?>same cost
              <?php else: ?>
                <?= $d > 0 ? '+' : '&minus;' ?><?= number_format(abs($d), 4) ?>/pc<?php
                if ($lineQty > 0): ?> &middot; <?= $d > 0 ? '+' : '&minus;' ?><?= e($cur) ?> <?= number_format(abs($dLine), 2) ?> on this line<?php endif; ?>
              <?php endif; ?>
            </small>
          </span>
        </label>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:10px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:11px;color:#8a97ab"><?php if ($lineQty > 0): ?>Line quantity <?= e(rtrim(rtrim(number_format($lineQty,3),'0'),'.')) ?> pcs — the &plusmn; figures are already multiplied out.<?php endif; ?></span>
        <button class="zbtn sec sm" style="padding:6px 12px;font-size:11.5px">Use This Variation</button>
      </div>
    </form>
  </div>
  <?php endif; endif; ?>

  <?php if ($locked): ?>
    <div class="fc-table" style="overflow-x:auto"><table class="fc-table">
      <thead><tr><th>Group</th><th>Item</th><th>Description</th><th>Qty</th><th>Unit</th><th>Alloc. Wt (kg)</th><th>Rate</th><th class="num">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($fc['lines'] as $l): ?>
        <tr><td><?= e($l['line_group']) ?></td><td><?= e($l['item_name']) ?></td><td><?= e($l['description']) ?></td>
          <td><?= e(fc_disp($l['quantity'])) ?></td><td><?= e($l['unit']) ?></td>
          <td><?= e(fc_disp($l['weight_kg'])) ?></td>
          <td><?= number_format((float)$l['rate'],2) ?></td>
          <td class="num"><?= number_format((float)$l['amount'],2) ?><?= $l['shared'] ? ' <span style="color:#6d5bd0;font-weight:400">÷</span>' : '' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$fc['lines']): ?><tr><td colspan="8" style="text-align:center;color:#8a97ab">No lines.</td></tr><?php endif; ?>
      <tr style="font-weight:700;border-top:2px solid #cbd5e3"><td colspan="7">Total Final Cost</td><td class="num"><?= e($nativeCur) ?> <?= number_format($nativeTotal,2) ?></td></tr>
      </tbody>
    </table></div>
    <p style="font-size:11.5px;color:#8a97ab;margin:10px 0 0">Locked <?= $fc['locked_at'] ? e(date('d M Y, H:i', strtotime($fc['locked_at']))) : '' ?>.</p>
    <form method="post" action="final_costing_lock.php" data-item-id="<?= $itemId ?>" data-confirm="Reopen this final costing for correction?" onsubmit="return fcAjaxSubmit(event,this)" style="margin-top:12px;padding:14px;border-radius:12px;background:#f6f8fc;border:1px solid #e3e9f2">
      <?= csrf_field() ?>
      <input type="hidden" name="fc_action" value="reopen">
      <input type="hidden" name="final_costing_id" value="<?= (int)$fc['id'] ?>">
      <input type="hidden" name="shipment_id" value="<?= (int)$shipmentId ?>">
      <label style="font-size:12px;color:#5a6b82;display:block;margin-bottom:8px">Reopen Reason *<textarea class="fc-in" name="reopen_reason" style="min-height:50px;margin-top:6px" required placeholder="Why does this locked final costing need correction?"></textarea></label>
      <button class="zbtn sec">Reopen for Correction</button>
    </form>
  <?php else: ?>
    <?php if (!$fc || empty($fc['lines'])): ?>
    <div style="padding:14px 16px;border-radius:12px;background:rgba(217,119,6,.07);border:1px solid rgba(217,119,6,.24);margin-bottom:14px">
      <div style="font-size:13px;font-weight:700;color:#a25c04;margin-bottom:6px">No Product Costing found for "<?= e($lc['product_name']) ?>"</div>
      <p style="font-size:12px;color:#8a5a06;margin:0 0 10px">Pick the correct product below — it pulls that costing in right away, and remembers the match so future lines named exactly this way link automatically (no need to do this again).</p>
      <form method="post" action="final_costing_link_product.php" data-item-id="<?= $itemId ?>" onsubmit="return fcAjaxSubmit(event,this)">
        <?= csrf_field() ?>
        <input type="hidden" name="shipment_id" value="<?= (int)$shipmentId ?>">
        <input type="hidden" name="item_id" value="<?= $itemId ?>">
        <input type="hidden" name="product_id" id="linkPid<?= $itemId ?>" value="">
        <?= fc_render_pick_row($itemId, $lc['suggestions'] ?? [], 'link', 'Type a product name…') ?>
        <button class="zbtn" type="submit" id="linkBtn<?= $itemId ?>" disabled style="margin-top:8px">Link &amp; Pull Costing</button>
      </form>
    </div>
    <?php endif; ?>
    <form method="post" action="final_costing_save.php" data-item-id="<?= $itemId ?>" onsubmit="return fcAjaxSubmit(event,this)">
      <?= csrf_field() ?>
      <input type="hidden" name="final_costing_id" value="<?= (int)$fc['id'] ?>">
      <input type="hidden" name="shipment_id" value="<?= (int)$shipmentId ?>">
      <div style="overflow-x:auto"><table class="fc-table" id="fcTable<?= $itemId ?>">
        <thead><tr><th>Group</th><th>Item</th><th>Description</th><th>Qty</th><th>Unit</th><th>Alloc. Wt (kg)</th><th>Rate</th><th class="num">Amount</th><th></th></tr></thead>
        <?php /* data-fcgrid marks this tbody for the spreadsheet keyboard, and
                 data-c names each cell in Tab order. Both are read by the
                 script block in final_costing.php after every AJAX swap. */ ?>
        <tbody data-fcgrid="<?= $itemId ?>">
        <?php foreach ($fc['lines'] as $l): ?>
          <tr>
            <td><select class="fc-in" data-c="grp" name="line_group[]"><?php foreach(['Fabric','Accessories','Packing','Workmanship','Other'] as $g): ?><option <?= $l['line_group']===$g?'selected':'' ?>><?= $g ?></option><?php endforeach; ?></select></td>
            <td><input class="fc-in lovf" data-lov="fcitem" data-c="item" name="item_name[]" autocomplete="off" value="<?= e($l['item_name']) ?>"></td>
            <td><input class="fc-in" data-c="desc" name="description[]" value="<?= e($l['description']) ?>"></td>
            <td><div class="qtywrap">
              <input class="fc-in mini" data-c="qty" type="number" step="0.001" name="quantity[]" value="<?= e(fc_disp($l['quantity'])) ?>" oninput="fcCalc(this)">
              <button type="button" class="shbtn <?= $l['shared']?'on':'' ?>" tabindex="-1" title="<?= $l['shared']?'Shared: amount = rate ÷ qty':'Normal: amount = qty × rate. Click for shared (÷).' ?>" onclick="fcToggleShared(this)">÷</button>
              <input type="hidden" name="shared[]" value="<?= $l['shared']?'1':'0' ?>">
            </div></td>
            <td><input class="fc-in mini" data-c="unit" name="unit[]" value="<?= e($l['unit']) ?>"></td>
            <td><input class="fc-in mini" data-c="wt" type="number" step="0.001" name="weight_kg[]" value="<?= e(fc_disp($l['weight_kg'])) ?>"></td>
            <td><input class="fc-in mini" data-c="rate" type="number" step="0.01" name="rate[]" value="<?= e(fc_disp($l['rate'])) ?>" oninput="fcCalc(this)">
              <input type="hidden" name="native_currency[]" value="<?= e($l['native_currency'] ?? $nativeCur) ?>">
            </td>
            <td class="num fc-amt"><?= number_format((float)$l['amount'],2) ?><?= $l['shared'] ? ' <span style="color:#6d5bd0;font-weight:400">÷</span>' : '' ?></td>
            <td><button type="button" class="zbtn red sm" tabindex="-1" onclick="this.closest('tr').remove();fcRecalcTotal(this)">✕</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div style="margin-top:9px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <button type="button" class="zbtn sec" onclick="fcAddRow(<?= $itemId ?>,'<?= e($nativeCur) ?>')">+ Add Line</button>
        <span style="font-size:11px;color:#8a97ab;font-family:monospace">Tab across &middot; Enter down &middot; Ctrl+D fill &middot; Alt+N new line &middot; Ctrl+S save</span>
        <span style="margin-left:auto;font-size:12.5px;color:#5a6b82">Draft total
          <b id="fcTotal<?= $itemId ?>" style="color:#0ea8c9;font-family:'Space Grotesk',monospace"><?= e($nativeCur) ?> <?= number_format($nativeTotal,2) ?></b></span>
      </div>
      <div id="fcPaste<?= $itemId ?>" style="display:none;font-size:12px;margin:9px 0 0;padding:8px 11px;border-radius:9px;background:rgba(217,119,6,.12);border:1px solid rgba(217,119,6,.3);color:#9a5710"></div>
      <div style="margin-top:12px;display:flex;gap:10px">
        <button class="zbtn">Save Draft</button>
      </div>
    </form>
    <?php if ($fc && $fc['lines']): ?>
    <form method="post" action="final_costing_lock.php" data-item-id="<?= $itemId ?>" data-confirm="Lock this final costing? It will become read-only until an admin reopens it." onsubmit="return fcAjaxSubmit(event,this)" style="margin-top:10px">
      <?= csrf_field() ?>
      <input type="hidden" name="fc_action" value="lock">
      <input type="hidden" name="final_costing_id" value="<?= (int)$fc['id'] ?>">
      <input type="hidden" name="shipment_id" value="<?= (int)$shipmentId ?>">
      <button class="zbtn green">Lock Final Costing</button>
    </form>
    <?php endif; ?>
  <?php endif; ?>
</div>
    <?php
    return ob_get_clean();
}

/* Given a shipment's already-computed $estimate (from ai_check_compute()),
   find one item's line_costing row + its item row — shared by every AJAX
   handler that needs to re-render a single card after acting on it. */
function fc_find_line(array $estimate, int $itemId): array {
    $itemRow = null;
    foreach ($estimate['items'] ?? [] as $it) { if ((int)$it['id'] === $itemId) { $itemRow = $it; break; } }
    $lc = null;
    if ($itemRow) {
        foreach ($estimate['line_costing'] ?? [] as $row) { if ((int)$row['line'] === (int)$itemRow['line_no']) { $lc = $row; break; } }
    }
    return [$lc, $itemRow];
}
