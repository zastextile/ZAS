<?php
/* Stock Health — the cross-check.

   There is no stored balance anywhere in this module. Every figure on
   Current Stock and every running total on the Stock Ledger is computed
   as SUM(qty_in) - SUM(qty_out) at the moment you look at it. So there
   is nothing to "refresh" and nothing that can drift out of sync.

   What CAN be wrong is the ledger itself — a document posted when the
   check that should have stopped it did not yet exist, a post that
   failed halfway, material received without a rate. This page finds
   those, names the document responsible, and links straight to it.

   READ ONLY. This page writes nothing. Every fix is a link to the
   document, where the normal reverse/post rules still apply. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/inventory.php';
inv_ensure_schema();
if (!inv_can_see()) { http_response_code(403); exit('You do not have permission to view stock health.'); }

/* --------------------------------------------------------- name lookups */
$MAT = []; $PRD = [];
try { foreach (db()->query("SELECT id, code, name, uom FROM inv_materials")->fetchAll() as $r) $MAT[(int)$r['id']] = $r; } catch (Throwable $e) {}
try { foreach (db()->query("SELECT id, name FROM products")->fetchAll() as $r) $PRD[(int)$r['id']] = $r; } catch (Throwable $e) {}

function iv_item(?int $mid, ?int $pid): string {
    global $MAT, $PRD;
    if ($mid && isset($MAT[$mid])) return $MAT[$mid]['code'] . ' · ' . $MAT[$mid]['name'];
    if ($pid && isset($PRD[$pid])) return $PRD[$pid]['name'];
    if ($mid) return 'Deleted material #' . $mid;
    if ($pid) return 'Deleted product #' . $pid;
    return 'no item on the row';
}
function iv_q($v): string { return number_format((float)$v, 3); }
function iv_m($v): string { return number_format((float)$v, 2); }

/* Link back to whichever document wrote a ledger row. */
function iv_doc_link(string $type, int $id, string $no): string {
    $no = $no !== '' ? $no : strtoupper($type) . ' #' . $id;
    $base = str_replace('_rev', '', $type);
    $href = '';
    if ($base === 'gate')        $href = 'inv_gate.php?id=' . $id;
    elseif ($base === 'store')   $href = 'inv_store.php?id=' . $id;
    elseif ($base === 'consumption') $href = 'inv_consume.php?id=' . $id;
    if ($href === '') return e($no);
    return '<a href="' . e($href) . '" style="font-family:monospace;font-weight:700;color:#0b5f8a">' . e($no) . '</a>';
}

/* ------------------------------------------------------------ findings */
/* Each finding is one problem class. sev: stop (wrong figures on screen
   now), warn (will bite later), info (nothing broken, just worth saying). */
$F = [];
function iv_add(string $sev, string $title, string $what, string $fix, array $cols, array $rows): void {
    global $F;
    if (!$rows) return;
    $F[] = ['sev' => $sev, 'title' => $title, 'what' => $what, 'fix' => $fix, 'cols' => $cols, 'rows' => $rows];
}

/* -- 1. negative balance at a location -------------------------------- */
/* The one that actually matters. A negative means more left that place
   than ever arrived there, which is physically impossible — so a document
   is wrong, not the arithmetic. */
$negRows = [];
try {
    $st = db()->query("SELECT material_id, product_id, location_id, ownership,
            COALESCE(SUM(qty_in),0) tin, COALESCE(SUM(qty_out),0) tout,
            COALESCE(SUM(qty_in),0)-COALESCE(SUM(qty_out),0) bal
        FROM inv_stock_ledger
        GROUP BY material_id, product_id, location_id, ownership
        HAVING bal < -0.0005
        ORDER BY bal ASC LIMIT 200");
    $culprit = db()->prepare("SELECT source_type, source_id, COALESCE(source_no,'') source_no,
            COALESCE(SUM(qty_out),0) q
        FROM inv_stock_ledger
        WHERE location_id <=> ? AND ownership = ? AND material_id <=> ? AND product_id <=> ? AND qty_out > 0
        GROUP BY source_type, source_id, source_no ORDER BY q DESC LIMIT 3");
    foreach ($st->fetchAll() as $r) {
        $mid = $r['material_id'] !== null ? (int)$r['material_id'] : null;
        $pid = $r['product_id'] !== null ? (int)$r['product_id'] : null;
        $culprit->execute([$r['location_id'], $r['ownership'], $mid, $pid]);
        $bits = [];
        foreach ($culprit->fetchAll() as $c) {
            $bits[] = iv_doc_link($c['source_type'], (int)$c['source_id'], $c['source_no']) .
                      ' <span style="color:#8a97ab">took ' . iv_q($c['q']) . '</span>';
        }
        $ledger = ($mid ? 'material=' . $mid : 'product=' . $pid) . '&amp;own=' . $r['ownership'] . '&amp;loc=' . (int)$r['location_id'];
        $negRows[] = [
            iv_item($mid, $pid),
            inv_location_name((int)$r['location_id']) . ($r['ownership'] === 'customer' ? ' (customer-owned)' : ''),
            ['r' => iv_q($r['tin'])],
            ['r' => iv_q($r['tout'])],
            ['r' => '<b style="color:#c0293f">' . iv_q($r['bal']) . '</b>', 'raw' => 1],
            ['raw' => 1, 'v' => ($bits ? implode('<br>', $bits) : '—')],
            ['raw' => 1, 'v' => '<a class="iv-btn" href="inv_ledger.php?' . $ledger . '">Ledger</a>'],
        ];
    }
} catch (Throwable $e) {}
iv_add('stop', 'Stock has gone below zero somewhere',
    'More has left this place than ever arrived there. That cannot happen physically, so one of the documents below is wrong — usually an issue raised against a location that never held the goods. The item TOTAL can still look correct while this is true, because a minus at one location cancels a plus at another.',
    'Open the document that took the largest quantity. If it moved goods that were never at that location, reverse it and raise it again from the location that actually held them. If the goods really did leave, the receipt for them is missing — post the inward first.',
    ['Item', 'Location', 'In', 'Out', 'Balance', 'Documents that took the most out', ''], $negRows);

/* -- 2. posted, but nothing written to the ledger ---------------------- */
$halfRows = [];
foreach ([['inv_gate', 'gate_no', 'gate_date', 'gate', 'inv_gate.php'],
          ['inv_store_move', 'move_no', 'move_date', 'store', 'inv_store.php'],
          ['inv_consumption', 'con_no', 'con_date', 'consumption', 'inv_consume.php']] as $d) {
    try {
        $st = db()->prepare("SELECT id, `{$d[1]}` no, `{$d[2]}` dt FROM `{$d[0]}` t
            WHERE t.status='posted' AND NOT EXISTS (
                SELECT 1 FROM inv_stock_ledger l WHERE l.source_type=? AND l.source_id=t.id)
            ORDER BY t.id DESC LIMIT 100");
        $st->execute([$d[3]]);
        foreach ($st->fetchAll() as $r) {
            $halfRows[] = [
                ['raw' => 1, 'v' => iv_doc_link($d[3], (int)$r['id'], (string)$r['no'])],
                ucfirst($d[3]), (string)$r['dt'],
                'Marked posted, but it wrote no stock rows',
            ];
        }
    } catch (Throwable $e) {}
}
iv_add('stop', 'A document says posted but moved no stock',
    'The document is flagged as posted, so the system will not let you post it again — yet it never wrote a single row to the ledger. This happens when a post is interrupted part-way. The quantities on it are invisible to every stock figure.',
    'Open it, reverse it with the reason "posting failed", then re-enter it as a new document and post that. Do not try to post the same one twice — it will refuse.',
    ['Document', 'Type', 'Date', 'What is wrong'], $halfRows);

/* -- 3. ledger rows whose document is gone or not posted --------------- */
/* A reversed document legitimately keeps its rows — reversal writes the
   opposite entries and leaves history intact. Only a MISSING parent, or
   one sitting in draft, is a real orphan. */
$orphRows = [];
foreach ([['gate', 'inv_gate', 'gate_no'], ['store', 'inv_store_move', 'move_no'],
          ['consumption', 'inv_consumption', 'con_no']] as $d) {
    try {
        $st = db()->prepare("SELECT l.source_id, COALESCE(l.source_no,'') no,
                COUNT(*) n, COALESCE(SUM(l.qty_in),0) tin, COALESCE(SUM(l.qty_out),0) tout,
                (SELECT t.status FROM `{$d[1]}` t WHERE t.id = l.source_id) st
            FROM inv_stock_ledger l WHERE l.source_type = ?
            GROUP BY l.source_id, l.source_no
            HAVING st IS NULL OR st IN ('draft','verified')
            ORDER BY l.source_id DESC LIMIT 100");
        $st->execute([$d[0]]);
        foreach ($st->fetchAll() as $r) {
            $orphRows[] = [
                ['raw' => 1, 'v' => iv_doc_link($d[0], (int)$r['source_id'], (string)$r['no'])],
                ucfirst($d[0]),
                ['r' => (string)(int)$r['n']],
                ['r' => iv_q($r['tin'])], ['r' => iv_q($r['tout'])],
                $r['st'] === null ? 'The document no longer exists' : 'The document is back in ' . $r['st'],
            ];
        }
    } catch (Throwable $e) {}
}
iv_add('stop', 'Stock rows with no live document behind them',
    'These rows are moving your stock, but the document that created them was deleted or has been put back to draft. Nothing on screen explains where the quantity went — which is exactly the situation the ledger exists to prevent.',
    'These have to be cleared out. Use Start Again below if this is trial data, or ask for a targeted clean-up if it is live.',
    ['Document', 'Type', 'Rows', 'In', 'Out', 'What is wrong'], $orphRows);

/* -- 4. owned material received at no rate ---------------------------- */
$rateRows = [];
try {
    $st = db()->query("SELECT l.id, l.txn_date, l.material_id, l.product_id, l.source_type, l.source_id,
            COALESCE(l.source_no,'') no, l.qty_in
        FROM inv_stock_ledger l
        WHERE l.ownership='own' AND l.qty_in > 0 AND COALESCE(l.rate,0) = 0
          AND l.source_type IN ('gate','store','consumption')
        ORDER BY l.id DESC LIMIT 100");
    foreach ($st->fetchAll() as $r) {
        // A store movement is a location change and legitimately carries the
        // rate it was issued at; only a genuine receipt at zero is a problem.
        if ($r['source_type'] === 'store') continue;
        $rateRows[] = [
            (string)$r['txn_date'],
            ['raw' => 1, 'v' => iv_doc_link($r['source_type'], (int)$r['source_id'], (string)$r['no'])],
            iv_item($r['material_id'] !== null ? (int)$r['material_id'] : null, $r['product_id'] !== null ? (int)$r['product_id'] : null),
            ['r' => iv_q($r['qty_in'])],
            'Received at rate 0 — it counts in quantity but adds nothing to stock value',
        ];
    }
} catch (Throwable $e) {}
iv_add('warn', 'Material received without a rate',
    'The quantity is in stock but carries no cost. Every figure built on it afterwards — consumption cost, order costing, stock value — is understated by exactly the amount that was never entered.',
    'Reverse the receipt and enter it again with the rate from the supplier invoice. Getting this right at the gate is the only place it is cheap to fix.',
    ['Date', 'Document', 'Item', 'Qty in', 'What is wrong'], $rateRows);

/* -- 5. value that does not match qty x rate --------------------------- */
$valRows = [];
try {
    $st = db()->query("SELECT id, txn_date, material_id, product_id, source_type, source_id,
            COALESCE(source_no,'') no, qty_in, qty_out, rate, value_amount,
            ROUND((qty_in-qty_out)*rate, 2) should_be
        FROM inv_stock_ledger
        WHERE ownership='own' AND ABS(value_amount - ROUND((qty_in-qty_out)*rate,2)) > 0.01
        ORDER BY id DESC LIMIT 100");
    foreach ($st->fetchAll() as $r) {
        $valRows[] = [
            (string)$r['txn_date'],
            ['raw' => 1, 'v' => iv_doc_link($r['source_type'], (int)$r['source_id'], (string)$r['no'])],
            iv_item($r['material_id'] !== null ? (int)$r['material_id'] : null, $r['product_id'] !== null ? (int)$r['product_id'] : null),
            ['r' => iv_q((float)$r['qty_in'] - (float)$r['qty_out'])],
            ['r' => iv_m($r['rate'])],
            ['r' => iv_m($r['value_amount'])],
            ['r' => iv_m($r['should_be'])],
        ];
    }
} catch (Throwable $e) {}
iv_add('warn', 'Stored value disagrees with quantity times rate',
    'The value column on these rows was not derived from the quantity and rate beside it. Stock value will not reconcile to the ledger.',
    'These rows predate the current posting code, which always derives value. Reverse and re-post the documents, or use Start Again if this is trial data.',
    ['Date', 'Document', 'Item', 'Net qty', 'Rate', 'Value stored', 'Should be'], $valRows);

/* -- 6. customer-owned rows carrying value ---------------------------- */
$custRows = [];
try {
    $st = db()->query("SELECT id, txn_date, material_id, product_id, source_type, source_id,
            COALESCE(source_no,'') no, value_amount
        FROM inv_stock_ledger WHERE ownership='customer' AND ABS(COALESCE(value_amount,0)) > 0.005
        ORDER BY id DESC LIMIT 100");
    foreach ($st->fetchAll() as $r) {
        $custRows[] = [
            (string)$r['txn_date'],
            ['raw' => 1, 'v' => iv_doc_link($r['source_type'], (int)$r['source_id'], (string)$r['no'])],
            iv_item($r['material_id'] !== null ? (int)$r['material_id'] : null, $r['product_id'] !== null ? (int)$r['product_id'] : null),
            ['r' => iv_m($r['value_amount'])],
        ];
    }
} catch (Throwable $e) {}
iv_add('stop', 'Customer-owned goods carrying a value',
    'Goods a customer sent you are your responsibility but they are not your asset. Valuing them inflates your stock and your balance sheet with something you do not own.',
    'Reverse and re-post. The current code forces value to zero on customer-owned rows, so a re-post fixes it permanently.',
    ['Date', 'Document', 'Item', 'Value wrongly held'], $custRows);

/* -- 7. malformed rows ------------------------------------------------ */
$badRows = [];
try {
    $st = db()->query("SELECT id, txn_date, source_type, source_id, COALESCE(source_no,'') no,
            qty_in, qty_out, material_id, product_id, location_id
        FROM inv_stock_ledger
        WHERE (qty_in > 0 AND qty_out > 0)
           OR (COALESCE(qty_in,0) = 0 AND COALESCE(qty_out,0) = 0)
           OR (material_id IS NULL AND product_id IS NULL)
           OR location_id IS NULL
        ORDER BY id DESC LIMIT 100");
    foreach ($st->fetchAll() as $r) {
        $why = [];
        if ((float)$r['qty_in'] > 0 && (float)$r['qty_out'] > 0) $why[] = 'in and out on the same row';
        if ((float)$r['qty_in'] == 0 && (float)$r['qty_out'] == 0) $why[] = 'moves no quantity at all';
        if ($r['material_id'] === null && $r['product_id'] === null) $why[] = 'names no item';
        if ($r['location_id'] === null) $why[] = 'names no location';
        $badRows[] = [
            (string)$r['txn_date'],
            ['raw' => 1, 'v' => iv_doc_link($r['source_type'], (int)$r['source_id'], (string)$r['no'])],
            ['r' => iv_q($r['qty_in'])], ['r' => iv_q($r['qty_out'])],
            implode(', ', $why),
        ];
    }
} catch (Throwable $e) {}
iv_add('warn', 'Ledger rows that are malformed',
    'A stock row must move a quantity one way, for one item, at one place. These break that rule, so any total they land in is unreliable.',
    'Reverse the document that wrote them, or use Start Again if this is trial data.',
    ['Date', 'Document', 'In', 'Out', 'What is wrong'], $badRows);

/* -- 8. ledger rows pointing at deleted masters ----------------------- */
$gone = [];
try {
    $st = db()->query("SELECT l.material_id, COUNT(*) n FROM inv_stock_ledger l
        WHERE l.material_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM inv_materials m WHERE m.id = l.material_id)
        GROUP BY l.material_id LIMIT 50");
    foreach ($st->fetchAll() as $r) $gone[] = ['Material #' . (int)$r['material_id'], ['r' => (string)(int)$r['n']], 'The item was deleted from the Item Master but its stock rows remain'];
} catch (Throwable $e) {}
iv_add('warn', 'Stock rows for an item that no longer exists',
    'These quantities are still in the ledger but the item they belong to has been deleted, so they appear on no stock screen and cannot be searched for.',
    'Recreate the item in Item Master with the same id, or use Start Again if this is trial data. Do not delete items that have ever moved — set them inactive instead.',
    ['Item', 'Rows', 'What is wrong'], $gone);

/* ------------------------------------------------------- reconciliation */
$tot = ['rows' => 0, 'tin' => 0.0, 'tout' => 0.0, 'val' => 0.0];
try {
    $r = db()->query("SELECT COUNT(*) n, COALESCE(SUM(qty_in),0) tin, COALESCE(SUM(qty_out),0) tout,
            COALESCE(SUM(value_amount),0) val FROM inv_stock_ledger")->fetch();
    $tot = ['rows' => (int)$r['n'], 'tin' => (float)$r['tin'], 'tout' => (float)$r['tout'], 'val' => (float)$r['val']];
} catch (Throwable $e) {}

$bySrc = [];
try {
    foreach (db()->query("SELECT source_type, COUNT(*) n, COALESCE(SUM(qty_in),0) tin,
            COALESCE(SUM(qty_out),0) tout FROM inv_stock_ledger
            GROUP BY source_type ORDER BY source_type")->fetchAll() as $r) $bySrc[] = $r;
} catch (Throwable $e) {}

$docCounts = [];
foreach ([['Gate passes', 'inv_gate'], ['Store movements', 'inv_store_move'], ['Consumptions', 'inv_consumption']] as $d) {
    $row = ['label' => $d[0], 'draft' => 0, 'posted' => 0, 'reversed' => 0];
    try {
        foreach (db()->query("SELECT status, COUNT(*) n FROM `{$d[1]}` GROUP BY status")->fetchAll() as $r) {
            $s = (string)$r['status'];
            if ($s === 'draft' || $s === 'verified') $row['draft'] += (int)$r['n'];
            elseif (isset($row[$s])) $row[$s] += (int)$r['n'];
        }
    } catch (Throwable $e) {}
    $docCounts[] = $row;
}

$stops = 0; $warns = 0;
foreach ($F as $f) { if ($f['sev'] === 'stop') $stops++; elseif ($f['sev'] === 'warn') $warns++; }

page_header('Stock Health');
flash();
?>
<div class="topbar">
  <div><h1>Stock Health</h1>
    <p class="lead">Cross-checks the stock ledger against itself and against the documents that wrote it. This page changes nothing — every fix is a link to the document.</p></div>
  <div><a class="iv-btn" href="inv_stock.php">Current Stock</a> <a class="iv-btn" href="inv_ledger.php">Stock Ledger</a></div>
</div>

<style>
.iv-card{background:#fff;border:1px solid #e3e9f2;border-radius:16px;padding:13px 15px;margin-bottom:11px;box-shadow:0 10px 30px rgba(15,35,65,.06)}
.iv-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.iv-tbl th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:800;padding:0 8px 5px;white-space:nowrap}
.iv-tbl td{padding:3px 8px;border-top:1px solid #eef1f7;vertical-align:top}
.iv-tbl td.r,.iv-tbl th.r{text-align:right;font-variant-numeric:tabular-nums}
.iv-btn{padding:7px 13px;border-radius:9px;background:#fff;color:#152033;border:1px solid #cbd5e3;font-weight:700;font-size:12px;cursor:pointer;text-decoration:none;display:inline-block}
.iv-hd{display:flex;gap:11px;align-items:baseline;margin-bottom:6px;flex-wrap:wrap}
.iv-sev{display:inline-block;font-size:9.5px;font-weight:800;padding:4px 9px;border-radius:20px;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}
.iv-sev.stop{background:#fdecef;color:#a51b32;border:1px solid #f6c3cd}
.iv-sev.warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}
.iv-what{font-size:12.5px;color:#41546d;line-height:1.7;margin:0 0 10px;max-width:78ch}
.iv-fix{font-size:12.5px;line-height:1.7;background:rgba(14,168,201,.07);border:1px solid rgba(14,168,201,.2);border-radius:11px;padding:11px 14px;color:#2c4a63;margin:12px 0 0;max-width:78ch}
.iv-ok{background:#f0fdf4;border:1px solid #bbf7d0;border-left:4px solid #16a34a;border-radius:12px;padding:16px 18px;font-size:13px;color:#14532d;line-height:1.7}
.iv-sum{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.iv-kpi{flex:1 1 150px;background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:14px 16px}
.iv-kpi b{display:block;font-size:20px;font-weight:800;font-variant-numeric:tabular-nums;color:#152033}
.iv-kpi span{font-size:10.5px;color:#8a97ab;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
</style>

<?php /* OPTING IN TO THE SKIN — with a second word, and it is load-bearing.
         inv_setup.php and inv_verify.php BOTH use the .iv-* vocabulary,
         and they disagree about two names: .iv-btn is a primary on one
         page and a secondary on the other, and .iv-ok is a small badge on
         one and a full-width panel on the other. A single .zskin .iv-ok
         rule would turn one into the other.

         Rather than rewrite either page's classes, each wrapper says
         which page it is. The skin writes the two names that disagree
         against "ivchk" alone. One word, no markup rewritten. */ ?>
<div class="zskin ivchk">

<div class="iv-sum">
  <div class="iv-kpi"><span>Must fix</span><b style="<?= $stops ? 'color:#c0293f' : '' ?>"><?= $stops ?></b></div>
  <div class="iv-kpi"><span>Worth fixing</span><b style="<?= $warns ? 'color:#9a3412' : '' ?>"><?= $warns ?></b></div>
  <div class="iv-kpi"><span>Ledger rows</span><b><?= number_format($tot['rows']) ?></b></div>
  <div class="iv-kpi"><span>Total in</span><b><?= iv_q($tot['tin']) ?></b></div>
  <div class="iv-kpi"><span>Total out</span><b><?= iv_q($tot['tout']) ?></b></div>
  <div class="iv-kpi"><span>Stock value</span><b><?= iv_m($tot['val']) ?></b></div>
</div>

<?php if (!$F): ?>
  <div class="iv-card"><div class="iv-ok">
    <b>Every check passed.</b> No negative balance anywhere, every posted document wrote its stock rows, every row has a live document behind it, and stock value reconciles to quantity times rate.
    <?= $tot['rows'] === 0 ? ' The ledger is empty — post a Gate Inward pass to start it.' : '' ?>
  </div></div>
<?php else: ?>
  <?php foreach ($F as $f): ?>
  <div class="iv-card">
    <div class="iv-hd">
      <span class="iv-sev <?= e($f['sev']) ?>"><?= $f['sev'] === 'stop' ? 'Must fix' : 'Worth fixing' ?></span>
      <h2 style="margin:0;font-size:15.5px"><?= e($f['title']) ?></h2>
      <span style="font-size:11.5px;color:#8a97ab;font-weight:700"><?= count($f['rows']) ?> found</span>
    </div>
    <p class="iv-what"><?= e($f['what']) ?></p>
    <div style="overflow-x:auto"><table class="iv-tbl">
      <thead><tr><?php foreach ($f['cols'] as $i => $c): ?>
        <th class="<?= in_array($c, ['In','Out','Balance','Qty in','Rows','Net qty','Rate','Value stored','Should be','Value wrongly held'], true) ? 'r' : '' ?>"><?= e($c) ?></th>
      <?php endforeach; ?></tr></thead>
      <tbody><?php foreach ($f['rows'] as $row): ?>
        <tr><?php foreach ($row as $cell):
          if (is_array($cell)) {
            $cls = isset($cell['r']) ? 'r' : '';
            $val = $cell['r'] ?? ($cell['v'] ?? '');
            echo '<td class="' . $cls . '">' . (!empty($cell['raw']) ? $val : e((string)$val)) . '</td>';
          } else {
            echo '<td>' . e((string)$cell) . '</td>';
          }
        endforeach; ?></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <p class="iv-fix"><b>What to do:</b> <?= e($f['fix']) ?></p>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="iv-card">
  <h2 style="margin:0 0 4px;font-size:15.5px">How the ledger adds up</h2>
  <p class="iv-what">Nothing here is stored. Every number is counted from the ledger the moment you open this page, which is why there is no cache to clear and no "rebuild" button anywhere in this module. If a figure looks wrong, the ledger row behind it is wrong.</p>
  <div style="overflow-x:auto"><table class="iv-tbl">
    <thead><tr><th>Written by</th><th class="r">Rows</th><th class="r">In</th><th class="r">Out</th><th class="r">Net</th></tr></thead>
    <tbody>
      <?php foreach ($bySrc as $r): $net = (float)$r['tin'] - (float)$r['tout']; ?>
      <tr>
        <td style="font-family:monospace"><?= e((string)$r['source_type']) ?></td>
        <td class="r"><?= number_format((int)$r['n']) ?></td>
        <td class="r" style="color:#16a34a"><?= iv_q($r['tin']) ?></td>
        <td class="r" style="color:#c0293f"><?= iv_q($r['tout']) ?></td>
        <td class="r" style="font-weight:700"><?= iv_q($net) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$bySrc): ?><tr><td colspan="5" style="color:#8a97ab;text-align:center;padding:20px 0">The ledger is empty.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($bySrc): ?>
    <tfoot><tr style="border-top:2px solid #cbd5e3">
      <td style="font-weight:800">All movements</td>
      <td class="r" style="font-weight:800"><?= number_format($tot['rows']) ?></td>
      <td class="r" style="font-weight:800"><?= iv_q($tot['tin']) ?></td>
      <td class="r" style="font-weight:800"><?= iv_q($tot['tout']) ?></td>
      <td class="r" style="font-weight:800"><?= iv_q($tot['tin'] - $tot['tout']) ?></td>
    </tr></tfoot>
    <?php endif; ?>
  </table></div>
</div>

<div class="iv-card">
  <h2 style="margin:0 0 4px;font-size:15.5px">Documents on file</h2>
  <p class="iv-what">Only the posted column has touched stock. Drafts are invisible to every figure on Current Stock, deliberately — and a reversed document keeps its original rows on purpose, with the opposite rows beside them, so the history reads as what happened and then the correction.</p>
  <div style="overflow-x:auto"><table class="iv-tbl">
    <thead><tr><th>Document</th><th class="r">Draft / verified</th><th class="r">Posted</th><th class="r">Reversed</th></tr></thead>
    <tbody><?php foreach ($docCounts as $d): ?>
      <tr><td style="font-weight:600"><?= e($d['label']) ?></td>
        <td class="r" style="<?= $d['draft'] ? 'color:#9a3412;font-weight:700' : 'color:#8a97ab' ?>"><?= (int)$d['draft'] ?></td>
        <td class="r" style="font-weight:700"><?= (int)$d['posted'] ?></td>
        <td class="r" style="color:#8a97ab"><?= (int)$d['reversed'] ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>

<?php if (is_admin()): ?>
<div class="iv-card" style="border-color:#f6c3cd">
  <h2 style="margin:0 0 4px;font-size:15.5px">Start again</h2>
  <p class="iv-what">If what you are looking at above is trial data from before the checks existed, the honest answer is to clear it and re-enter properly rather than patch it. That tool is separate, admin-only, and asks you to type the words before it does anything.</p>
  <a class="iv-btn" style="border-color:#c0293f;color:#c0293f;font-weight:800" href="inv_reset.php">Open Start Again</a>
</div>
<?php endif; ?>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
