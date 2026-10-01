<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

if (is_staff()) {
    http_response_code(403);
    exit('Staff cannot use AI search.');
}

$q = trim($_POST['q'] ?? $_GET['q'] ?? '');
$matches = [];

if ($q !== '') {
    $like = "%{$q}%";
    $sql = "SELECT s.* FROM shipments s WHERE s.status='approved_locked' AND (s.invoice_no LIKE ? OR s.buyer_name LIKE ? OR s.buyer_country LIKE ?) ORDER BY s.id DESC LIMIT 20";
    $stmt = db()->prepare($sql);
    $stmt->execute([$like, $like, $like]);
    $matches = $stmt->fetchAll();
}

page_header('Search');
flash();
?>
<div class="topbar">
  <div><h1>Search Approved Records</h1></div>
</div>

<div class="card">
  <form method="post">
    <div style="display:flex;gap:10px">
      <input name="q" value="<?= e($q) ?>" placeholder="Invoice no, buyer, country..." style="flex:1">
      <button class="btn green">Search</button>
    </div>
  </form>
</div>

<?php if($matches): ?>
<div class="card">
  <h2>Results (<?= count($matches) ?>)</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Invoice</th><th>Buyer</th><th>Country</th><th>Status</th><th>Open</th></tr></thead>
    <tbody>
    <?php foreach($matches as $m): ?>
      <tr>
        <td><?= e($m['invoice_no']) ?></td>
        <td><?= e($m['buyer_name']) ?></td>
        <td><?= e($m['buyer_country']) ?></td>
        <td><?= status_badge($m['status']) ?></td>
        <td><a class="btn secondary" href="shipment_view.php?id=<?= (int)$m['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php elseif($q): ?>
<div class="card"><p>No approved records found.</p></div>
<?php endif; ?>
<?php page_footer(); ?>
