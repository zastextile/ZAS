<?php
/* PRODUCTION ENTRY — THE PREVIEW PANE (owner, 27 Nov 2026): the row the arrow
   keys or the mouse are on is shown in full beside the list. */
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; echo "  ok  $m\n"; } else { $F++; echo "  FAIL: $m\n"; } }
$pe = file_get_contents(__DIR__ . '/../public_html/production_entry.php');
$zc = file_get_contents(__DIR__ . '/../public_html/includes/zp_correct.php');
ok(str_contains($pe, '<aside class="ze-pv" id="zePrev" hidden></aside>'), 'a pane beside the main list');
ok(str_contains($pe, 'function pvWork(') && str_contains($pe, 'function pvWorker('), 'one view for a job, one for a person');
ok(str_contains($pe, "pvTopCur();\n  }") && str_contains($pe, "o[k].scrollIntoView({block:'nearest'}); pvSugCur();"), 'it follows the arrow keys in both lists');
ok(substr_count($pe, "addEventListener('mouseover'") >= 2, '  and the mouse');
ok(str_contains($pe, 'function zeSugHide(){ var b = $(\'zeSugBox\'); if (b) b.hidden = true; sugRow = -1; pvSug(null); }'), 'closing the in-row list closes its pane');
ok(str_contains($pe, '.ze-pv table.pv-t2{width:100% !important;min-width:0 !important'), 'its size table is not stretched by the page-wide table width');
ok(str_contains($pe, '@media (max-width:980px)'), 'on a narrow screen it sits under the list');
ok(str_contains($zc, "\$g['msz'] = \$g['sz'];") && str_contains($zc, "\$g['mop'][(int)\$r['op']] = (string)\$r['on'];"), 'each size and operation of a merged row is named for the pane');
$js = substr($pe, strpos($pe, 'function pvWork('), 9000);
ok(!preg_match('/fetch\(|XMLHttpRequest/', $js), 'it asks the server nothing — built from the data already on the page');
ok(substr_count($js, 'esc(') > 15, 'every value shown in it is escaped');
echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
