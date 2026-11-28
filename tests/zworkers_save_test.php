<?php
/* PRODUCTION WORKERS — SAVE ONCE (owner, 28 Nov 2026): chips and Give / Take
   off change the page only; one Save (or Ctrl+S) sends everybody changed. */
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; echo "  ok  $m\n"; } else { $F++; echo "  FAIL: $m\n"; } }
$pw = file_get_contents(__DIR__ . '/../public_html/production_workers.php');
$zp = file_get_contents(__DIR__ . '/../public_html/includes/zprod.php');
$js = substr($pw, strpos($pw, '<script>'));
echo "1. Nothing is sent while you work\n";
ok(substr_count($js, 'fetch(') === 1 && strpos($js, 'fetch(') > strpos($js, 'function save(){'), 'the only request is inside Save');
ok(str_contains($js, "setStage(r, s, r.st.indexOf(s) < 0); draw();"), 'a chip click changes the page only');
ok(str_contains($js, "ids.forEach(function(id){ if (byId[id] && !byId[id].hid) setStage(byId[id], s, add); });"), 'Give / Take off for the ticked people changes the page only');
ok(str_contains($js, "(e.key === 's' || e.key === 'S')) { e.preventDefault(); save(); }"), 'Ctrl+S saves');
ok(str_contains($js, "window.addEventListener('beforeunload'"), 'leaving with unsaved changes asks first');
ok(str_contains($js, "if (e.shiftKey && lastCk !== null)"), 'Shift+click ticks a range of people');
echo "2. Pay type\n";
ok(str_contains($pw, "require_once __DIR__ . '/includes/zp_correct.php';") && str_contains($pw, "'pt' => (string)(\$PAYT[(int)\$w['id']] ?? '')"), 'each person carries their pay type from People Finance');
ok(str_contains($js, "if (payF && (payF === 'none' ? r.pt : r.pt !== payF)) return false;"), 'filter: fixed salary / daily wages / contractor / not set');
echo "3. The save on the server\n";
ok(str_contains($zp, 'function zp_stage_save_many(array $want, array $was, array $hide = []): array {'), 'one function saves everybody');
ok(str_contains($zp, "if (\$moved) return ['ok' => false, 'error' => 'These people were changed by someone else"), 'someone changed meanwhile: refused, named, nothing saved');
ok(preg_match('/function zp_stage_save_many.*?db\(\)->beginTransaction\(\);.*?db\(\)->commit\(\);.*?db\(\)->rollBack\(\);/s', $zp) === 1, 'one transaction: all or nothing');
ok(str_contains($pw, "if (\$a === 'stage_save') {") && str_contains($pw, 'verify_csrf();'), 'it carries the form token');
echo "4. Remove from the list (not production)\n";
$pe = file_get_contents(__DIR__ . '/../public_html/production_entry.php');
ok(str_contains($zp, 'CREATE TABLE IF NOT EXISTS zp_worker_hidden'), 'its own small table, made on its own — no SQL step');
$sv = substr($zp, strpos($zp, 'function zp_stage_save_many('), 4000);
ok(!preg_match('/DELETE FROM zp_workers|DELETE FROM att_employees|DELETE FROM zp_entries/', $sv), 'nobody is deleted: People Finance and the wages are untouched');
ok(str_contains($zp, "if (\$hide[\$w]) { \$del->execute([\$w]); \$hOn->execute("), 'removed: stages cleared and marked, in the same transaction');
ok(str_contains($js, "if (r.hid && ORIGH[r.id]) return false;"), 'once saved, gone from every view but Removed');
ok(str_contains($js, "else if (!ORIGH[r.id]) r.st = ORIG[r.id].slice();"), 'undo before Save gives the old stages back');
ok(str_contains($js, "fd.append('hide', JSON.stringify(hide));"), 'sent with the same one Save');
ok(str_contains($pe, "\$workers = array_values(array_filter(\$workers, fn(\$w) => !isset(\$HIDW[(int)\$w['id']])));"), 'Daily Entry never offers a removed person');
echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
