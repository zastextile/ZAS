<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$fx = fx_get_rates();
$fxSynced = fx_last_synced_at();
$cardCss = 'padding:20px;border-radius:18px;background:#ffffff;border:1px solid #e3e9f2;backdrop-filter:blur(12px)';

page_header('Settings');
flash();
?>
<div class="topbar"><div><h1>Settings</h1><p class="lead">App-wide configuration — admin only.</p></div></div>

<div style="<?= $cardCss ?>;max-width:520px">
  <h2 style="font-size:15px;margin:0 0 4px">Currency / FX Rates</h2>
  <p style="color:#8a97ab;font-size:12px;margin:0 0 18px">Used to fairly compare and convert amounts across currencies on the Dashboard, Costing Print, and AI Check. Enter today's rate for each currency below and save — no external service is called, you're fully in control of these numbers.</p>

  <form method="post" action="fx_refresh.php">
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="settings.php">
    <?php foreach ($fx as $cur => $rate): if ($cur === 'PKR') continue; $pkrPerUnit = $rate > 0 ? 1 / $rate : 0; ?>
    <div style="margin-bottom:16px">
      <label style="display:block;font-size:12.5px;color:#33415c;font-weight:600;margin-bottom:6px">1 <?= e($cur) ?> =</label>
      <div style="display:flex;align-items:center;gap:8px">
        <input type="number" step="0.01" min="0.01" name="rate_<?= strtolower(e($cur)) ?>" value="<?= number_format($pkrPerUnit, 2, '.', '') ?>" style="width:150px;padding:9px 12px;border-radius:9px;border:1px solid #cbd5e3;font-family:'Space Grotesk',system-ui,sans-serif;font-size:14px">
        <span style="color:#5a6b82;font-size:13px">PKR</span>
      </div>
    </div>
    <?php endforeach; ?>

    <p style="color:#8a97ab;font-size:12px;margin:0 0 16px">
      <?= $fxSynced ? 'Last updated ' . e(date('d M Y, H:i', strtotime($fxSynced))) : 'Not yet set — showing config.php defaults above.' ?>
    </p>

    <button type="submit" style="padding:10px 18px;border-radius:10px;border:1px solid #cbd5e3;background:#f6f8fc;color:#152033;font-weight:600;font-size:13px;cursor:pointer">Save Rates</button>
  </form>
</div>

<?php page_footer(); ?>
