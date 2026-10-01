<?php
/*
  LOGISTICS BOARD — the eight questions worth answering every morning.

  Every card is a thing somebody has to DO. "Shipments this month" is
  interesting; "Final BL pending on a vessel that already sailed" is work.
  Only the second kind earns a place here.

  Each card is ONE aggregate query against an indexed column. Clicking a card
  lists the matching shipments on this same page — shipments.php is not
  touched, so the main list stays exactly as fast as it is today.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/export.php';

require_login();
exp_ensure_schema();

if (is_staff() || is_production_staff()) { http_response_code(403); exit('Not permitted.'); }
if (!exp_can('logistics') && !exp_can('payments') && !exp_can('costs')) {
    http_response_code(403);
    exit('You do not have permission for the logistics board.');
}

$seeMoney = can_see_rates();
$today    = date('Y-m-d');
$week     = date('Y-m-d', strtotime('+7 days'));

/* Non-admins see only what they are assigned. A colleague is assigned to all,
   which assigned_shipment_ids() reports as ALL. */
$scope = ''; $scopeParams = [];
if (!is_admin()) {
    $ids = assigned_shipment_ids();
    if ($ids === ['ALL']) {
        /* no restriction */
    } elseif (!$ids) {
        $scope = ' AND 1=0';
    } else {
        $scope = ' AND s.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $scopeParams = $ids;
    }
}

/* The BL-final document type, found by label so a rename in the master does
   not silently break the card. */
$blTypeIds = [];
foreach (exp_masters('doc_type', false) as $t) {
    if (stripos((string)$t['label'], 'bl') === 0 || stripos((string)$t['label'], 'bill of lading') !== false) {
        $blTypeIds[] = (int)$t['id'];
    }
}
$blIn = $blTypeIds ? implode(',', array_map('intval', $blTypeIds)) : '0';

/* Each card: its own WHERE against shipments joined to logistics. The join is
   LEFT, so a shipment with no logistics row yet still appears where it should
   — a booking that has not been made is exactly the thing to chase. */
$CARDS = [
    'booking' => [
        'label' => 'Booking Pending',
        'why'   => 'Invoice approved, no vessel booked yet',
        'where' => "s.status='approved_locked' AND (l.vessel_name IS NULL OR l.vessel_name='')",
        'need'  => 'logistics',
    ],
    'loading' => [
        'label' => 'Loading This Week',
        'why'   => 'Expected loading within 7 days, not yet loaded',
        'where' => "l.expected_load_date BETWEEN ? AND ? AND l.actual_load_date IS NULL",
        'args'  => [$today, $week],
        'need'  => 'logistics',
    ],
    'transit' => [
        'label' => 'In Transit',
        'why'   => 'Loaded, not yet arrived',
        'where' => "l.actual_load_date IS NOT NULL AND l.actual_arrival_date IS NULL",
        'need'  => 'logistics',
    ],
    'eta' => [
        'label' => 'ETA This Week',
        'why'   => 'Arriving within 7 days — tell the buyer',
        'where' => "l.eta_destination BETWEEN ? AND ? AND l.actual_arrival_date IS NULL",
        'args'  => [$today, $week],
        'need'  => 'logistics',
    ],
    'delayed' => [
        'label' => 'Delayed',
        'why'   => 'Past its ETA with no arrival recorded',
        'where' => "l.eta_destination < ? AND l.actual_arrival_date IS NULL",
        'args'  => [$today],
        'need'  => 'logistics',
        'bad'   => true,
    ],
    'blfinal' => [
        'label' => 'Final BL Pending',
        'why'   => 'Vessel sailed, no final BL on file',
        'where' => "l.actual_load_date IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM shipment_documents d
                        WHERE d.shipment_id = s.id AND d.is_archived = 0
                          AND d.stage = 'final' AND d.doc_type_id IN ($blIn))",
        'need'  => 'documents',
    ],
    'payment' => [
        'label' => 'Payment Outstanding',
        'why'   => 'Approved invoice not fully received',
        'where' => "s.status='approved_locked' AND s.total_amount > 0 AND s.total_amount >
                    COALESCE((SELECT SUM(p.amount) FROM shipment_payments p
                              WHERE p.shipment_id = s.id AND p.is_void = 0), 0)",
        'need'  => 'payments',
        'money' => true,
        'bad'   => true,
    ],
    'freight' => [
        'label' => 'Costs Payable',
        'why'   => 'Supplier or agent bills still unpaid',
        'where' => "EXISTS (SELECT 1 FROM shipment_costs c
                            WHERE c.shipment_id = s.id AND c.is_void = 0 AND c.is_paid = 0)",
        'need'  => 'costs',
        'money' => true,
    ],
];

function board_count(array $card, string $scope, array $scopeParams): int {
    $sql = "SELECT COUNT(*) FROM shipments s
            LEFT JOIN shipment_logistics l ON l.shipment_id = s.id
            WHERE " . $card['where'] . $scope;
    try {
        $st = db()->prepare($sql);
        $st->execute(array_merge($card['args'] ?? [], $scopeParams));
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

$counts = [];
foreach ($CARDS as $k => $c) {
    if (!exp_can($c['need'])) continue;
    if (!empty($c['money']) && !$seeMoney) continue;
    $counts[$k] = board_count($c, $scope, $scopeParams);
}

/* The drill-down, on this same page. */
$view = (string)($_GET['view'] ?? '');
$rows = [];
if ($view !== '' && isset($counts[$view])) {
    $c = $CARDS[$view];
    $sql = "SELECT s.id, s.invoice_no, s.invoice_date, s.buyer_name, s.buyer_country,
                   s.destination_port, s.currency, s.total_amount, s.logistics_status,
                   l.vessel_name, l.expected_load_date, l.etd_pakistan, l.eta_destination,
                   l.actual_load_date, l.actual_arrival_date, l.bl_no
            FROM shipments s
            LEFT JOIN shipment_logistics l ON l.shipment_id = s.id
            WHERE " . $c['where'] . $scope . "
            ORDER BY COALESCE(l.eta_destination, l.expected_load_date, s.invoice_date) , s.id DESC
            LIMIT 200";
    try {
        $st = db()->prepare($sql);
        $st->execute(array_merge($c['args'] ?? [], $scopeParams));
        $rows = $st->fetchAll();
    } catch (Throwable $e) { $rows = []; }
}

/* Balances only for the rows actually shown, and only when money is visible —
   one query for the whole page rather than one per row. */
$balances = [];
if ($rows && $seeMoney && exp_can('payments')) {
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = db()->prepare("SELECT shipment_id, COALESCE(SUM(amount),0) s
                             FROM shipment_payments
                             WHERE is_void=0 AND shipment_id IN ($in) GROUP BY shipment_id");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) $balances[(int)$r['shipment_id']] = (float)$r['s'];
    } catch (Throwable $e) {}
}

page_header('Logistics Board');
flash();
echo exp_page_css();
?>
<style>
.bcard{padding:14px 15px;border-radius:14px;background:#fff;border:1px solid #e3e9f2;text-decoration:none;display:block;transition:.15s}
.bcard:hover{border-color:rgba(14,168,201,.45);box-shadow:0 4px 14px rgba(21,32,51,.06)}
.bcard.on{border-color:#0ea8c9;box-shadow:0 0 0 1px #0ea8c9 inset}
.bcard .n{font-size:26px;font-weight:800;font-variant-numeric:tabular-nums;color:#152033;line-height:1}
.bcard.bad .n{color:#b8283f}
.bcard.zero .n{color:#cbd5e3}
.bcard .l{font-size:12px;font-weight:700;color:#33415c;margin-top:7px}
.bcard .w{font-size:10.5px;color:#8a97ab;margin-top:3px;line-height:1.4}
.bgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(168px,1fr));gap:10px;margin-bottom:14px}
</style>

<div class="topbar">
  <div><h1>Logistics Board</h1><p class="lead">Eight things that need doing. Click one to see which shipments.</p></div>
  <a class="btn secondary" href="shipments.php">All Shipments</a>
</div>

<div class="bgrid">
  <?php foreach ($CARDS as $k => $c):
      if (!isset($counts[$k])) continue;
      $n = $counts[$k];
      $cls = ($n === 0 ? 'zero' : (!empty($c['bad']) ? 'bad' : '')) . ($view === $k ? ' on' : ''); ?>
    <a class="bcard <?= $cls ?>" href="shipment_board.php?view=<?= e($k) ?>">
      <div class="n"><?= $n ?></div>
      <div class="l"><?= e($c['label']) ?></div>
      <div class="w"><?= e($c['why']) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<?php if (!$counts): ?>
  <div class="xcard"><div class="xnote">You do not have permission for any of the board's indicators.</div></div>
<?php endif; ?>

<?php if ($view !== '' && isset($CARDS[$view])): ?>
<div class="xcard">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:11px;flex-wrap:wrap;gap:8px">
    <div>
      <h2 style="margin:0"><?= e($CARDS[$view]['label']) ?></h2>
      <div style="font-size:11.5px;color:#8a97ab;margin-top:3px"><?= e($CARDS[$view]['why']) ?></div>
    </div>
    <span style="font-size:12px;color:#8a97ab"><?= count($rows) ?> shown<?= count($rows) >= 200 ? ' (first 200)' : '' ?></span>
  </div>

  <div class="xwrap">
    <table class="xtable">
      <thead><tr>
        <th>Invoice</th><th>Buyer</th><th>Destination</th><th>Vessel</th>
        <th>ETD</th><th>ETA</th><th>Status</th>
        <?php if ($seeMoney && exp_can('payments')): ?><th class="num">Balance</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" style="padding:22px;text-align:center;color:#8a97ab">Nothing in this group — good.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r):
          $bal = null;
          if ($seeMoney && exp_can('payments')) {
              $bal = round((float)$r['total_amount'] - (float)($balances[(int)$r['id']] ?? 0), 2);
          } ?>
        <tr onclick="location.href='shipment_logistics.php?id=<?= (int)$r['id'] ?>'" style="cursor:pointer">
          <td style="font-weight:700;color:#0ea8c9"><?= e(short_ref((string)$r['invoice_no'])) ?></td>
          <td><?= e($r['buyer_name']) ?></td>
          <td><?= e($r['destination_port'] ?: $r['buyer_country']) ?></td>
          <td><?= e($r['vessel_name']) ?></td>
          <td><?= $r['etd_pakistan'] ? e(date('d/m/y', strtotime((string)$r['etd_pakistan']))) : '' ?></td>
          <td><?= $r['eta_destination'] ? e(date('d/m/y', strtotime((string)$r['eta_destination']))) : '' ?></td>
          <td><?= $r['logistics_status'] ? '<span class="xpill b">' . e($r['logistics_status']) . '</span>' : '' ?></td>
          <?php if ($seeMoney && exp_can('payments')): ?>
            <td class="num" style="font-weight:700;color:<?= ($bal > 0.0049) ? '#b8283f' : '#16a34a' ?>">
              <?= $bal !== null ? e(money_fmt($bal, (string)$r['currency'])) : '' ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="xnote" style="margin-top:11px">Click a row to open that shipment's logistics.</div>
</div>
<?php else: ?>
<div class="xcard">
  <div class="xnote">
    Pick a card above to see the shipments behind the number. The invoice numbers are shortened the
    same way as everywhere else — <b><?= e(short_ref('PI-260908-786')) ?></b> rather than the full reference.
  </div>
</div>
<?php endif; ?>
<?php page_footer(); ?>
