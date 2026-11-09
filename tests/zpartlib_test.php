<?php
/* PART LIBRARY: FITS THE SCREEN, ONE SAVE.
 *
 * "not fit ease for full view ... need scroll horizontal ... make it ease for
 *  desktop ... make ease standard form for edit"
 *
 * Why it did not fit: assets/css/app.css gives EVERY table min-width:980px.
 * The parts list sits in a 40% panel, so Ops, Cost and the three buttons on
 * each row were pushed behind a sideways scroll.
 *
 * Why one Save: there were two buttons, each saving half the panel. Rename a
 * part, change a rate, press Save Operations, and the new name was lost.
 *
 * Driven in a browser against MariaDB at 1920, 1366 and 1280: the list fits
 * at all three; one Save stored a new name and a new rate together; Ctrl+S
 * saved; a refused name kept what was typed; Cancel brought the saved part
 * back; leaving with changes asked first; new part, Duplicate and Delete
 * worked from the panel header. This file holds the code shut.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function t(string $n, bool $c, $got = null): void {
    global $P, $F;
    if ($c) { $P++; }
    else { $F++; echo "  FAIL  $n" . ($got !== null ? "\n        got: " . var_export($got, true) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }
function nocomments(string $s): string {
    $out = '';
    foreach (token_get_all($s) as $t) {
        if (is_array($t)) { $out .= ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) ? ' ' : $t[1]; }
        else $out .= $t;
    }
    return $out;
}

$raw = (string)file_get_contents($B . 'part_library.php');
$pl  = nocomments($raw);
$css = (string)file_get_contents($B . 'assets/css/app.css');

/* ===================================================================== */
head('1. The list fits its panel');
t('the app-wide rule this page has to answer is still there', str_contains($css, 'min-width:980px'));
t('this page lets its own tables be narrower', str_contains($pl, 'table.zp-t{width:100%;min-width:0;'));
t('the list is 40%, the editor 60%', str_contains($pl, 'grid-template-columns:minmax(0,2fr) minmax(0,3fr)'));
t('no Action column on every row any more', !str_contains($pl, '>Action</th>'));
t('  and no Edit button per row', !str_contains($pl, 'href="part_library.php?part=<?= $pid ?>#partform">Edit</a>'));
t('a row opens its part, by click or Enter',
  str_contains($pl, 'data-href="part_library.php?part=<?= $pid ?>#partform"')
  && str_contains($pl, "t.addEventListener('click'") && str_contains($pl, "if (e.key === 'Enter') open("));
t('the unit column is short enough for a 1280 screen', str_contains($pl, '<th style="width:46px" title="Default unit of measure">UOM</th>'));
t('a search box finds a part by name or ID', str_contains($pl, 'id="plFind"') && str_contains($pl, " of ' + rows.length + ' parts'"));
t('the list scrolls in its own box with its headings held',
  str_contains($pl, '.pl-scroll{max-height:calc(100vh - 250px);overflow:auto') && str_contains($pl, '.pl-scroll thead th{position:sticky'));
t('Copy still reads the same five columns', str_contains($pl, "lines.push(['Part ID','Part Name','Default UOM','Ops','Cost / Pc'].join('\\t'));"));

head('2. The editor: one header, one form, one Save');
t('Duplicate, Delete and Close sit in the part header',
  str_contains($pl, '<div class="ed-head">') && str_contains($pl, 'value="duplicate_part"') && str_contains($pl, 'value="delete_part"')
  && str_contains($pl, '<a class="zp-b sm" href="part_library.php">Close</a>'));
t('  Delete still warns that a used part is deactivated, not deleted', str_contains($pl, 'it will be deactivated instead of deleted'));
t('details and operations are ONE form posting save_all',
  substr_count($pl, 'id="partForm"') === 1 && str_contains($pl, '<input type="hidden" name="action" value="save_all">'));
t('  the two old forms are gone', !str_contains($pl, 'id="partDetails"') && !str_contains($pl, 'id="opsForm"'));
t('  and their two buttons', !str_contains($pl, 'Save Part Details') && !str_contains($pl, '>Save Operations<'));
t('one Save bar: Save (or Create part), Cancel, "Unsaved changes"',
  str_contains($pl, "<?= \$editId ? 'Save' : 'Create part' ?>") && str_contains($pl, 'id="cancelBtn"') && str_contains($pl, 'Unsaved changes'));
t('Cancel links without #partform, so it really reloads the saved part',
  str_contains($pl, "href=\"<?= \$editId ? 'part_library.php?part=' . (int)\$editId : 'part_library.php' ?>\""));
t('Ctrl+S saves the one form', str_contains($pl, "var f = document.getElementById('partForm');"));
t('leaving with unsaved changes asks first', str_contains($pl, "window.addEventListener('beforeunload'") && str_contains($pl, 'if (!dirty) return;'));
t('  removing a line counts as a change', str_contains($pl, 'window.zpDropRow = function(b){ drop(b); mark(); };'));
t('  Cancel and Save do not ask', str_contains($pl, "cancel.addEventListener('click', function(){ dirty = false; });")
  && str_contains($pl, "form.addEventListener('submit', function(){ dirty = false; });"));

head('3. save_all on the server');
$a = strpos($pl, "if (\$action === 'save_all') {");
$b = strpos($pl, "} elseif (\$action === 'save_part') {");
$sa = ($a !== false && $b !== false) ? substr($pl, $a, $b - $a) : '';
t('save_all exists', $sa !== '');
t('details are saved first', strpos($sa, 'zp_save_part(') !== false && strpos($sa, 'zp_save_part(') < strpos($sa, 'zp_save_part_ops('));
t('a refused detail keeps what was typed, and stops there',
  str_contains($sa, '$keepPart = true;') && str_contains($sa, "if (isset(\$_POST['op_name'])) \$keepOps = \$postedOps();"));
t('a new part with no grid yet is created and opened', str_contains($sa, "'Part created. Now add its operations below.'"));
t('operations saved re-feed Costing like before', str_contains($sa, 'zp_bridge_sync_part($pid)'));
t('refused operations say the details DID save, and keep the grid',
  str_contains($sa, "'The part details were saved, but the operations were not: '") && str_contains($sa, '$keepOps = $rows;'));
t('what was typed is what is redrawn', str_contains($pl, 'if (!empty($keepPart)) {') && str_contains($pl, 'if (isset($keepOps)) {'));
t('  and the panel is shown even for a refused new part', str_contains($pl, "if (!\$editId && !isset(\$_GET['part']) && empty(\$keepPart)):"));
t('the old save_part and save_ops still work for anything that posts them',
  str_contains($pl, "=== 'save_part'") && str_contains($pl, "=== 'save_ops'"));
t('writes are still guarded', str_contains($pl, 'verify_csrf()') && str_contains($pl, 'require_login()'));

head('4. Paste from Excel, copy to Excel, and a typo is not a rate');
/* "allow copy and paste from excel just only Operations, stage by stage area".
   Driven in a browser: a 3-column block with a header, a 2-column block running
   into new lines, a column of "Rs 1,250.00" rates, an unknown stage reported,
   a real Ctrl+V, a single value left to the browser, Copy round-trips. */
t('Ctrl+V in the grid is caught', str_contains($pl, "body.addEventListener('paste', function(ev){"));
t('  a single value is left to the browser', str_contains($pl, "if (text.indexOf('\\t') < 0 && text.indexOf('\\n') < 0) return;"));
t('  a header row is skipped', str_contains($pl, "/^(stage|operation|rate)/i.test(String(grid[0][0]).trim())"));
t('  a Stage · Operation · Rate block starts at Stage wherever you clicked',
  str_contains($pl, "if (first.length >= 3 && (stageIdByName(first[0]) || isNum(first[2]))) start = 0;"));
t('  stages are matched by YOUR names, and an unknown one is reported, not guessed',
  str_contains($pl, 'function stageIdByName(v){') && str_contains($pl, "' Stage not found, left as it was: '"));
t('  line 1 keeps the first stage', str_contains($pl, "' Line 1 stays at ' + stageName(FIRST_STAGE)"));
t('  money formatting is cleaned off a pasted rate', str_contains($pl, "box.value = v.replace(/[^\\d.,\\-]/g, '').replace(/,/g, '');"));
t('  lines run on into new rows', str_contains($pl, 'if (!tr) tr = window.zpAddRow();'));
t('  the Save bar learns there are unsaved changes', str_contains($pl, "el.dispatchEvent(new Event('input', { bubbles: true }));"));
t('Copy operations gives Excel cells with a header', str_contains($pl, 'id="opCopy"') && str_contains($pl, "var lines = ['Stage\\tOperation\\tRate'];"));
t('a rate that is not a number is marked red and left out of the total',
  str_contains($pl, 'function badRate(s){') && str_contains($pl, "if (!bad && !isNaN(v)) t += v;"));
$zp = nocomments((string)file_get_contents($B . 'includes/zprod.php'));
t('the server refuses it too, by line, with what was typed ("4..5" used to save as 4)',
  str_contains($zp, "if (\$rawRate !== '' && !is_numeric(\$rawRate))")
  && str_contains($zp, "'\") has the rate \"' . \$rawRate . '\", which is not a number.'"));

head('5. The help text folds away');
t('"How this page works" on the left', str_contains($pl, '<summary>How this page works</summary>'));
t('"How stages and operations work" in the editor, with the long list inside it',
  str_contains($pl, '<summary>How stages and operations work</summary>')
  && strpos($pl, '<summary>How stages and operations work</summary>') < strpos($pl, '<div class="hintbox"'));

echo "\n" . str_repeat('-', 58) . "\n";
echo ($F === 0 ? "ALL PASS" : "FAILURES") . "   $P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
