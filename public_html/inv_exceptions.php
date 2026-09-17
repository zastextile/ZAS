<?php
/* Production Exceptions — the wage-fraud check.

   The production module exists to stop a worker or an incharge billing
   the same work twice, or billing work never done. Its cumulative stage
   caps already prevent claiming more than the order, claiming a later
   stage before an earlier one, splitting a claim across rows, editing the
   rate, and working on an unassigned order.

   What the caps CANNOT catch is two people claiming the same physical
   work, because that still fits inside the order total. This page finds
   those cases by comparing the floor's claims against facts recorded by
   somebody else — the store.

   IMPORTANT: this page is READ ONLY. It writes nothing, changes nothing,
   and needs no new column on any production table. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/inventory.php';
inv_ensure_schema();
if (!is_admin() && !inv_perm('view')) { http_response_code(403); exit('You do not have permission to view production exceptions.'); }

$days = max(1, min(365, (int)($_GET['days'] ?? 30)));
$since = date('Y-m-d', strtotime("-$days days"));
$ex = [];
$stats = ['claimed' => 0.0, 'wage' => 0.0, 'entries' => 0];

/* -- headline: what was claimed in the window ------------------------- */
try {
    $s = db()->prepare("SELECT COALESCE(SUM(quantity),0) q, COALESCE(SUM(amount),0) a, COUNT(*) n
        FROM production_transactions WHERE status='active' AND production_date >= ?");
    $s->execute([$since]); $r = $s->fetch();
    $stats = ['claimed' => (float)$r['q'], 'wage' => (float)$r['a'], 'entries' => (int)$r['n']];
} catch (Throwable $e) {}

/* -- 1. two workers, same item, same stage, same day ------------------ */
try {
    $s = db()->prepare("SELECT pt.production_date, pt.proforma_item_id, pt.stage,
            COALESCE(pt.component_name,'') comp, COUNT(DISTINCT pt.worker_id) workers,
            COALESCE(SUM(pt.quantity),0) qty, COALESCE(SUM(pt.amount),0) amt,
            GROUP_CONCAT(DISTINCT w.worker_name ORDER BY w.worker_name SEPARATOR ', ') names,
            pi.product_name, pf.pi_no
        FROM production_transactions pt
        LEFT JOIN production_workers w ON w.id = pt.worker_id
        LEFT JOIN proforma_items pi ON pi.id = pt.proforma_item_id
        LEFT JOIN proforma_invoices pf ON pf.id = pt.proforma_id
        WHERE pt.status='active' AND pt.production_date >= ?
        GROUP BY pt.production_date, pt.proforma_item_id, pt.stage, comp
        HAVING workers > 1
        ORDER BY amt DESC LIMIT 40");
    $s->execute([$since]);
    foreach ($s->fetchAll() as $r) {
        $ex[] = ['sev' => 'high', 'kind' => 'Two or more workers, same item and stage, same day',
            'detail' => e($r['product_name'] ?: 'item #' . $r['proforma_item_id']) . ' · ' . e(inv_stage_label($r['stage']))
                      . ($r['comp'] ? ' · ' . e($r['comp']) : '') . ' on ' . e($r['production_date'])
                      . ' — ' . (int)$r['workers'] . ' workers claimed ' . number_format((float)$r['qty'], 0) . ' pieces between them',
            'who' => e($r['names'] ?: '—'), 'ref' => e($r['pi_no'] ?: ''),
            'qty' => (float)$r['qty'], 'val' => (float)$r['amt']];
    }
} catch (Throwable $e) {}

/* -- 2. claimed finished, but never reached stock --------------------- */
/* The floor's final-stage total against what the store actually posted
   into finished goods. Two people, two records, no shared control. */
try {
    $s = db()->prepare("SELECT pf.id, pf.pi_no, pf.customer_name,
            COALESCE(SUM(pt.quantity),0) claimed
        FROM production_transactions pt
        JOIN proforma_invoices pf ON pf.id = pt.proforma_id
        WHERE pt.status='active' AND pt.stage='Dispatch' AND pt.production_date >= ?
        GROUP BY pf.id HAVING claimed > 0");
    $s->execute([$since]);
    foreach ($s->fetchAll() as $r) {
        $made = 0.0;
        try {
            $s2 = db()->prepare("SELECT COALESCE(SUM(ci.qty),0) FROM inv_consumption_items ci
                JOIN inv_consumption c ON c.id=ci.con_id
                WHERE c.proforma_id=? AND c.status='posted' AND ci.side='output'");
            $s2->execute([(int)$r['id']]); $made = (float)$s2->fetchColumn();
        } catch (Throwable $e) {}
        $gap = (float)$r['claimed'] - $made;
        if ($made > 0 && $gap > 0.5) {
            $ex[] = ['sev' => 'high', 'kind' => 'Claimed finished, but never reached stock',
                'detail' => e($r['pi_no']) . ' · floor claimed ' . number_format((float)$r['claimed'], 0)
                          . ' finished, store posted ' . number_format($made, 0) . ' into stock',
                'who' => e($r['customer_name'] ?: ''), 'ref' => e($r['pi_no']),
                'qty' => $gap, 'val' => 0.0];
        }
    }
} catch (Throwable $e) {}

/* -- 3. backdated entries -------------------------------------------- */
/* Future dates are already blocked by production_save.php. Past dates are
   not limited, so a claim can land in a closed wage period. */
$limit = (int)inv_setting('backdate_days', '7');
if ($limit > 0) {
    try {
        $s = db()->prepare("SELECT pt.id, pt.production_date, DATE(pt.created_at) entered,
                DATEDIFF(DATE(pt.created_at), pt.production_date) back,
                pt.quantity, pt.amount, w.worker_name, pi.product_name
            FROM production_transactions pt
            LEFT JOIN production_workers w ON w.id=pt.worker_id
            LEFT JOIN proforma_items pi ON pi.id=pt.proforma_item_id
            WHERE pt.status='active' AND pt.created_at >= ?
              AND DATEDIFF(DATE(pt.created_at), pt.production_date) > ?
            ORDER BY back DESC LIMIT 25");
        $s->execute([$since, $limit]);
        foreach ($s->fetchAll() as $r) {
            $ex[] = ['sev' => 'med', 'kind' => 'Backdated entry',
                'detail' => 'Entered ' . e($r['entered']) . ' but dated ' . e($r['production_date'])
                          . ' — ' . (int)$r['back'] . ' days back (limit is ' . $limit . ')',
                'who' => e($r['worker_name'] ?: '—'), 'ref' => e($r['product_name'] ?: ''),
                'qty' => (float)$r['quantity'], 'val' => (float)$r['amount']];
        }
    } catch (Throwable $e) {}
}

/* -- 4. output far above the worker's own average --------------------- */
try {
    $s = db()->prepare("SELECT pt.worker_id, w.worker_name, pt.production_date,
            SUM(pt.quantity) day_qty, SUM(pt.amount) day_amt
        FROM production_transactions pt
        LEFT JOIN production_workers w ON w.id=pt.worker_id
        WHERE pt.status='active' AND pt.production_date >= ?
        GROUP BY pt.worker_id, pt.production_date");
    $s->execute([$since]);
    $byWorker = [];
    foreach ($s->fetchAll() as $r) $byWorker[(int)$r['worker_id']][] = $r;
    foreach ($byWorker as $wid => $daysArr) {
        if (count($daysArr) < 5) continue;               // too little history to judge
        $tot = 0.0; foreach ($daysArr as $d) $tot += (float)$d['day_qty'];
        $avg = $tot / count($daysArr);
        if ($avg <= 0) continue;
        foreach ($daysArr as $d) {
            if ((float)$d['day_qty'] > $avg * 2.5) {
                $ex[] = ['sev' => 'low', 'kind' => 'Day far above own average',
                    'detail' => number_format((float)$d['day_qty'], 0) . ' on ' . e($d['production_date'])
                              . ' against a ' . number_format($avg, 0) . '/day average over ' . count($daysArr) . ' days',
                    'who' => e($d['worker_name'] ?: '—'), 'ref' => '',
                    'qty' => (float)$d['day_qty'] - $avg, 'val' => (float)$d['day_amt']];
            }
        }
    }
} catch (Throwable $e) {}

$order = ['high' => 0, 'med' => 1, 'low' => 2];
usort($ex, fn($a, $b) => [$order[$a['sev']], -$a['val']] <=> [$order[$b['sev']], -$b['val']]);
$counts = ['high' => 0, 'med' => 0, 'low' => 0];
$atRisk = 0.0;
foreach ($ex as $e2) { $counts[$e2['sev']]++; if ($e2['sev'] !== 'low') $atRisk += $e2['val']; }

page_header('Production Exceptions');
flash();
?>
<div class="topbar">
  <div><h1>Production Exceptions</h1>
    <p class="lead">Wage claims that need an answer. This page reads your existing production entries and compares them with what the store recorded — it writes nothing.<?php if (is_admin()): ?>
      To take a wrong entry back out of the figures, use <a href="production_amend.php" style="color:#0ea8c9;font-weight:700">Correct a Production Entry</a>.<?php endif; ?></p></div>
  <form method="get" style="display:flex;gap:8px;align-items:flex-end">
    <div><label style="display:block;font-size:10.5px;color:#8a97ab;font-weight:800;text-transform:uppercase;margin-bottom:5px">Period</label>
      <select name="days" class="ex-inp" onchange="this.form.submit()" style="padding:9px 11px;border-radius:9px;border:1px solid #cbd5e3;font-size:12.5px">
        <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $days === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select></div>
  </form>
</div>

<style>
.ex-card{background:#fff;border:1px solid #e3e9f2;border-radius:16px;padding:20px 22px;margin-bottom:16px;box-shadow:0 10px 30px rgba(15,35,65,.06)}
.ex-grid{display:grid;gap:13px;grid-template-columns:repeat(4,1fr)}
@media(max-width:900px){.ex-grid{grid-template-columns:repeat(2,1fr)}}
.ex-kpi{background:#f7f9fc;border-radius:12px;padding:14px 16px}
.ex-kpi .l{font-size:10px;color:#8a97ab;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.ex-kpi .v{font-size:20px;font-weight:800;margin-top:5px;font-variant-numeric:tabular-nums}
.ex-row{border:1px solid #e3e9f2;border-radius:12px;padding:14px 16px;margin-bottom:10px;display:flex;gap:14px;
  justify-content:space-between;align-items:flex-start;flex-wrap:wrap}
.ex-row.high{background:rgba(224,67,93,.05);border-color:rgba(224,67,93,.24)}
.ex-row.med{background:rgba(217,119,6,.05);border-color:rgba(217,119,6,.24)}
.ex-row b{font-size:13px;display:block;margin-bottom:3px}
.ex-row p{margin:0;font-size:12.5px;color:#5a6b82;line-height:1.6}
.ex-row .who{font-size:11.5px;color:#8a97ab;margin-top:5px}
.ex-val{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.ex-val .q{font-size:16px;font-weight:800}
.ex-val .a{font-size:11.5px;color:#5a6b82}
.ex-pill{display:inline-block;font-size:10px;font-weight:800;padding:3px 8px;border-radius:20px}
.s-high{background:rgba(224,67,93,.13);color:#c0293f}
.s-med{background:rgba(217,119,6,.14);color:#a8630a}
.s-low{background:rgba(14,168,201,.12);color:#0b7f9b}
.ex-note{border-radius:11px;padding:12px 15px;font-size:12.5px;line-height:1.65}
.ex-note.info{background:rgba(14,168,201,.07);border:1px solid rgba(14,168,201,.2);color:#2c4a63}
.ex-note.ok{background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.22);color:#1c5334}
</style>

<?php /* OPTING IN TO THE SKIN. Every rule in assets/css/zskin.css is
         scoped under .zskin, so this one wrapper is what makes the page
         compact, and deleting it restores the styles above with nothing
         else to undo. It wraps the markup and never the <style>. */ ?>
<div class="zskin">

<div class="ex-grid" style="margin-bottom:16px">
  <div class="ex-kpi"><div class="l">Claimed in period</div><div class="v"><?= number_format($stats['claimed'], 0) ?></div>
    <div style="font-size:11px;color:#5a6b82;margin-top:4px"><?= (int)$stats['entries'] ?> entries</div></div>
  <div class="ex-kpi"><div class="l">Wage value</div><div class="v"><?= number_format($stats['wage'], 0) ?></div></div>
  <div class="ex-kpi" style="<?= $counts['high'] ? 'background:rgba(224,67,93,.07)' : '' ?>">
    <div class="l">Needs investigating</div><div class="v" style="<?= $counts['high'] ? 'color:#c0293f' : '' ?>"><?= $counts['high'] ?></div></div>
  <div class="ex-kpi" style="<?= $counts['med'] ? 'background:rgba(217,119,6,.07)' : '' ?>">
    <div class="l">Worth a question</div><div class="v" style="<?= $counts['med'] ? 'color:#a8630a' : '' ?>"><?= $counts['med'] ?></div></div>
</div>

<div class="ex-card">
  <?php if (!$ex): ?>
    <div class="ex-note ok"><b>Nothing flagged in the last <?= $days ?> days.</b>
      Either the claims are clean, or there is not yet enough data to compare — the strongest check needs the store to be posting finished goods, so that the floor's claims can be measured against somebody else's record.</div>
  <?php else: ?>
    <div class="ex-note info" style="margin-bottom:16px">
      Every line below is a <b>question, not an accusation</b>. Two workers can genuinely share a batch, a high day can be real, and a stock gap can simply mean the store has not posted yet. The value is that somebody now has to answer.
    </div>
    <?php foreach ($ex as $e2): ?>
      <div class="ex-row <?= e($e2['sev']) ?>">
        <div style="flex:1;min-width:260px">
          <b><?= $e2['kind'] ?> <span class="ex-pill s-<?= e($e2['sev']) ?>"><?= $e2['sev'] === 'high' ? 'Investigate' : ($e2['sev'] === 'med' ? 'Query' : 'Note') ?></span></b>
          <p><?= $e2['detail'] ?></p>
          <?php if ($e2['who'] || $e2['ref']): ?><div class="who"><?= $e2['who'] ?><?= $e2['ref'] ? ' · ' . $e2['ref'] : '' ?></div><?php endif; ?>
        </div>
        <div class="ex-val"><div class="q"><?= number_format($e2['qty'], 0) ?></div>
          <?php if ($e2['val'] > 0): ?><div class="a"><?= number_format($e2['val'], 0) ?> wage value</div><?php endif; ?></div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="ex-card">
  <h2 style="font-size:15px;margin:0 0 10px;font-weight:800">Already prevented by your existing code</h2>
  <p style="color:#8a97ab;font-size:12px;margin:0 0 12px">These need no report — the save routine refuses them outright.</p>
  <ul style="margin:0;padding-left:20px;font-size:12.5px;color:#5a6b82;line-height:1.95">
    <li>Claiming more than the order quantity — <b>capped at stage 1</b></li>
    <li>Claiming a later stage before the earlier one — each stage is capped by the previous stage's output</li>
    <li>Splitting one over-claim across several rows in a single submit — rows are stacked before the cap is applied</li>
    <li>Editing the piece rate in the browser — the rate is re-read on the server and whatever was submitted is ignored</li>
    <li>Claiming against an order not assigned to you — enforced on save, not just hidden from the search</li>
    <li>Dating a claim in the future — rejected outright</li>
    <li>Deleting a worker to hide their history — blocked once they have any saved entry</li>
  </ul>
  <div class="ex-note info" style="margin-top:14px">
    The gap those rules cannot close is <b>two people claiming the same physical work</b>, because it still fits inside the order total. That is what the first check on this page is for, and why the store's stock postings must stay independent of the floor's claims — if one produced the other, they would always agree and the comparison would be worthless.
  </div>
</div>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
