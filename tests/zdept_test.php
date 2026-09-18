<?php
/* DEPARTMENT ON THE FLOOR BALANCE REPORT.
 *
 * The report was headed "What is out with each DEPARTMENT right now" and
 * showed a location, a material and no department anywhere. The ledger does
 * not carry one — it carries which document a row came from, and the document
 * carries the department. So both kinds of document are joined back.
 *
 * Department is NOT a master record in this system. It is free text on a gate
 * pass, a store move and a consumption, which means the only honest list is
 * the one the documents have written. That is worth a test of its own,
 * because it is also the weakness: three spellings are three departments.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$inv   = file_get_contents($B . 'includes/inventory.php');
$store = file_get_contents($B . 'inv_store.php');

echo "1. Department is gathered from the documents, because that is where it lives\n";
ok(str_contains($inv, 'function inv_departments()'), 'inv_departments() exists');
foreach (['inv_store_move', 'inv_consumption', 'inv_gate'] as $t)
    ok(str_contains($inv, "\$ask('$t');"), "  it asks $t");
ok(str_contains($inv, 'THAT IS ALSO ITS WEAKNESS'),
   'and it says plainly that free text is not a master list');
ok(str_contains($inv, 'strcasecmp'), 'the list sorts without case fighting it');

/* --- run it --- */
final class FStmt {
    public array $rows = [];
    public function __construct(private string $sql, private object $db) {}
    public function execute(array $a = []): bool { $this->rows = ($this->db->answer)($this->sql, $a); return true; }
    public function fetchAll(): array { return $this->rows; }
}
final class FDb {
    public $answer; public array $seen = [];
    public function prepare(string $s) { $this->seen[] = $s; return new FStmt($s, $this); }
    public function query(string $s) { $this->seen[] = $s; $st = new FStmt($s, $this); $st->execute([]); return $st; }
}
$DB = new FDb();
function db() { global $DB; return $DB; }

$DEPTS = [
    'inv_store_move'  => ['Stitching Floor', 'Packing Hall', '  Cutting  '],
    'inv_consumption' => ['packing hall', 'Stitching Floor'],
    'inv_gate'        => ['Dispatch'],
];
$DB->answer = function (string $sql) use (&$DEPTS) {
    foreach ($DEPTS as $t => $list)
        if (str_contains($sql, "FROM $t"))
            return array_map(fn($d) => ['d' => trim($d)], $list);
    return [];
};
$a = strpos($inv, 'function inv_departments()');
$b = strpos($inv, "\n}\n", $a);
eval(substr($inv, $a, $b - $a + 3));

$list = inv_departments();
ok(count($list) === 5, 'five distinct spellings across three tables, got ' . count($list) . ': ' . json_encode($list));
ok(in_array('Cutting', $list, true), '  and they are trimmed, so "  Cutting  " is Cutting');
ok(in_array('Stitching Floor', $list, true), '  the same word on two documents appears once');
/* THE POINT THAT MATTERS TO THE OWNER: this is exactly why it should be a
   master list. "Packing Hall" and "packing hall" are two rows here, and the
   test says so rather than quietly folding them together — folding them
   would make the filter offer one name and match the other. */
ok(in_array('Packing Hall', $list, true) && in_array('packing hall', $list, true),
   'TWO SPELLINGS STAY TWO DEPARTMENTS — the list reports the truth, it does not tidy it');
for ($i = 1; $i < count($list); $i++)
    ok(strcasecmp($list[$i - 1], $list[$i]) <= 0,
       'sorted ignoring case at position ' . $i . ', got ' . json_encode($list));

echo "2. The report shows a department, and can be narrowed to one\n";
$code = preg_replace('!/\*.*?\*/!s', '', $store);
$code = preg_replace('!<\?php /\*.*?\*/ \?>!s', '', $code);

ok(str_contains($code, 'LEFT JOIN inv_store_move  sm ON'), 'store moves are joined back for their department');
ok(str_contains($code, 'LEFT JOIN inv_consumption cn ON'), 'and consumptions too');
ok(str_contains($code, 'COALESCE(sm.department, cn.department)'), '  whichever of the two has one');
ok(str_contains($code, 'GROUP_CONCAT(DISTINCT'), 'a line can name more than one department');
ok(str_contains($code, '<th>Department</th>'), 'the column is on the table the heading always promised');
ok(str_contains($code, 'not stated'),
   'a movement with no department says so, rather than leaving a hole');

ok(str_contains($code, "AND TRIM(COALESCE(sm.department, cn.department)) = ?"),
   'the filter is a bound parameter, never pasted into the SQL');
ok(str_contains($code, '$st = db()->prepare($sql);'), '  and the query is prepared');
ok(!preg_match('/\$fDept[^;]*\.\s*"/', $code), '  the typed value is never concatenated in');

ok(str_contains($code, 'if ($fDept !== \'\' && !in_array($fDept, $depts, true)) $fDept = \'\';'),
   'a department that no longer exists falls back to all, instead of an empty table');
ok(str_contains($code, 'Show all departments'), 'and there is a way back out of the filter');
ok(str_contains($code, 'Nothing is out with <b>'),
   'an empty filtered result says which department is empty');

/* THE SEMANTICS, STATED. Filtering narrows the MOVEMENTS, so every figure on
   a line is that department's own — a different number from "the line total,
   which this department happened to touch". Getting this wrong silently is
   how a report becomes untrustworthy. */
ok(str_contains($store, 'Filtering narrows the MOVEMENTS, not the rows'),
   'the two possible meanings of the filter are settled in writing');
ok(str_contains($code, 'Every figure on a line is that department&rsquo;s own.')
   || str_contains($code, "issued, consumed and returned"),
   '  and said on screen, where the person reading the number is');

echo "3. The form offers what has been typed before\n";
ok(str_contains($code, 'list="deptSeen"'), 'the department box offers past spellings');
ok(str_contains($code, '<datalist id="deptSeen">'), '  from a datalist');
ok(str_contains($store, 'A real master list belongs in'), '  while saying that is not the real fix');

echo "4. Nothing else on the page moved\n";
ok(substr_count($code, '<div class="zskin">') === 1, 'still one skin wrapper');
/* Read from the file, not from $code: the wrapper is CLOSED by a comment,
   and $code has had every comment stripped out of it. Stripping comments
   before grepping is right; forgetting that the thing you are grepping for
   IS a comment is the mistake. */
ok(substr_count($store, 'closes .zskin') === 1, '  closed once');
/* The row and the header must still agree on how many columns there are —
   adding a column to one and not the other is the exact bug this screen
   already had once, on its totals row. */
$th = substr_count($store, '<th>Location</th><th>Department</th><th>Material</th>');
ok($th === 1, 'the header carries the new column');
$a2 = strpos($store, '<th>Location</th><th>Department</th>');
$rowStart = strpos($store, '<tr<?= $stale', $a2);
$rowEnd   = strpos($store, '</tr>', $rowStart);
$row = substr($store, $rowStart, $rowEnd - $rowStart);
ok(substr_count($row, '<td') === 10, 'and the row has the same ten cells, got ' . substr_count($row, '<td'));

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
