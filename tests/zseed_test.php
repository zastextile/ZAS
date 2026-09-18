<?php
/* SEEDING THE ITEM MASTER WITHOUT MAKING DUPLICATES.
 *
 * "i want o corect that old costing but if i go manual one by one take time
 *  so allow show click link of each where can amend in side costing and
 *  change item whcih already availble instead saving dublicate nmaes as
 *  separate"
 *
 * Pressing Create on a costing name that is ALMOST an item you already have
 * makes a second item for the same cloth — for ever, and no screen ever
 * shows it. From then on the stock for that cloth is split between two
 * names and neither half is right. The only cheap moment to stop that is
 * before the button.
 */
$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
$inv = file_get_contents($B . 'includes/inventory.php');
$su  = file_get_contents($B . 'inv_setup.php');
$cl  = file_get_contents($B . 'costing_items_link.php');

function lift(string $src, string $from): string {
    $a = strpos($src, $from);
    if ($a === false) return '';
    $b = strpos($src, "\n}\n", $a);
    return substr($src, $a, $b - $a + 3);
}
eval(lift($inv, 'function inv_seed_norm('));
eval(lift($inv, 'function inv_seed_nearest('));

echo "1. Flattening a name\n";
ok(inv_seed_norm('Cotton Fabric-60s') === 'cotton fabric 60s', 'punctuation becomes a space, got ' . inv_seed_norm('Cotton Fabric-60s'));
ok(inv_seed_norm('  COTTON   FABRIC  ') === 'cotton   fabric' || inv_seed_norm('  COTTON   FABRIC  ') === 'cotton fabric',
   'case and edges go, got "' . inv_seed_norm('  COTTON   FABRIC  ') . '"');

$master = [];
foreach ([[1,'FB-0001','Cotton Fabric 60S'], [2,'AC-0001','Button 4-hole'], [3,'AC-0002','Sewing Thread']] as $m)
    $master[] = ['id'=>$m[0], 'code'=>$m[1], 'name'=>$m[2], 'n'=>inv_seed_norm($m[2])];

echo "2. The near match — the whole point\n";
/* THE ONE THAT MATTERS MOST. "Button 4-hole" and "Button 4 hole" are not
   an exact match — the seed compares raw names — so without this they
   become two items for the same button and nothing ever says so. */
$n3 = inv_seed_nearest('Button 4 hole', $master);
ok($n3 && $n3['code'] === 'AC-0001' && $n3['score'] === 100,
   'THE SAME NAME WITH DIFFERENT PUNCTUATION IS CAUGHT, at 100%: ' . json_encode($n3));
$n1 = inv_seed_nearest('Cotton Fabrik 60S', $master);
ok($n1 && $n1['code'] === 'FB-0001', 'a misspelling finds the item: ' . json_encode($n1));
$n2 = inv_seed_nearest('Cotton Fabric', $master);
ok($n2 && $n2['code'] === 'FB-0001', 'a shorter name finds the longer one: ' . json_encode($n2));
$n4 = inv_seed_nearest('Sewing  Thread', $master);
ok($n4 && $n4['code'] === 'AC-0002', 'and doubled spaces do not hide it: ' . json_encode($n4));

/* A WRONG SUGGESTION IS WORSE THAN NONE. It invites one press that merges
   two genuinely different things, and nothing afterwards shows the mistake. */
ok(inv_seed_nearest('Zip 8 inch', $master) === null, 'something genuinely new is NOT matched to anything');
ok(inv_seed_nearest('Polyester Wadding 200gsm', $master) === null, '  nor is a second genuinely new thing');
ok(inv_seed_nearest('', $master) === null, 'an empty name matches nothing');
/* An exact match never reaches this function: the preview marks that row
   "already in master" and skips the near lookup entirely. */
ok(str_contains($inv, "'near' => \$isThere ? null : inv_seed_nearest("),
   'an exact match never asks for a near one — the row already says "already in master"');
ok(inv_seed_nearest('anything', []) === null, 'an empty master suggests nothing');

echo "3. The preview carries it\n";
ok(str_contains($inv, "'near' => \$isThere ? null : inv_seed_nearest("),
   'every row that would be created carries its near match');
ok(str_contains($inv, "'raw'  => \$rawName"),
   '  and the name as the costing really spells it, for the link');
$invF = preg_replace('/\s+/', ' ', $inv);
ok(str_contains($invF, 'Suggested, never applied'), 'and it is suggested, never applied');

echo "4. The screen warns BEFORE the button\n";
ok(str_contains($su, '$seedNear = array_values(array_filter($seedNew'), 'the near matches are counted');
ok(str_contains($su, 'look like something you already have'), 'and named above the table');
ok(str_contains($su, 'would be a DUPLICATE'), 'the row itself says so');
ok(str_contains($su, '<th>Looks like</th>'), 'there is a column for what it looks like');
ok(str_contains($su, 'costing_items_link.php?q='), 'EVERY ROW LINKS STRAIGHT TO SETTLING THAT NAME');
ok(substr_count($su, 'costing_items_link.php') >= 3, '  from the count, the suggestion and the button');
ok(str_contains($su, 'onclick="return confirm('), 'and Create asks once when duplicates are in the list');
ok(str_contains($su, 'Settle old names first'), 'with the other way out next to it');

echo "5. The link lands on the name\n";
ok(str_contains($cl, "\$q = trim((string)(\$_GET['q'] ?? ''));"), 'the settle page takes ?q=');
ok(str_contains($cl, '$qn = cil_norm($q);'), '  matched the same flattened way as everything else here');
ok(str_contains($cl, 'if ($hit) { $qHits = count($rows); $rows = $hit; }'),
   '  and only narrows when something actually matched');
ok(str_contains($cl, 'Showing everything instead'),
   'AN UNMATCHED q SHOWS EVERYTHING — an empty page would read as "the work is done"');
ok(str_contains($cl, 'Show them all'), 'and there is a way back to the full list');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
