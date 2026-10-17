<?php
/*
  IMPORT — TIMELINE. Everything done on this import, newest first: who, when,
  what, and the reason where one was asked for (a due date set by hand, a
  void, a status change, a document deleted).
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';

$imp = imp_open('imp');
$rows = [];
try {
    $s = db()->prepare("SELECT * FROM imp_audit WHERE import_id=? ORDER BY id DESC LIMIT 500");
    $s->execute([(int)$imp['id']]);
    $rows = $s->fetchAll();
} catch (Throwable $e) {}

page_header('Timeline — ' . $imp['ref']);
flash();
echo imp_page_css();
imp_tab_strip($imp, 'timeline');
?>
<div class="xcard">
  <h2>Timeline</h2>
  <?php if (!$rows): ?><div class="xnote">Nothing recorded yet.</div><?php endif; ?>
  <div style="border-left:2px solid #e3e9f2;margin-left:6px;padding-left:16px">
  <?php foreach ($rows as $r): ?>
    <div style="position:relative;padding:0 0 14px;font-size:12.5px">
      <span style="position:absolute;left:-22px;top:4px;width:10px;height:10px;border-radius:50%;background:#0ea8c9"></span>
      <b><?= e($r['action']) ?></b><?= $r['detail'] ? ' — ' . e($r['detail']) : '' ?>
      <?= $r['reason'] ? '<div style="color:#9a5a06">Reason: ' . e($r['reason']) . '</div>' : '' ?>
      <div class="imp-small imp-muted"><?= e(date('d M Y H:i', strtotime((string)$r['created_at']))) ?> · <?= e($r['user_name']) ?></div>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php page_footer(); ?>
