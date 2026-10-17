<?php
/*
  IMPORTS — OPENING BALANCES (afnan: "I have sure old data, so make it easy
  to start, edit, and update the qty whatever is owed").

  What was still owed for EFS on the go-live date, one row per GD line:
  imported kg and valuation, wastage and value addition, and what has been
  exported against it so far — "still owed" works itself out. Typed, pasted
  from Excel, or uploaded from the template (CSV). Everything stays freely
  editable until an admin locks it; after that a change is an adjustment
  with a reason, kept in the history.

  The two other kinds of old data are not entered here: an old import still
  unpaid or not arrived is a normal import with "Opening import" ticked, and
  material already in the store is in Store & Inventory already (or goes in
  through its Opening Stock) — never entered twice.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';
require_login();
imp_ensure_schema();
imp_require('efs', 'v');

const IMP_OPEN_COLS = ['gd_no', 'gd_date', 'hs_code', 'product', 'imported_kg', 'valuation', 'waste_pct', 'va_pct', 'exported_kg', 'exported_value', 'months'];
const IMP_OPEN_NUM  = ['imported_kg', 'valuation', 'waste_pct', 'va_pct', 'exported_kg', 'exported_value', 'months'];
const IMP_OPEN_LBL  = ['gd_no' => 'GD no.', 'gd_date' => 'GD date', 'hs_code' => 'PCT / HS', 'product' => 'Product', 'imported_kg' => 'Imported kg', 'valuation' => 'Valuation / kg',
                       'waste_pct' => 'Wastage %', 'va_pct' => 'Value addition %', 'exported_kg' => 'Exported kg so far', 'exported_value' => 'Exported value so far', 'months' => 'Months to export'];

/* one row from typed / pasted values, checked; null when the row is empty */
function imp_open_row(array $in, int $rowNo): ?array
{
    $r = [];
    foreach (IMP_OPEN_COLS as $c) $r[$c] = trim((string)($in[$c] ?? ''));
    if (implode('', $r) === '' || ($r['gd_no'] === '' && $r['product'] === '' && imp_num($r['imported_kg']) == 0)) return null;
    $at = 'Row ' . $rowNo . ': ';
    if ($r['gd_no'] === '') throw new Exception($at . 'the GD number is needed.');
    $d = $r['gd_date'];
    if ($d !== '' && !imp_date($d)) {
        /* Excel often gives 14/03/2026 */
        $t = DateTime::createFromFormat('d/m/Y', $d) ?: DateTime::createFromFormat('d-m-Y', $d) ?: DateTime::createFromFormat('d M Y', $d);
        if (!$t) throw new Exception($at . 'the GD date "' . $d . '" is not a date (use 2026-03-14).');
        $d = $t->format('Y-m-d');
    }
    $out = ['gd_no' => mb_substr($r['gd_no'], 0, 80), 'gd_date' => $d ?: null, 'hs_code' => mb_substr($r['hs_code'], 0, 20) ?: null, 'product' => mb_substr($r['product'], 0, 190)];
    foreach (IMP_OPEN_NUM as $c) {
        $v = $r[$c] === '' ? ($c === 'waste_pct' ? IMP_EFS_WASTE : ($c === 'months' ? IMP_EFS_MONTHS : 0)) : imp_num($r[$c]);
        if ($v < 0) throw new Exception($at . IMP_OPEN_LBL[$c] . ' cannot be negative.');
        $out[$c] = $c === 'months' ? (int)$v : $v;
    }
    if ($out['waste_pct'] > 100) throw new Exception($at . 'wastage cannot be over 100%.');
    return $out;
}
function imp_open_insert(array $r): void
{
    db()->prepare("INSERT INTO imp_opening_efs (" . implode(', ', IMP_OPEN_COLS) . ", created_by, created_at) VALUES (" . implode(',', array_fill(0, count(IMP_OPEN_COLS), '?')) . ",?,NOW())")
        ->execute(array_merge(array_map(fn($c) => $r[$c], IMP_OPEN_COLS), [(int)(current_user()['id'] ?? 0)]));
}

$locked = imp_opening_locked();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if (in_array($action, ['save', 'paste', 'upload', 'delete', 'golive'], true) && $locked)
            throw new Exception('Opening balances are locked. Use "Adjust" on a row, with a reason.');
        if ($action === 'golive') {
            imp_require('efs', 'u');
            $d = imp_date($_POST['golive'] ?? '');
            if (!$d) throw new Exception('Give the go-live date.');
            imp_set_setting('golive_date', $d);
            imp_log(0, 'Go-live date', $d);
            $_SESSION['flash'] = 'Go-live date set to ' . imp_dmy($d) . '.';
        }
        if ($action === 'save') {
            imp_require('efs', 'u');
            db()->beginTransaction();
            $n = 0;
            foreach ((array)($_POST['row'] ?? []) as $rid => $vals) {
                $r = imp_open_row((array)$vals, ++$n);
                if ($r === null) continue;
                if ((int)$rid > 0) {
                    db()->prepare("UPDATE imp_opening_efs SET " . implode(', ', array_map(fn($c) => "$c=?", IMP_OPEN_COLS)) . ", updated_by=?, updated_at=NOW() WHERE id=?")
                        ->execute(array_merge(array_map(fn($c) => $r[$c], IMP_OPEN_COLS), [(int)(current_user()['id'] ?? 0), (int)$rid]));
                } else {
                    imp_open_insert($r);
                }
            }
            imp_log(0, 'Opening balances saved', $n . ' row' . ($n === 1 ? '' : 's'));
            db()->commit();
            $_SESSION['flash'] = 'Opening balances saved.';
        }
        if ($action === 'paste' || $action === 'upload') {
            imp_require('efs', 'c');
            $lines = [];
            if ($action === 'paste') {
                foreach (preg_split('/\r?\n/', (string)($_POST['paste'] ?? '')) as $ln) if (trim($ln) !== '') $lines[] = explode("\t", $ln);
            } else {
                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new Exception('Choose the filled template (CSV).');
                $fh = fopen((string)$_FILES['file']['tmp_name'], 'r');
                while (($c = fgetcsv($fh)) !== false) if (array_filter($c, fn($v) => trim((string)$v) !== '')) $lines[] = $c;
                fclose($fh);
            }
            db()->beginTransaction();
            $added = 0; $n = 0;
            foreach ($lines as $c) {
                $n++;
                if (preg_match('/^\s*gd\s*no/i', (string)($c[0] ?? ''))) continue;   /* the header row */
                $in = [];
                foreach (IMP_OPEN_COLS as $i => $col) $in[$col] = $c[$i] ?? '';
                $r = imp_open_row($in, $n);
                if ($r === null) continue;
                imp_open_insert($r); $added++;
            }
            imp_log(0, 'Opening balances ' . ($action === 'paste' ? 'pasted' : 'uploaded'), $added . ' row' . ($added === 1 ? '' : 's') . ' added');
            db()->commit();
            $_SESSION['flash'] = $added . ' row' . ($added === 1 ? '' : 's') . ' added — check them, then lock when they match your records.';
        }
        if ($action === 'delete') {
            imp_require('efs', 'd');
            $rid = (int)($_POST['row_id'] ?? 0);
            $s = db()->prepare("SELECT * FROM imp_opening_efs WHERE id=?"); $s->execute([$rid]); $row = $s->fetch();
            if (!$row) throw new Exception('That row is not there.');
            db()->prepare("DELETE FROM imp_opening_efs WHERE id=?")->execute([$rid]);
            imp_log(0, 'Opening row deleted', $row['gd_no'] . ' · ' . $row['product']);
            $_SESSION['flash'] = 'Row deleted.';
        }
        if ($action === 'lock') {
            if (!is_admin()) throw new Exception('Only an admin can lock the opening balances.');
            if ($locked) throw new Exception('Already locked.');
            imp_set_setting('opening_locked_at', date('Y-m-d H:i:s'));
            imp_set_setting('opening_locked_by', (string)(current_user()['name'] ?? ''));
            $c = (int)db()->query("SELECT COUNT(*) FROM imp_opening_efs")->fetchColumn();
            imp_log(0, 'Opening balances locked', $c . ' row' . ($c === 1 ? '' : 's'));
            $_SESSION['flash'] = 'Opening balances locked. A change now needs an adjustment with a reason.';
        }
        if ($action === 'adjust') {
            imp_require('efs', 'u');
            if (!$locked) throw new Exception('Not locked yet — edit the row directly.');
            $rid = (int)($_POST['row_id'] ?? 0); $field = (string)($_POST['field'] ?? ''); $reason = trim((string)($_POST['reason'] ?? ''));
            if (!in_array($field, IMP_OPEN_COLS, true)) throw new Exception('Pick what to adjust.');
            if ($reason === '') throw new Exception('An adjustment needs a reason.');
            $s = db()->prepare("SELECT * FROM imp_opening_efs WHERE id=?"); $s->execute([$rid]); $row = $s->fetch();
            if (!$row) throw new Exception('That row is not there.');
            $test = $row; $test[$field] = (string)($_POST['value'] ?? '');
            $r = imp_open_row($test, 1);
            if ($r === null) throw new Exception('That would empty the row.');
            db()->prepare("UPDATE imp_opening_efs SET $field=?, updated_by=?, updated_at=NOW() WHERE id=?")->execute([$r[$field], (int)(current_user()['id'] ?? 0), $rid]);
            imp_log(0, 'Opening balance adjusted', $row['gd_no'] . ' · ' . IMP_OPEN_LBL[$field] . ': ' . $row[$field] . ' → ' . $r[$field], $reason);
            $_SESSION['flash'] = 'Adjusted: ' . IMP_OPEN_LBL[$field] . ' on ' . $row['gd_no'] . '.';
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }
    redirect('imp_opening.php');
}

$rows = [];
try { $rows = db()->query("SELECT * FROM imp_opening_efs ORDER BY gd_date, id")->fetchAll(); } catch (Throwable $e) {}
$golive = imp_setting('golive_date');
$canEdit = !$locked && imp_can('efs', 'u'); $canAdd = !$locked && imp_can('efs', 'c'); $canDel = !$locked && imp_can('efs', 'd');
$T = ['kg' => 0.0, 'val' => 0.0, 'left_kg' => 0.0, 'left_val' => 0.0];
foreach ($rows as $r) { $c = imp_opening_calc($r); $T['kg'] += (float)$r['imported_kg']; $T['val'] += $c['imported_value']; $T['left_kg'] += max(0, $c['left_kg']); $T['left_val'] += max(0, $c['left_value']); }
$raw = static fn($v) => $v === null || $v === '' ? '' : rtrim(rtrim((string)$v, '0'), '.');
$history = [];
try { $history = db()->query("SELECT * FROM imp_audit WHERE import_id=0 ORDER BY id DESC LIMIT 30")->fetchAll(); } catch (Throwable $e) {}

page_header('Opening Balances');
flash();
echo imp_page_css();
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">
  <h1 style="font-size:20px;margin:0">Opening Balances</h1>
  <span class="xpill <?= $locked ? 'g' : 'o' ?>"><?= $locked ? 'Locked ' . e(imp_dmy(substr(imp_setting('opening_locked_at'), 0, 10))) . ' by ' . e(imp_setting('opening_locked_by')) : 'Open for editing' ?></span>
</div>
<div class="xnote" style="margin-bottom:12px">What is still open on the go-live date<?= $golive ? ', <b>' . e(imp_dmy($golive)) . '</b>' : '' ?>. Type, paste from Excel, or upload the template. <b>Edit freely until it is locked</b>; after that a change is an adjustment with a reason, kept in the history below.</div>

<?php if (!$locked && imp_can('efs', 'u')): ?>
<form class="xcard" method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="action" value="golive">
  <?= imp_input('Go-live date', 'golive', $golive, 'date', ' required') ?><button class="xbtn sec">Set go-live date</button>
</form>
<?php endif; ?>

<div class="xkpi">
  <div class="k"><div class="l">GD lines</div><div class="v"><?= count($rows) ?></div></div>
  <div class="k"><div class="l">Imported (kg)</div><div class="v"><?= e(number_format($T['kg'], 3)) ?></div></div>
  <div class="k"><div class="l">Still owed — material</div><div class="v"><?= e(number_format($T['left_kg'], 3)) ?> kg</div></div>
  <div class="k"><div class="l">Still owed — value</div><div class="v"><?= e(number_format($T['left_val'], 2)) ?></div><div class="imp-small imp-muted">in the GD currency (usually USD)</div></div>
</div>

<form class="xcard" method="post" id="opForm">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">
  <h2>1 · EFS still to export (one row per GD line)</h2>
  <div class="xwrap"><table class="xtable" style="min-width:1380px">
    <thead><tr><?php foreach (IMP_OPEN_COLS as $c): ?><th class="<?= in_array($c, IMP_OPEN_NUM, true) ? 'num' : '' ?>"><?= e(IMP_OPEN_LBL[$c]) ?></th><?php endforeach; ?><th class="num">Still owed kg</th><th class="num">Still owed value</th><th>Export by</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows && !$canEdit): ?><tr><td colspan="15" class="imp-muted" style="padding:14px;text-align:center">No opening balances.</td></tr><?php endif; ?>
    <?php $list = $rows; if ($canAdd) $list[] = ['id' => 0]; if ($canAdd && !$rows) { $list[] = ['id' => -1]; $list[] = ['id' => -2]; } ?>
    <?php foreach ($list as $r): $rid = (int)$r['id']; $c = $rid > 0 ? imp_opening_calc($r) : null; ?>
      <tr data-row>
        <?php foreach (IMP_OPEN_COLS as $col): $num = in_array($col, IMP_OPEN_NUM, true); $v = $rid > 0 ? ($num ? $raw($r[$col]) : (string)($r[$col] ?? '')) : ''; ?>
          <td><?php if ($canEdit): ?><input class="xin<?= $num ? ' num' : '' ?>" name="row[<?= $rid ?>][<?= $col ?>]" data-c="<?= $col ?>" value="<?= e($v) ?>"<?= $col === 'gd_date' ? ' type="date"' : '' ?><?= $rid <= 0 && $col === 'waste_pct' ? ' placeholder="5"' : '' ?><?= $rid <= 0 && $col === 'months' ? ' placeholder="9"' : '' ?>>
              <?php else: ?><?= $col === 'gd_date' ? e(imp_dmy($r[$col] ?? null)) : e($v) ?><?php endif; ?></td>
        <?php endforeach; ?>
        <td class="num calc o-kg"><?= $c ? e(number_format($c['left_kg'], 3)) : '' ?></td>
        <td class="num calc o-val"><?= $c ? e(number_format($c['left_value'], 2)) : '' ?></td>
        <td class="o-dl"><?= $c ? e(imp_dmy($c['deadline'])) : '' ?></td>
        <td style="white-space:nowrap">
          <?php if ($rid > 0 && $canDel): ?><button type="button" class="xbtn red sm" onclick="if(confirm('Delete this row?')){var f=document.getElementById('delForm');f.row_id.value=<?= $rid ?>;f.submit();}">✕</button><?php endif; ?>
          <?php if ($rid > 0 && $locked && imp_can('efs', 'u')): ?><button type="button" class="xbtn sec sm" onclick="adj(<?= $rid ?>,<?= e(json_encode($r['gd_no'] . ' · ' . $r['product'])) ?>)">Adjust</button><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($canEdit): ?><div style="margin-top:10px"><button class="xbtn">Save</button> <span class="imp-small imp-muted">Empty rows are ignored. "Still owed" = imported kg less wastage, less exported kg; value = imported value plus value addition, less exported value.</span></div><?php endif; ?>
</form>

<?php if ($canAdd): ?>
<div class="xcard">
  <h2>Paste from Excel, or upload the template</h2>
  <div class="xgrid">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="paste">
      <label class="xlabel">Copy the rows in Excel (same column order as the table), paste here
        <textarea class="xin" name="paste" rows="5" style="font-family:'IBM Plex Mono',monospace" placeholder="GD no. → GD date → PCT/HS → Product → Imported kg → Valuation/kg → Wastage % → Value addition % → Exported kg so far → Exported value so far → Months"></textarea></label>
      <button class="xbtn sm" style="margin-top:6px">Add pasted rows</button></form>
    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
      <label class="xlabel">Or upload the filled template (CSV)<input class="xin" type="file" name="file" accept=".csv" required></label>
      <button class="xbtn sm" style="margin-top:6px">Upload</button>
      <div class="imp-small imp-muted" style="margin-top:6px">The header row is skipped. Blank wastage = 5%, blank months = 9.</div></form>
  </div>
</div>
<?php endif; ?>

<div class="xcard">
  <h2>2 · Old imports still open (unpaid or not arrived)</h2>
  <div class="xnote">Entered as ordinary imports with <b>"Opening import"</b> ticked — only the open part is needed. Payments made before go-live go in as one "Paid before go-live" line. If the goods are already in your store, tick <b>"already in stock"</b> so Gate In never adds them again.</div>
  <?php if (imp_can('imp', 'c')): ?><div style="margin-top:10px"><a class="xbtn sec" href="imp_new.php?opening=1">+ New opening import</a></div><?php endif; ?>
</div>
<div class="xcard">
  <h2>3 · Imported material already in your store</h2>
  <div class="xnote">Nothing to do if it is already in <b>Store &amp; Inventory → Current Stock</b> — it is never entered twice. If some is missing there, add it with the existing <a href="inv_opening.php">Opening Stock</a> document, in kg.</div>
</div>

<?php if (!$locked && is_admin()): ?>
<form class="xcard" method="post" onsubmit="return confirm('Lock the opening balances? After this, a change needs an adjustment with a reason.')">
  <?= csrf_field() ?><input type="hidden" name="action" value="lock">
  <button class="xbtn">Lock opening balances</button> <span class="imp-small imp-muted">Admin only. Lock once the figures match your records.</span>
</form>
<?php endif; ?>

<?php if ($history): ?>
<div class="xcard"><h2>History</h2>
  <?php foreach ($history as $h): ?><div class="imp-small" style="padding:4px 0;border-top:1px solid #f0f3f8"><b><?= e($h['action']) ?></b><?= $h['detail'] ? ' — ' . e($h['detail']) : '' ?><?= $h['reason'] ? ' <span style="color:#9a5a06">(' . e($h['reason']) . ')</span>' : '' ?> <span class="imp-muted">· <?= e(date('d M Y H:i', strtotime((string)$h['created_at']))) ?> · <?= e($h['user_name']) ?></span></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" id="delForm" style="display:none"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="row_id"></form>
<?php if ($locked && imp_can('efs', 'u')): ?>
<div id="aModal" style="display:none;position:fixed;inset:0;background:rgba(10,15,30,.5);z-index:999;align-items:center;justify-content:center;padding:16px">
  <form method="post" style="background:#fff;border-radius:16px;padding:22px;max-width:430px;width:100%;border:1px solid #e3e9f2">
    <?= csrf_field() ?><input type="hidden" name="action" value="adjust"><input type="hidden" name="row_id" id="aId">
    <h2 style="font-size:15px;margin:0 0 10px">Adjust <span id="aName"></span></h2>
    <?= imp_select('What', 'field', IMP_OPEN_LBL, 'exported_kg', '', true) ?>
    <div style="margin-top:8px"><?= imp_input('New value', 'value', '', 'text', ' required') ?></div>
    <div style="margin-top:8px"><?= imp_input('Reason', 'reason', '', 'text', ' required') ?></div>
    <div style="display:flex;gap:9px;margin-top:14px"><button type="button" class="xbtn sec" style="flex:1" onclick="document.getElementById('aModal').style.display='none'">Cancel</button><button class="xbtn" style="flex:1">Adjust</button></div>
  </form>
</div>
<script>function adj(id, name) { document.getElementById('aId').value = id; document.getElementById('aName').textContent = name; document.getElementById('aModal').style.display = 'flex'; }</script>
<?php endif; ?>
<?php if ($canEdit): ?>
<script>
/* still owed and the deadline follow as figures are typed */
(function () {
  var n = function (v) { var x = parseFloat(String(v || '').replace(/,/g, '')); return isFinite(x) ? x : 0; };
  var f = function (v, d) { return Number(v).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }); };
  document.getElementById('opForm').addEventListener('input', function (e) {
    var tr = e.target.closest('tr[data-row]'); if (!tr) return;
    var g = function (c) { var el = tr.querySelector('[data-c="' + c + '"]'); return el ? el.value : ''; };
    var kg = n(g('imported_kg')), w = g('waste_pct').trim() === '' ? 5 : n(g('waste_pct')), va = n(g('va_pct')), m = g('months').trim() === '' ? 9 : n(g('months'));
    var imp = kg * n(g('valuation'));
    tr.querySelector('.o-kg').textContent = f(kg * (1 - w / 100) - n(g('exported_kg')), 3);
    tr.querySelector('.o-val').textContent = f(imp * (1 + va / 100) - n(g('exported_value')), 2);
    var d = g('gd_date'); if (d) { var t = new Date(d + 'T00:00:00Z'); t.setUTCMonth(t.getUTCMonth() + m); tr.querySelector('.o-dl').textContent = t.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }); }
  });
})();
</script>
<?php endif; ?>
<?php page_footer(); ?>
