<?php
/* The Export / Imports tab of inv_reset.php. Included by that page only, with
   $mod ('export' or 'imports'), $err and $zrDone already set by it. */
$zrPrev   = zr_preview($mod);
$zrPhrase = ZR_PHRASES[$mod];
$zrName   = $mod === 'export' ? 'Export' : 'Imports';
?>
<?php if ($zrDone): ?>
  <div class="ir-card"><div class="ir-note ok">
    <b>Done.</b> <?= e($zrName) ?> data cleared.
    <br><br><?= number_format(array_sum($zrDone['deleted'])) ?> row(s) removed across <?= count(array_filter($zrDone['deleted'])) ?> table(s)<?php if ($zrDone['files']): ?>, and <?= (int)$zrDone['files'] - (int)$zrDone['files_left'] ?> uploaded file(s) removed from storage<?php endif; ?>.
    <?php if ($zrDone['files_left']): ?><br><br><b><?= (int)$zrDone['files_left'] ?> file(s) could not be removed from storage</b> — the rows are gone; remove the files by hand if needed.<?php endif; ?>
    <br><br><?= $mod === 'export'
        ? 'Commercial invoice numbering starts again from 1' . (in_array('proformas', $zrDone['picked'], true) ? ', and so does proforma numbering' : '') . '.'
        : 'Import references start again from 0001.' ?>
    The reason and the counts are in the audit log.
  </div>
  <div style="margin-top:16px"><a class="ir-btn" href="<?= $mod === 'export' ? 'shipments.php' : 'imp_register.php' ?>">Open <?= $mod === 'export' ? 'Shipments' : 'the Import Register' ?></a></div>
  </div>
<?php else: ?>

<div class="ir-card">
  <div class="ir-note info">
    <b>Try the smaller fix first.</b> One wrong <?= $mod === 'export' ? 'draft shipment can be deleted on its own page' : 'test import can be deleted on its own page with "Delete a test import completely"' ?>.
    Use this page when the whole <?= $mod === 'export' ? 'export' : 'import' ?> side is <b>trial data</b>, entered while you were learning.
  </div>
</div>

<?php if ($err): ?><div class="ir-card"><div class="ir-note warn"><b>Not done.</b> <?= e($err) ?></div></div><?php endif; ?>

<form method="post" class="ir-card" action="inv_reset.php?m=<?= e($mod) ?>">
  <?= csrf_field() ?>
  <h2 style="margin:0 0 12px;font-size:15.5px">What to clear — <?= e($zrName) ?></h2>

  <?php foreach ($zrPrev as $k => $g): $always = !empty($g['always']); $blocked = $g['blocked']; ?>
  <div class="ir-grp <?= $always ? 'on' : '' ?><?= $blocked ? ' blocked' : '' ?>">
    <h3>
      <?php if ($always): ?>
        <input type="checkbox" checked disabled>
      <?php else: ?>
        <input type="checkbox" name="g_<?= e($k) ?>" value="1" <?= $blocked ? 'disabled' : '' ?> onchange="this.closest('.ir-grp').classList.toggle('on', this.checked)">
      <?php endif; ?>
      <?= e($g['label']) ?>
      <?php if ($always): ?><span style="font-size:10px;font-weight:800;color:#c0293f;letter-spacing:.05em">ALWAYS</span><?php endif; ?>
      <span style="font-size:11.5px;color:#8a97ab;font-weight:600;margin-left:auto"><?= number_format($g['total']) ?> row(s)</span>
    </h3>
    <p><?= e($g['blurb']) ?></p>
    <?php if ($blocked): ?>
      <div class="ir-block">Cannot be cleared yet: <?= e(implode('; ', $blocked)) ?>.</div>
    <?php endif; ?>
    <details><summary style="font-size:11.5px;color:#0b7fa3;cursor:pointer;margin-top:6px">Show the tables</summary>
      <table class="ir-tbl" style="margin-top:6px"><?php foreach ($g['rows'] as $r): ?>
        <tr><td><?= e($r['table']) ?></td><td class="n"><?= number_format($r['n']) ?></td></tr>
      <?php endforeach; ?></table>
    </details>
  </div>
  <?php endforeach; ?>

  <div class="ir-note warn" style="margin:16px 0">
    <b>Never touched by this tab:</b> production entries and wages, product costings, users and permissions, inventory stock
    (it has its own tab), the audit logs, and settings such as banks, document and cost types, and numbering patterns.
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;max-width:1040px">
    <div>
      <label class="ir-lbl">Type <?= e($zrPhrase) ?> to confirm</label>
      <input class="ir-inp" name="phrase" autocomplete="off" spellcheck="false" placeholder="<?= e($zrPhrase) ?>">
    </div>
    <div>
      <label class="ir-lbl">Reason — goes to the audit log</label>
      <input class="ir-inp" name="reason" autocomplete="off" placeholder="e.g. trial shipments entered while learning the module">
    </div>
    <div>
      <label class="ir-lbl">Your login password</label>
      <input class="ir-inp" type="password" name="password" autocomplete="current-password">
    </div>
  </div>

  <div style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <button class="ir-btn ir-go" type="submit" <?= $zrPrev[array_key_first($zrPrev)]['blocked'] ? 'disabled title="Clear what is listed above first"' : '' ?>>Clear the ticked <?= e(strtolower($zrName)) ?> data</button>
    <a class="ir-btn" href="inv_reset.php?m=<?= e($mod) ?>">Cancel</a>
    <span style="font-size:11.5px;color:#8a97ab">This cannot be undone from inside the app. If in doubt, take a database backup first.</span>
  </div>
</form>
<?php endif; ?>
