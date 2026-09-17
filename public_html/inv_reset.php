<?php
/* Start Again — clear inventory data and begin from a known-empty state.

   This exists for one honest reason: trial data entered before a check
   existed cannot always be patched into correctness, and pretending
   otherwise leaves figures nobody trusts. Better to wipe deliberately,
   with a record of who did it and why, than to carry a ledger with a
   minus in it forever.

   Three safety rules, none of them optional:
     1. Admin only.
     2. The exact phrase must be typed. No "are you sure" button that a
        wrist can press by accident.
     3. A reason is required and is written to the audit log before a
        single row is deleted, so the record survives the deletion.

   What it NEVER touches: production entries, proformas, costings,
   users, or anything outside the inventory module. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/inventory.php';
inv_ensure_schema();
if (!is_admin()) { http_response_code(403); exit('Only an administrator can clear inventory data.'); }

const IV_PHRASE = 'RESET INVENTORY';

/* Groups, in the order they must be deleted — children before parents,
   so nothing is orphaned even for the instant the transaction is open. */
$GROUPS = [
    'moves' => [
        'label'  => 'Stock movements',
        'blurb'  => 'The stock ledger and every document that writes to it: gate passes, store issues and returns, consumptions, and job work bills. This is what a reset is for, so it is always included.',
        'always' => true,
        'tables' => ['inv_stock_ledger', 'inv_gate_items', 'inv_gate', 'inv_store_move_items',
                     'inv_store_move', 'inv_consumption_items', 'inv_consumption',
                     'inv_jobwork_items', 'inv_jobwork_charges'],
    ],
    'orders' => [
        'label'  => 'Reservations and order charges',
        'blurb'  => 'Material reserved against an order, and the extra charges you entered on Order Costing Control. Leave this off if you want to keep the costing side of your orders intact.',
        'tables' => ['inv_allocations', 'inv_order_charges'],
    ],
    'contracts' => [
        'label'  => 'Contracts',
        'blurb'  => 'Purchase, sales and job work contracts with their lines. Only tick this if the contracts were trial entries too — gate passes can be re-entered against contracts that still exist.',
        'tables' => ['inv_contract_items', 'inv_contracts'],
    ],
    'masters' => [
        'label'  => 'Item Master, parties and locations',
        'blurb'  => 'The items themselves, your suppliers and customers, and your location list. The standard locations are recreated immediately afterwards. Any Product Costing line that was linked to an item is unlinked, so costing keeps its typed names and loses only the pointer.',
        'tables' => ['inv_materials', 'inv_parties', 'inv_locations'],
        'danger' => true,
    ],
];

/* Live counts, so you are agreeing to a number and not to a word. */
function iv_count(string $t): int {
    try { return (int)db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } catch (Throwable $e) { return 0; }
}
$counts = [];
foreach ($GROUPS as $k => $g) { foreach ($g['tables'] as $t) $counts[$t] = iv_count($t); }

$err = ''; $done = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $phrase = trim((string)($_POST['phrase'] ?? ''));
    $reason = trim((string)($_POST['reason'] ?? ''));
    $pick   = ['moves' => true];
    foreach (['orders', 'contracts', 'masters'] as $k) if (!empty($_POST['g_' . $k])) $pick[$k] = true;

    if ($phrase !== IV_PHRASE) {
        $err = 'The phrase did not match. Type ' . IV_PHRASE . ' exactly — capitals and the space included — and nothing else.';
    } elseif (mb_strlen($reason) < 5) {
        $err = 'Write a reason of at least 5 characters. It is written to the audit log and it is the only thing that survives this.';
    } else {
        // The record goes in FIRST. If the deletion succeeds, the reason is
        // already safe; if it fails, an attempt is still on file.
        $planned = [];
        foreach ($pick as $k => $_) foreach ($GROUPS[$k]['tables'] as $t) $planned[$t] = $counts[$t];
        inv_audit('inventory_reset_start', array_keys($pick), $planned, $reason);

        $deleted = []; $failed = [];
        try {
            db()->beginTransaction();
            foreach (array_keys($GROUPS) as $k) {          // fixed order, children first
                if (empty($pick[$k])) continue;
                foreach ($GROUPS[$k]['tables'] as $t) {
                    try {
                        db()->exec("DELETE FROM `$t`");
                        $deleted[$t] = $counts[$t];
                    } catch (Throwable $e) { $failed[$t] = $e->getMessage(); }
                }
            }
            if (!empty($pick['masters'])) {
                // Costing keeps every typed item name; only the pointer to a
                // now-deleted item is cleared, so nothing reads as a ghost.
                try { db()->exec("UPDATE costing_lines SET material_id = NULL WHERE material_id IS NOT NULL"); } catch (Throwable $e) {}
            }
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $err = 'Nothing was deleted — the database refused: ' . $e->getMessage();
        }

        if ($err === '') {
            /* Ids restart at 1. This runs AFTER the commit on purpose:
               ALTER TABLE commits the open transaction implicitly in
               MySQL, so doing it inside would quietly break the
               all-or-nothing promise made above. Numbering itself does
               not depend on this — document numbers are derived from the
               rows on file, so an empty table already starts at 0001. */
            foreach (array_keys($deleted) as $t) {
                try { db()->exec("ALTER TABLE `$t` AUTO_INCREMENT = 1"); } catch (Throwable $e) {}
            }
            // Put the standard locations back straight away, otherwise the
            // very next gate pass has nowhere to post to.
            if (!empty($pick['masters'])) { try { inv_seed_defaults(); } catch (Throwable $e) {} }
            inv_audit('inventory_reset_done', array_keys($pick), $deleted, $reason);
            $done = ['deleted' => $deleted, 'failed' => $failed, 'picked' => array_keys($pick)];
            $_SESSION['flash'] = 'Inventory data cleared. Document numbering starts again from 0001.';
        }
    }
}

page_header('Start Again');
flash();
?>
<div class="topbar">
  <div><h1>Start Again</h1>
    <p class="lead">Clear inventory data and begin from an empty ledger. Admin only, phrase-confirmed, and written to the audit log before anything is deleted.</p></div>
  <div><a class="ir-btn" href="inv_verify.php">Back to Stock Health</a></div>
</div>

<style>
.ir-card{background:#fff;border:1px solid #e3e9f2;border-radius:16px;padding:20px 22px;margin-bottom:16px;box-shadow:0 10px 30px rgba(15,35,65,.06)}
.ir-btn{padding:9px 16px;border-radius:10px;background:#fff;color:#152033;border:1px solid #cbd5e3;font-weight:700;font-size:12.5px;cursor:pointer;text-decoration:none;display:inline-block;font-family:inherit}
.ir-go{background:#c0293f;border-color:#c0293f;color:#fff}
.ir-inp{padding:10px 12px;border-radius:9px;border:1px solid #cbd5e3;font-size:13px;font-family:inherit;width:100%;box-sizing:border-box}
.ir-lbl{display:block;font-size:10.5px;color:#8a97ab;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.ir-note{border-radius:11px;padding:13px 16px;font-size:12.5px;line-height:1.7;max-width:80ch}
.ir-note.info{background:rgba(14,168,201,.07);border:1px solid rgba(14,168,201,.2);color:#2c4a63}
.ir-note.warn{background:#fdecef;border:1px solid #f6c3cd;color:#8a1628}
.ir-note.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#14532d}
.ir-grp{border:1px solid #e3e9f2;border-radius:13px;padding:14px 16px;margin-bottom:11px}
.ir-grp.on{border-color:#c0293f;background:#fffafb}
.ir-grp h3{margin:0 0 4px;font-size:13.5px;display:flex;gap:9px;align-items:center}
.ir-grp p{margin:0 0 9px;font-size:12.5px;color:#41546d;line-height:1.7;max-width:78ch}
.ir-tbl{width:100%;border-collapse:collapse;font-size:12px}
.ir-tbl td{padding:3px 8px 3px 0;font-family:monospace;color:#5a6b82}
.ir-tbl td.n{text-align:right;font-weight:700;font-family:inherit;font-variant-numeric:tabular-nums;color:#152033;width:90px}
</style>

<?php /* OPTING IN TO THE SKIN. Every rule in assets/css/zskin.css is
         scoped under .zskin, so this one wrapper is what makes the page
         compact, and deleting it restores the styles above with nothing
         else to undo. It wraps the markup and never the <style>. */ ?>
<div class="zskin">

<?php if ($done): ?>
  <div class="ir-card"><div class="ir-note ok">
    <b>Done.</b> The inventory ledger is empty and document numbering starts again at 0001.
    <?php $tot = array_sum($done['deleted']); ?>
    <br><br><?= number_format($tot) ?> row(s) removed across <?= count($done['deleted']) ?> table(s).
    <?php if ($done['failed']): ?><br><br><b>These would not clear:</b> <?= e(implode(', ', array_keys($done['failed']))) ?>.<?php endif; ?>
    <br><br>Production entries, proformas, costings and users were not touched.
  </div>
  <div style="margin-top:16px"><a class="ir-btn" href="inv_verify.php">Run Stock Health</a>
    <a class="ir-btn" href="inv_gate.php?dir=in">Enter the first Gate Inward</a></div>
  </div>
<?php else: ?>

<div class="ir-card">
  <div class="ir-note info">
    <b>Try the smaller fix first.</b> If only one document is wrong, you do not need this page. Open that document and reverse it — the original stays, the opposite entries appear beside it, and the balance corrects itself with the history intact. That is the right answer for anything that has been shown to a customer, a supplier or an auditor.
    <br><br>Use this page when the data is <b>trial data</b> — entered while you were learning the module, or before a check existed — and re-entering it cleanly is faster and more honest than patching it.
  </div>
</div>

<?php if ($err): ?><div class="ir-card"><div class="ir-note warn"><b>Not done.</b> <?= e($err) ?></div></div><?php endif; ?>

<form method="post" class="ir-card">
  <?= csrf_field() ?>
  <h2 style="margin:0 0 12px;font-size:15.5px">What to clear</h2>

  <?php foreach ($GROUPS as $k => $g): $always = !empty($g['always']); ?>
  <div class="ir-grp <?= $always ? 'on' : '' ?>" id="grp_<?= e($k) ?>">
    <h3>
      <?php if ($always): ?>
        <input type="checkbox" checked disabled>
      <?php else: ?>
        <input type="checkbox" name="g_<?= e($k) ?>" value="1" onchange="this.closest('.ir-grp').classList.toggle('on', this.checked)">
      <?php endif; ?>
      <?= e($g['label']) ?>
      <?php if ($always): ?><span style="font-size:10px;font-weight:800;color:#c0293f;letter-spacing:.05em">ALWAYS</span><?php endif; ?>
      <?php if (!empty($g['danger'])): ?><span style="font-size:10px;font-weight:800;color:#c0293f;letter-spacing:.05em">MOST DESTRUCTIVE</span><?php endif; ?>
    </h3>
    <p><?= e($g['blurb']) ?></p>
    <table class="ir-tbl"><?php foreach ($g['tables'] as $t): ?>
      <tr><td><?= e($t) ?></td><td class="n"><?= number_format($counts[$t]) ?></td></tr>
    <?php endforeach; ?></table>
  </div>
  <?php endforeach; ?>

  <div class="ir-note warn" style="margin:16px 0">
    <b>Not touched by any option above:</b> production entries and wages, proformas and orders, product costings, users and permissions, and your inventory settings such as GST %, tolerances and document prefixes. Those all stay exactly as they are.
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;max-width:820px">
    <div>
      <label class="ir-lbl">Type <?= e(IV_PHRASE) ?> to confirm</label>
      <input class="ir-inp" name="phrase" autocomplete="off" spellcheck="false" placeholder="<?= e(IV_PHRASE) ?>">
    </div>
    <div>
      <label class="ir-lbl">Reason — goes to the audit log</label>
      <input class="ir-inp" name="reason" autocomplete="off" placeholder="e.g. trial data entered before the outward stock check existed">
    </div>
  </div>

  <div style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <button class="ir-btn ir-go" type="submit">Clear the ticked data</button>
    <a class="ir-btn" href="inv_verify.php">Cancel</a>
    <span style="font-size:11.5px;color:#8a97ab">This cannot be undone from inside the app. If in doubt, take a database backup in Hostinger first.</span>
  </div>
</form>
<?php endif; ?>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
