<?php
/*
  LINE LINKS — what an invoice or proforma line really is.
  ========================================================

  A line already carries product_id, its link to the Product Master (see
  includes/bootstrap.php). This file adds three things around that link and
  changes nothing about how it works:

    PARTS        a line may instead be linked to a Part (Production › Parts)
                 when a part is sold by itself. The link is for NAMING AND
                 REPORTS ONLY — production does not plan work from it. A line
                 is linked to a product or to a part, never both.
    LAST PRICE   what this buyer was last charged for a product or part, in
                 the same currency, offered when the line is picked.
    LINK LATER   every line with no link, so the office can link them in one
                 sitting instead of opening each document (line_links.php).

  THE PART LINK CAN NEVER BREAK A SAVE. The column is added by ll_ready(), and
  every write of it is separate from the statements that save the line and is
  wrapped: if the column is missing or the write fails, the invoice or the
  proforma is saved exactly as before, simply without the part link.
*/

/* Is the part_id column there? Adds it once if not. One cheap query per page. */
function ll_ready(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = true;
    foreach (['shipment_items', 'proforma_items'] as $t) {
        try {
            $has = db()->query("SHOW COLUMNS FROM `$t` LIKE 'part_id'")->fetch();
            if (!$has) {
                db()->exec("ALTER TABLE `$t` ADD COLUMN part_id INT NULL");
                try { db()->exec("ALTER TABLE `$t` ADD INDEX idx_{$t}_part (part_id)"); } catch (Throwable $e) {}
            }
        } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/* Active parts for the picker: id, name, unit. Empty when Production is not installed. */
function ll_parts(): array {
    try {
        $rows = db()->query("SELECT id, part_name, uom FROM zp_parts WHERE is_active = 1 ORDER BY part_name LIMIT 1500")->fetchAll();
    } catch (Throwable $e) { return []; }
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['part_name'], 'unit' => (string)($r['uom'] ?: 'Pc')], $rows);
}

/* [line id => part id] for the lines of one document. */
function ll_row_parts(string $table, string $fk, int $docId): array {
    if (!in_array($table, ['shipment_items', 'proforma_items'], true) || !ll_ready()) return [];
    try {
        $st = db()->prepare("SELECT id, part_id FROM `$table` WHERE `$fk` = ? AND part_id IS NOT NULL");
        $st->execute([$docId]);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[(int)$r['id']] = (int)$r['part_id'];
        return $out;
    } catch (Throwable $e) { return []; }
}

/* Set or clear one line's part link. A line linked to a product has no part. */
function ll_set_part(string $table, int $lineId, int $partId, int $productId = 0): void {
    if ($lineId <= 0 || !in_array($table, ['shipment_items', 'proforma_items'], true) || !ll_ready()) return;
    try {
        $v = ($productId > 0 || $partId <= 0) ? null : $partId;
        db()->prepare("UPDATE `$table` SET part_id = ? WHERE id = ?")->execute([$v, $lineId]);
    } catch (Throwable $e) {}
}

/*
 * What this buyer last paid, per product and per part, in this currency.
 * Keys are 'p<product id>' and 'k<part id>'; the newest document wins.
 * Invoices are looked at first, then proformas for anything still missing.
 */
function ll_last_prices(string $buyer, string $currency, int $excludeShipmentId = 0, int $excludeProformaId = 0): array {
    $buyer = trim($buyer); $out = [];
    if ($buyer === '') return $out;
    $part = ll_ready() ? 'si.part_id' : 'NULL';
    try {
        $st = db()->prepare("SELECT si.product_id, $part AS part_id, si.rate FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id
            WHERE LOWER(TRIM(s.buyer_name)) = LOWER(?) AND UPPER(s.currency) = UPPER(?) AND s.id <> ? AND si.rate > 0
              AND (si.product_id IS NOT NULL OR $part IS NOT NULL)
            ORDER BY COALESCE(s.invoice_date, DATE(s.created_at)) DESC, s.id DESC, si.id DESC LIMIT 3000");
        $st->execute([$buyer, $currency, $excludeShipmentId]);
        foreach ($st->fetchAll() as $r) {
            $k = (int)($r['product_id'] ?? 0) > 0 ? 'p' . (int)$r['product_id'] : ((int)($r['part_id'] ?? 0) > 0 ? 'k' . (int)$r['part_id'] : '');
            if ($k !== '' && !isset($out[$k])) $out[$k] = round((float)$r['rate'], 4);
        }
    } catch (Throwable $e) {}
    $part = ll_ready() ? 'pit.part_id' : 'NULL';
    try {
        $st = db()->prepare("SELECT pit.product_id, $part AS part_id, pit.unit_price FROM proforma_items pit JOIN proforma_invoices pf ON pf.id = pit.proforma_id
            WHERE LOWER(TRIM(pf.customer_name)) = LOWER(?) AND UPPER(COALESCE(pf.currency,'USD')) = UPPER(?) AND pf.id <> ? AND pit.unit_price > 0
              AND (pit.product_id IS NOT NULL OR $part IS NOT NULL)
            ORDER BY COALESCE(pf.pi_date, DATE(pf.created_at)) DESC, pf.id DESC, pit.id DESC LIMIT 3000");
        $st->execute([$buyer, $currency, $excludeProformaId]);
        foreach ($st->fetchAll() as $r) {
            $k = (int)($r['product_id'] ?? 0) > 0 ? 'p' . (int)$r['product_id'] : ((int)($r['part_id'] ?? 0) > 0 ? 'k' . (int)$r['part_id'] : '');
            if ($k !== '' && !isset($out[$k])) $out[$k] = round((float)$r['unit_price'], 4);
        }
    } catch (Throwable $e) {}
    return $out;
}

/* The block a page prints so assets/js/linelinks.js can do its work. */
function ll_script(array $cfg): string {
    return '<script>window.ZLL = ' . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>'
         . '<script src="assets/js/linelinks.js?v=1"></script>';
}

/* ---------------------------------------------------------------- link later */

/* Every invoice and proforma line with neither link, newest document first. */
function ll_unlinked(int $limit = 400): array {
    $part = ll_ready();
    $out = [];
    try {
        foreach (db()->query("SELECT si.id, si.product_name, si.qty, si.unit, s.id AS doc_id, s.invoice_no AS doc_no, s.buyer_name AS party, s.status,
                    COALESCE(s.invoice_date, DATE(s.created_at)) AS dt
               FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id
              WHERE si.product_id IS NULL" . ($part ? ' AND si.part_id IS NULL' : '') . " AND TRIM(si.product_name) <> ''
              ORDER BY dt DESC, s.id DESC, si.line_no LIMIT " . (int)$limit)->fetchAll() as $r) {
            $out[] = ['kind' => 'inv', 'id' => (int)$r['id'], 'name' => (string)$r['product_name'], 'qty' => (float)$r['qty'], 'unit' => (string)$r['unit'],
                      'doc_id' => (int)$r['doc_id'], 'doc_no' => (string)$r['doc_no'], 'party' => (string)$r['party'], 'status' => (string)$r['status'], 'dt' => (string)$r['dt']];
        }
    } catch (Throwable $e) {}
    try {
        foreach (db()->query("SELECT pit.id, pit.product_name, pit.qty, pit.unit, pf.id AS doc_id, pf.pi_no AS doc_no, pf.customer_name AS party, pf.status,
                    COALESCE(pf.pi_date, DATE(pf.created_at)) AS dt
               FROM proforma_items pit JOIN proforma_invoices pf ON pf.id = pit.proforma_id
              WHERE pit.product_id IS NULL" . ($part ? ' AND pit.part_id IS NULL' : '') . " AND TRIM(COALESCE(pit.product_name,'')) <> ''
              ORDER BY dt DESC, pf.id DESC, pit.sort_order LIMIT " . (int)$limit)->fetchAll() as $r) {
            $out[] = ['kind' => 'pf', 'id' => (int)$r['id'], 'name' => (string)$r['product_name'], 'qty' => (float)$r['qty'], 'unit' => (string)$r['unit'],
                      'doc_id' => (int)$r['doc_id'], 'doc_no' => (string)$r['doc_no'], 'party' => (string)$r['party'], 'status' => (string)$r['status'], 'dt' => (string)$r['dt']];
        }
    } catch (Throwable $e) {}
    usort($out, fn($a, $b) => strcmp($b['dt'], $a['dt']) ?: ($b['doc_id'] <=> $a['doc_id']));
    return $out;
}

/*
 * The best guess for a typed name among products and parts: an exact match on
 * letters and digits, else the closest by similar_text at 72% or better.
 * Returns ['p'|'k', id, name] or null. A guess is only ever OFFERED on screen;
 * nothing is linked until a person presses Link.
 */
function ll_suggest(string $typed, array $products, array $parts): ?array {
    $n = lov_norm($typed);
    if ($n === '') return null;
    $best = null; $bestPct = 0.0;
    foreach ([['p', $products], ['k', $parts]] as [$kind, $list]) {
        foreach ($list as $row) {
            $m = lov_norm((string)$row['name']);
            if ($m === '') continue;
            if ($m === $n) return [$kind, (int)$row['id'], (string)$row['name']];
            similar_text($n, $m, $pct);
            if (str_contains($n, $m) && strlen($m) >= 5) $pct = max($pct, 80.0);       // "thermal blanket 150x200" contains "thermal blanket"
            if ($pct > $bestPct) { $bestPct = $pct; $best = [$kind, (int)$row['id'], (string)$row['name']]; }
        }
    }
    return $bestPct >= 72.0 ? $best : null;
}

/*
 * Link one line. $kind 'inv' or 'pf'; $target 'p<id>' (product) or 'k<id>' (part).
 * Only a line that is still unlinked is changed, so two people working the
 * list at once cannot overwrite each other.
 */
function ll_link(string $kind, int $lineId, string $target): array {
    $table = $kind === 'inv' ? 'shipment_items' : ($kind === 'pf' ? 'proforma_items' : '');
    if ($table === '' || $lineId <= 0 || !preg_match('/^([pk])(\d+)$/', $target, $m)) return ['ok' => false, 'error' => 'Choose a product or a part.'];
    $id = (int)$m[2];
    try {
        if ($m[1] === 'p') {
            $st = db()->prepare("SELECT name FROM products WHERE id = ? AND is_active = 1"); $st->execute([$id]);
            $name = $st->fetchColumn();
            if ($name === false) return ['ok' => false, 'error' => 'That product is no longer in the Product Master.'];
            $u = db()->prepare("UPDATE `$table` SET product_id = ? WHERE id = ? AND product_id IS NULL");
            $u->execute([$id, $lineId]);
        } else {
            if (!ll_ready()) return ['ok' => false, 'error' => 'Part links are not available on this server yet.'];
            $st = db()->prepare("SELECT part_name FROM zp_parts WHERE id = ? AND is_active = 1"); $st->execute([$id]);
            $name = $st->fetchColumn();
            if ($name === false) return ['ok' => false, 'error' => 'That part is no longer in Production › Parts.'];
            $u = db()->prepare("UPDATE `$table` SET part_id = ? WHERE id = ? AND product_id IS NULL AND part_id IS NULL");
            $u->execute([$id, $lineId]);
        }
        return ['ok' => true, 'changed' => $u->rowCount() > 0, 'name' => (string)$name];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not link that line.'];
    }
}
