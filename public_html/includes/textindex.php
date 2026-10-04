<?php
/*
  ###################################################################
  #  UNFINISHED. NOTHING LOADS THIS FILE YET, AND NOTHING SHOULD    #
  #  UNTIL THE LIST BELOW IS DONE. It is committed so the work is   #
  #  not lost, not because it is ready.                             #
  #                                                                 #
  #  STILL TO DO:                                                   #
  #   - the exp_text_index table (schema 5 -> 6 in export.php)      #
  #   - exp_document_bytes() in storage.php, which this calls and   #
  #     which does not exist yet                                    #
  #   - the fallback route in search.php                            #
  #   - the Rebuild screen in exp_settings.php                      #
  #   - the index hooks on the six save paths                       #
  #   - extraction status on the Documents tab                      #
  #   - tests                                                       #
  ###################################################################

  TEXT SEARCH — Phase 3b.

  Phase 3a answers a question when it can be turned into filters. When it
  cannot, it said "not understood" and stopped. This fills that dead end:
  the same words are looked for in the text itself.

  ------------------------------------------------------------------ the trap

  MySQL's full-text index ignores any word shorter than innodb_ft_min_token_size,
  which is three by default and lives in my.cnf — a file you cannot edit on
  shared hosting. So a pure full-text build silently cannot find TT, LC, DP or
  BL, which are four of the terms used most often in this business. It would
  look like it worked.

  So every search runs both: full-text for the words long enough to be
  indexed, and a direct match for the ones that are not. One query, one set of
  results, and you never see the seam.

  ------------------------------------------------------- one table, not seven

  Everything searchable becomes a row in exp_text_index: a shipment and its
  items, a proforma, a costing, a payment note, a cost note, a logistics
  event, and the text read out of an uploaded document. One table means one
  index and one query, rather than seven searches stitched together and sorted
  in PHP.

  ------------------------------------------------------------- and the rules

  A row carries the shipment it belongs to, so results pass the same
  visibility check as every other screen. A row holding money carries a flag,
  so a user without rate visibility cannot read a cost note through a search
  box when the Costs tab is closed to them. SEARCH NEVER SHOWS SOMEONE A
  RECORD THEY COULD NOT ALREADY OPEN.
*/

require_once __DIR__ . '/doctext.php';

const TXT_LIMIT     = 60;     /* results returned */
const TXT_SNIPPET   = 190;    /* characters of context around a hit */
const TXT_MIN_FT    = 3;      /* MySQL will not index anything shorter */
const TXT_BODY_MAX  = 200000;

/* Every kind of thing that can be indexed, and how to describe it. */
const TXT_SOURCES = [
    'shipment'  => 'Commercial Invoice',
    'proforma'  => 'Proforma Invoice',
    'costing'   => 'Costing',
    'document'  => 'Document',
    'legacy'    => 'Attachment',
    'payment'   => 'Payment',
    'cost'      => 'Shipment Cost',
    'logistics' => 'Logistics',
    'provider'  => 'Service Provider',
];

/* ======================================================= writing the index */

/* One row in, one row out. Keyed on (source, source_id) so re-indexing the
   same record replaces it instead of piling up duplicates. */
function txt_put(string $source, int $sourceId, array $r): void
{
    if (!isset(TXT_SOURCES[$source]) || $sourceId <= 0) return;
    $body = (string)($r['body'] ?? '');
    if (strlen($body) > TXT_BODY_MAX) $body = mb_substr($body, 0, TXT_BODY_MAX, 'UTF-8');

    try {
        db()->prepare(
            "INSERT INTO exp_text_index
                (source, source_id, shipment_id, rate_sensitive, title, subtitle, body,
                 extract_status, extract_note, chars, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE
                shipment_id=VALUES(shipment_id), rate_sensitive=VALUES(rate_sensitive),
                title=VALUES(title), subtitle=VALUES(subtitle), body=VALUES(body),
                extract_status=VALUES(extract_status), extract_note=VALUES(extract_note),
                chars=VALUES(chars), updated_at=NOW()"
        )->execute([
            $source, $sourceId,
            isset($r['shipment_id']) && $r['shipment_id'] ? (int)$r['shipment_id'] : null,
            !empty($r['rate_sensitive']) ? 1 : 0,
            mb_substr((string)($r['title'] ?? ''), 0, 250),
            mb_substr((string)($r['subtitle'] ?? ''), 0, 250),
            $body,
            $r['extract_status'] ?? null,
            isset($r['extract_note']) ? mb_substr((string)$r['extract_note'], 0, 250) : null,
            strlen($body),
        ]);
    } catch (Throwable $e) { /* the index is never allowed to break a save */ }
}

function txt_drop(string $source, int $sourceId): void {
    try { db()->prepare("DELETE FROM exp_text_index WHERE source=? AND source_id=?")->execute([$source, $sourceId]); }
    catch (Throwable $e) {}
}

/* Join the pieces of a record into one searchable body, dropping the empties
   so a row is not mostly separators. */
function txt_join(array $parts): string {
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p !== '') $out[] = $p;
    }
    return doctext_clean(implode(' · ', $out));
}

/* ------------------------------------------------------------- a shipment

   The invoice header, its line items, and the free text nobody could search
   before: terms, addresses, the optional column's own title. */
function txt_index_shipment(int $id): void
{
    try {
        $st = db()->prepare("SELECT * FROM shipments WHERE id=?");
        $st->execute([$id]); $s = $st->fetch();
        if (!$s) { txt_drop('shipment', $id); return; }

        $items = [];
        $it = db()->prepare("SELECT product_name, des_col, unit FROM shipment_items WHERE shipment_id=? ORDER BY line_no");
        $it->execute([$id]);
        foreach ($it->fetchAll() as $r) $items[] = txt_join([$r['product_name'] ?? '', $r['des_col'] ?? '', $r['unit'] ?? '']);

        txt_put('shipment', $id, [
            'shipment_id' => $id,
            'title'       => (string)($s['invoice_no'] ?? ''),
            'subtitle'    => trim((string)($s['buyer_name'] ?? '')),
            'body'        => txt_join(array_merge([
                $s['invoice_no'] ?? '', $s['buyer_name'] ?? '', $s['buyer_address'] ?? '',
                $s['buyer_country'] ?? '', $s['destination_port'] ?? '', $s['po_no'] ?? '',
                $s['bl_container_no'] ?? '', $s['payment_terms'] ?? '',
                $s['optional_column_title'] ?? '', $s['logistics_status'] ?? '',
            ], $items)),
        ]);
    } catch (Throwable $e) {}
}

function txt_index_proforma(int $id): void
{
    try {
        $st = db()->prepare("SELECT * FROM proforma_invoices WHERE id=?");
        $st->execute([$id]); $p = $st->fetch();
        if (!$p) { txt_drop('proforma', $id); return; }

        $items = [];
        $it = db()->prepare("SELECT product_name, description, size, unit FROM proforma_items WHERE proforma_id=? ORDER BY sort_order");
        $it->execute([$id]);
        foreach ($it->fetchAll() as $r) $items[] = txt_join([$r['product_name'] ?? '', $r['description'] ?? '', $r['size'] ?? '', $r['unit'] ?? '']);

        txt_put('proforma', $id, [
            'title'    => (string)($p['pi_no'] ?? ''),
            'subtitle' => trim((string)($p['customer_name'] ?? '')),
            'body'     => txt_join(array_merge([
                $p['pi_no'] ?? '', $p['customer_name'] ?? '', $p['customer_address'] ?? '',
                $p['payment_terms'] ?? '', $p['delivery_terms'] ?? '', $p['shipment_terms'] ?? '',
                $p['packing_details'] ?? '', $p['validity'] ?? '', $p['remarks'] ?? '',
                $p['delivery_date'] ?? '',
            ], $items)),
        ]);
    } catch (Throwable $e) {}
}

function txt_index_costing(int $id): void
{
    try {
        $st = db()->prepare("SELECT cv.*, p.name AS pname, p.product_code AS pcode
                             FROM costing_versions cv LEFT JOIN products p ON p.id=cv.product_id WHERE cv.id=?");
        $st->execute([$id]); $c = $st->fetch();
        if (!$c) { txt_drop('costing', $id); return; }

        txt_put('costing', $id, [
            /* A costing carries the selling price, so it is only shown to
               someone allowed to see rates. */
            'rate_sensitive' => true,
            'title'    => (string)($c['costing_no'] ?? ''),
            'subtitle' => trim((string)($c['pname'] ?? '')),
            'body'     => txt_join([
                $c['costing_no'] ?? '', $c['version_name'] ?? '', $c['pname'] ?? '',
                $c['pcode'] ?? '', $c['remarks'] ?? '', $c['notes'] ?? '', $c['status'] ?? '',
            ]),
        ]);
    } catch (Throwable $e) {}
}

/* The notes on a shipment's money and movement. These are the ones that were
   least findable before: a reason typed into a payment nine months ago was
   effectively gone. */
function txt_index_shipment_notes(int $shipmentId): void
{
    try {
        $inv = '';
        $s = db()->prepare("SELECT invoice_no FROM shipments WHERE id=?");
        $s->execute([$shipmentId]); $inv = (string)($s->fetchColumn() ?: '');

        $sets = [
            ['payment',   "SELECT id, reference AS a, notes AS b, void_reason AS c FROM exp_payments WHERE shipment_id=?",            true],
            ['cost',      "SELECT id, bill_no AS a, notes AS b, void_reason AS c FROM exp_costs WHERE shipment_id=?",                 true],
            ['logistics', "SELECT id, vessel_name AS a, notes AS b, bl_no AS c, voyage_no AS d, freight_quote_ref AS e,
                                  last_event AS f, last_location AS g FROM exp_logistics WHERE shipment_id=?",                        false],
        ];
        foreach ($sets as [$src, $sql, $sensitive]) {
            $st = db()->prepare($sql);
            $st->execute([$shipmentId]);
            foreach ($st->fetchAll() as $r) {
                $body = txt_join([$r['a'] ?? '', $r['b'] ?? '', $r['c'] ?? '',
                                  $r['d'] ?? '', $r['e'] ?? '', $r['f'] ?? '', $r['g'] ?? '']);
                if ($body === '') { txt_drop($src, (int)$r['id']); continue; }
                txt_put($src, (int)$r['id'], [
                    'shipment_id'    => $shipmentId,
                    'rate_sensitive' => $sensitive,
                    'title'          => TXT_SOURCES[$src] . ($inv !== '' ? ' on ' . $inv : ''),
                    'subtitle'       => mb_substr($body, 0, 120),
                    'body'           => txt_join([$inv, $body]),
                ]);
            }
        }
    } catch (Throwable $e) {}
}

/* ------------------------------------------------------------- a document

   The bytes are fetched once, read once, and the result stored. Searching
   never opens a file. */
function txt_index_document(int $docId): array
{
    try {
        $st = db()->prepare("SELECT d.*, s.invoice_no FROM exp_documents d
                             LEFT JOIN shipments s ON s.id = d.shipment_id WHERE d.id=?");
        $st->execute([$docId]); $d = $st->fetch();
        if (!$d) { txt_drop('document', $docId); return ['status' => 'error', 'note' => 'Document not found.']; }

        $name  = (string)($d['original_name'] ?? '');
        $inv   = (string)($d['invoice_no'] ?? '');
        $label = txt_join([$d['doc_no'] ?? '', $name]);

        /* A type that can never hold text is recorded as such without being
           fetched. Downloading a 40 MB scan to discover it is a scan costs a
           round trip to R2 on every rebuild and tells us nothing new. */
        if (!doctext_worth_reading($name)) {
            $r = doctext_read('', $name);
            $r = ['text' => '', 'status' => $r['status'] === 'empty' ? 'picture' : $r['status'], 'note' => $r['note']];
        } else {
            $bytes = null;
            try { $bytes = exp_document_bytes($d); } catch (Throwable $e) { $bytes = null; }
            $r = $bytes === null
               ? ['text' => '', 'status' => 'error', 'note' => 'The stored file could not be fetched.']
               : doctext_read($bytes, $name);
        }

        txt_put('document', $docId, [
            'shipment_id'    => (int)$d['shipment_id'],
            'title'          => $label !== '' ? $label : 'Document',
            'subtitle'       => $inv,
            'body'           => txt_join([$label, $inv, $d['notes'] ?? '', $r['text']]),
            'extract_status' => $r['status'],
            'extract_note'   => $r['note'],
        ]);
        return $r;
    } catch (Throwable $e) {
        return ['status' => 'error', 'note' => 'The document could not be indexed.'];
    }
}

/* What the extractor found for a document, for the Documents tab to show. */
function txt_document_status(array $docIds): array
{
    if (!$docIds) return [];
    try {
        $in = implode(',', array_fill(0, count($docIds), '?'));
        $st = db()->prepare("SELECT source_id, extract_status, extract_note, chars
                             FROM exp_text_index WHERE source='document' AND source_id IN ($in)");
        $st->execute(array_map('intval', $docIds));
        $out = [];
        foreach ($st->fetchAll() as $r) $out[(int)$r['source_id']] = $r;
        return $out;
    } catch (Throwable $e) { return []; }
}

/* ========================================================== reading it back */

/* Split what was typed into terms, and separate the ones MySQL can index
   from the ones it cannot. Boolean-mode operators are stripped: a stray
   + or * or " from a pasted reference is a syntax error, not a search. */
function txt_terms(string $q): array
{
    $q = mb_strtolower(trim($q));
    $q = (string)preg_replace('~[+\-><()~*"@]~', ' ', $q);
    $raw = preg_split('~[^\p{L}\p{N}/._]+~u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $long = []; $short = [];
    foreach ($raw as $t) {
        $t = trim($t, './_');
        if ($t === '') continue;
        if (mb_strlen($t) >= TXT_MIN_FT) $long[] = $t; else $short[] = $t;
    }
    return ['long' => array_values(array_unique($long)),
            'short' => array_values(array_unique($short)),
            'all' => array_values(array_unique(array_merge($long, $short)))];
}

/* The sentence the word was found in, with the word marked.
 *
 * Returned as an array of [text, isHit] pairs rather than as HTML, so the
 * page escapes it. Building highlight markup in here would mean a document's
 * own contents could put tags on your screen. */
function txt_snippet(string $body, array $terms): array
{
    $body = trim($body);
    if ($body === '') return [];

    $at = null; $hit = '';
    foreach ($terms as $t) {
        $p = mb_stripos($body, $t);
        if ($p !== false && ($at === null || $p < $at)) { $at = $p; $hit = $t; }
    }
    if ($at === null) {
        return [[mb_substr($body, 0, TXT_SNIPPET) . (mb_strlen($body) > TXT_SNIPPET ? '…' : ''), false]];
    }

    $start = max(0, $at - (int)(TXT_SNIPPET / 3));
    $piece = mb_substr($body, $start, TXT_SNIPPET);
    if ($start > 0) $piece = '…' . $piece;
    if ($start + TXT_SNIPPET < mb_strlen($body)) $piece .= '…';

    /* Mark every term, not only the first one found. */
    $parts = [[$piece, false]];
    foreach ($terms as $t) {
        $next = [];
        foreach ($parts as [$txt, $isHit]) {
            if ($isHit) { $next[] = [$txt, true]; continue; }
            $i = 0;
            while (($p = mb_stripos($txt, $t, $i)) !== false) {
                if ($p > $i) $next[] = [mb_substr($txt, $i, $p - $i), false];
                $next[] = [mb_substr($txt, $p, mb_strlen($t)), true];
                $i = $p + mb_strlen($t);
            }
            if ($i < mb_strlen($txt)) $next[] = [mb_substr($txt, $i), false];
        }
        $parts = $next;
    }
    return $parts;
}

/* Which sources this user may see at all. */
function txt_allowed_sources(): array
{
    $ok = ['shipment', 'document', 'legacy', 'logistics', 'provider'];
    if (function_exists('costing_perm')) {
        if (costing_perm('view'))     $ok[] = 'costing';
        if (costing_perm('proforma')) $ok[] = 'proforma';
    }
    if (function_exists('exp_can')) {
        if (exp_can('shippay'))  $ok[] = 'payment';
        if (exp_can('shipcost')) $ok[] = 'cost';
    }
    return $ok;
}

/* THE SEARCH.
 *
 * Full-text for the long words, a direct match for the short ones, both in
 * one statement. Every value is bound. */
function txt_search(string $q): array
{
    $t = txt_terms($q);
    if (!$t['all']) return ['rows' => [], 'terms' => [], 'note' => 'There were no words to look for.'];

    $where = []; $params = [];
    $relevance = '0';

    if ($t['long']) {
        /* Boolean mode so every word must appear, with a trailing * so
           "cham" finds "chamber". Natural-language mode would return rows
           matching any one word, ranked — which reads as noise. */
        $expr = implode(' ', array_map(fn($w) => '+' . $w . '*', $t['long']));
        $relevance = 'MATCH(title, subtitle, body) AGAINST (? IN BOOLEAN MODE)';
        $params[] = $expr;
        $where[]  = 'MATCH(title, subtitle, body) AGAINST (? IN BOOLEAN MODE)';
        $params[] = $expr;
    }

    /* The short words full-text cannot see — TT, LC, DP, BL. Matched
       directly, with the wildcards escaped so a typed % is a literal %. */
    foreach ($t['short'] as $w) {
        $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $w);
        $where[] = "(title LIKE ? ESCAPE '\\\\' OR subtitle LIKE ? ESCAPE '\\\\' OR body LIKE ? ESCAPE '\\\\')";
        array_push($params, "%$esc%", "%$esc%", "%$esc%");
    }

    /* ------------------------------------------------- what you may see */
    $allowed = txt_allowed_sources();
    $where[] = 'source IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
    foreach ($allowed as $s) $params[] = $s;

    if (function_exists('can_see_rates') && !can_see_rates()) {
        $where[] = 'rate_sensitive = 0';
    }

    /* A row tied to a shipment is only visible if that shipment is. */
    if (function_exists('is_admin') && !is_admin()) {
        $ids = function_exists('assigned_shipment_ids') ? assigned_shipment_ids() : ['ALL'];
        if ($ids === ['ALL']) {
            /* a colleague sees every shipment */
        } elseif (!$ids) {
            $where[] = 'shipment_id IS NULL';
        } else {
            $where[] = '(shipment_id IS NULL OR shipment_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
            foreach ($ids as $i) $params[] = (int)$i;
        }
    }

    try {
        $sql = "SELECT id, source, source_id, shipment_id, title, subtitle, body,
                       extract_status, chars, ($relevance) AS score
                FROM exp_text_index
                WHERE " . implode(' AND ', $where) . "
                ORDER BY score DESC, updated_at DESC
                LIMIT " . TXT_LIMIT;
        $st = db()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        return ['rows' => [], 'terms' => $t['all'], 'note' => 'The text index is not ready yet. An admin can build it from Export Masters.'];
    }

    foreach ($rows as &$r) $r['snippet'] = txt_snippet((string)$r['body'], $t['all']);
    unset($r);

    return ['rows' => $rows, 'terms' => $t['all'], 'note' => ''];
}

/* Where a result goes when you click it. */
function txt_link(array $row): string
{
    $sid = (int)($row['shipment_id'] ?? 0);
    switch ($row['source']) {
        case 'shipment':  return 'shipment_view.php?id=' . $sid;
        case 'proforma':  return 'proforma.php?id=' . (int)$row['source_id'];
        case 'costing':   return 'costing_view.php?id=' . (int)$row['source_id'];
        case 'document':
        case 'legacy':    return $sid ? 'shipment_documents.php?id=' . $sid : '#';
        case 'payment':   return $sid ? 'shipment_payments.php?id=' . $sid : '#';
        case 'cost':      return $sid ? 'shipment_costs.php?id=' . $sid : '#';
        case 'logistics': return $sid ? 'shipment_logistics.php?id=' . $sid : '#';
        case 'provider':  return 'exp_providers.php';
    }
    return '#';
}

/* ============================================================== the rebuild

   Batched, because a rebuild that reads every stored document cannot finish
   inside one request and a half-finished rebuild that times out silently is
   worse than none. Each call does a slice and reports where it got to. */
function txt_stats(): array
{
    $out = ['total' => 0, 'by_source' => [], 'docs_ok' => 0, 'docs_no_text' => 0, 'built' => null];
    try {
        $out['total'] = (int)db()->query("SELECT COUNT(*) FROM exp_text_index")->fetchColumn();
        foreach (db()->query("SELECT source, COUNT(*) c FROM exp_text_index GROUP BY source")->fetchAll() as $r) {
            $out['by_source'][$r['source']] = (int)$r['c'];
        }
        $out['docs_ok'] = (int)db()->query("SELECT COUNT(*) FROM exp_text_index WHERE source='document' AND extract_status='ok'")->fetchColumn();
        $out['docs_no_text'] = (int)db()->query("SELECT COUNT(*) FROM exp_text_index WHERE source='document' AND extract_status IS NOT NULL AND extract_status<>'ok'")->fetchColumn();
        $out['built'] = db()->query("SELECT MAX(updated_at) FROM exp_text_index")->fetchColumn() ?: null;
    } catch (Throwable $e) {}
    return $out;
}

/* The slices, in order. Documents last and smallest because each one may
   mean a fetch from R2. */
const TXT_STEPS = [
    ['shipment',  'Commercial invoices',  200],
    ['proforma',  'Proforma invoices',    200],
    ['costing',   'Costings',             200],
    ['notes',     'Payments, costs, logistics', 100],
    ['provider',  'Service providers',    200],
    ['document',  'Documents',             15],
];

function txt_rebuild_step(string $step, int $after): array
{
    $done = 0; $last = $after;
    $size = 50;
    foreach (TXT_STEPS as [$k, , $n]) if ($k === $step) $size = $n;

    try {
        if ($step === 'shipment') {
            $st = db()->prepare("SELECT id FROM shipments WHERE id>? ORDER BY id LIMIT $size");
            $st->execute([$after]);
            foreach ($st->fetchAll() as $r) { txt_index_shipment((int)$r['id']); $last = (int)$r['id']; $done++; }
        } elseif ($step === 'proforma') {
            $st = db()->prepare("SELECT id FROM proforma_invoices WHERE id>? ORDER BY id LIMIT $size");
            $st->execute([$after]);
            foreach ($st->fetchAll() as $r) { txt_index_proforma((int)$r['id']); $last = (int)$r['id']; $done++; }
        } elseif ($step === 'costing') {
            $st = db()->prepare("SELECT id FROM costing_versions WHERE id>? ORDER BY id LIMIT $size");
            $st->execute([$after]);
            foreach ($st->fetchAll() as $r) { txt_index_costing((int)$r['id']); $last = (int)$r['id']; $done++; }
        } elseif ($step === 'notes') {
            $st = db()->prepare("SELECT id FROM shipments WHERE id>? ORDER BY id LIMIT $size");
            $st->execute([$after]);
            foreach ($st->fetchAll() as $r) { txt_index_shipment_notes((int)$r['id']); $last = (int)$r['id']; $done++; }
        } elseif ($step === 'provider') {
            $st = db()->prepare("SELECT * FROM exp_providers WHERE id>? ORDER BY id LIMIT $size");
            $st->execute([$after]);
            foreach ($st->fetchAll() as $r) {
                txt_put('provider', (int)$r['id'], [
                    'title'    => (string)$r['name'],
                    'subtitle' => trim((string)($r['city'] ?? '')),
                    'body'     => txt_join([$r['name'] ?? '', $r['city'] ?? '', $r['address'] ?? '',
                                            $r['email'] ?? '', $r['phone'] ?? '', $r['ntn'] ?? '', $r['notes'] ?? '']),
                ]);
                $last = (int)$r['id']; $done++;
            }
        } elseif ($step === 'document') {
            $st = db()->prepare("SELECT id FROM exp_documents WHERE id>? AND is_archived=0 ORDER BY id LIMIT $size");
            $st->execute([$after]);
            foreach ($st->fetchAll() as $r) { txt_index_document((int)$r['id']); $last = (int)$r['id']; $done++; }
        }
    } catch (Throwable $e) {
        return ['done' => $done, 'last' => $last, 'more' => false, 'error' => 'This step could not finish.'];
    }
    return ['done' => $done, 'last' => $last, 'more' => $done >= $size, 'error' => ''];
}
