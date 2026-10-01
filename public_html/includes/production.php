<?php
/*
  Production Module — shared schema + small helpers.
  Workers, per-product Operations (rates), and the daily production
  transactions ledger. No embeddings/AI search here on purpose (per
  spec) — plain indexed lookups only.
*/

/* ============================================================
   THE DISPATCH STAGE IS SWITCHED OFF.
   ============================================================

   Asked for plainly: "these packing stage still problem for this project so
   remove it — from everywhere."

   It is OFF here, in ONE place, and every screen reads this. It is not deleted
   from fifteen files, and that is deliberate:

     * THE DATABASE IS NOT TOUCHED. `stage` stays ENUM('Cutting','Stitching',
       'Dispatch') in both tables. Any row already carrying Dispatch keeps it,
       keeps its rate, keeps its wage. Nothing is converted, nothing is dropped.
       Removing the value from the ENUM would have silently rewritten those rows
       to Cutting — a wage moved without anyone asking.

     * "FOR THE TIME" MEANS IT COMES BACK. Flip this one line to true and every
       dropdown, every rule, every dashboard tile returns exactly as it was.
       Fifteen separate deletions could not be undone that way.

   With it OFF: Dispatch is offered nowhere, required nowhere, bookable nowhere,
   and shown on no dashboard. Production runs Cutting -> Stitching and stops. */
const PRODUCTION_DISPATCH_STAGE = false;

function production_dispatch_on(): bool { return PRODUCTION_DISPATCH_STAGE; }

/* THE ONE STAGE LIST. Every dropdown, every loop, every column header reads
   this — never a hard-coded array — so the switch above is the whole story. */
function production_stages(): array {
    return production_dispatch_on()
        ? ['Cutting', 'Stitching', 'Dispatch']
        : ['Cutting', 'Stitching'];
}

/* WHAT MAY BE STORED is a wider list than what may be CHOSEN.
   Old rows are read back as they are; only new choices are restricted. */
function production_stage_storable(): array { return ['Cutting', 'Stitching', 'Dispatch']; }

/* THE LAST LIVE STAGE, AND THE PROGRESS FIGURE THAT GOES WITH IT.
 *
 * This is the part of switching Dispatch off that would otherwise have broken
 * quietly. Every "% Complete" in the app divided DISPATCHED by ORDERED. With
 * nothing ever booked at Dispatch, every order would have read 0% complete
 * forever — no error, no warning, just a dashboard that had stopped meaning
 * anything. So completion follows the last stage that is actually running. */
function production_final_stage(): string { return production_dispatch_on() ? 'Dispatch' : 'Stitching'; }
function production_final_key(): string   { return production_dispatch_on() ? 'dispatched' : 'stitched'; }
function production_final_label(): string { return production_dispatch_on() ? 'Dispatched' : 'Stitched'; }

function production_ensure_schema(): void {
    try { db()->exec("CREATE TABLE IF NOT EXISTS production_workers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        worker_code VARCHAR(20) NOT NULL,
        worker_name VARCHAR(120) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_worker_code (worker_code),
        INDEX(worker_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    try { db()->exec("CREATE TABLE IF NOT EXISTS production_operations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        operation_name VARCHAR(80) NOT NULL,
        stage ENUM('Cutting','Stitching','Dispatch') NOT NULL,
        rate DECIMAL(12,2) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(product_id), INDEX(stage)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    // nullable = simple product (zero change to existing behaviour); a value
    // groups operations into a named part of a composite product (e.g.
    // "Bed Sheet", "Pillow Case") so tracking/costing can work per-part.
    try { db()->exec("ALTER TABLE production_operations ADD COLUMN component_name VARCHAR(80) NULL DEFAULT NULL AFTER operation_name"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE production_operations ADD INDEX idx_component (product_id, component_name)"); } catch (Throwable $e) {}
    // nullable = applies to every size/Costing Version of the product (today's
    // behaviour, zero change); a value scopes this rate row to one specific
    // product_sizes row, so the same operation (e.g. "Overlock Stitching") can
    // cost a different amount for a Bath Towel than for a Hand Towel. Loosely
    // references product_sizes (owned by product_costing.php's pc_ensure_schema())
    // the same way product_component_qty.product_size_id already does.
    try { db()->exec("ALTER TABLE production_operations ADD COLUMN product_size_id INT NULL DEFAULT NULL AFTER stage"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE production_operations ADD INDEX idx_size (product_id, product_size_id)"); } catch (Throwable $e) {}

    try { db()->exec("CREATE TABLE IF NOT EXISTS production_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        production_date DATE NOT NULL,
        user_id INT NOT NULL,
        worker_id INT NOT NULL,
        proforma_id INT NOT NULL,
        proforma_item_id INT NOT NULL,
        product_id INT NOT NULL,
        operation_id INT NOT NULL,
        stage ENUM('Cutting','Stitching','Dispatch') NOT NULL,
        quantity DECIMAL(12,2) NOT NULL,
        applied_rate DECIMAL(12,2) NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
        order_reference VARCHAR(60) NULL,
        created_by INT NULL,
        cancelled_by INT NULL,
        cancellation_reason TEXT NULL,
        cancelled_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        INDEX(proforma_item_id, stage), INDEX(worker_id), INDEX(production_date), INDEX(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    // denormalized copy of the operation's component at save time, so
    // progress can be grouped by component without a join on every read
    try { db()->exec("ALTER TABLE production_transactions ADD COLUMN component_name VARCHAR(80) NULL DEFAULT NULL AFTER operation_id"); } catch (Throwable $e) {}
    // why a Cutting entry went above the planned figure. NULL on every normal
    // entry, and on every row already in the table — this only ever holds the
    // sentence somebody typed to explain an over-cut.
    try { db()->exec("ALTER TABLE production_transactions ADD COLUMN variance_reason VARCHAR(255) NULL DEFAULT NULL"); } catch (Throwable $e) {}

    // how many of each component make up one "set" of a product at a given
    // size — e.g. Bed Set / Double = 1 Bed Sheet + 2 Pillow Case. A missing
    // row means quantity 1 (safe default — never blocks entry on incomplete data).
    try { db()->exec("CREATE TABLE IF NOT EXISTS product_component_qty (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        product_size_id INT NOT NULL,
        component_name VARCHAR(80) NOT NULL,
        qty_per_set DECIMAL(10,2) NOT NULL DEFAULT 1,
        UNIQUE KEY uniq_pcq (product_size_id, component_name),
        INDEX(product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    try { db()->exec("CREATE TABLE IF NOT EXISTS production_ai_usage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        usage_date DATE NOT NULL,
        request_count INT NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_user_date (user_id, usage_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    try { db()->exec("ALTER TABLE proforma_invoices ADD COLUMN production_enabled TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE proforma_invoices ADD COLUMN production_status ENUM('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started'"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','colleague','staff','production_staff') NOT NULL DEFAULT 'colleague'"); } catch (Throwable $e) {}

    /* which production_staff logins may act on a given order — hard
       restriction: unassigned orders don't show up in their search at
       all. Many-to-many (an order can have several assigned staff). */
    try { db()->exec("CREATE TABLE IF NOT EXISTS production_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proforma_id INT NOT NULL,
        user_id INT NOT NULL,
        assigned_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_assignment (proforma_id, user_id),
        INDEX(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* ONE ORDER PAYS MORE FOR ONE OPERATION.
     *
     * A buyer asks for a tighter hem, a doubled stitch, a quality parameter
     * nobody else asks for — and that one operation on that one order is worth
     * more than the standard rate. Changing the rate in the Part Library or on
     * the product would change it for EVERY order, which is wrong; leaving it
     * alone underpays the worker, which is also wrong.
     *
     * So the override lives here: one row per (order, operation). It is read
     * only at the moment a booking is priced, and NOTHING else in the app
     * changes — the product keeps its rate, the library keeps its rate, and
     * every other order keeps paying the standard.
     *
     * A reason is NOT optional. An unexplained rate change six months later is
     * indistinguishable from a mistake. */
    try { db()->exec("CREATE TABLE IF NOT EXISTS production_order_rates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proforma_id INT NOT NULL,
        operation_id INT NOT NULL,
        rate DECIMAL(12,2) NOT NULL,
        reason VARCHAR(255) NOT NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_order_op (proforma_id, operation_id),
        INDEX(operation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* EVERY RATE CHANGE, FROM ALL THREE PLACES A RATE CAN LIVE.
     *
     * library  — the Part Library definition (the template for the next product)
     * product  — one operation on one master product
     * order    — an override on one production order
     *
     * Written by whoever makes the change; never edited, never deleted. This is
     * what answers "when did Singer go from 0.50 to 0.75, and who agreed to
     * it?" — a question that currently has no answer at all. */
    try { db()->exec("CREATE TABLE IF NOT EXISTS rate_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        scope ENUM('library','product','order') NOT NULL,
        ref_id INT NOT NULL,
        operation_id INT NULL,
        part_name VARCHAR(120) NULL,
        operation_name VARCHAR(120) NULL,
        old_rate DECIMAL(12,2) NULL,
        new_rate DECIMAL(12,2) NOT NULL,
        reason VARCHAR(255) NULL,
        changed_by INT NULL,
        changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_scope_ref (scope, ref_id),
        INDEX idx_when (changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
}

/* ---------- rate history ----------
   Deliberately the plainest possible writer: it never throws, because a
   history row failing to write must not stop the rate change itself from
   being saved. A missing history line is a gap in a report; a refused save
   is a wage that does not get paid. */
function rate_history_log(string $scope, int $refId, ?int $operationId, string $partName,
                          string $operationName, ?float $oldRate, float $newRate,
                          string $reason = '', ?int $userId = null): void {
    if (!in_array($scope, ['library', 'product', 'order'], true)) return;
    /* nothing actually moved — do not fill the report with no-ops */
    if ($oldRate !== null && abs($oldRate - $newRate) < 0.0001) return;
    try {
        db()->prepare("INSERT INTO rate_history
            (scope,ref_id,operation_id,part_name,operation_name,old_rate,new_rate,reason,changed_by)
            VALUES (?,?,?,?,?,?,?,?,?)")
          ->execute([$scope, $refId, $operationId ?: null, mb_substr($partName, 0, 120),
                     mb_substr($operationName, 0, 120), $oldRate, $newRate,
                     mb_substr($reason, 0, 255), $userId]);
    } catch (Throwable $e) {}
}

/* Newest first. $filter accepts scope, ref_id, operation_id, part_name, days. */
function rate_history_read(array $filter = [], int $limit = 200): array {
    $where = []; $args = [];
    if (!empty($filter['scope']))        { $where[] = 'scope = ?';         $args[] = $filter['scope']; }
    if (!empty($filter['ref_id']))       { $where[] = 'ref_id = ?';        $args[] = (int)$filter['ref_id']; }
    if (!empty($filter['operation_id'])) { $where[] = 'operation_id = ?';  $args[] = (int)$filter['operation_id']; }
    if (!empty($filter['part_name']))    { $where[] = 'LOWER(part_name) = LOWER(?)'; $args[] = $filter['part_name']; }
    if (!empty($filter['days']))         { $where[] = 'changed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)'; $args[] = (int)$filter['days']; }
    $sql = "SELECT * FROM rate_history" . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . " ORDER BY changed_at DESC, id DESC LIMIT " . max(1, min(1000, $limit));
    try { $st = db()->prepare($sql); $st->execute($args); return $st->fetchAll(); }
    catch (Throwable $e) { return []; }
}

/* ---------- per-order rate overrides ---------- */

/* [operation_id => ['rate'=>float,'reason'=>string,...]] for one order. */
function production_order_rate_map(int $proformaId): array {
    if ($proformaId <= 0) return [];
    try {
        $st = db()->prepare("SELECT * FROM production_order_rates WHERE proforma_id=?");
        $st->execute([$proformaId]);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[(int)$r['operation_id']] = $r;
        return $out;
    } catch (Throwable $e) { return []; }
}

/* The rate a booking on this order should actually be paid at.
   No override = the product's own rate, exactly as before. */
function production_rate_for_order(int $proformaId, int $operationId, float $baseRate, ?array $map = null): float {
    if ($map === null) $map = production_order_rate_map($proformaId);
    return isset($map[$operationId]) ? (float)$map[$operationId]['rate'] : $baseRate;
}

/* Set or move one override. A reason is mandatory, and a rate of 0 or less is
   refused — "free" is never a rate somebody meant to type.

   ALREADY-BOOKED WORK IS NOT RE-PRICED. Every production_transactions row
   carries applied_rate, the number agreed at the moment it was booked, so an
   override changes what is paid from now on and can never quietly restate a
   wage that has already been earned. */
function production_order_rate_save(int $proformaId, int $operationId, float $rate,
                                    string $reason, ?int $userId = null): array {
    $reason = trim($reason);
    if ($proformaId <= 0 || $operationId <= 0) return ['ok' => false, 'error' => 'Missing order or operation.'];
    if ($rate <= 0)      return ['ok' => false, 'error' => 'Enter a rate greater than 0.'];
    if ($reason === '')  return ['ok' => false, 'error' => 'A reason is required — that is the whole point of unlocking the line.'];

    try {
        $st = db()->prepare("SELECT o.operation_name, o.component_name, o.rate
                             FROM production_operations o WHERE o.id=?");
        $st->execute([$operationId]);
        $op = $st->fetch();
        if (!$op) return ['ok' => false, 'error' => 'That operation no longer exists.'];

        $cur  = production_order_rate_map($proformaId);
        $from = isset($cur[$operationId]) ? (float)$cur[$operationId]['rate'] : (float)$op['rate'];

        db()->prepare("INSERT INTO production_order_rates (proforma_id,operation_id,rate,reason,created_by)
                       VALUES (?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE rate=VALUES(rate), reason=VALUES(reason), updated_at=NOW()")
          ->execute([$proformaId, $operationId, $rate, mb_substr($reason, 0, 255), $userId]);

        rate_history_log('order', $proformaId, $operationId,
                         (string)($op['component_name'] ?? ''), (string)$op['operation_name'],
                         $from, $rate, $reason, $userId);
        return ['ok' => true, 'error' => '', 'from' => $from, 'to' => $rate];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save that rate.'];
    }
}

/* Put one line back on the standard rate. Logged like any other change, so the
   report shows the amendment AND the day it was withdrawn. */
function production_order_rate_clear(int $proformaId, int $operationId, ?int $userId = null): bool {
    try {
        $cur = production_order_rate_map($proformaId);
        if (!isset($cur[$operationId])) return false;
        $st = db()->prepare("SELECT operation_name, component_name, rate FROM production_operations WHERE id=?");
        $st->execute([$operationId]);
        $op = $st->fetch() ?: ['operation_name' => '', 'component_name' => '', 'rate' => 0];
        db()->prepare("DELETE FROM production_order_rates WHERE proforma_id=? AND operation_id=?")
            ->execute([$proformaId, $operationId]);
        rate_history_log('order', $proformaId, $operationId,
                         (string)($op['component_name'] ?? ''), (string)$op['operation_name'],
                         (float)$cur[$operationId]['rate'], (float)$op['rate'],
                         'Amendment withdrawn — back to the standard rate', $userId);
        return true;
    } catch (Throwable $e) { return false; }
}

function production_assigned_staff(int $proformaId): array {
    $st = db()->prepare("SELECT u.id, u.name FROM production_assignments pa JOIN users u ON u.id = pa.user_id WHERE pa.proforma_id=? ORDER BY u.name");
    $st->execute([$proformaId]);
    return $st->fetchAll();
}

/* replaces the full assignment list for one order in one call — simplest
   correct way to sync a multi-select chip picker without diffing */
function production_set_assignments(int $proformaId, array $userIds, int $assignedBy): void {
    db()->prepare("DELETE FROM production_assignments WHERE proforma_id=?")->execute([$proformaId]);
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds) return;
    $ins = db()->prepare("INSERT IGNORE INTO production_assignments (proforma_id, user_id, assigned_by) VALUES (?,?,?)");
    foreach ($userIds as $uid) $ins->execute([$proformaId, $uid, $assignedBy]);
}

/* every proforma_id this production_staff login is allowed to touch —
   null return means "no restriction" (used for admin), an array (even
   empty) means "only these" */
function production_assigned_proforma_ids(int $userId): array {
    $st = db()->prepare("SELECT proforma_id FROM production_assignments WHERE user_id=?");
    $st->execute([$userId]);
    return array_map('intval', array_column($st->fetchAll(), 'proforma_id'));
}

/* mirrors pm_audit() in product_master.php — same audit_logs table, shipment_id
   just carries 0 for non-shipment sections (the column allows NULL, but every
   other module already uses this same 0-not-null convention, so we match it) */
function production_audit($action, $old, $new, $reason = ''): void {
    try { audit_log(0, 'Production', $action, is_array($old) ? json_encode($old) : (string)$old, is_array($new) ? json_encode($new) : (string)$new, $reason); } catch (Throwable $e) {}
}

function production_num($v): float {
    $s = preg_replace('/[^0-9.\-]/', '', (string)$v);
    return is_numeric($s) ? (float)$s : 0.0;
}

/* has this worker ever been used in a saved entry? if so, delete must be
   blocked (deactivate only) so past wage history never gets orphaned */
function production_worker_has_history(int $workerId): bool {
    $st = db()->prepare("SELECT COUNT(*) FROM production_transactions WHERE worker_id=?");
    $st->execute([$workerId]);
    return (int)$st->fetchColumn() > 0;
}

/* same guard for a product's operation */
function production_operation_has_history(int $operationId): bool {
    $st = db()->prepare("SELECT COUNT(*) FROM production_transactions WHERE operation_id=?");
    $st->execute([$operationId]);
    return (int)$st->fetchColumn() > 0;
}

/* ============================================================
   Search + matching (local only — no embeddings/OpenAI in this
   module's search path, per spec). Same tiered idea as the demo's
   client-side fuzzyMatch(): exact > starts-with > contains > loose
   character-subsequence (catches typos like "duvt" -> "Duvet").
   ============================================================ */
function production_norm(string $s): string {
    return strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $s)));
}

function production_fuzzy_score(string $query, string $text): int {
    $q = production_norm($query); $t = production_norm($text);
    if ($q === '' || $t === '') return 0;
    if ($t === $q) return 100;
    if (strpos($t, $q) === 0) return 90;
    if (strpos($t, $q) !== false) return 75;
    $qi = 0; $qlen = strlen($q);
    for ($i = 0; $i < strlen($t) && $qi < $qlen; $i++) if ($t[$i] === $q[$qi]) $qi++;
    return $qi === $qlen ? 40 : 0;
}

/* "PI-260722-995" -> "995" — the last dash-delimited segment, which is the
   part workers actually recognize on the floor. Falls back to the full
   string when there's no dash to split on. */
function production_short_pi(string $piNo): string {
    $piNo = trim($piNo);
    if ($piNo === '') return '';
    $parts = explode('-', $piNo);
    $last = trim((string)end($parts));
    return $last !== '' ? $last : $piNo;
}

/* "ZIPPER MATTRESS ENCASEMENT - FLORAL PRINT" -> name "ZIPPER MATTRESS
   ENCASEMENT", variant "FLORAL PRINT" — splits on the first " - " (the
   consistent pattern in how products are named here); no dash means the
   whole string is the name, truncated, and variant is empty. */
function production_short_name(string $productName, int $maxLen = 26): array {
    $productName = trim($productName);
    $variant = '';
    $pos = strpos($productName, ' - ');
    if ($pos !== false) {
        $variant = trim(mb_substr($productName, $pos + 3));
        $productName = trim(mb_substr($productName, 0, $pos));
    }
    if (mb_strlen($productName) > $maxLen) $productName = mb_substr($productName, 0, $maxLen - 1) . '…';
    return ['name' => $productName, 'variant' => $variant];
}

/* Resolve a proforma_items.product_name (free text, no FK) to a Product
   Master row — local exact/substring/similar_text matching ONLY, never
   embeddings, so this never costs anything and never calls OpenAI. If
   nothing matches with reasonable confidence, the item is simply excluded
   from production search (its product isn't in Product Master yet, or the
   name is too different to trust automatically). */
/* Which product a proforma or invoice LINE is.

   Prefer the answer over the guess. If the line carries a product_id —
   because somebody picked it from the list — that is the product, full
   stop: no matching, no threshold, no possibility of the line vanishing
   or attaching to a neighbour that happened to share some letters.

   Only a line with no stored id falls through to name matching, which is
   every line saved before this existed. Those behave exactly as they did
   yesterday.

   Pass the whole row, not the name. That is the point: the row knows
   something the name does not. */
/* Is this product id still a real, active row in Product Master? A line
   can carry an id for a product that was later deactivated; that id is a
   dead link, and every caller needs to know it. */
function production_product_is_live(int $id): bool {
    if ($id <= 0) return false;
    static $live = null;
    if ($live === null) {
        $live = [];
        try {
            foreach (db()->query("SELECT id FROM products WHERE is_active=1")->fetchAll() as $p) {
                $live[(int)$p['id']] = true;
            }
        } catch (Throwable $e) {}
    }
    return isset($live[$id]);
}

function production_resolve_item(array $row): ?int {
    // a product deleted from the master must not silently keep its old
    // link — fall through to the guesser, same as a line that never had one
    if (production_product_is_live((int)($row['product_id'] ?? 0))) {
        return (int)$row['product_id'];
    }
    return production_resolve_product((string)($row['product_name'] ?? ''));
}

/* Was this line CHOSEN, or is the system guessing at it? Used to mark a
   guessed line on screen instead of letting it pass as certain.

   This asks about the stored link itself, NOT whether the guesser happens
   to agree with it. A dead id whose name coincidentally still matches the
   same product is a guess that landed well, not a choice the user made,
   and it must not be shown as certain. */
function production_line_is_linked(array $row): bool {
    return production_product_is_live((int)($row['product_id'] ?? 0));
}

function production_resolve_product(string $productName): ?int {
    static $products = null;
    if ($products === null) {
        $products = db()->query("SELECT id, name FROM products WHERE is_active=1")->fetchAll();
    }
    $norm = production_norm($productName);
    if ($norm === '') return null;
    foreach ($products as $p) if (production_norm($p['name']) === $norm) return (int)$p['id'];
    $best = null; $bestScore = 0.0;
    foreach ($products as $p) {
        $pn = production_norm($p['name']);
        if ($pn === '') continue;
        if (strpos($pn, $norm) !== false || strpos($norm, $pn) !== false) { $score = 0.85; }
        else { similar_text($norm, $pn, $pct); $score = $pct / 100; }
        if ($score > $bestScore) { $bestScore = $score; $best = (int)$p['id']; }
    }
    return $bestScore >= 0.55 ? $best : null;
}

/* distinct part names used by a product's active operations. Empty array =
   simple product — every component-aware code path falls back to legacy
   whole-item behaviour when this is empty, so simple products get zero
   extra steps anywhere in the app. */
function production_product_components(int $productId): array {
    static $cache = [];
    if (isset($cache[$productId])) return $cache[$productId];
    $st = db()->prepare("SELECT DISTINCT component_name FROM production_operations WHERE product_id=? AND is_active=1 AND component_name IS NOT NULL AND component_name<>'' ORDER BY component_name");
    $st->execute([$productId]);
    return $cache[$productId] = array_column($st->fetchAll(), 'component_name');
}

/* Active Production Operations for a product, keyed [component][operation
   name][size scope] => rate. Scope is '' for an "All Sizes" row
   (product_size_id NULL) or the product_size_id (as a string) for a row
   priced specifically for that one size. Kept per-operation (not collapsed
   straight to a per-component total) so a component made of several
   operations — some global, some size-scoped — resolves each one on its
   own and adds them together, instead of a size-scoped operation silently
   replacing its component's other, unrelated operations. Used both for the
   real Workmanship total (pc_workmanship_rate_for_version()) and to feed
   the live per-size preview in Product Costing's JS editor. */
function production_operation_rate_map(int $productId): array {
    $out = [];
    if ($productId <= 0) return $out;
    $st = db()->prepare("SELECT COALESCE(component_name,'') component_name, operation_name, COALESCE(product_size_id,'') size_scope, SUM(rate) rate
        FROM production_operations WHERE product_id=? AND is_active=1 GROUP BY component_name, operation_name, product_size_id");
    $st->execute([$productId]);
    foreach ($st->fetchAll() as $r) {
        $out[$r['component_name']][$r['operation_name']][(string)$r['size_scope']] = (float)$r['rate'];
    }
    return $out;
}

/* Sum of one component's operations, each resolved for a specific size — a
   row priced for that exact size wins for that operation; otherwise falls
   back to that operation's "All Sizes" row; an operation with size-scoped
   rows but no "All Sizes" row simply contributes 0 for a size it wasn't
   priced for. Passing $sizeId=null (no single size to resolve against)
   uses every operation's "All Sizes" rate only, same as an unmatched size. */
function production_operation_rate_for_size(array $rateMap, string $component, ?int $sizeId): float {
    $ops = $rateMap[$component] ?? [];
    $total = 0.0;
    foreach ($ops as $bySize) {
        if ($sizeId !== null && array_key_exists((string)$sizeId, $bySize)) $total += $bySize[(string)$sizeId];
        elseif (array_key_exists('', $bySize)) $total += $bySize[''];
    }
    return $total;
}

/* how many of this component make up one "set" at the given size label.
   No size, no matching product_sizes row, or no defined quantity all fall
   back to 1 — never blocks tracking/costing on incomplete size data. */
function production_component_qty_per_set(int $productId, ?string $sizeLabel, string $component): float {
    $sizeLabel = trim((string)$sizeLabel);
    if ($sizeLabel === '') return 1.0;
    $st = db()->prepare("SELECT id FROM product_sizes WHERE product_id=? AND LOWER(size_label)=LOWER(?)");
    $st->execute([$productId, $sizeLabel]);
    $psid = $st->fetchColumn();
    if (!$psid) return 1.0;
    $st2 = db()->prepare("SELECT qty_per_set FROM product_component_qty WHERE product_size_id=? AND component_name=?");
    $st2->execute([(int)$psid, $component]);
    $v = $st2->fetchColumn();
    return $v !== false ? (float)$v : 1.0;
}

/* map: [proforma_item_id][stage][component_name-or-''] => qty. Grouping by
   component (in addition to the original item+stage) is the actual fix for
   same-stage operations under one product colliding into one false total. */
function production_progress_map(): array {
    /* Grouped by OPERATION as well as stage, then reduced with MIN.

       It used to SUM every transaction in a stage, discarding operation_id
       even though the table stores it. Give one part two Stitching
       operations — "sew" and "hem" — record 45 of each, and the stage read
       90 against 50 cut: 180% done, and Dispatch was then told 90 pieces
       were waiting. Nobody hit it while each part had one operation per
       stage; it breaks the day a second is added.

       MIN is the honest reduction, not SUM and not MAX: a part has only
       passed a stage as far as its SLOWEST operation in that stage. 45 sewn
       and 20 hemmed means 20 are truly through Stitching. */
    $rows = db()->query(
        "SELECT proforma_item_id, stage, COALESCE(component_name,'') component_name,
                operation_id, SUM(quantity) qty
         FROM production_transactions
         WHERE status='active'
         GROUP BY proforma_item_id, stage, component_name, operation_id"
    )->fetchAll();

    $byOp = [];
    foreach ($rows as $r) {
        $byOp[(int)$r['proforma_item_id']][$r['stage']][$r['component_name']][(int)$r['operation_id']] = (float)$r['qty'];
    }
    $map = [];
    foreach ($byOp as $itemId => $stages) {
        foreach ($stages as $stage => $comps) {
            foreach ($comps as $comp => $ops) {
                $map[$itemId][$stage][$comp] = $ops ? min($ops) : 0.0;
            }
        }
    }
    return $map;
}

/* The same numbers, but per operation rather than reduced — for the
   balance an entry screen needs: how much is left on THIS operation, not
   on its stage as a whole. Keyed [item][component][operation_id]. */
function production_operation_done_map(): array {
    $out = [];
    try {
        $rows = db()->query(
            "SELECT proforma_item_id, COALESCE(component_name,'') component_name,
                    operation_id, SUM(quantity) qty
             FROM production_transactions
             WHERE status='active'
             GROUP BY proforma_item_id, component_name, operation_id"
        )->fetchAll();
        foreach ($rows as $r) {
            $out[(int)$r['proforma_item_id']][$r['component_name']][(int)$r['operation_id']] = (float)$r['qty'];
        }
    } catch (Throwable $e) {}
    return $out;
}

/* legacy whole-item progress — sums across components. For a simple product
   (component is always '') this is byte-for-byte the same number as before;
   for a multi-component product it's the combined floor-activity total,
   still meaningful for overall status/dashboard rollups. */
function production_item_progress(array $map, int $itemId): array {
    $m = $map[$itemId] ?? [];
    $sumStage = function ($stage) use ($m) { return array_sum($m[$stage] ?? []); };
    return ['cut' => $sumStage('Cutting'), 'stitched' => $sumStage('Stitching'), 'dispatched' => $sumStage('Dispatch')];
}

/* progress for one specific component of an item (pass '' for a simple
   product's single "no component" bucket — same numbers as production_item_progress). */
function production_item_component_progress(array $map, int $itemId, string $component = ''): array {
    $m = $map[$itemId] ?? [];
    return [
        'cut' => (float)($m['Cutting'][$component] ?? 0),
        'stitched' => (float)($m['Stitching'][$component] ?? 0),
        'dispatched' => (float)($m['Dispatch'][$component] ?? 0),
    ];
}

/* per-component breakdown for one order item — null for a simple product
   (caller should use production_combo_for_item()/production_item_progress()
   directly in that case, exactly as before). Used to show the "which part?"
   picker and per-component bottleneck info. */
function production_components_for_item(int $itemId, ?array $allowedProformaIds = null): ?array {
    $st = db()->prepare("SELECT pi.id item_id, pi.proforma_id, pi.product_name, pi.product_id, pi.size, pi.qty ordered_qty, pf.pi_no, pf.customer_name
        FROM proforma_items pi JOIN proforma_invoices pf ON pf.id = pi.proforma_id
        WHERE pi.id=? AND pf.production_enabled=1");
    $st->execute([$itemId]);
    $r = $st->fetch();
    if (!$r) return null;
    if ($allowedProformaIds !== null && !in_array((int)$r['proforma_id'], $allowedProformaIds, true)) return null;

    $pid = production_resolve_item($r);
    if (!$pid) return null;
    $components = production_product_components($pid);
    if (!$components) return null;

    $progMap = production_progress_map();
    $out = [];
    foreach ($components as $comp) {
        $qtyPerSet = production_component_qty_per_set($pid, $r['size'], $comp);
        $ordered = (float)$r['ordered_qty'] * $qtyPerSet;
        $prog = production_item_component_progress($progMap, $itemId, $comp);
        $stage = production_recommended_stage($ordered, $prog);
        $out[] = [
            'component' => $comp, 'ordered' => $ordered,
            'cut' => $prog['cut'], 'stitched' => $prog['stitched'], 'dispatched' => $prog['dispatched'],
            'stage' => $stage, 'remaining' => production_stage_remaining($stage, $ordered, $prog),
            'done' => $ordered > 0 && $prog['dispatched'] >= $ordered,
        ];
    }
    return [
        'item_id' => $itemId, 'proforma_id' => (int)$r['proforma_id'], 'product_id' => $pid,
        'product_name' => $r['product_name'], 'size' => $r['size'], 'pi_no' => $r['pi_no'], 'customer_name' => $r['customer_name'],
        'ordered_qty' => (float)$r['ordered_qty'], 'components' => $out,
    ];
}

/* Cutting is capped by ordered qty; Stitching by what's been Cut; Dispatch
   by what's been Stitched — the cumulative chain the spec requires. */
/* Which stage actually FINISHES a part, read from its own operations.
 *
 * A part is ready to go into a set when it has been through the last stage it
 * has any operations in. Most parts end at Stitching; a part that is only cut
 * (a label, a tag) ends at Cutting. Assuming Stitching for everything would
 * hold packing at zero forever for those parts, which is why this is read
 * rather than hard-coded. Dispatch is ignored here: a per-part Dispatch row is
 * the other counting mode entirely, and Product Master refuses to mix them.
 *
 * Returns [component_name => 'cut'|'stitched'].
 */
function production_part_finish_stage(int $productId): array {
    static $cache = [];
    if (isset($cache[$productId])) return $cache[$productId];
    $out = [];
    try {
        $st = db()->prepare("SELECT COALESCE(component_name,'') c, stage
                             FROM production_operations
                             WHERE product_id=? AND is_active=1 AND stage<>'Dispatch'");
        $st->execute([$productId]);
        foreach ($st->fetchAll() as $r) {
            $c = trim((string)$r['c']);
            if ($c === '') continue;
            if ($r['stage'] === 'Stitching') $out[$c] = 'stitched';
            elseif (!isset($out[$c]))        $out[$c] = 'cut';
        }
    } catch (Throwable $e) {}
    return $cache[$productId] = $out;
}

/* Does this product pack as a whole set — i.e. is there an active Dispatch
   operation with no part named? If so, its parts are never dispatched
   individually and a per-part "Dispatch" figure is meaningless for them. */
function production_has_whole_set_step(int $productId): bool {
    /* NO DISPATCH STAGE, NO WHOLE-SET STEP. Gated here rather than at each of
       the four callers, so a stale Dispatch row left in the data cannot make
       one screen believe in a packing step the rest of the app has dropped. */
    if (!production_dispatch_on()) return false;
    static $cache = [];
    if (isset($cache[$productId])) return $cache[$productId];
    $has = false;
    try {
        $st = db()->prepare("SELECT 1 FROM production_operations
                             WHERE product_id=? AND is_active=1 AND stage='Dispatch'
                               AND COALESCE(component_name,'')='' LIMIT 1");
        $st->execute([$productId]);
        $has = (bool)$st->fetchColumn();
    } catch (Throwable $e) {}
    return $cache[$productId] = $has;
}

/* THE PACKING CEILING.
 *
 * How many whole sets can be packed right now =
 *     MIN over every part of ( that part's finished pieces / its per-set count )
 *   capped by the order, less the sets already packed.
 *
 * A MINIMUM, not a sum. A set is complete only when its SLOWEST part is ready;
 * finishing a part that is already ahead adds nothing. The old Dispatch rule
 * (stitched - dispatched, where "stitched" was array_sum across every part)
 * added pieces of different parts together as if they were sets, and ignored
 * the order entirely — on 100 sets of a 1+2 product fully stitched it offered
 * 300. This is the same MIN reduction used for stage progress in v19, one
 * level up: there the slowest operation within a stage, here the slowest part
 * within a set.
 *
 * Partial sets are not offered: 10 pieces at 3 per set is 3 whole sets.
 *
 * Returns ['sets'=>float, 'held_by'=>string, 'packed'=>float, 'per_part'=>[...]].
 */
function production_set_pack_ceiling(int $itemId, int $productId, ?string $sizeLabel,
                                     float $orderedSets, array $progMap): array {
    $parts = production_product_components($productId);
    $out = ['sets' => 0.0, 'held_by' => '', 'packed' => 0.0, 'per_part' => []];
    if (!$parts) return $out;

    $finishStage = production_part_finish_stage($productId);
    $cap = null; $worst = null;

    foreach ($parts as $c) {
        $per = production_component_qty_per_set($productId, $sizeLabel, $c);
        $prog = production_item_component_progress($progMap, $itemId, $c);
        $key = $finishStage[$c] ?? 'stitched';
        $done = (float)($prog[$key] ?? 0);
        // a per-set count of 0 means the part is not in this size at all —
        // it cannot hold the order back, so it is left out of the minimum
        $allows = $per > 0 ? floor($done / $per) : null;
        $out['per_part'][] = ['component' => $c, 'per_set' => $per, 'finished' => $done,
                              'finish_stage' => $key, 'allows' => $allows];
        if ($allows === null) continue;
        if ($cap === null || $allows < $cap) { $cap = $allows; $worst = $c; }
    }
    if ($cap === null) return $out;

    // the blank-part bucket is where whole sets are recorded
    $packed = (float)(production_item_component_progress($progMap, $itemId, '')['dispatched'] ?? 0);
    if ($orderedSets > 0) $cap = min($cap, $orderedSets);

    $out['sets']    = max(0.0, (float)$cap - $packed);
    $out['packed']  = $packed;
    // only name a bottleneck when one part is genuinely behind the others
    $distinct = array_unique(array_filter(array_column($out['per_part'], 'allows'), fn($a) => $a !== null));
    $out['held_by'] = count($distinct) > 1 ? (string)$worst : '';
    return $out;
}

/* The ONE place that answers "how much may still be booked here".
 *
 * Four screens used to carry their own copy of this if/else (the entry API,
 * the combo search, My Work and the save guard). When the whole-set rule
 * changed, five copies would have had to change together. Now they call this.
 *
 * Returns ['remaining'=>float, 'ordered'=>float, 'prog'=>array,
 *          'held_by'=>string, 'per_part'=>array].
 */
function production_limit_for(int $itemId, int $productId, ?string $sizeLabel, float $orderedSets,
                              string $stage, string $component, array $progMap,
                              int $operationId = 0, ?array $doneMap = null): array {
    $component = trim($component);

    if ($component !== '') {                      // a named part: counted in PIECES
        $per = production_component_qty_per_set($productId, $sizeLabel, $component);
        $ordered = $orderedSets * $per;
        $prog = production_item_component_progress($progMap, $itemId, $component);

        /* EVERY OPERATION HAS ITS OWN ALLOWANCE.
         *
         * The stage figure is a MIN across a part's operations, which is right
         * for reporting progress and WRONG as a booking limit. With Singer at
         * 200 of 200 and Overlock at 50, the stage said "150 left" — meant for
         * Overlock, but nothing said so, and booking that 150 against Singer
         * put it at 350 pieces on a 200 piece order. The stage total still
         * read correctly, and the 150 stayed on offer, so it could be repeated.
         *
         * The mirror of the same fault: once Singer reached 200 the stage read
         * 0 and Overlock could not be booked at all.
         *
         * So an operation is capped by ITS OWN booked total against the
         * ceiling of its stage — never by what its siblings have done.
         *
         *   Cutting    ceiling = ordered
         *   Stitching  ceiling = what has actually been CUT (never the plan)
         *   Dispatch   ceiling = what has actually been finished before it
         */
        if ($operationId > 0) {
            /* The map is PASSED IN, the same way $progMap is. A static cache
               here made the answer depend on the order the callers happened to
               ask in — a caller that asked once before any work was booked got
               a stale zero for every later question in the same request. A
               caller that loops (the combo search) computes it once and hands
               it over; anyone else lets it be read here. */
            if ($doneMap === null) $doneMap = production_operation_done_map();
            $done = (float)($doneMap[$itemId][$component][$operationId] ?? 0);

            if ($stage === 'Cutting')        $ceiling = $ordered;
            elseif ($stage === 'Stitching')  $ceiling = min($ordered, (float)$prog['cut']);
            else                             $ceiling = min($ordered, (float)$prog['stitched']);

            return ['remaining' => max(0.0, $ceiling - $done),
                    'ordered' => $ordered, 'prog' => $prog, 'held_by' => '', 'per_part' => [],
                    'op_done' => $done, 'op_ceiling' => $ceiling];
        }

        return ['remaining' => production_stage_remaining($stage, $ordered, $prog),
                'ordered' => $ordered, 'prog' => $prog, 'held_by' => '', 'per_part' => []];
    }

    $prog = production_item_progress($progMap, $itemId);   // counted in SETS

    // the whole-set packing step of a product that HAS parts is the only case
    // with a derived ceiling; everything else keeps the behaviour it had
    if ($stage === 'Dispatch' && production_product_components($productId)) {
        $ceil = production_set_pack_ceiling($itemId, $productId, $sizeLabel, $orderedSets, $progMap);
        return ['remaining' => $ceil['sets'], 'ordered' => $orderedSets, 'prog' => $prog,
                'held_by' => $ceil['held_by'], 'per_part' => $ceil['per_part']];
    }

    return ['remaining' => production_stage_remaining($stage, $orderedSets, $prog),
            'ordered' => $orderedSets, 'prog' => $prog, 'held_by' => '', 'per_part' => []];
}

function production_stage_remaining(string $stage, float $ordered, array $prog): float {
    if ($stage === 'Cutting') return max(0, $ordered - $prog['cut']);
    if ($stage === 'Stitching') return max(0, $prog['cut'] - $prog['stitched']);
    /* Dispatch. With the stage switched off there is nothing left to do at it,
       ever — so an old Dispatch operation still sitting in the data offers 0
       and cannot be booked, rather than quietly remaining bookable. */
    if (!production_dispatch_on()) return 0.0;
    return max(0, $prog['stitched'] - $prog['dispatched']);
}

/* the combined "Product & Operation" search behind both entry modes.
   Only looks at proforma_invoices with production_enabled=1. Returns up
   to $limit best-scoring product+operation combinations. */
/* $allowedProformaIds: null = no restriction (admin sees every enabled
   order); an array (even empty) = hard-restricts results to only those
   proforma ids — used for production_staff logins so an unassigned order
   never appears in their search at all, not just "hidden but reachable". */
function production_search_combos(string $q, int $limit = 8, ?array $allowedProformaIds = null): array {
    $q = trim($q);
    if ($q === '') return [];
    if ($allowedProformaIds !== null && !$allowedProformaIds) return [];

    $sql = "SELECT pi.id item_id, pi.proforma_id, pi.product_name, pi.product_id, pi.description, pi.size, pi.qty ordered_qty,
               pf.pi_no, pf.customer_name
        FROM proforma_items pi
        JOIN proforma_invoices pf ON pf.id = pi.proforma_id
        WHERE pf.production_enabled = 1";
    $params = [];
    if ($allowedProformaIds !== null) {
        $in = implode(',', array_fill(0, count($allowedProformaIds), '?'));
        $sql .= " AND pf.id IN ($in)";
        $params = $allowedProformaIds;
    }
    $sql .= " ORDER BY pf.id DESC LIMIT 500";
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    if (!$rows) return [];

    $resolved = [];
    foreach ($rows as $r) {
        $pid = production_resolve_item($r);
        if ($pid) $resolved[(int)$r['item_id']] = $pid;
    }
    if (!$resolved) return [];

    $pids = array_values(array_unique($resolved));
    $in = implode(',', array_fill(0, count($pids), '?'));
    $opsByProduct = [];
    /* A stage that is switched off is not offered as work. An old Dispatch
       operation left in the data is filtered out HERE, at the source of every
       entry screen, rather than at each screen that displays the result. */
    $stageIn = implode(',', array_fill(0, count(production_stages()), '?'));
    $opSt = db()->prepare("SELECT * FROM production_operations
                           WHERE is_active=1 AND product_id IN ($in) AND stage IN ($stageIn)
                           ORDER BY FIELD(stage,'Cutting','Stitching','Dispatch'), operation_name");
    $opSt->execute(array_merge($pids, production_stages()));
    foreach ($opSt->fetchAll() as $op) { $opsByProduct[(int)$op['product_id']][] = $op; }

    $progMap = production_progress_map();

    $doneMap = production_operation_done_map();   // once, not once per combo
    $combos = [];
    $ordRates = [];              // per-order rate amendments, one lookup per order
    foreach ($rows as $r) {
        $itemId = (int)$r['item_id'];
        if (!isset($resolved[$itemId])) continue;
        $pid = $resolved[$itemId];
        $ops = $opsByProduct[$pid] ?? [];
        if (!$ops) continue;
        $itemScore = max(
            production_fuzzy_score($q, $r['product_name']),
            production_fuzzy_score($q, $r['pi_no']),
            production_fuzzy_score($q, $r['customer_name']) * 0.9
        );
        foreach ($ops as $op) {
            $comp = trim((string)($op['component_name'] ?? ''));
            $opScore = max(
                production_fuzzy_score($q, $op['operation_name']) * 0.95,
                production_fuzzy_score($q, $op['stage']) * 0.7,
                $comp !== '' ? production_fuzzy_score($q, $comp) * 0.9 : 0
            );
            $score = max($itemScore, $opScore);
            if ($score <= 0) continue;
            $lim = production_limit_for($itemId, $pid, $r['size'], (float)$r['ordered_qty'],
                                        $op['stage'], $comp, $progMap, (int)$op['id'], $doneMap);
            $ordered = $lim['ordered']; $prog = $lim['prog']; $remaining = $lim['remaining'];
            if ($remaining <= 0.0001) continue; // nothing left to log — don't clutter the list (a fully-closed order has 0 remaining at every stage, so this also drops it entirely)
            /* SHOW THE RATE THIS ORDER ACTUALLY PAYS, not the standard one.
               A screen that shows 0.50 while the save writes 0.75 is worse
               than no figure at all. */
            $pfid = (int)$r['proforma_id'];
            if (!isset($ordRates[$pfid])) $ordRates[$pfid] = production_order_rate_map($pfid);
            $payRate = production_rate_for_order($pfid, (int)$op['id'], (float)$op['rate'], $ordRates[$pfid]);
            $combos[] = [
                'item_id' => $itemId, 'proforma_id' => $pfid, 'product_id' => $pid,
                'pi_no' => $r['pi_no'], 'customer_name' => $r['customer_name'],
                'product_name' => $r['product_name'], 'description' => $r['description'], 'size' => $r['size'],
                'component' => $comp,
                'ordered_qty' => $ordered,
                'operation_id' => (int)$op['id'], 'operation_name' => $op['operation_name'], 'stage' => $op['stage'], 'rate' => $payRate,
                'standard_rate' => (float)$op['rate'],
                'rate_amended' => abs($payRate - (float)$op['rate']) > 0.0001,
                'cut' => $prog['cut'], 'stitched' => $prog['stitched'], 'dispatched' => $prog['dispatched'],
                'remaining' => $remaining,
                // the whole-set packing step is counted in sets, and names the
                // part holding it; every other row is pieces and names nothing
                'unit' => ($comp === '' && production_product_components($pid)) ? 'sets' : 'pcs',
                'held_by' => $lim['held_by'],
                'score' => $score,
            ];
        }
    }
    usort($combos, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($combos, 0, $limit);
}

/* resolves one exact combo directly from an item_id + stage — used by the
   "My Production Work" tap-through flow so opening an order card jumps
   straight to a ready-to-fill entry, no search step. Picks the
   product's first active operation in that stage; same allowed-list
   restriction as production_search_combos().
   For a multi-component product, $component is required — pass null to get
   null back (caller should have already called production_components_for_item()
   and shown a "which part?" picker; production_api.php enforces this order). */
function production_combo_for_item(int $itemId, string $stage, ?array $allowedProformaIds = null, ?string $component = null): ?array {
    if (!in_array($stage, production_stages(), true)) return null;   // Dispatch off = no combo
    $st = db()->prepare("SELECT pi.id item_id, pi.proforma_id, pi.product_name, pi.product_id, pi.description, pi.size, pi.qty ordered_qty,
            pf.pi_no, pf.customer_name
        FROM proforma_items pi JOIN proforma_invoices pf ON pf.id = pi.proforma_id
        WHERE pi.id=? AND pf.production_enabled=1");
    $st->execute([$itemId]);
    $r = $st->fetch();
    if (!$r) return null;
    if ($allowedProformaIds !== null && !in_array((int)$r['proforma_id'], $allowedProformaIds, true)) return null;

    $pid = production_resolve_item($r);
    if (!$pid) return null;

    $components = production_product_components($pid);
    $isMulti = (bool)$components;
    if ($isMulti && ($component === null || !in_array($component, $components, true))) return null;

    if ($isMulti) {
        $opSt = db()->prepare("SELECT * FROM production_operations WHERE is_active=1 AND product_id=? AND stage=? AND component_name=? ORDER BY operation_name LIMIT 1");
        $opSt->execute([$pid, $stage, $component]);
    } else {
        $opSt = db()->prepare("SELECT * FROM production_operations WHERE is_active=1 AND product_id=? AND stage=? ORDER BY operation_name LIMIT 1");
        $opSt->execute([$pid, $stage]);
    }
    $op = $opSt->fetch();
    if (!$op) return null;

    $progMap = production_progress_map();
    $lim = production_limit_for($itemId, $pid, $r['size'], (float)$r['ordered_qty'],
                                $stage, $isMulti ? (string)$component : '', $progMap, (int)$op['id']);
    $ordered = $lim['ordered']; $prog = $lim['prog'];
    /* same amendment the save will apply — see production_search_combos() */
    $pfid    = (int)$r['proforma_id'];
    $payRate = production_rate_for_order($pfid, (int)$op['id'], (float)$op['rate']);
    return [
        'item_id' => $itemId, 'proforma_id' => $pfid, 'product_id' => $pid,
        'pi_no' => $r['pi_no'], 'customer_name' => $r['customer_name'],
        'product_name' => $r['product_name'], 'description' => $r['description'], 'size' => $r['size'],
        'component' => $isMulti ? $component : '',
        'ordered_qty' => $ordered,
        'operation_id' => (int)$op['id'], 'operation_name' => $op['operation_name'], 'stage' => $op['stage'], 'rate' => $payRate,
        'standard_rate' => (float)$op['rate'],
        'rate_amended' => abs($payRate - (float)$op['rate']) > 0.0001,
        'cut' => $prog['cut'], 'stitched' => $prog['stitched'], 'dispatched' => $prog['dispatched'],
        'remaining' => $lim['remaining'],
        'unit' => ($isMulti ? 'pcs' : (production_product_components($pid) ? 'sets' : 'pcs')),
        'held_by' => $lim['held_by'], 'per_part' => $lim['per_part'],
        'score' => 100,
    ];
}

/* the recommended next stage for one item — Cutting until fully cut, then
   Stitching until fully stitched, then Dispatch. Shared by "My Production
   Work" cards and can be reused anywhere else a default stage is needed. */
function production_recommended_stage(float $ordered, array $prog): string {
    if ($prog['cut'] < $ordered) return 'Cutting';
    if ($prog['stitched'] < $prog['cut']) return 'Stitching';
    /* With Dispatch off, work ENDS at Stitching. Returning 'Dispatch' here
       would have put a card on the worker's screen for a stage that no longer
       exists, with nothing behind it to book. */
    return production_dispatch_on() ? 'Dispatch' : 'Stitching';
}

function production_search_workers(string $q, int $limit = 8): array {
    $q = trim($q);
    if ($q === '') return [];
    $rows = db()->query("SELECT id, worker_code, worker_name FROM production_workers WHERE is_active=1")->fetchAll();
    $scored = [];
    foreach ($rows as $w) {
        $s = max(production_fuzzy_score($q, $w['worker_name']), production_fuzzy_score($q, $w['worker_code']));
        if ($s > 0) $scored[] = ['id' => (int)$w['id'], 'worker_code' => $w['worker_code'], 'worker_name' => $w['worker_name'], 'score' => $s];
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($scored, 0, $limit);
}

/* one row per production-enabled proforma, aggregated across its items —
   shared by the dashboard's PI progress table and the Proforma Production
   Progress report so the two stay in sync instead of duplicating logic */
function production_pi_progress_rows(?string $piFilter = null, int $limit = 100): array {
    $sql = "SELECT id, pi_no, customer_name, production_status FROM proforma_invoices WHERE production_enabled=1";
    $params = [];
    if ($piFilter !== null && $piFilter !== '') { $sql .= " AND pi_no LIKE ?"; $params[] = '%' . $piFilter . '%'; }
    $sql .= " ORDER BY FIELD(production_status,'in_progress','not_started','completed'), id DESC LIMIT " . (int)$limit;
    $st = db()->prepare($sql); $st->execute($params);
    $piRows = $st->fetchAll();

    $progMap = production_progress_map();
    $out = [];
    foreach ($piRows as $pf) {
        $items = db()->prepare("SELECT id, product_name, product_id, qty, size FROM proforma_items WHERE proforma_id=?");
        $items->execute([$pf['id']]); $itemRows = $items->fetchAll();
        $ordered = 0.0; $cut = 0.0; $stitched = 0.0; $dispatched = 0.0; $names = []; $gating = [];
        foreach ($itemRows as $it) {
            $itemId = (int)$it['id'];
            $pid = production_resolve_item($it);
            $components = $pid ? production_product_components($pid) : [];
            if ($components) {
                $detail = production_components_for_item($itemId);
                foreach (($detail['components'] ?? []) as $c) {
                    $ordered += $c['ordered']; $cut += $c['cut']; $stitched += $c['stitched']; $dispatched += $c['dispatched'];
                    if (!$c['done']) $gating[] = $it['product_name'] . ': ' . $c['component'] . ' (' . $c['stage'] . ')';
                }
            } else {
                $ordered += (float)$it['qty'];
                $prog = production_item_progress($progMap, $itemId);
                $cut += $prog['cut']; $stitched += $prog['stitched']; $dispatched += $prog['dispatched'];
            }
            if ($it['product_name']) $names[] = $it['product_name'];
        }
        $out[] = ['pi_no' => $pf['pi_no'], 'customer_name' => $pf['customer_name'], 'status' => $pf['production_status'], 'lines' => count($itemRows), 'ordered' => $ordered, 'cut' => $cut, 'stitched' => $stitched, 'dispatched' => $dispatched, 'products' => implode(', ', array_unique($names)), 'gating' => $gating];
    }
    return $out;
}

/* recompute a proforma's rollup status from its items' actual progress —
   called after every save, never edited by hand */
function production_refresh_status(int $proformaId): void {
    $items = db()->prepare("SELECT id, qty FROM proforma_items WHERE proforma_id=?");
    $items->execute([$proformaId]); $rows = $items->fetchAll();
    if (!$rows) return;
    $totalOrdered = 0.0; $totalDispatched = 0.0; $anyActivity = false;
    $progMap = production_progress_map();
    foreach ($rows as $r) {
        $totalOrdered += (float)$r['qty'];
        $prog = production_item_progress($progMap, (int)$r['id']);
        $totalDispatched += $prog['dispatched'];
        if ($prog['cut'] > 0 || $prog['stitched'] > 0 || $prog['dispatched'] > 0) $anyActivity = true;
    }
    $status = 'not_started';
    if ($anyActivity) $status = ($totalOrdered > 0 && $totalDispatched >= $totalOrdered) ? 'completed' : 'in_progress';
    db()->prepare("UPDATE proforma_invoices SET production_status=? WHERE id=?")->execute([$status, $proformaId]);
}

/* ============================================================
   Save + validate. Rate is ALWAYS looked up server-side from
   production_operations and copied into applied_rate — any rate the
   browser might submit is ignored outright. Cumulative stage limits are
   re-checked here even though the entry page already checks them
   client-side, because client-side checks are a UX convenience, not a
   security boundary. Multiple rows in one submit are validated together
   (so two rows against the same item+stage in one batch stack correctly)
   and saved in a single DB transaction — either the whole batch saves, or
   none of it does.
   ============================================================ */
/* $allowedProformaIds: same meaning as in production_search_combos() —
   null for admin (no restriction), an array for production_staff (hard
   restriction). Checked here too, not just in search: a determined client
   could otherwise POST an item_id it never saw in its own search results. */
function production_validate_and_save(array $entries, string $date, int $userId, ?array $allowedProformaIds = null): array {
    $errors = [];
    $prepared = [];
    $progMap = production_progress_map();
    /* read once, before the row loop — every row is checked against the same
       picture, and each row's own contribution is tracked in $batchDelta */
    $doneMap = production_operation_done_map();
    $batchDelta = [];
    /* one override lookup per ORDER in the batch, not one per row */
    $orderRateCache = [];

    foreach ($entries as $i => $e) {
        $rowLabel = 'Row ' . ($i + 1);
        $itemId = (int)($e['item_id'] ?? 0);
        $opId = (int)($e['operation_id'] ?? 0);
        $workerId = (int)($e['worker_id'] ?? 0);
        $qty = production_num($e['quantity'] ?? 0);

        if ($itemId <= 0 || $opId <= 0 || $workerId <= 0) { $errors[] = "$rowLabel: missing product, operation, or worker."; continue; }
        if ($qty <= 0) { $errors[] = "$rowLabel: quantity must be greater than 0."; continue; }

        $itemSt = db()->prepare("SELECT pi.*, pf.production_enabled FROM proforma_items pi JOIN proforma_invoices pf ON pf.id = pi.proforma_id WHERE pi.id=?");
        $itemSt->execute([$itemId]); $item = $itemSt->fetch();
        if (!$item || !$item['production_enabled']) { $errors[] = "$rowLabel: this order item is not enabled for production."; continue; }
        if ($allowedProformaIds !== null && !in_array((int)$item['proforma_id'], $allowedProformaIds, true)) { $errors[] = "$rowLabel: this order is not assigned to you."; continue; }

        $opSt = db()->prepare("SELECT * FROM production_operations WHERE id=? AND is_active=1");
        $opSt->execute([$opId]); $op = $opSt->fetch();
        if (!$op) { $errors[] = "$rowLabel: operation not found or inactive."; continue; }

        /* THE LAST DOOR. Every screen already hides a switched-off stage, but a
           saved page, a bookmarked entry, or a hand-made request could still
           carry an old Dispatch operation id. Refuse it here, where the wage
           would actually be written, and say plainly why. */
        if (!in_array($op['stage'], production_stages(), true)) {
            $errors[] = "$rowLabel: {$op['stage']} is switched off — nothing can be booked against it. "
                      . "Book this work against Cutting or Stitching instead.";
            continue;
        }

        $resolvedPid = production_resolve_item($item);
        if (!$resolvedPid || $resolvedPid !== (int)$op['product_id']) { $errors[] = "$rowLabel: operation does not match this order's product."; continue; }

        $workerSt = db()->prepare("SELECT * FROM production_workers WHERE id=? AND is_active=1");
        $workerSt->execute([$workerId]); $worker = $workerSt->fetch();
        if (!$worker) { $errors[] = "$rowLabel: worker not found or inactive."; continue; }

        $comp = trim((string)($op['component_name'] ?? ''));
        $lim = production_limit_for($itemId, $resolvedPid, $item['size'], (float)$item['qty'],
                                    $op['stage'], $comp, $progMap, $opId, $doneMap);
        $orderedForCheck = $lim['ordered'];
        $heldBy = $lim['held_by'];
        /* the batch key carries the OPERATION now. Two rows for Singer in one
           save must add up against Singer's allowance; a row for Singer and a
           row for Overlock must not eat each other's. */
        $key = $itemId . ':' . $op['stage'] . ':' . $comp . ':' . $opId;
        $already = $batchDelta[$key] ?? 0.0;
        /* rows earlier in the SAME batch count against this one. For a whole-set
           packing row the earlier rows are sets, and the ceiling is already in
           sets, so the same subtraction works for both modes. */
        $remaining = max(0.0, $lim['remaining'] - $already);

        /* CUTTING MAY GO ABOVE THE PLAN — but never silently.
         *
         * Cutting is the one stage capped by the plan (ordered x per-set)
         * rather than by what actually happened; every later stage already
         * reads the actual. Wastage, a short roll, an extra lay — cutting 105
         * against a plan of 100 is ordinary, and refusing to record it did not
         * stop it happening, it only stopped it being written down.
         *
         * So: the over-cut is allowed when a reason is given, and the reason is
         * stored on the row. No other stage may exceed — stitching more than
         * was cut, or packing more sets than exist, is a mistake, not wastage.
         */
        $over = $qty - $remaining;
        /* THIS READ THE WRONG VARIABLE. The loop variable is $e; $r does not
         * exist in this scope, so $reason was ALWAYS '' and the over-cut
         * branch below could never be reached. Every over-cut was refused with
         * a message telling the user to do the thing the code then ignored. */
        $reason = trim((string)($e['variance_reason'] ?? ''));
        if ($over > 0.0001 && $op['stage'] === 'Cutting' && $reason !== '') {
            $varianceReason = mb_substr($reason, 0, 255);
        } elseif ($qty > $remaining + 0.0001) {
            $varianceReason = null;
            $label = $comp !== '' ? "{$item['product_name']} — {$comp}" : $item['product_name'];
            $num  = rtrim(rtrim(number_format($remaining, 2), '0'), '.');
            /* the whole-set packing step is counted in SETS, and when one part
               is behind, saying WHICH part is the difference between a number
               that gets argued with and one that sends someone to a machine */
            $unit = ($comp === '' && production_product_components($resolvedPid)) ? 'sets' : 'pieces';
            $errors[] = "$rowLabel: only {$num} {$unit} are available for {$op['stage']} on {$label}."
                . ($op['stage'] === 'Cutting'
                    ? " Cutting above the plan is allowed — give a reason on this row and it will be recorded as an over-cut."
                    : ($heldBy !== '' ? " Held by {$heldBy} — finish it to raise this." : ''));
            continue;
        } else {
            $varianceReason = null;
        }
        $batchDelta[$key] = $already + $qty;

        /* THE RATE THIS ORDER ACTUALLY PAYS.
         *
         * Still looked up server-side and never taken from the browser. The
         * only change is that an order carrying an approved amendment for this
         * operation pays the amended rate instead of the product's standard
         * one — and the number lands in applied_rate exactly as before, so it
         * is frozen onto the row the moment it is booked. */
        $pfid = (int)$item['proforma_id'];
        if (!isset($orderRateCache[$pfid])) $orderRateCache[$pfid] = production_order_rate_map($pfid);
        $payRate = production_rate_for_order($pfid, $opId, (float)$op['rate'], $orderRateCache[$pfid]);

        $prepared[] = [
            'production_date' => $date, 'user_id' => $userId, 'worker_id' => $workerId,
            'proforma_id' => $pfid, 'proforma_item_id' => $itemId, 'product_id' => $resolvedPid,
            'operation_id' => $opId, 'stage' => $op['stage'], 'component_name' => $comp !== '' ? $comp : null, 'quantity' => $qty,
            'applied_rate' => $payRate, 'amount' => round($qty * $payRate, 2),
            'variance_reason' => $varianceReason,
        ];
    }

    if ($errors) return ['ok' => false, 'errors' => $errors, 'saved' => 0];
    if (!$prepared) return ['ok' => false, 'errors' => ['Nothing to save.'], 'saved' => 0];

    db()->beginTransaction();
    try {
        $ins = db()->prepare("INSERT INTO production_transactions
            (production_date,user_id,worker_id,proforma_id,proforma_item_id,product_id,operation_id,stage,component_name,quantity,applied_rate,amount,status,created_by,variance_reason)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'active',?,?)");
        $touchedProformas = [];
        foreach ($prepared as $p) {
            $ins->execute([$p['production_date'], $p['user_id'], $p['worker_id'], $p['proforma_id'], $p['proforma_item_id'], $p['product_id'], $p['operation_id'], $p['stage'], $p['component_name'], $p['quantity'], $p['applied_rate'], $p['amount'], $p['user_id'], $p['variance_reason']]);
            $touchedProformas[$p['proforma_id']] = true;
        }
        foreach (array_keys($touchedProformas) as $pfid) production_refresh_status((int)$pfid);
        db()->commit();
        production_audit('entry_save', '', count($prepared) . ' row(s)', 'Production entries saved');
        return ['ok' => true, 'errors' => [], 'saved' => count($prepared)];
    } catch (Throwable $e) {
        db()->rollBack();
        return ['ok' => false, 'errors' => ['Save failed: ' . $e->getMessage()], 'saved' => 0];
    }
}

/* ============================================================
   Correcting a saved entry.

   A saved production entry is never edited and never deleted. The row
   stays exactly as it was written, and is marked cancelled with a reason,
   a user and a timestamp — the same rule the store documents follow. Every
   query that reads production_transactions already filters status='active',
   so a cancelled entry disappears from progress, wages, dashboards,
   reports, order costing and the exception checks the moment it is
   cancelled, while the original claim stays visible in the audit trail.

   "Amending" an entry therefore means: cancel the wrong one, then log the
   right one normally. Cancelling gives the quantity back to the stage, so
   the corrected entry passes the same cumulative limits as any other.
   ============================================================ */
function production_cancel_entry(int $txnId, int $userId, string $reason): array {
    $reason = trim($reason);
    if (mb_strlen($reason) < 5) return ['ok' => false, 'error' => 'Write a reason of at least 5 characters — it stays with the entry permanently.'];
    if (mb_strlen($reason) > 500) $reason = mb_substr($reason, 0, 500);

    $st = db()->prepare("SELECT * FROM production_transactions WHERE id=?");
    $st->execute([$txnId]);
    $t = $st->fetch();
    if (!$t) return ['ok' => false, 'error' => 'Entry not found.'];
    if ($t['status'] === 'cancelled') return ['ok' => false, 'error' => 'That entry is already cancelled.'];

    /* Stitching stands on Cutting and Dispatch stands on Stitching, so an
       earlier stage cannot be pulled out from under a later one — that
       would leave the item showing more stitched than cut. Later stages
       must be cancelled first. */
    $comp = (string)($t['component_name'] ?? '');
    $prog = production_item_component_progress(production_progress_map(), (int)$t['proforma_item_id'], $comp);
    $qty = (float)$t['quantity'];
    if ($t['stage'] === 'Cutting' && ($prog['cut'] - $qty) < $prog['stitched'] - 0.0001) {
        return ['ok' => false, 'error' => 'Cannot cancel this Cutting entry — ' . rtrim(rtrim(number_format($prog['stitched'], 2), '0'), '.') . ' pieces have already been stitched against it. Cancel the Stitching entries for this item first.'];
    }
    if ($t['stage'] === 'Stitching' && ($prog['stitched'] - $qty) < $prog['dispatched'] - 0.0001) {
        return ['ok' => false, 'error' => 'Cannot cancel this Stitching entry — ' . rtrim(rtrim(number_format($prog['dispatched'], 2), '0'), '.') . ' pieces have already been dispatched against it. Cancel the Dispatch entries for this item first.'];
    }

    db()->beginTransaction();
    try {
        // AND status='active' so two admins pressing Cancel at the same
        // moment can never both succeed on the same row
        $up = db()->prepare("UPDATE production_transactions
            SET status='cancelled', cancelled_by=?, cancellation_reason=?, cancelled_at=NOW(), updated_at=NOW()
            WHERE id=? AND status='active'");
        $up->execute([$userId, $reason, $txnId]);
        if ($up->rowCount() !== 1) { db()->rollBack(); return ['ok' => false, 'error' => 'That entry was just changed by someone else — reload the page and check it.']; }
        production_refresh_status((int)$t['proforma_id']);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        return ['ok' => false, 'error' => 'Cancel failed: ' . $e->getMessage()];
    }

    production_audit('entry_cancel', [
        'id' => (int)$t['id'], 'date' => $t['production_date'], 'worker_id' => (int)$t['worker_id'],
        'proforma_item_id' => (int)$t['proforma_item_id'], 'stage' => $t['stage'], 'component' => $comp,
        'quantity' => $qty, 'applied_rate' => (float)$t['applied_rate'], 'amount' => (float)$t['amount'],
    ], 'cancelled', $reason);

    return ['ok' => true, 'entry' => $t];
}

/* ============================================================
   AI assist (optional, explicit button-press only). Sends ONLY the
   free-text sentence plus a handful of already-narrowed local candidates
   — never the full products/workers tables. Always returns a draft; the
   caller (production_api.php) never saves it directly, the entry page
   always requires a human click to accept it. Every returned id is
   re-validated against the candidate list the model was given, so even
   a hallucinated id can't reach the save step.
   ============================================================ */
/* bulk-insert helper for the fast entry grid and CSV import — validates
   each row the same way the single Add Operation form does (product must
   exist, operation name + rate>0 required), inserts everything that's
   valid, and reports the rest as errors instead of failing the whole batch. */
function production_bulk_save_operations(array $rows, int $userId): array {
    $errors = []; $saved = 0; $updated = 0;
    foreach ($rows as $i => $r) {
        $rowLabel = 'Row ' . ($i + 1);
        /* An 'id' turns this row into an UPDATE of that exact operation.

           This is what makes the CSV a round trip rather than a way to
           duplicate everything you already have: export writes the id into
           each row, so re-importing an edited file corrects those rows
           instead of colliding with them. A blank id still inserts, which
           is every row typed in the grid and every row of a hand-made file
           — their behaviour is unchanged. */
        $opId = (int)($r['id'] ?? 0);
        $pid = (int)($r['product_id'] ?? 0);
        $name = trim((string)($r['operation_name'] ?? ''));
        $stage = $r['stage'] ?? 'Cutting';
        $rate = production_num($r['rate'] ?? 0);
        $component = trim((string)($r['component_name'] ?? ''));
        if (!in_array($stage, ['Cutting', 'Stitching', 'Dispatch'], true)) $stage = 'Cutting';
        if ($pid <= 0) { $errors[] = "$rowLabel: no product selected."; continue; }
        if ($name === '') { $errors[] = "$rowLabel: operation name is required."; continue; }
        if ($rate <= 0) { $errors[] = "$rowLabel: rate must be greater than 0."; continue; }
        $chk = db()->prepare("SELECT COUNT(*) FROM products WHERE id=?"); $chk->execute([$pid]);
        if (!$chk->fetchColumn()) { $errors[] = "$rowLabel: product not found."; continue; }

        if ($opId > 0) {
            $own = db()->prepare("SELECT product_id FROM production_operations WHERE id=?");
            $own->execute([$opId]);
            $ownPid = $own->fetchColumn();
            if ($ownPid === false) { $errors[] = "$rowLabel: operation #$opId no longer exists — clear the Operation ID to add it as new."; continue; }
            /* Moving an operation to a different product by editing the
               product name next to an id is almost certainly a mistake in
               the spreadsheet, not an intention. Refuse rather than
               silently reassigning it. */
            if ((int)$ownPid !== $pid) {
                $errors[] = "$rowLabel: operation #$opId belongs to a different product. Clear the Operation ID to create a new one instead.";
                continue;
            }
            db()->prepare("UPDATE production_operations SET operation_name=?, stage=?, rate=?, component_name=? WHERE id=?")
                ->execute([$name, $stage, $rate, $component !== '' ? $component : null, $opId]);
            production_audit('operation_edit', $pid, ['id' => $opId, 'operation_name' => $name, 'stage' => $stage, 'rate' => $rate, 'component' => $component], 'Production operation updated (bulk import)');
            $updated++;
            continue;
        }

        // re-running the same CSV/grid import must never double an operation —
        // skip an exact match on product+stage+name+part instead of inserting
        // a second active row (which would double-count in Workmanship).
        $dupSt = db()->prepare("SELECT COUNT(*) FROM production_operations WHERE product_id=? AND stage=? AND LOWER(operation_name)=LOWER(?) AND LOWER(COALESCE(component_name,''))=LOWER(?) AND is_active=1");
        $dupSt->execute([$pid, $stage, $name, $component]);
        if ($dupSt->fetchColumn() > 0) {
            $partNote = $component !== '' ? "/$component" : '';
            $errors[] = "$rowLabel: \"$name\" already exists for this product/$stage$partNote — skipped (export the operations, edit the rate in that file, and re-import to change it).";
            continue;
        }

        db()->prepare("INSERT INTO production_operations (product_id,operation_name,stage,rate,component_name,is_active,created_by) VALUES (?,?,?,?,?,1,?)")
            ->execute([$pid, $name, $stage, $rate, $component !== '' ? $component : null, $userId]);
        production_audit('operation_add', $pid, ['operation_name' => $name, 'stage' => $stage, 'rate' => $rate, 'component' => $component], 'Production operation added (bulk)');
        $saved++;
    }
    return ['ok' => ($saved + $updated) > 0 || !$errors, 'saved' => $saved, 'updated' => $updated, 'errors' => $errors];
}

/* ---------------------------------------------------------------------
   Export: what you already have, in the shape you can edit and send back.

   $onlyGaps limits it to products with NO active operations at all — the
   list you cannot see anywhere in the app today, and the one that answers
   "which of my products still need setting up?". Those products come back
   as a single blank starter row each, so the file is something to fill in
   rather than a format to remember. */
/* UNUSED SINCE THE OPERATIONS CSV WAS REMOVED — AND NOT SAFE TO REVIVE AS IS.
 * That file had NO SIZE COLUMN, but production_operations rows can be scoped
 * to one product_size_id ("Applies to" in Product Master). A product priced
 * across six sizes therefore exported as six rows identical except the rate,
 * with nothing saying which size each one was. Its partner below,
 * production_bulk_save_operations(), had the matching hole: its INSERT never
 * set product_size_id, so anything added from a file landed on "All sizes".
 * An operations CSV needs a size column at BOTH ends before it works. */
function production_export_operations(bool $onlyGaps = false): array {
    $out = [];
    $ops = [];
    try {
        $ops = db()->query(
            "SELECT po.id, po.product_id, p.name product_name, COALESCE(po.component_name,'') component_name,
                    po.operation_name, po.stage, po.rate
             FROM production_operations po
             JOIN products p ON p.id = po.product_id
             WHERE po.is_active = 1 AND p.is_active = 1
             ORDER BY p.name, po.component_name, FIELD(po.stage,'Cutting','Stitching','Dispatch'), po.operation_name"
        )->fetchAll();
    } catch (Throwable $e) {}

    $haveOps = [];
    foreach ($ops as $o) $haveOps[(int)$o['product_id']] = true;

    if (!$onlyGaps) {
        foreach ($ops as $o) {
            $out[] = [
                'id' => (int)$o['id'], 'product_name' => $o['product_name'],
                'component_name' => $o['component_name'], 'operation_name' => $o['operation_name'],
                'stage' => $o['stage'], 'rate' => (float)$o['rate'],
            ];
        }
    }

    try {
        foreach (db()->query("SELECT id, name FROM products WHERE is_active=1 ORDER BY name")->fetchAll() as $p) {
            if (isset($haveOps[(int)$p['id']])) continue;
            $out[] = [
                'id' => 0, 'product_name' => $p['name'], 'component_name' => '',
                'operation_name' => '', 'stage' => '', 'rate' => '',
            ];
        }
    } catch (Throwable $e) {}

    return $out;
}

/* ---------------------------------------------------------------------
   Set quantities — a SEPARATE export, on purpose.

   An operation is one row per product+part+operation+stage and does not
   care about size. A set quantity is one row per product+size+part and
   does not care about operations. Forcing both into one sheet makes every
   operation repeat once per size, and the moment two copies disagree
   about a rate nothing can say which is right.

   The full grid is exported, not only the stored rows: a missing row
   behaves as 1 (see production_component_qty_per_set), so showing every
   combination with its effective value is the only way to see what the
   system currently believes. */
function production_export_component_qty(): array {
    $out = [];
    try {
        $rows = db()->query(
            "SELECT p.id product_id, p.name product_name, ps.id size_id, ps.size_label,
                    po.component_name
             FROM products p
             JOIN product_sizes ps ON ps.product_id = p.id
             JOIN production_operations po ON po.product_id = p.id
             WHERE p.is_active = 1 AND po.is_active = 1
               AND po.component_name IS NOT NULL AND po.component_name <> ''
             GROUP BY p.id, p.name, ps.id, ps.size_label, po.component_name
             ORDER BY p.name, ps.id, po.component_name"
        )->fetchAll();
    } catch (Throwable $e) { return []; }

    $stored = [];
    try {
        foreach (db()->query("SELECT product_size_id, component_name, qty_per_set FROM product_component_qty")->fetchAll() as $q) {
            $stored[(int)$q['product_size_id'] . '|' . $q['component_name']] = (float)$q['qty_per_set'];
        }
    } catch (Throwable $e) {}

    foreach ($rows as $r) {
        $k = (int)$r['size_id'] . '|' . $r['component_name'];
        $out[] = [
            'product_name' => $r['product_name'], 'size_label' => $r['size_label'],
            'component_name' => $r['component_name'],
            'qty_per_set' => $stored[$k] ?? 1.0,
            'is_stored' => isset($stored[$k]),
        ];
    }
    return $out;
}

/* Import counterpart. Resolves (product, size label, part) to the size row
   and upserts, exactly as Product Master's own editor does. */
function production_bulk_save_component_qty(array $rows, int $userId): array {
    $errors = []; $saved = 0;
    $ins = db()->prepare("INSERT INTO product_component_qty (product_id,product_size_id,component_name,qty_per_set) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE qty_per_set=VALUES(qty_per_set)");
    $sizeSt = db()->prepare("SELECT id FROM product_sizes WHERE product_id=? AND LOWER(size_label)=LOWER(?)");
    foreach ($rows as $i => $r) {
        $rowLabel = 'Row ' . ($i + 1);
        $pid = (int)($r['product_id'] ?? 0);
        $size = trim((string)($r['size_label'] ?? ''));
        $comp = trim((string)($r['component_name'] ?? ''));
        $qty = production_num($r['qty_per_set'] ?? 0);
        if ($pid <= 0) { $errors[] = "$rowLabel: no product selected."; continue; }
        if ($size === '') { $errors[] = "$rowLabel: size is required."; continue; }
        if ($comp === '') { $errors[] = "$rowLabel: part is required."; continue; }
        if ($qty < 0) { $errors[] = "$rowLabel: quantity cannot be negative."; continue; }
        $sizeSt->execute([$pid, $size]);
        $sid = $sizeSt->fetchColumn();
        if (!$sid) { $errors[] = "$rowLabel: this product has no size called \"$size\"."; continue; }
        $ins->execute([$pid, (int)$sid, $comp, $qty]);
        production_audit('component_qty_set', $pid, ['size' => $size, 'component' => $comp, 'qty_per_set' => $qty], 'Set quantity updated (bulk import)');
        $saved++;
    }
    return ['ok' => $saved > 0 || !$errors, 'saved' => $saved, 'errors' => $errors];
}

function production_ai_daily_cap(): int {
    global $config;
    return (int)($config['production_ai_daily_cap'] ?? 40);
}

function production_ai_check_and_bump(int $userId): bool {
    $today = date('Y-m-d');
    $st = db()->prepare("SELECT request_count FROM production_ai_usage WHERE user_id=? AND usage_date=?");
    $st->execute([$userId, $today]);
    $count = (int)$st->fetchColumn();
    if ($count >= production_ai_daily_cap()) return false;
    db()->prepare("INSERT INTO production_ai_usage (user_id, usage_date, request_count) VALUES (?,?,1)
        ON DUPLICATE KEY UPDATE request_count = request_count + 1")->execute([$userId, $today]);
    return true;
}

function production_ai_parse(string $sentence, array $comboCandidates, array $workerCandidates): array {
    global $config;
    $comboLines = [];
    foreach ($comboCandidates as $c) {
        $partLabel = !empty($c['component']) ? " / {$c['component']}" : '';
        $comboLines[] = "item_id={$c['item_id']} op_id={$c['operation_id']}: {$c['product_name']}{$partLabel} / {$c['operation_name']} ({$c['stage']}, {$c['pi_no']}, {$c['customer_name']})";
    }
    $workerLines = [];
    foreach ($workerCandidates as $w) {
        $workerLines[] = "worker_id={$w['id']}: {$w['worker_name']} ({$w['worker_code']})";
    }
    $prompt = "Parse this factory floor production entry sentence into rows for a garment factory system.\n"
        . "Only use item_id/op_id/worker_id values copied EXACTLY from the candidate lists below — never invent one, never use a value not listed.\n"
        . "Return ONLY compact JSON, no explanation: {\"rows\":[{\"item_id\":N,\"op_id\":N,\"worker_id\":N,\"quantity\":N}]}\n"
        . "If a worker, product, operation, or quantity can't be matched confidently from the lists, omit that row entirely rather than guessing.\n\n"
        . "Sentence: {$sentence}\n\n"
        . "Product/Operation candidates:\n" . implode("\n", $comboLines) . "\n\n"
        . "Worker candidates:\n" . implode("\n", $workerLines);

    $data = openai_request('responses', [
        'model' => $config['ai_answer_model'] ?? 'gpt-5-mini',
        'input' => $prompt,
        'text' => ['verbosity' => 'low'],
    ]);
    $text = $data['output_text'] ?? '';
    if ($text === '') {
        foreach (($data['output'] ?? []) as $item) foreach (($item['content'] ?? []) as $c) if (isset($c['text'])) $text .= $c['text'];
    }
    $text = trim(preg_replace('/^```json\s*|```\s*$/m', '', trim($text)));
    $json = json_decode($text, true);
    $rows = is_array($json) && isset($json['rows']) && is_array($json['rows']) ? $json['rows'] : [];

    // defense in depth: only accept ids the model was actually given
    $validItemOp = [];
    foreach ($comboCandidates as $c) $validItemOp[$c['item_id'] . ':' . $c['operation_id']] = $c;
    $validWorkers = [];
    foreach ($workerCandidates as $w) $validWorkers[$w['id']] = $w;

    $out = [];
    foreach ($rows as $r) {
        $itemId = (int)($r['item_id'] ?? 0);
        $opId = (int)($r['op_id'] ?? 0);
        $workerId = (int)($r['worker_id'] ?? 0);
        $qty = production_num($r['quantity'] ?? 0);
        $combo = $validItemOp[$itemId . ':' . $opId] ?? null;
        $worker = $validWorkers[$workerId] ?? null;
        if (!$combo || !$worker || $qty <= 0) continue;
        $out[] = [
            'item_id' => $itemId, 'operation_id' => $opId, 'worker_id' => $workerId, 'quantity' => $qty,
            'product_name' => $combo['product_name'], 'operation_name' => $combo['operation_name'], 'stage' => $combo['stage'],
            'pi_no' => $combo['pi_no'], 'worker_name' => $worker['worker_name'], 'worker_code' => $worker['worker_code'],
        ];
    }
    return $out;
}

/* ---------------------------------------------------------------------
   WHY IS THERE NOTHING TO ENTER?

   Production entry is gated FOUR times, and until now every gate failed
   the same silent way — an empty screen. Worse, My Work's one message
   named only the FIRST gate ("no production-enabled orders"), so a user
   whose real problem was a product with no operations was sent to
   Proforma Invoices, where there was nothing wrong.

   An order becomes enterable only when ALL of these hold:
     1. a proforma has production_enabled = 1
     2. that proforma has line items
     3. each line resolves to a live product (by product_id, else by name)
     4. that product has at least one ACTIVE operation

   This walks them in order and reports the FIRST one that fails, with the
   names involved and where to go. Read-only: it counts, it never writes.  */
function production_entry_blockers(?array $allowedProformaIds = null): array {
    $out = ['ok' => false, 'stage' => '', 'title' => '', 'detail' => '',
            'link' => '', 'link_text' => '', 'names' => []];

    /* 1 — any order switched on for production at all? */
    $sql = "SELECT COUNT(*) FROM proforma_invoices WHERE production_enabled = 1";
    $params = [];
    if ($allowedProformaIds !== null) {
        if (!$allowedProformaIds) {
            return ['ok'=>false, 'stage'=>'assigned', 'names'=>[],
                'title'  => 'No orders are assigned to you.',
                'detail' => 'An admin assigns production-enabled orders to a worker. '
                          . 'Until then this screen stays empty for you, even when orders exist.',
                'link' => '', 'link_text' => ''];
        }
        $in = implode(',', array_fill(0, count($allowedProformaIds), '?'));
        $sql .= " AND id IN ($in)";
        $params = $allowedProformaIds;
    }
    $st = db()->prepare($sql); $st->execute($params);
    if ((int)$st->fetchColumn() === 0) {
        return ['ok'=>false, 'stage'=>'no_order', 'names'=>[],
            'title'  => 'No order is switched on for production yet.',
            'detail' => 'Open a Proforma Invoice and turn on production for it. '
                      . 'Nothing can be booked against an order the system has not been told to produce.',
            'link' => 'proforma.php', 'link_text' => 'Open Proforma Invoices'];
    }

    /* 2 — do those orders have lines? */
    $sql2 = "SELECT pi.id item_id, pi.product_name, pi.product_id
             FROM proforma_items pi JOIN proforma_invoices pf ON pf.id = pi.proforma_id
             WHERE pf.production_enabled = 1";
    if ($allowedProformaIds !== null) {
        $in = implode(',', array_fill(0, count($allowedProformaIds), '?'));
        $sql2 .= " AND pf.id IN ($in)";
    }
    $st2 = db()->prepare($sql2); $st2->execute($params);
    $rows = $st2->fetchAll();
    if (!$rows) {
        return ['ok'=>false, 'stage'=>'no_lines', 'names'=>[],
            'title'  => 'The production order has no lines on it.',
            'detail' => 'The order is switched on, but it has no items to make. Add its lines first.',
            'link' => 'proforma.php', 'link_text' => 'Open Proforma Invoices'];
    }

    /* 3 — does every line point at a product that still exists? */
    $unmatched = []; $resolved = [];
    foreach ($rows as $r) {
        $pid = production_resolve_item($r);
        if ($pid) { $resolved[(int)$r['item_id']] = $pid; }
        else {
            $nm = trim((string)$r['product_name']);
            if ($nm !== '' && !in_array($nm, $unmatched, true)) $unmatched[] = $nm;
        }
    }
    if (!$resolved) {
        return ['ok'=>false, 'stage'=>'no_match', 'names'=>$unmatched,
            'title'  => 'No order line matches a product in Product Master.',
            'detail' => 'A line is matched by its saved product link, or failing that by its name. '
                      . 'These names match nothing: ' . implode(', ', array_slice($unmatched, 0, 6))
                      . (count($unmatched) > 6 ? ' and ' . (count($unmatched) - 6) . ' more' : '') . '.',
            'link' => 'product_master.php', 'link_text' => 'Open Product Master'];
    }

    /* 4 — and does that product have any operation to book against? */
    $pids = array_values(array_unique($resolved));
    $in = implode(',', array_fill(0, count($pids), '?'));
    $opSt = db()->prepare("SELECT DISTINCT product_id FROM production_operations
                           WHERE is_active = 1 AND product_id IN ($in)");
    $opSt->execute($pids);
    $withOps = array_map('intval', array_column($opSt->fetchAll(), 'product_id'));
    $missing = array_values(array_diff($pids, $withOps));
    if (!$withOps) {
        $names = [];
        if ($missing) {
            $in2 = implode(',', array_fill(0, count($missing), '?'));
            $nSt = db()->prepare("SELECT name FROM products WHERE id IN ($in2) ORDER BY name");
            $nSt->execute($missing);
            $names = array_column($nSt->fetchAll(), 'name');
        }
        return ['ok'=>false, 'stage'=>'no_ops', 'names'=>$names,
            'title'  => 'The products on this order have no operations yet.',
            'detail' => 'An operation is what a worker books against and what sets the rate — '
                      . 'with none, there is nothing to enter. Open the product, import its parts '
                      . 'from the Part Library, and give each one its Cutting and Stitching steps. '
                      . ($names ? 'Waiting on: ' . implode(', ', array_slice($names, 0, 6))
                        . (count($names) > 6 ? ' and ' . (count($names) - 6) . ' more' : '') . '.' : ''),
            'link' => 'product_master.php', 'link_text' => 'Open Product Master'];
    }

    /* Something IS enterable. Still report partial gaps, because a half-set-up
       order is the case where a worker says "my item is not in the list". */
    $partial = [];
    if ($missing) {
        $in2 = implode(',', array_fill(0, count($missing), '?'));
        $nSt = db()->prepare("SELECT name FROM products WHERE id IN ($in2) ORDER BY name");
        $nSt->execute($missing);
        $partial = array_column($nSt->fetchAll(), 'name');
    }
    return ['ok'=>true, 'stage'=>'ready',
        'names'  => array_merge($partial, $unmatched),
        'title'  => '',
        'detail' => ($partial || $unmatched)
            ? 'Ready — but these will not appear until they are finished: '
              . implode(', ', array_slice(array_merge($partial, $unmatched), 0, 6)) . '.'
            : '',
        'link' => 'product_master.php', 'link_text' => 'Open Product Master'];
}
