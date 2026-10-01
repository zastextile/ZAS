<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_admin(); // deploy tool — admin only, safe to leave live since it's login-gated

$msg = ''; $ok = false;
if (function_exists('opcache_reset')) {
    $ok = opcache_reset();
    $msg = $ok ? 'OPcache cleared successfully — the site is now running the latest uploaded files.' : 'opcache_reset() returned false (OPcache may be disabled by the host, or there was nothing cached).';
} else {
    $ok = true;
    $msg = 'OPcache extension is not active on this server — there is nothing to clear, so file changes should already show up without this step.';
}
page_header('OPcache Clear');
?>
<div class="zcard" style="max-width:520px;margin:40px auto;text-align:center;padding:32px 26px">
  <div style="font-size:40px;line-height:1;margin-bottom:12px"><?= $ok ? '✅' : '⚠️' ?></div>
  <h2 style="margin:0 0 10px"><?= $ok ? 'Cleared' : 'Heads up' ?></h2>
  <p style="color:#5a6b82;font-size:13.5px;line-height:1.6"><?= e($msg) ?></p>
  <p style="font-size:11px;color:#8a97ab;margin-top:18px">Cleared at <?= e(date('Y-m-d H:i:s')) ?></p>
  <a class="zbtn" style="margin-top:14px;display:inline-block" href="dashboard.php">← Back to Dashboard</a>
</div>
<?php page_footer(); ?>
