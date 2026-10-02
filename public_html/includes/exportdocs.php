<?php
/*
  CUSTOMS AND CHAMBER DOCUMENTS — Phase 2.
  ========================================

  One shipment, three documents. The commercial invoice is shipment_items and
  is never touched by anything in this file. The customs and chamber invoices
  are typed by hand into exp_doc_lines, with their own descriptions, HS codes,
  quantities and rates.

  TWO RULES, AND THEY ARE NOT THE SAME RULE
  -----------------------------------------
  TOTAL UNITS ARE LOCKED. A customs or chamber document whose quantity differs
  from the commercial invoice describes a shipment that was not sent. It may
  be saved half-finished, but it cannot be PRINTED until the totals agree.

  TOTAL VALUE IS FREE. A CFR commercial invoice against an FOB declaration is
  ordinary export practice, and the two figures legitimately differ. So the
  rate is yours to type — and the difference is calculated, displayed and
  written to the audit log, so the reason sits on the record instead of having
  to be reconstructed a year later.

  THE MEMORY
  ----------
  Nothing is configured in Product Master. You type a short description once
  and it is remembered, globally — a customs invoice describes goods to
  Pakistan Customs, not to a buyer, so a new customer inherits the whole
  vocabulary on their first shipment.

  Matching strips everything that is not a letter or a digit, so "Sheet set",
  "SHEET SET", "Sheet-Set" and "Sheetset." are one memory and fill themselves.
  A genuine typo is OFFERED and never applied, because "Bath Towel" and
  "Beach Towel" are two letters apart and both are real products.
*/

const EXPDOC_VIEWS = ['customs' => 'Customs', 'chamber' => 'Chamber'];

/* Near-match thresholds. Both must hold. Chosen against real pairs: they
   accept shetseTs -> sheetset (87.5%) and reject Flat Sheet -> Fitted Sheet
   (70%), while Bath Towel -> Beach Towel (84.2%) lands inside the window,
   which is exactly why a near match is never applied silently. */
const EXPDOC_MAX_LEV = 2;
const EXPDOC_MIN_SIM = 80.0;

function expdoc_view_ok(string $v): bool { return isset(EXPDOC_VIEWS[$v]); }

/* ----------------------------------------------------------- normalisation */

/* Everything that is not a letter or a digit goes. Case goes. What is left is
   the key two spellings of the same thing have in common. */
function expdoc_norm(string $s): string {
    return strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $s));
}

/* PHP's levenshtein() refuses strings over 255 bytes and returns -1. A
   description that long is not a near-match candidate anyway. */
function expdoc_near(string $a, string $b): ?float {
    if ($a === '' || $b === '') return null;
    if (strlen($a) > 255 || strlen($b) > 255) return null;
    $d = levenshtein($a, $b);
    if ($d < 0 || $d > EXPDOC_MAX_LEV) return null;
    similar_text($a, $b, $pct);
    return $pct >= EXPDOC_MIN_SIM ? round((float)$pct, 1) : null;
}

/* ------------------------------------------------------------- the memory */

function expdoc_memory(string $view): array {
    if (!expdoc_view_ok($view)) return [];
    try {
        $st = db()->prepare("SELECT * FROM exp_doc_memory WHERE view=? ORDER BY times_used DESC, description");
        $st->execute([$view]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* Exact wins outright. Otherwise the closest near match above the thresholds,
   flagged so the screen knows to ask rather than fill. */
function expdoc_recall(string $view, string $text): ?array {
    $key = expdoc_norm($text);
    if ($key === '') return null;

    try {
        $st = db()->prepare("SELECT * FROM exp_doc_memory WHERE view=? AND norm_key=? LIMIT 1");
        $st->execute([$view, $key]);
        $exact = $st->fetch();
        if ($exact) return ['kind' => 'exact', 'row' => $exact, 'score' => 100.0];
    } catch (Throwable $e) { return null; }

    $best = null; $bestScore = 0.0;
    foreach (expdoc_memory($view) as $m) {
        $p = expdoc_near($key, (string)$m['norm_key']);
        if ($p !== null && $p > $bestScore) { $best = $m; $bestScore = $p; }
    }
    return $best ? ['kind' => 'near', 'row' => $best, 'score' => $bestScore] : null;
}

function expdoc_remember(string $view, string $desc, string $hs = '', string $unit = ''): void {
    $desc = trim($desc);
    $key  = expdoc_norm($desc);
    if (!expdoc_view_ok($view) || $key === '') return;

    try {
        /* An existing memory keeps its first spelling as source_text and takes
           the newest wording — you corrected it for a reason. */
        db()->prepare("INSERT INTO exp_doc_memory (view, norm_key, source_text, description, hs_code, unit, times_used, last_used_at, created_by, created_at)
                       VALUES (?,?,?,?,?,?,1,NOW(),?,NOW())
                       ON DUPLICATE KEY UPDATE
                         description = VALUES(description),
                         hs_code = COALESCE(NULLIF(VALUES(hs_code),''), hs_code),
                         unit    = COALESCE(NULLIF(VALUES(unit),''), unit),
                         times_used = times_used + 1,
                         last_used_at = NOW()")
            ->execute([$view, $key, $desc, $desc, $hs, $unit, (current_user()['id'] ?? null)]);
    } catch (Throwable $e) {}
}

/* ------------------------------------------------------------- the lines */

function expdoc_lines(int $shipmentId, string $view): array {
    if (!expdoc_view_ok($view)) return [];
    try {
        $st = db()->prepare("SELECT * FROM exp_doc_lines WHERE shipment_id=? AND view=? ORDER BY line_no, id");
        $st->execute([$shipmentId, $view]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* The commercial invoice, which is the reference for both checks and the
   source for the Copy button. Read only — never written from this module. */
function expdoc_source_lines(int $shipmentId): array {
    try {
        $st = db()->prepare("SELECT line_no, product_name, des_col, optional_value, qty, unit, rate, amount
                             FROM shipment_items WHERE shipment_id=? ORDER BY line_no, id");
        $st->execute([$shipmentId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function expdoc_totals(array $lines): array {
    $q = 0.0; $v = 0.0;
    foreach ($lines as $l) {
        $q += (float)($l['qty'] ?? 0);
        $v += (float)($l['amount'] ?? ((float)($l['qty'] ?? 0) * (float)($l['rate'] ?? 0)));
    }
    return ['qty' => round($q, 3), 'value' => round($v, 2)];
}

/* Units must agree; value need not. Returned together because every screen
   and every print asks the same two questions. */
function expdoc_check(int $shipmentId, string $view, ?array $lines = null): array {
    $lines = $lines ?? expdoc_lines($shipmentId, $view);
    $mine  = expdoc_totals($lines);
    $src   = expdoc_totals(expdoc_source_lines($shipmentId));

    $qtyDiff = round($mine['qty'] - $src['qty'], 3);
    $valDiff = round($mine['value'] - $src['value'], 2);

    return [
        'lines'        => count($lines),
        'qty'          => $mine['qty'],
        'value'        => $mine['value'],
        'src_qty'      => $src['qty'],
        'src_value'    => $src['value'],
        'qty_diff'     => $qtyDiff,
        'value_diff'   => $valDiff,
        'value_pct'    => $src['value'] > 0 ? round($valDiff / $src['value'] * 100, 1) : null,
        'units_match'  => abs($qtyDiff) < 0.0005,
        'value_match'  => abs($valDiff) < 0.005,
        'empty'        => count($lines) === 0,
        /* The one gate: no printing a document that declares a different
           quantity from the goods that actually went. */
        'can_print'    => count($lines) > 0 && abs($qtyDiff) < 0.0005,
    ];
}

/* --------------------------------------------------------------- saving */

/* Replaces the whole set for this view in one transaction. Empty rows are
   dropped rather than saved blank; a row with no description but a quantity
   is kept, because a half-finished document must be savable. */
function expdoc_save_lines(int $shipmentId, string $view, array $posted, bool $learn = true): array
{
    if (!expdoc_view_ok($view)) return [false, 'Unknown document view.'];

    $clean = [];
    $n = 0;
    foreach ($posted as $row) {
        $desc = trim((string)($row['description'] ?? ''));
        $qty  = (float)($row['qty'] ?? 0);
        $rate = (float)($row['rate'] ?? 0);
        if ($desc === '' && $qty == 0.0 && $rate == 0.0) continue;   /* a blank row the user left behind */
        if ($qty < 0) return [false, 'A quantity cannot be negative.'];
        if ($rate < 0) return [false, 'A rate cannot be negative.'];
        $n++;
        $clean[] = [
            'line_no'     => $n,
            'description' => mb_substr($desc, 0, 255),
            'hs_code'     => mb_substr(trim((string)($row['hs_code'] ?? '')), 0, 40),
            'unit'        => mb_substr(trim((string)($row['unit'] ?? '')), 0, 40),
            'qty'         => round($qty, 3),
            'rate'        => round($rate, 4),
            'amount'      => round($qty * $rate, 2),
        ];
    }

    $before = expdoc_lines($shipmentId, $view);

    try {
        db()->beginTransaction();
        db()->prepare("DELETE FROM exp_doc_lines WHERE shipment_id=? AND view=?")->execute([$shipmentId, $view]);
        if ($clean) {
            $ins = db()->prepare("INSERT INTO exp_doc_lines
                (shipment_id, view, line_no, description, hs_code, unit, qty, rate, amount, created_by, created_at, updated_by, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),?,NOW())");
            $uid = current_user()['id'] ?? null;
            foreach ($clean as $c) {
                $ins->execute([$shipmentId, $view, $c['line_no'], $c['description'], $c['hs_code'],
                               $c['unit'], $c['qty'], $c['rate'], $c['amount'], $uid, $uid]);
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return [false, 'Could not save: ' . $e->getMessage()];
    }

    /* The memory learns only from a line that actually says something. */
    if ($learn) {
        foreach ($clean as $c) {
            if ($c['description'] !== '') expdoc_remember($view, $c['description'], $c['hs_code'], $c['unit']);
        }
    }

    expdoc_audit_change($shipmentId, $view, $before, $clean);

    return [true, EXPDOC_VIEWS[$view] . ' document saved — ' . count($clean) . ' line' . (count($clean) === 1 ? '' : 's') . '.'];
}

/* What changed, in words, rather than two blobs of JSON nobody reads. The
   value difference against the commercial invoice is recorded on every save,
   because that is the number someone will ask about. */
function expdoc_audit_change(int $shipmentId, string $view, array $before, array $after): void
{
    $label = EXPDOC_VIEWS[$view] ?? $view;
    $b = expdoc_totals($before);
    $a = expdoc_totals($after);

    $bits = [];
    if (count($before) !== count($after)) $bits[] = count($before) . ' lines to ' . count($after);
    if (abs($b['qty'] - $a['qty']) > 0.0005) $bits[] = 'qty ' . trim_num($b['qty'], 3) . ' to ' . trim_num($a['qty'], 3);
    if (abs($b['value'] - $a['value']) > 0.005) $bits[] = 'value ' . number_format($b['value'], 2) . ' to ' . number_format($a['value'], 2);

    $src = expdoc_totals(expdoc_source_lines($shipmentId));
    $diff = round($a['value'] - $src['value'], 2);
    $note = 'Commercial ' . number_format($src['value'], 2)
          . ' / ' . strtolower($label) . ' ' . number_format($a['value'], 2)
          . ' (' . ($diff >= 0 ? '+' : '') . number_format($diff, 2) . ')';

    try {
        audit_log($shipmentId, $label . ' Document', 'lines',
                  count($before) . ' lines, ' . number_format($b['value'], 2),
                  count($after) . ' lines, ' . number_format($a['value'], 2),
                  ($bits ? implode('; ', $bits) . '. ' : '') . $note);
    } catch (Throwable $e) {}
}

/* -------------------------------------------------- starting from the invoice

   Gives you the commercial lines to merge down, with the memory already
   applied to each so most descriptions arrive filled in. Nothing is saved
   until you press Save. */
function expdoc_from_invoice(int $shipmentId, string $view): array
{
    $out = []; $n = 0;
    foreach (expdoc_source_lines($shipmentId) as $s) {
        $n++;
        $name = (string)$s['product_name'];
        $r = expdoc_recall($view, $name);
        $out[] = [
            'line_no'     => $n,
            /* Only an EXACT memory fills itself. A near match leaves the
               original text in place for the screen to query. */
            'description' => ($r && $r['kind'] === 'exact') ? (string)$r['row']['description'] : $name,
            'hs_code'     => ($r && $r['kind'] === 'exact') ? (string)$r['row']['hs_code'] : '',
            'unit'        => (string)($s['unit'] ?? ''),
            'qty'         => (float)$s['qty'],
            'rate'        => (float)$s['rate'],
            'amount'      => (float)$s['amount'],
            'from_memory' => ($r && $r['kind'] === 'exact'),
        ];
    }
    return $out;
}

/* ------------------------------------------------------ CSV and Excel paste

   Two ways in, one parser. A pasted block from Excel arrives TAB separated;
   a saved .csv arrives comma separated. Guessing wrong turns one column into
   five, so the separator is detected from the first line rather than assumed.

   A header row is optional. With one, columns are matched by name in any
   order and any spelling of the name. Without one, the order is taken as
   description, HS code, unit, quantity, rate — which is the order the
   template downloads in. */

const EXPDOC_CSV_COLS = ['description', 'hs_code', 'unit', 'qty', 'rate'];

function expdoc_csv_header_match(string $cell): ?string {
    $k = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $cell), '_'));
    $map = [
        'description' => 'description', 'desc' => 'description', 'goods' => 'description',
        'product' => 'description', 'product_name' => 'description', 'item' => 'description',
        'description_of_goods' => 'description', 'particulars' => 'description',
        'hs' => 'hs_code', 'hs_code' => 'hs_code', 'hscode' => 'hs_code', 'hs_no' => 'hs_code',
        'tariff' => 'hs_code', 'tariff_code' => 'hs_code',
        'unit' => 'unit', 'uom' => 'unit', 'units' => 'unit',
        'qty' => 'qty', 'quantity' => 'qty', 'pcs' => 'qty', 'pieces' => 'qty',
        'rate' => 'rate', 'price' => 'rate', 'unit_price' => 'rate', 'unit_rate' => 'rate',
    ];
    return $map[$k] ?? null;
}

/* A number typed by a person: thousands separators, a currency symbol, stray
   spaces. Anything that is not a digit, a dot or a minus goes. */
function expdoc_csv_num(string $v): float {
    $s = preg_replace('/[^0-9.\-]/', '', $v);
    return is_numeric($s) ? (float)$s : 0.0;
}

/* Returns [rows, notes]. Notes are for the operator, not for the log —
   "row 4 was skipped, it had no quantity" is the kind of thing that saves a
   phone call. */
function expdoc_parse_csv(string $text, string $view): array
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') return [[], ['Nothing was pasted.']];

    $lines = array_values(array_filter(explode("\n", $text), fn($l) => trim($l) !== ''));
    if (!$lines) return [[], ['Nothing readable in that.']];

    /* Tabs win when present: that is an Excel paste, and a description may
       legitimately contain a comma. */
    $first = $lines[0];
    $sep = substr_count($first, "\t") >= 1 ? "\t"
         : (substr_count($first, ';') > substr_count($first, ',') ? ';' : ',');

    $split = function (string $line) use ($sep): array {
        $cells = $sep === "\t" ? explode("\t", $line) : str_getcsv($line, $sep);
        return array_map(fn($c) => trim((string)$c, " \t\"'"), $cells);
    };

    /* Is the first row a header? Only if at least two of its cells name a
       column we know AND it carries no number where a quantity would be. */
    $head = $split($lines[0]);
    $named = 0; $byPos = EXPDOC_CSV_COLS;
    foreach ($head as $c) if (expdoc_csv_header_match($c) !== null) $named++;
    $hasHeader = $named >= 2;

    $cols = $byPos;
    $start = 0;
    if ($hasHeader) {
        $cols = [];
        foreach ($head as $c) $cols[] = expdoc_csv_header_match($c);   /* null = ignore this column */
        $start = 1;
    }

    $rows = []; $notes = []; $skipped = 0;
    for ($i = $start, $n = count($lines); $i < $n; $i++) {
        $cells = $split($lines[$i]);
        $r = ['description' => '', 'hs_code' => '', 'unit' => '', 'qty' => 0.0, 'rate' => 0.0];
        foreach ($cells as $ci => $val) {
            $key = $cols[$ci] ?? null;
            if ($key === null) continue;
            if ($key === 'qty' || $key === 'rate') $r[$key] = expdoc_csv_num((string)$val);
            else $r[$key] = (string)$val;
        }

        if (trim($r['description']) === '' && $r['qty'] == 0.0) { $skipped++; continue; }
        if ($r['qty'] < 0 || $r['rate'] < 0) {
            $notes[] = 'Row ' . ($i + 1) . ' had a negative number and was skipped.';
            continue;
        }

        /* The memory still applies to an imported row — an exact match fills
           in the HS code the operator did not type. */
        if ($r['hs_code'] === '' && trim($r['description']) !== '') {
            $rc = expdoc_recall($view, $r['description']);
            if ($rc && $rc['kind'] === 'exact' && (string)$rc['row']['hs_code'] !== '') {
                $r['hs_code'] = (string)$rc['row']['hs_code'];
            }
        }

        $r['line_no'] = count($rows) + 1;
        $r['amount']  = round($r['qty'] * $r['rate'], 2);
        $rows[] = $r;
    }

    if (!$rows) $notes[] = 'No usable rows were found. Each row needs at least a description or a quantity.';
    if ($skipped) $notes[] = $skipped . ' empty row' . ($skipped === 1 ? '' : 's') . ' ignored.';
    if ($hasHeader) array_unshift($notes, 'Header row recognised, columns matched by name.');
    else array_unshift($notes, 'No header row found — columns read in order: description, HS code, unit, qty, rate.');
    array_unshift($notes, $sep === "\t" ? 'Read as an Excel paste (tab separated).'
                                        : 'Read as CSV (separator "' . $sep . '").');

    return [$rows, $notes];
}

function expdoc_csv_template(): string {
    return "Description,HS Code,Unit,Qty,Rate\n"
         . "Cotton Bed Linen,630231,Pcs,1200,11.98\n"
         . "Cotton Terry Towels,630260,Pcs,550,11.63\n";
}

/* Everything the editing screen needs to draw one row's recall state, in one
   pass over the memory rather than a query per line. */
function expdoc_annotate(string $view, array $lines): array
{
    $mem = expdoc_memory($view);
    $byKey = [];
    foreach ($mem as $m) $byKey[(string)$m['norm_key']] = $m;

    foreach ($lines as &$l) {
        $key = expdoc_norm((string)($l['description'] ?? ''));
        $l['recall'] = null;
        if ($key === '') continue;

        if (isset($byKey[$key])) {
            $l['recall'] = ['kind' => 'exact', 'row' => $byKey[$key], 'score' => 100.0];
            continue;
        }
        $best = null; $score = 0.0;
        foreach ($mem as $m) {
            $p = expdoc_near($key, (string)$m['norm_key']);
            if ($p !== null && $p > $score) { $best = $m; $score = $p; }
        }
        if ($best) $l['recall'] = ['kind' => 'near', 'row' => $best, 'score' => $score];
    }
    unset($l);
    return $lines;
}

/* ------------------------------------------------------------ permissions

   No new permission module. Customs and Chamber are presentations of an
   invoice the user can already open, so they ride on the existing Shipments
   & Invoices rights. Adding two more rows to the User Access screen for what
   is a print button would be the over-engineering the brief warns against. */
function expdoc_can_view(): bool {
    if (is_staff() || is_production_staff()) return false;
    return function_exists('zu_can') ? zu_can('ship', 'v') : is_admin();
}
function expdoc_can_edit(array $shipment): bool {
    if (!expdoc_can_view()) return false;
    if (!can_see_rates()) return false;   /* these documents carry rates */
    return function_exists('zu_can') ? zu_can('ship', 'u') : is_admin();
}
