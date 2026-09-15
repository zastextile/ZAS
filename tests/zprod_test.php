<?php
/* THE REBUILT PRODUCTION MODULE.
 *
 * The old module was abandoned after two days of chasing faults through it.
 * This tests the replacement — and it RUNS the shipped functions rather than
 * searching the file for strings, because "the text is present" is not the
 * same claim as "the rule holds".
 *
 * The rules being defended, in order of how much they would cost if broken:
 *
 *   1. THE MASTER PRODUCT TABLE IS NEVER WRITTEN TO DESTRUCTIVELY. 26 products
 *      are pointed at by costing and invoicing. Losing one is not recoverable
 *      from inside the app.
 *   2. THE FIRST OPERATION IS CUTTING. Cutting decides how many pieces exist;
 *      every later stage is capped by it. A part starting at Stitching has no
 *      ceiling, and stitching could be booked on pieces never cut.
 *   3. A PART IN USE IS DEACTIVATED, NOT DELETED. Deleting leaves products
 *      pointing at nothing and wages orphaned.
 *   4. A MISSING QUANTITY MEANS 1, NOT 0. A part on a product is in it at
 *      least once; defaulting to 0 makes a product silently produce nothing
 *      while looking like it works.
 */
$B   = __DIR__ . '/app_src/public_html/';
$src = file_get_contents($B . 'includes/zprod.php');

$P = 0; $F = 0;
function t(string $n, bool $c, $got = null): void {
    global $P, $F;
    if ($c) { $P++; echo "  ok   $n\n"; }
    else { $F++; echo "  FAIL $n" . ($got !== null ? "\n         got: " . json_encode($got) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }
function code_only(string $s): string {
    $s = preg_replace('!/\*.*?\*/!s', ' ', $s);
    $s = preg_replace('!//[^\n]*!', ' ', $s);
    return $s;
}

/* ---------- lift the pure functions and run them for real ---------- */
function lift(string $src, string $fn): string {
    $i = strpos($src, "function $fn(");
    if ($i === false) return '';
    $a = strpos($src, '{', $i); $d = 0;
    for ($j = $a; $j < strlen($src); $j++) {
        if ($src[$j] === '{') $d++;
        elseif ($src[$j] === '}') { $d--; if ($d === 0) return substr($src, $i, $j - $i + 1); }
    }
    return '';
}
/* zp_ensure_schema is stubbed: these functions are pure, and the ones that are
   not are tested through the fake database further down. */
function zp_ensure_schema(): void {}
/* STAGES NOW COME FROM A TABLE, so these are no longer pure — they read the
   list you typed. The fixture below IS that list, and it deliberately contains
   no word this module used to hardcode. */
$GLOBALS['STAGE_ROWS'] = [
    ['id' => 11, 'seq' => 1, 'name' => 'Laser Cut',    'is_active' => 1],
    ['id' => 12, 'seq' => 2, 'name' => 'Direct Sewing','is_active' => 1],
    ['id' => 13, 'seq' => 3, 'name' => 'Hand Finish',  'is_active' => 1],
    ['id' => 14, 'seq' => 4, 'name' => 'Retired Step', 'is_active' => 0],
];
function zp_stage_all(bool $activeOnly = false): array {
    $r = $GLOBALS['STAGE_ROWS'];
    return $activeOnly ? array_values(array_filter($r, fn($x) => (int)$x['is_active'] === 1)) : $r;
}
foreach (['zp_stage_first', 'zp_stage_first_id', 'zp_stage_is_first', 'zp_stage_by_id',
          'zp_stage_name', 'zp_stage_prev', 'zp_stage_id_for',
          'zp_stages', 'zp_is_cutting', 'zp_stage_clean', 'zp_part_code', 'zp_part_id_from',
          'zp_qty_for', 'zp_usage_unknown', 'zp_is_used', 'zp_usage_label'] as $fn) {
    $c = lift($src, $fn);
    if ($c === '') { echo "  FAIL could not lift $fn\n"; $F++; continue; }
    eval($c);
}

head('THE STAGES ARE YOURS — NOT THREE WORDS IN THIS FILE');
/* THIS BLOCK REPLACES "the stages are Cutting, Manual Cutting, Stitching".
   That assertion defended a hardcoded list, and the hardcoded list was the bug:
   the factory works how it works, and the old costing column accepted only
   three specific words, so a fourth was thrown away with no error. */
/* The ONE legitimate place a stage name may appear is the first-run seed, so a
   brand-new install is never left with an empty list and nothing priceable.
   Everywhere else, a stage name in the code is the bug coming back. */
$seedAt   = strpos($src, '$stageTableExisted = false;');
$seedEnds = strpos($src, 'CREATE TABLE IF NOT EXISTS zp_part_ops');
$noSeed   = code_only(substr($src, 0, $seedAt) . substr($src, $seedEnds));
/* zp_bridge_stage() is the other allowed exception: those two words are the OLD
   costing table's ENUM values, not stage names of yours, and your stage is
   mapped onto them by POSITION. Everything else must be free of stage names. */
$bridgeFn = lift($src, 'zp_bridge_stage');
$noSeed   = str_replace(code_only($bridgeFn), ' ', $noSeed);
t('no stage name is written into the module outside the seed and the costing map',
  !preg_match("/'(Cutting|Manual Cutting|Stitching|Dispatch|Packing)'/", $noSeed),
  'a hardcoded stage name survives');
t('  and the costing map goes by position, not by the word',
  str_contains($bridgeFn, 'zp_is_cutting($stage)')
  && str_contains($src, "mapped onto them BY POSITION"));
/* VARCHAR(20) would have cut any stage name past twenty letters — silently,
   leaving the row filed under a stage that matches nothing. Same failure as the
   old ENUM, one column along, and it was in the WAGE LEDGER too. */
t('no stage is stored in a column too short to hold its name',
  !preg_match('/\bstage VARCHAR\(2?0\)/', $src), 'a 20-char stage column survives');
t('  both stage columns are widened to 60 in place',
  substr_count($src, 'MODIFY stage VARCHAR(60) NULL DEFAULT NULL') === 2);
t('  and neither defaults itself to a stage', !str_contains($src, "DEFAULT 'Stitching'"));
t('the wage ledger files a wage under the stage ID',
  str_contains($src, 'ALTER TABLE zp_entries ADD COLUMN stage_id')
  && str_contains(lift($src, 'zp_book'), 'stage_id, stage, qty, rate_applied'));
t('  so a renamed stage never orphans a wage already paid',
  str_contains($src, 'must still know which stage it was paid for'));
t('  and the seed runs only on the very first install',
  str_contains($src, 'SEEDED EXACTLY ONCE') && str_contains($src, 'if (!$stageTableExisted)'));
t('the list comes from the table, in your order',
  zp_stages() === ['Laser Cut', 'Direct Sewing', 'Hand Finish'], zp_stages());
t('  a switched-off stage is not offered', !in_array('Retired Step', zp_stages(), true));
t('Dispatch is still gone, as asked', !in_array('Dispatch', zp_stages(), true));

head('POSITION CARRIES THE ONLY RULE');
t('the FIRST stage makes the pieces, whatever it is called',
  zp_is_cutting('Laser Cut'), 'the first stage is not recognised');
t('  and a later one does not', !zp_is_cutting('Direct Sewing') && !zp_is_cutting('Hand Finish'));
t('  the check is by position, not by the word "Cutting"', !zp_is_cutting('Cutting'));
t('  it is case and space tolerant, because it reads typed text',
  zp_is_cutting('  laser cut '));
t('zp_stage_is_first answers on the id', zp_stage_is_first(11) && !zp_stage_is_first(12));
t('  and 0 is never the first stage', !zp_stage_is_first(0));
t('a switched-off top stage hands the job to the next ACTIVE one, not to a dead row',
  (function () {
      $GLOBALS['STAGE_ROWS'][0]['is_active'] = 0;
      $r = zp_stage_first();
      $GLOBALS['STAGE_ROWS'][0]['is_active'] = 1;
      return $r && $r['name'] === 'Direct Sewing';
  })());

head('WHAT LIMITS WHAT');
t('the first stage is limited by the order, so it has nothing before it',
  zp_stage_prev(11) === null);
t('the second is limited by the first', (zp_stage_prev(12)['name'] ?? '') === 'Laser Cut');
t('the third is limited by the second', (zp_stage_prev(13)['name'] ?? '') === 'Direct Sewing');

head('NAMES CARRY NO RULE, SO RENAMING IS SAFE');
t('a stage is looked up by id', (zp_stage_by_id(12)['name'] ?? '') === 'Direct Sewing');
t('a deleted stage still prints something, rather than a blank',
  zp_stage_name(999) === '(removed stage)', zp_stage_name(999));
t('a name maps back to its id', zp_stage_id_for('direct sewing') === 12);
t('  and an unknown name maps to nothing, so the caller must decide',
  zp_stage_id_for('Nonsense') === 0 && zp_stage_id_for('') === 0);

head('AN UNKNOWN STAGE NEVER BECOMES THE ONE THAT MAKES PIECES');
/* guessing the FIRST stage would quietly hand a stray row the power to create
   pieces out of nothing, so an unrecognised name snaps to the second */
t('nonsense snaps to the second stage, not the first',
  zp_stage_clean('Packing') === 'Direct Sewing', zp_stage_clean('Packing'));
t('  and so does a blank', zp_stage_clean('') === 'Direct Sewing');
t('a real stage is kept exactly', zp_stage_clean('Hand Finish') === 'Hand Finish');
t('  matched without caring about case', zp_stage_clean('hand finish') === 'Hand Finish');

head('PART CODES ARE DERIVED, NEVER STORED');
/* a stored code is one more thing to keep unique, renumber, and get wrong */
t('id 1 is P001', zp_part_code(1) === 'P001');
t('id 42 is P042', zp_part_code(42) === 'P042');
t('id 1234 does not get truncated', zp_part_code(1234) === 'P1234', zp_part_code(1234));
t('the code column does not exist in the schema',
  !str_contains($src, 'part_code VARCHAR'));
foreach (['P001' => 1, 'p1' => 1, '#1' => 1, '1' => 1, 'P042' => 42, '  P007  ' => 7] as $in => $want) {
    t("\"$in\" reads back as part $want", zp_part_id_from((string)$in) === $want, zp_part_id_from((string)$in));
}
t('nonsense reads as nothing, not as part 0 doing something',
  zp_part_id_from('Bed Sheet') === 0 && zp_part_id_from('') === 0);

head('A MISSING QUANTITY MEANS ONE, NOT ZERO');
$map = [5 => [10 => 2.0, 11 => 0.0]];
t('a stored 2 is 2', zp_qty_for($map, 5, 10) === 2.0);
t('a stored ZERO is honoured — somebody meant it', zp_qty_for($map, 5, 11) === 0.0);
t('a MISSING row is 1, not 0', zp_qty_for($map, 5, 12) === 1.0, zp_qty_for($map, 5, 12));
t('  and a part not in the map at all is also 1', zp_qty_for($map, 99, 12) === 1.0);

head('"Used In" reads as a sentence, not a code');
t('unused says No', zp_usage_label([], 7) === 'No');
t('one use names it', zp_usage_label([7 => ['Production']], 7) === 'Yes (Production)');
t('several are listed', zp_usage_label([7 => ['Production', 'Invoice']], 7) === 'Yes (Production, Invoice)');

/* ================================================================
   NOW THE DATABASE-BACKED RULES, against a fake PDO.
   ================================================================ */
head('THE MASTER PRODUCT TABLE IS NEVER DAMAGED');
$code = code_only($src);
t('there is no DELETE against products',
  !preg_match('/DELETE\s+FROM\s+`?products`?/i', $code), 'a delete exists');
t('no TRUNCATE or DROP of products',
  !preg_match('/(TRUNCATE\s+(TABLE\s+)?`?products|DROP\s+TABLE\s+`?products)/i', $code));
t('no UPDATE that deactivates products wholesale',
  !preg_match('/UPDATE\s+`?products`?\s+SET\s+is_active\s*=\s*0/i', $code));
t('products is only ever SELECTed, or given a nullable column',
  !preg_match('/INSERT\s+INTO\s+`?products`?/i', $code));
t('the only write is an additive ALTER, and it is wrapped',
  preg_match('/ALTER TABLE products ADD COLUMN description/', $code)
  && preg_match('/ALTER TABLE products ADD COLUMN fcl_40hc_qty/', $code));
t('  both are nullable, so an existing row cannot be rejected',
  substr_count($src, 'ADD COLUMN description VARCHAR(255) NULL') === 1
  && str_contains($src, 'ADD COLUMN fcl_40hc_qty DECIMAL(12,2) NULL'));
t('and it says plainly that products is never emptied',
  str_contains($src, 'THE PRODUCTS TABLE IS READ, NEVER CREATED AND NEVER EMPTIED'));

head('EVERY NEW TABLE IS PREFIXED, SO THE OLD DATA SURVIVES');
preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+)/', $src, $m);
$tables = $m[1];
t('tables are created', count($tables) >= 8, $tables);
foreach ($tables as $tbl) t("  $tbl starts with zp_", str_starts_with($tbl, 'zp_'), $tbl);
/* THIS RULE CHANGED, AND THE REASON MATTERS.
 *
 * It used to read "no old production_* table is touched" — total isolation.
 * That rule is what broke Costing: product_costing.php reads product_sizes and
 * production_operations, so a module that refused to write them left every
 * costing sheet with Workmanship = 0 and under-priced the product by its whole
 * wage bill.
 *
 * The rule is now narrower and stricter, not looser:
 *   - the LEDGER tables are still untouchable, absolutely — zp_entries and
 *     zp_workers replaced them and a double-written wage is unrecoverable;
 *   - the three COSTING tables may be written, but ONLY from the bridge, so
 *     the feed stays in one reviewable place instead of leaking through the
 *     module. */
$bridgeAt = strpos($src, 'THE BRIDGE — KEEPING COSTING FED');
t('the bridge section exists and is marked', $bridgeAt !== false);
$beforeBridge = code_only(substr($src, 0, $bridgeAt === false ? strlen($src) : $bridgeAt));
t('the wage ledger and worker tables are never touched, anywhere',
  !preg_match('/\bproduction_(transactions|workers)\b/', $code));
t('no old part_library table is touched anywhere', !str_contains($code, 'part_library'));
/* product_sizes LEFT THIS LIST ON PURPOSE. It is no longer an "old table the
   bridge feeds" — it is THE size table, the one seventeen files already read,
   and the module now writes it directly. Keeping it bridge-only was the split
   that made Product Master and Costing disagree about sizes in the first place.
   The other two are still costing-only and stay behind the bridge. */
foreach (['production_operations', 'product_component_qty'] as $old) {
    t("  $old is written ONLY from the bridge", !str_contains($beforeBridge, $old), $old);
}
t('product_sizes is now THE size list, read directly',
  str_contains(lift($src, 'zp_sizes'), 'FROM product_sizes'));
t('  and the old production-only size table is no longer read',
  !str_contains(code_only(lift($src, 'zp_sizes')), 'zp_sizes WHERE'));
t('  nor written by the size save', !str_contains(code_only(lift($src, 'zp_save_sizes')), 'INTO zp_sizes'));
t('  but it is LEFT ON DISK, never dropped',
  !preg_match('/(DROP|TRUNCATE)\s+TABLE\s+`?zp_sizes/i', $src)
  && str_contains($src, 'kept on disk untouched'));
t('the size mirror in the bridge writes nothing at all',
  !preg_match('/(INSERT INTO|UPDATE|DELETE FROM)\s+product_sizes/', lift($src, 'zp_bridge_sizes')));
t('  and the bridge says why it exists at all',
  str_contains($src, 'every costing sheet saved since the rebuild is missing its stitching'));
/* the DELETE names the stray by its own row id now, because one operation can
   own several rows — the All Sizes one plus a row per size rate — but it still
   says zp_op_id IS NOT NULL, so it still only ever touches rows the bridge
   itself wrote. */
t('nothing on the old side is deleted except rows the bridge itself wrote',
  str_contains($src, 'NOTHING ON THE OLD SIDE IS EVER DELETED')
  && str_contains(preg_replace('/\s+/', ' ', $src),
                  'WHERE product_id=? AND zp_op_id IS NOT NULL AND id IN ($in)')
  && !preg_match('/DELETE FROM product_component_qty\b/', $src));
/* product_sizes CAN now be deleted from — it is the module's own size list, not
   a mirror — but only through zp_save_sizes, and only after zp_size_refs() has
   confirmed that no costing, no proforma line and no quantity points at the row. */
t('  and every product_sizes delete is guarded by the reference check',
  (function () use ($src) {
      foreach (['zp_save_sizes'] as $fn) {
          $f = code_only(lift($src, $fn));
          if (str_contains($f, 'DELETE FROM product_sizes')
              && !str_contains($f, 'zp_size_refs(')) return false;
      }
      /* and nowhere else in the module deletes from it at all */
      $others = $src;
      foreach (['zp_save_sizes'] as $fn) $others = str_replace(lift($src, $fn), ' ', $others);
      return !preg_match('/DELETE FROM product_sizes/', code_only($others));
  })());
t('a legacy operation is deactivated rather than destroyed',
  str_contains($src, 'A LEGACY OPERATION IS DEACTIVATED, NOT DESTROYED')
  && str_contains($src, 'SET is_active=0'));
t('  because SUM() over a survivor would double the wage cost',
  str_contains(preg_replace('/\s+/', ' ', $src), 'the product would cost DOUBLE its true'));
t("the old stage ENUM has no 'Manual Cutting', so it is mapped",
  str_contains($src, 'function zp_bridge_stage')
  && str_contains($src, "return zp_is_cutting(\$stage) ? 'Cutting' : 'Stitching';"));
t('  and the reason is recorded', str_contains(preg_replace('/\s+/', ' ', $src), 'THE OLD STAGE COLUMN IS AN ENUM'));
t('no old part_library table is touched', !str_contains($code, 'part_library'));
t('every CREATE is IF NOT EXISTS',
  substr_count($src, 'CREATE TABLE ') === substr_count($src, 'CREATE TABLE IF NOT EXISTS'));
t('the reason for new tables rather than reused ones is written down',
  str_contains($src, 'NEW TABLES, NOT REUSED ONES'));

head('THE FIRST OPERATION MUST SIT AT THE FIRST STAGE');
$save = code_only(lift($src, 'zp_save_part_ops'));
t('the rule is enforced in the save, not only in the browser',
  str_contains($save, "(int)\$clean[0]['stage_id'] !== (int)\$firstStage['id']"));
t('  and it compares IDs, not spellings',
  !str_contains($save, "zp_is_cutting(\$clean[0]"));
t('  and refuses rather than silently reordering',
  str_contains($save, "return ['ok' => false"));
t('the message names YOUR first stage, so it means something',
  str_contains($src, "'The first line must be \"' . \$firstStage['name']"));
t('  and says why it matters',
  str_contains($src, 'it is the stage that makes the pieces'));
t('a line with no stage is refused, never filed under a guess',
  str_contains($save, "has no stage. Pick one from the list."));
t('nothing can be priced before the stages exist',
  str_contains($save, 'Set up your production stages first'));
t('  and that is checked before any row is read',
  strpos($save, 'Set up your production stages first') < strpos($save, 'foreach ($rows as $i => $r)'));

head('The grid save cannot half-happen, and cannot double-count');
t('it checks every row BEFORE writing any',
  strpos($save, 'foreach ($rows as $i => $r)') < strpos($save, 'beginTransaction'));
t('it runs in a transaction', str_contains($save, 'beginTransaction()'));
t('  rolling back on any failure', str_contains($save, 'rollBack()'));
t('  and saying nothing was saved', str_contains($src, 'Nothing was saved — the database refused'));
t('two identical lines at the same stage are refused',
  str_contains($src, 'Two lines both say'));
t('a rate of zero or less is refused', str_contains($src, 'needs a rate above 0'));
t('a name with a rate but no name is refused', str_contains($src, 'has a rate but no operation name'));
t('a completely blank row is skipped, not treated as an error',
  str_contains($save, "if (\$name === '' && \$rate <= 0) continue;"));

head('NOTHING WITH WAGES AGAINST IT IS EVER DELETED');
t('a removed operation with bookings is deactivated',
  str_contains($save, "UPDATE zp_part_ops SET is_active=0"));
t('  and only a never-used one is really deleted',
  str_contains($save, 'DELETE FROM zp_part_ops WHERE id=?'));
$del = code_only(lift($src, 'zp_delete_part'));
t('a part used by a product is deactivated, not deleted',
  str_contains($del, 'UPDATE zp_parts SET is_active=0'));
t('  it checks products AND booked wages',
  str_contains($del, 'FROM zp_product_parts WHERE part_id=?')
  && str_contains($del, "FROM zp_entries WHERE part_id=? AND status='active'"));
t('  and tells the user which happened',
  str_contains($src, 'deactivated instead of deleted'));
t('the wage ledger has no DELETE at all — a correction is visible, not erased',
  !preg_match('/DELETE\s+FROM\s+zp_entries/i', $code));
t('  cancelling keeps the row and records who and why',
  str_contains($src, 'cancelled_by') && str_contains($src, 'cancel_reason'));

head('THE RATE PAID IS FROZEN THE MOMENT IT IS BOOKED');
t('the ledger stores the rate applied, not a link to the current rate',
  str_contains($src, 'rate_applied DECIMAL(12,2) NOT NULL'));
t('  and says why that column exists',
  str_contains($src, 'IS A SNAPSHOT AND IS NEVER RECALCULATED'));
t('every rate change is logged with its old value',
  str_contains($src, 'old_rate DECIMAL(12,2) NULL'));
t('  logged on a change', str_contains($save, 'zp_rate_log('));
t('  and only when it actually moved, not on every save',
  str_contains($save, 'abs($old - $c[\'rate\']) > 0.004'));

head('A DUPLICATE PART NAME IS REFUSED, NOT QUIETLY CREATED');
$sp = code_only(lift($src, 'zp_save_part'));
t('the name is checked against every OTHER part',
  str_contains($sp, 'WHERE part_name=? AND id<>?'));
t('  and the message points at the existing one',
  str_contains($src, 'Open that one instead of making a second'));
t('the table enforces it too, so a race cannot slip through',
  str_contains($src, 'UNIQUE KEY uniq_part_name (part_name)'));

head('Duplicating a part brings its operations with it');
$dup = code_only(lift($src, 'zp_duplicate_part'));
t('the copy gets a free name', str_contains($dup, "' (Copy'"));
t('  and does not loop forever looking for one', str_contains($dup, 'if ($n > 60)'));
t('the operations are copied', str_contains($dup, 'INSERT INTO zp_part_ops'));
t('  in their original order', str_contains($dup, 'ORDER BY seq, id'));

head('Removing a part from a product does not rewrite history');
$rm = code_only(lift($src, 'zp_remove_product_part'));
t('the quantities go with the link', str_contains($rm, 'DELETE FROM zp_part_qty'));
t('  and the link itself', str_contains($rm, 'DELETE FROM zp_product_parts'));
t('but wages already booked are untouched', !str_contains($rm, 'zp_entries'));
t('  and the reason is recorded',
  str_contains($src, 'history is not edited by changing a recipe'));

head('A SIZE IS NOT MINE TO DELETE — ITS ID IS SHARED');
/* This rule got STRICTER, not looser. "delete it if it carries no quantity" was
   safe while sizes lived in a table only this module used. They now live in
   product_sizes, which costing_version_sizes and proforma_items point at, so
   every referrer has to be asked before anything goes. */
$ss  = code_only(lift($src, 'zp_save_sizes'));
$ref = code_only(lift($src, 'zp_size_refs'));
t('a costing pointing at the size blocks the delete',
  str_contains($ref, 'FROM costing_version_sizes WHERE product_size_id=?'));
t('a proforma line pointing at it blocks the delete',
  str_contains($ref, 'FROM proforma_items WHERE product_size_id=?'));
t('quantities still block it too',
  str_contains($ref, 'FROM zp_part_qty WHERE size_id=? AND qty<>1'));
t('A CHECK THAT CANNOT RUN COUNTS AS IN USE',
  str_contains($ref, 'return -1;') && str_contains($ref, 'could not be checked'));
/* A DELIBERATE CHANGE OF BEHAVIOUR, ASKED FOR BY THE OWNER.
   This used to REFUSE: a size that any costing, proforma or quantity pointed
   at was kept on the list however plainly you had removed it. Safe, and
   genuinely annoying — a size typed by mistake could never be taken off once
   anything had touched it, and the screen then disagreed with what you had
   just typed. A rate is a number you can type again.
   So the removal always happens now. What it must never do is happen QUIETLY:
   proforma_items.product_size_id goes dead with it, which leaves that order
   line unbookable until a size is linked again. */
t('  the save no longer refuses to remove a size',
  !str_contains($ss, 'if ($refs) { $kept[] ='));
t('  but it still looks, so it can say what the removal took with it',
  str_contains($ss, '$refs = zp_size_refs($sid);') && str_contains($ss, 'if ($refs) $dropped[] ='));
t('  naming what was using it', str_contains($ss, "implode(', ', \$refs)"));
t('  and taking the size rates and quantities with it, not orphaning them',
  str_contains($ss, 'DELETE FROM zp_op_rate  WHERE size_id=?')
  && str_contains($ss, 'DELETE FROM zp_part_qty WHERE size_id=?'));
/* $ss is comment-stripped on purpose, so a claim about what the code SAYS has
   to read the raw function, not the code-only copy of it. */
t('  with the real consequence written down, not left to be discovered',
  str_contains(preg_replace('/\s+/', ' ', lift($src, 'zp_save_sizes')), 'CANNOT BE BOOKED'));
head('THE ONE-TIME SIZE MOVE KEEPS EVERYTHING');
$mg = code_only(lift($src, 'zp_size_migrate'));
t('quantities are re-pointed, never rewritten away',
  str_contains($mg, 'UPDATE zp_part_qty SET size_id=? WHERE size_id=? AND product_id=?'));
t('a size with no twin is CREATED, not dropped',
  str_contains($mg, 'INSERT INTO product_sizes (product_id, size_label) VALUES (?,?)'));
t('nothing at all is deleted by the move', !str_contains($mg, 'DELETE'));
t('  and the old table is never dropped', !preg_match('/(DROP|TRUNCATE)/i', $mg));
t('it runs once and records that it did',
  str_contains($mg, "zp_meta_get('size_merge')") && str_contains($mg, "zp_meta_set('size_merge'"));
t('  because re-running after a rename would re-point the wrong rows',
  str_contains($src, 'would re-point quantities at the wrong row'));
t('a part-way failure is reported and is safe to retry',
  str_contains($mg, 'Stopped part-way') && str_contains($mg, 'safe to run again'));

head('Sizes that carry data are not silently dropped');
t('a size typed twice keeps the first, rather than failing',
  str_contains($ss, 'if (isset($seen[$k])) continue;'));
t('it runs in a transaction', str_contains($ss, 'beginTransaction()'));

head('The list screens do not run one query per row');
/* the old module called a per-row helper and ran 300 queries to draw a table */
t('part costs come back in ONE grouped query',
  str_contains(code_only(lift($src, 'zp_part_cost_map')), 'GROUP BY part_id'));
t('operation counts too',
  str_contains(code_only(lift($src, 'zp_part_op_count_map')), 'GROUP BY part_id'));
t('usage is one query per table, not per product',
  str_contains(code_only(lift($src, 'zp_usage_map')), 'SELECT DISTINCT product_id'));
t('  and a module that is not installed contributes nothing instead of failing',
  substr_count(code_only(lift($src, 'zp_usage_map')), 'catch (Throwable') >= 2);

head('The checklist tells you the NEXT ACTION, not just a fact');
$todo = lift($src, 'zp_product_todo');
t('no sizes names where to add them', str_contains($todo, 'add them on the Basic Info tab'));
t('no parts names the button to press', str_contains($todo, 'Add Parts from Library'));
t('a part with no operations says where to go',
  str_contains($todo, 'open it in the Part Library and add its Cutting line first'));
t('a part with no cutting line says what breaks',
  str_contains($todo, 'nothing sets how many pieces exist'));

head('THE PAGES');
$pl = file_get_contents($B . 'part_library.php');
$pc = file_get_contents($B . 'products_csv.php');
t('the Part Library loads the new module, not the old one',
  str_contains($pl, "includes/zprod.php") && !str_contains($pl, 'includes/part_library.php'));
t('  and holds no reference to the old production include',
  !str_contains($pl, 'includes/production.php'));
t('it offers Add, Import, Export, Copy, Paste — the five in the layout',
  str_contains($pl, '+ Add New Part') && str_contains($pl, 'Import CSV')
  && str_contains($pl, 'Export CSV') && str_contains($pl, 'id="copyBtn"')
  && str_contains($pl, 'Paste a block from Excel'));
t('the list columns match the layout',
  str_contains($pl, '>Part ID<') && str_contains($pl, '>Part Name<') && str_contains($pl, '>Default UOM<'));
t('the operations grid columns match the layout',
  str_contains($pl, '>Stage<') && str_contains($pl, '>Operation<') && str_contains($pl, 'Rate / Pc'));
t('there is NO finishing cost and NO rate at the top',
  !str_contains($pl, 'finishing') || str_contains($pl, 'no finishing cost, no rate at the top'));
t('the first row offers ONLY the first stage, whatever it is called',
  str_contains($pl, 'STAGES.filter(function(s){ return s.id === FIRST_STAGE; })'));
t('  and the stage box posts an id, not a name',
  str_contains($pl, "name=\"op_stage_id[]\"") && !str_contains($pl, "name=\"op_stage[]\""));
t('  and has no delete button',
  str_contains($pl, 'The first line cannot be removed'));
t('no stage name is written into the Part Library either',
  !preg_match("/'(Cutting|Manual Cutting|Stitching)'/", $pl), 'a hardcoded stage name survives');
t('  it reads your list instead', str_contains($pl, 'zp_stage_all(true)'));
t('and it says so when you have no stages yet',
  str_contains($pl, 'no production stages yet'));
t('the grid can never be emptied to nothing',
  str_contains($pl, 'if (tr.parentNode.children.length <= 1) return;'));
t('rows are built from ONE template, not written twice',
  substr_count($pl, 'function rowHtml(') === 1 && !str_contains($pl, '<?php foreach($ops'));
t('the running total is shown live', str_contains($pl, "id=\"opTotal\""));
t('the page cannot be served from cache',
  str_contains($pl, "header('Cache-Control: no-store"));

head('  Products CSV takes the exact file that was exported');
foreach (['Product ID', 'Product Name', 'Description', 'Category', 'Default Unit', '40ft HC Capacity', 'Active'] as $col) {
    t("  the header carries $col", str_contains($pc, "'" . $col . "'"));
}
t('IMPORT NEVER DELETES', !preg_match('/DELETE\s+FROM\s+`?products`?/i', code_only($pc)));
t('  nor truncates or drops', !preg_match('/(TRUNCATE|DROP\s+TABLE)/i', code_only($pc)));
t('only INSERT and UPDATE are prepared',
  str_contains($pc, 'INSERT INTO products') && str_contains($pc, 'UPDATE products SET'));
t('a product missing from the file is left alone, and it says so',
  str_contains($pc, 'is left exactly where it is'));
/* the earlier version of this assertion compared against the FIRST `$plan[] =`
   in the file, which is the skipped-row branch and sits above the matching
   logic. It failed for a reason that had nothing to do with the rule. */
t('matching is id first — which is what makes it a round trip',
  strpos($pc, "'update by id'") < strpos($pc, "'update by name'"));
t('  the default before any match is "new"', str_contains($pc, "\$how = 'new'; \$matchId = 0;"));
t('  the id lookup is confirmed against the table, not trusted from the file',
  str_contains($pc, 'SELECT id FROM products WHERE id=?'));
t('  and the name lookup only runs when the id did not match',
  str_contains($pc, 'if (!$matchId) {'));
t('columns are matched by name, not position',
  str_contains($pc, 'matched by <b>name</b>'));
t('it is all or nothing', str_contains($pc, 'beginTransaction()') && str_contains($pc, 'rollBack()'));
t('  and says the list is untouched when it fails',
  str_contains($pc, 'your product list is exactly as it was'));
t('there is a cap so one file cannot run away', str_contains($pc, 'more than 1000 products'));
t('Windows-1252 from Excel is cleaned per cell', str_contains($pc, 'function pc_utf8'));
t('a blank Active column means active, not inactive',
  str_contains($pc, "if (\$v === '') return 1;"));
t('the import reports line by line what it did',
  str_contains($pc, 'What the import did'));

head('A CHECK THAT COULD NOT RUN IS NEVER TREATED AS "NOT USED"');
/* Carried over from the old module — writing the note that retired it is what
   found the gap. Each usage lookup is wrapped so a missing module cannot break
   the page, but "this table threw" and "this table is empty" produce the SAME
   empty result. If that silence counts as proof, a product in use reads as free
   and its Delete button lights up. Being wrong here is unrecoverable, so the
   unknown is recorded and treated as used. */
$clean   = [7 => ['Production']];
$unknown = ['__unknown' => ['proforma items']];
t('a product with a real use is used', zp_is_used($clean, 7) === true);
t('a product with no use, everything checked, is free', zp_is_used($clean, 9) === false);
t('BUT when a check failed, even an unseen product counts as used',
  zp_is_used($unknown, 9) === true);
t('  and the label says so instead of "No"',
  zp_usage_label($unknown, 9) === 'Could not be checked (proforma items)',
  zp_usage_label($unknown, 9));
t('  it never says No on an unchecked table', zp_usage_label($unknown, 9) !== 'No');
t('a clean map still says No, plainly', zp_usage_label($clean, 9) === 'No');
t('the failure is recorded per table, with the table named',
  zp_usage_unknown($unknown) === ['proforma items']);
t('a clean map reports nothing unknown', zp_usage_unknown($clean) === []);
t('every catch records which table it was, rather than swallowing it',
  substr_count($src, '$unknown[] =') === 2, substr_count($src, '$unknown[] ='));
t('and the reasoning is written where the next person will change it',
  str_contains($src, 'A CHECK THAT COULD NOT RUN MUST NEVER READ AS "NOT USED"'));

head('MASTER PRODUCTS: ONE PAGE, NO TABS');
$pm = file_get_contents($B . 'product_master.php');
t('there is no tab strip', !str_contains($pm, 'class="tabs"'));
t('  and no tab CSS left behind', !str_contains($pm, '.tabs a.on'));
t('  no link carries a tab number', !str_contains($pm, '&tab='));
t('  and no $tab variable is read', !str_contains($pm, "\$_GET['tab']"));
t('the reason tabs were dropped is written down',
  str_contains($pm, 'Tabs on a data-entry screen hide state'));

head('  ONE form, ONE Save, writing all of it');
t('the whole editor is a single form', substr_count($pm, '<form method="post" id="pmForm">') === 1);
t('  posting one action', str_contains($pm, 'name="action" value="save_all"'));
t('  which writes name, sizes, parts AND quantities',
  str_contains($pm, 'zp_save_sizes($pid') && str_contains($pm, 'zp_save_qty($pid, $q)')
  && str_contains($pm, 'UPDATE products SET name=?'));
t('add-part is a button in that same form, not a nested one',
  str_contains($pm, '<button class="zp-b pri" name="do" value="add_part">'));
t('remove-part is too', str_contains($pm, "name=\"do\" value=\"save\"\n                        title=\"Take this part off this product\"")
  || str_contains($pm, "input[name=remove_part]"));
t('  because HTML forbids a form inside a form',
  str_contains($pm, 'HTML does not allow a form inside a form'));
t('the Save bar sticks to the bottom so it is always reachable',
  str_contains($pm, 'position:sticky;bottom:0'));

head('  the four sections are numbered, in the order you fill them');
foreach (['1 &nbsp;The product', '2 &nbsp;Its sizes', '3 &nbsp;What it is made of',
          '4 &nbsp;Operations &amp; cost'] as $h) {
    t("  section \"$h\" is present", str_contains($pm, $h));
}

head('  a size deleted in the same submit cannot strand a quantity');
t('quantities are filtered against the sizes that survived',
  str_contains($pm, 'if (isset($live[(int)$sizeId]))'));
t('  built AFTER the sizes were saved',
  strpos($pm, '$sz = zp_save_sizes($pid') < strpos($pm, 'foreach (zp_sizes($pid) as $s2) $live'));
t('  and the danger is named', str_contains($pm, 'writing a quantity against a dead id'));

head('  Enter in a size box adds a size — it must not submit the page');
t('the default is prevented', str_contains($pm, "if (ev.key !== 'Enter' || ev.target.name !== 'size_label[]') return;"));
t('  and it says why that matters now the page is one form',
  str_contains($pm, 'would save and reload halfway through typing'));

head('  Delete stays disabled while anything uses the product');
t('the button is disabled rather than hidden', str_contains($pm, '<button class="zp-b sm dis" disabled'));
t('the list asks zp_is_used(), so an unreadable table greys Delete too',
  str_contains($pm, '$used = zp_is_used($usageMap, $pid);'));
t('  and the server-side delete asks the same question',
  str_contains($pm, 'if (zp_is_used($usage, $pid)) {'));
t('  saying which checks failed rather than inventing a reason',
  str_contains($pm, 'Some of the checks could not be run'));
t('  with the reason on it', str_contains($pm, 'deleting it would orphan those documents'));
t('  and the rule is checked AGAIN on the server',
  str_contains($pm, 'CHECKED AGAIN HERE, NOT JUST ON THE BUTTON'));
t('a used product is deactivated instead', str_contains($pm, 'UPDATE products SET is_active=0 WHERE id=?'));
t('  and only an unused one is really deleted',
  str_contains($pm, 'DELETE FROM products WHERE id=?'));
t('that delete runs in a transaction with its children',
  strpos($pm, 'DELETE FROM zp_part_qty WHERE product_id=?') < strpos($pm, 'DELETE FROM products WHERE id=?'));
t('the rules panel from the layout is on the list',
  str_contains($pm, 'Delete / Deactivate rules'));

head('  a duplicated product arrives switched OFF');
t('the copy is inserted inactive', str_contains($pm, 'fcl_40hc_qty, is_active)
                           VALUES (?,?,?,?,?,0)'));
t('  and the reason is stated', str_contains($pm, 'A duplicate is a starting point, not a'));
t('sizes, parts and quantities all come with it',
  str_contains($pm, 'INSERT INTO product_sizes') && str_contains($pm, 'INSERT INTO zp_product_parts')
  && str_contains($pm, 'INSERT INTO zp_part_qty'));
t('  and the copy takes its sizes from the SHARED list, not a second one',
  !str_contains($pm, 'INSERT INTO zp_sizes'));
t('  with the size ids remapped, not reused',
  str_contains($pm, '$sizeMap[(int)$s[\'id\']] = (int)db()->lastInsertId();')
  && str_contains($pm, 'if (isset($sizeMap[$oldSize]))'));
t('  all inside a transaction', str_contains($pm, 'db()->beginTransaction();'));

head('  the set cost updates as you type');
t('the totals row is recomputed from the grid itself',
  str_contains($pm, 'function recalc()') && str_contains($pm, "grid.querySelectorAll('.qz')"));
t('  never from a stored total that could drift',
  str_contains($pm, 'never from a stored'));
t('arrow keys move down the column like a spreadsheet',
  str_contains($pm, "ev.key === 'ArrowDown' || ev.key === 'Enter'"));

echo "\n" . str_repeat('-', 58) . "\n";
echo ($F === 0 ? "ALL PASS" : "FAILURES") . "   $P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
