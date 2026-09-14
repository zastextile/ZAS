<?php
/* THE BOOKING ENGINE — where the money is decided.
 *
 * These RUN the shipped arithmetic, not a copy of it. Every rule here was a
 * real fault in the old module at some point, and each one costs money in a
 * different direction:
 *
 *   STITCHING > CUTTING       pays for pieces that do not exist
 *   SHARED ALLOWANCE          one operation silently eats another's
 *   HALF-SAVED SHEET          re-entering it double-books what went in
 *   UNMATCHED SIZE => 0       an order looks finished before it starts
 *   LIVE RATE, NOT SNAPSHOT   tomorrow's rate rewrites today's wage
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
/* PROSE ASSERTIONS READ FLATTENED TEXT. A comment that wraps across lines is
   the same comment; asserting on its exact line breaks fails for a reflow that
   changed nothing. (Three of these failed on their own first run.) */
function flat(string $s): string { return preg_replace('/\s+/', ' ', $s); }
function code_only(string $s): string {
    $s = preg_replace('!/\*.*?\*/!s', ' ', $s);
    return preg_replace('!//[^\n]*!', ' ', $s);
}
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

/* ---- the collaborators the arithmetic calls, made explicit ---- */
function zp_ensure_schema(): void {}
function zp_sizes(int $pid): array { return $GLOBALS['SIZES'][$pid] ?? []; }
function zp_qty_map(int $pid): array { return $GLOBALS['QTY'][$pid] ?? []; }

/* zp_is_cutting() now asks "is this the FIRST stage", reading the list you
   typed — so the booking rules are tested against stage names this module has
   never heard of, which is the whole point of the change. */
$GLOBALS['STAGE_ROWS'] = [
    ['id' => 11, 'seq' => 1, 'name' => 'Laser Cut',     'is_active' => 1],
    ['id' => 12, 'seq' => 2, 'name' => 'Direct Sewing', 'is_active' => 1],
];
function zp_stage_all(bool $activeOnly = false): array { return $GLOBALS['STAGE_ROWS']; }
function zp_stage_first(): ?array { $r = zp_stage_all(true); return $r ? $r[0] : null; }

define('ZP_NO_SIZE', -1.0);
function zp_size_problem(array $l): string { return ''; }
foreach (['zp_is_cutting', 'zp_qty_for', 'zp_line_size_id', 'zp_pieces_needed',
          'zp_remaining', 'zp_rate_for'] as $fn) {
    $c = lift($src, $fn);
    if ($c === '') { echo "  FAIL could not lift $fn\n"; $F++; continue; }
    eval($c);
}

/* ---- a Bed Sheet Set: 1 sheet, 2 pillow covers, in Double ---- */
$GLOBALS['SIZES'] = [9 => [['id' => 20, 'size_label' => 'Single'], ['id' => 21, 'size_label' => 'Double']]];
$GLOBALS['QTY']   = [9 => [5 => [20 => 1.0, 21 => 1.0],      // part 5 = bed sheet
                           6 => [20 => 1.0, 21 => 2.0]]];    // part 6 = pillow cover
$line = ['item_id' => 100, 'proforma_id' => 1, 'product_id' => 9, 'ordered_qty' => 500.0, 'size' => 'Double'];

head('HOW MANY PIECES AN ORDER ACTUALLY NEEDS');
t('500 sets need 500 bed sheets',  zp_pieces_needed($line, 5) === 500.0, zp_pieces_needed($line, 5));
t('500 DOUBLE sets need 1000 pillow covers',
  zp_pieces_needed($line, 6) === 1000.0, zp_pieces_needed($line, 6));
$single = array_merge($line, ['size' => 'Single']);
t('the same order in Single needs only 500 pillow covers',
  zp_pieces_needed($single, 6) === 500.0, zp_pieces_needed($single, 6));
t('the size is matched without caring about capitals',
  zp_pieces_needed(array_merge($line, ['size' => 'DOUBLE']), 6) === 1000.0);

head('A SIZE THAT MATCHES NOTHING IS REFUSED — IT IS NOT GUESSED AT');
/* THIS RULE WAS WRONG AND IS NOW REVERSED.
 *
 * It used to assert that an unresolvable size falls back to ONE per set,
 * defended as "better than zero". That was the wrong pair of options. The real
 * choice was between GUESSING and SAYING SO, and guessing always guesses LOW:
 * a Double that genuinely takes TWO pillow cases got planned as one, the order
 * read finished at half done, and half the wages were never bookable — with
 * nothing on any screen saying so. Refusing is the only honest answer. */
$odd = array_merge($line, ['size' => 'King (special)']);
t('an unrecognised size is refused, not planned low',
  zp_pieces_needed($odd, 6) === ZP_NO_SIZE, zp_pieces_needed($odd, 6));
t('  and a blank size too',
  zp_pieces_needed(array_merge($line, ['size' => '']), 6) === ZP_NO_SIZE);
t('  it is never quietly 1 per set', zp_pieces_needed($odd, 6) !== 500.0);
t('nothing at all can be booked against a line that cannot be planned',
  zp_remaining($odd, 6, 77, 'Laser Cut', ['op'=>[], 'stage'=>[]]) === 0.0,
  zp_remaining($odd, 6, 77, 'Laser Cut', ['op'=>[], 'stage'=>[]]));
t('the reason is written down', str_contains($src, 'guessing is worse here'));
t('  and it names what it costs',
  str_contains(preg_replace('/\s+/', ' ', $src), 'wages are never bookable'));

head('THE SIZE IS TAKEN FROM THE LINE\'S OWN ID FIRST');
/* matching on typed text is how the old bug worked, so it is the SECOND
   answer here and never the first */
t('an explicit product_size_id wins outright',
  zp_line_size_id(array_merge($line, ['product_size_id' => 21, 'size' => 'nonsense'])) === 21);
t('  and is used even when the text would have matched something else',
  zp_pieces_needed(array_merge($line, ['product_size_id' => 21, 'size' => 'Single']), 6) === 1000.0,
  zp_pieces_needed(array_merge($line, ['product_size_id' => 21, 'size' => 'Single']), 6));
t('the typed label is the fallback, for lines saved before the id existed',
  zp_line_size_id(array_merge($line, ['size' => 'Double'])) === 21);
t('  matched without caring about case',
  zp_line_size_id(array_merge($line, ['size' => '  DOUBLE '])) === 21);
t('and nothing resolvable returns 0, so the caller must decide',
  zp_line_size_id(array_merge($line, ['size' => 'King (special)'])) === 0);

head('CUTTING IS CAPPED BY THE ORDER');
$empty = ['op' => [], 'stage' => []];
t('nothing cut yet: the whole order is available',
  zp_remaining($line, 6, 77, 'Laser Cut', $empty) === 1000.0);
$cut400 = ['op' => [100 => [6 => [77 => 400.0]]], 'stage' => [100 => [6 => ['cut' => 400.0]]]];
t('400 cut leaves 600', zp_remaining($line, 6, 77, 'Laser Cut', $cut400) === 600.0);
$cutAll = ['op' => [100 => [6 => [77 => 1000.0]]], 'stage' => [100 => [6 => ['cut' => 1000.0]]]];
t('fully cut leaves 0', zp_remaining($line, 6, 77, 'Laser Cut', $cutAll) === 0.0);
t('  and never goes negative', zp_remaining($line, 6, 77, 'Laser Cut',
  ['op' => [100 => [6 => [77 => 1200.0]]], 'stage' => [100 => [6 => ['cut' => 1200.0]]]]) === 0.0);
t('MANUAL CUTTING is capped the same way — it is cutting',
  zp_remaining($line, 6, 78, 'Laser Cut', $empty) === 1000.0);

head('STITCHING IS CAPPED BY WHAT WAS ACTUALLY CUT — NOT BY THE PLAN');
/* the single most important rule in the module: you cannot stitch a piece that
   does not exist, and the number has to refuse rather than let it through */
t('nothing cut means nothing to stitch',
  zp_remaining($line, 6, 88, 'Direct Sewing', $empty) === 0.0,
  zp_remaining($line, 6, 88, 'Direct Sewing', $empty));
t('400 cut allows 400 stitched, not the 1000 ordered',
  zp_remaining($line, 6, 88, 'Direct Sewing', $cut400) === 400.0,
  zp_remaining($line, 6, 88, 'Direct Sewing', $cut400));
$over = ['op' => [100 => [6 => [77 => 1200.0]]], 'stage' => [100 => [6 => ['cut' => 1200.0]]]];
t('cutting MORE than ordered still does not let stitching pass the order',
  zp_remaining($line, 6, 88, 'Direct Sewing', $over) === 1000.0,
  zp_remaining($line, 6, 88, 'Direct Sewing', $over));

head('EACH OPERATION HAS ITS OWN ALLOWANCE');
/* Overlock and Singer are two jobs done to EVERY piece. In the old module they
   shared one pool, so booking 150 on Overlock silently ate Singer's. */
$mixed = ['op'    => [100 => [6 => [77 => 1000.0, 88 => 150.0]]],
          'stage' => [100 => [6 => ['cut' => 1000.0, 'stitched' => 150.0]]]];
t('Overlock has done 150, so 850 of Overlock remain',
  zp_remaining($line, 6, 88, 'Direct Sewing', $mixed) === 850.0,
  zp_remaining($line, 6, 88, 'Direct Sewing', $mixed));
t('SINGER STILL HAS ALL 1000 — it is a different job',
  zp_remaining($line, 6, 99, 'Direct Sewing', $mixed) === 1000.0,
  zp_remaining($line, 6, 99, 'Direct Sewing', $mixed));
t('and one part does not eat another part\'s allowance',
  zp_remaining($line, 5, 88, 'Laser Cut', $mixed) === 500.0,
  zp_remaining($line, 5, 88, 'Laser Cut', $mixed));

head('THE RATE THIS ORDER PAYS');
t('with no amendment the part\'s own rate is used', zp_rate_for(88, 8.0, []) === 8.0);
t('an amendment for THIS operation wins', zp_rate_for(88, 8.0, [88 => 9.5]) === 9.5);
t('an amendment for a DIFFERENT operation is ignored', zp_rate_for(88, 8.0, [77 => 9.5]) === 8.0);
t('an amendment of ZERO is honoured — somebody meant it',
  zp_rate_for(88, 8.0, [88 => 0.0]) === 0.0);

/* ================= the rules that live in the save ================= */
$book = code_only(lift($src, 'zp_book'));

head('NOTHING SAVES UNTIL EVERY ROW PASSES');
t('every row is checked before anything is written',
  strpos($book, 'foreach ($rows as $i => $r)') < strpos($book, 'beginTransaction'));
t('an error list stops the write entirely',
  str_contains($book, "if (\$errors) return ['ok' => false"));
t('it runs in a transaction', str_contains($book, 'beginTransaction()'));
t('  rolling back on any failure', str_contains($book, 'rollBack()'));
t('  and saying nothing was saved', str_contains($src, 'Nothing was saved — the database refused the sheet'));
t('the reason a half-save is worse than a refusal is written down',
  str_contains($src, 'you cannot tell which half went in'));

head('ROWS IN THE SAME SHEET COUNT AGAINST EACH OTHER');
/* two lines for the same operation must add up against ONE allowance, or a
   sheet can book twice what exists simply by splitting it in half */
t('a running total is kept per operation', str_contains($book, "\$key   = \$itemId . ':' . \$partId . ':' . \$opId;"));
t('  and subtracted from what is left', str_contains($book, '$left  = zp_remaining($line, $partId, $opId, (string)$op[\'stage\'], $prog) - $taken;'));
t('  and only then added', str_contains($book, '$batch[$key] = $taken + $qty;'));
t('the reason is stated', str_contains($src, 'can book twice what exists by splitting it in half'));

head('THE SAVE REFUSES WHAT THE SCREEN REFUSES');
t('an over-book is rejected with the number left',
  str_contains($book, 'if ($qty > $left + 0.0001)'));
t('  and cutting says "that is all the order needs"',
  str_contains($src, 'that is all the order needs'));
t('  while stitching says why it is lower',
  str_contains($src, 'you cannot stitch more than was cut'));
t('a blank row is skipped, not treated as an error',
  str_contains($book, 'if (!$itemId && !$opId && !$wid && $qty <= 0) continue;'));
t('the operation must belong to the part', str_contains($book, "belongs to a different part"));
t('the part must be on the product this line makes', str_contains($src, 'is not a part of'));
t('an inactive worker is refused', str_contains($src, 'not on the active list'));
t('an inactive operation is refused', str_contains($src, 'has been switched off'));

head('A FUTURE DATE IS ALWAYS A TYPO');
t('tomorrow is refused', str_contains($book, "if (\$date > date('Y-m-d'))"));
t('  saying why', str_contains($src, 'Production cannot be booked before it happens'));
t('a nonsense date is refused too', str_contains($book, "preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/', \$date)"));

head('THE RATE IS FROZEN, AND NEVER COMES FROM THE BROWSER');
t('the rate is looked up on the server at save time',
  str_contains($book, '$rate = zp_rate_for($opId, (float)$op[\'rate\'], $rateCache[$pfid]);'));
t('  and stored as rate_applied', str_contains($book, "'rate_applied' => \$rate"));
t('  with the amount computed from it, not sent in',
  str_contains($book, "'amount' => round(\$qty * \$rate, 2)"));
t('no posted rate is read anywhere in the save',
  !preg_match('/\$r\[[\'"]rate/', $book), 'a posted rate is read');
t('the reason is written down', str_contains($src, 'Change the rate tomorrow and this wage'));

head('A CORRECTION IS VISIBLE, NEVER INVISIBLE');
$canc = code_only(lift($src, 'zp_cancel_entry'));
t('cancelling marks the row', str_contains($canc, "UPDATE zp_entries SET status='cancelled'"));
t('  it does not delete it', !preg_match('/DELETE\s+FROM\s+zp_entries/i', code_only($src)));
t('  and records who and why', str_contains($canc, 'cancelled_by=?') && str_contains($canc, 'cancel_reason=?'));
t('a reason is required', str_contains($src, 'a correction with no reason cannot be checked later'));
t('cancelling twice is refused', str_contains($src, 'already cancelled'));

head('  and cancelling cutting cannot strand stitching');
/* stitching stands on cutting: undoing the cut under finished stitching would
   leave pieces that were stitched but never cut — unfixable afterwards */
t('the check exists', str_contains($canc, '$cut - (float)$e[\'qty\'] < $stitched - 0.0001'));
t('  and it names the number in the way', str_contains($src, 'pieces have already been stitched against it'));
t('  telling you what to do instead', str_contains($src, 'Cancel the stitching first'));

head('A CANCELLED ROW STOPS HOLDING ITS LIMIT DOWN');
$pm2 = code_only(lift($src, 'zp_progress_map'));
t('progress counts only active rows', str_contains($pm2, "WHERE status='active'"));
t('  decided in ONE place', substr_count($pm2, "status='active'") === 1);
t('it is one grouped query, not one per line', str_contains($pm2, 'GROUP BY proforma_item_id, part_id, op_id, stage'));

head('THE LIST NEVER HIDES A WAGE IT IS STILL COUNTING');
$ent = code_only(lift($src, 'zp_entries'));
foreach (['zp_workers', 'products', 'zp_parts', 'zp_part_ops', 'proforma_invoices'] as $tbl) {
    t("  $tbl is LEFT JOINed", (bool)preg_match('/LEFT JOIN\s+' . preg_quote($tbl, '/') . '/', $ent), $tbl);
}
t('no INNER JOIN anywhere in it', !preg_match('/\bINNER\s+JOIN\b/i', $ent));
t('the reason is written down',
  str_contains($src, 'while its wage still counted in the total'));

head('THE SCREENS');
$pe = file_get_contents($B . 'production_entry.php');
$mw = file_get_contents($B . 'production_my_work.php');
$pw = file_get_contents($B . 'production_workers.php');
foreach (['production_entry.php' => $pe, 'production_my_work.php' => $mw, 'production_workers.php' => $pw] as $f => $c) {
    t("$f uses the new module", str_contains($c, "includes/zprod.php"));
    t("  and not the old one", !str_contains($c, "includes/production.php"));
    t("  and cannot be served from cache", str_contains($c, "header('Cache-Control: no-store"));
}

head('  both booking screens go through the SAME function');
/* two screens that book work in two different ways will disagree eventually,
   and the disagreement will be about money */
t('the day sheet calls zp_book()', str_contains($pe, 'zp_book($date, $rows, $userId)'));
t('My Work calls zp_book() too', str_contains($mw, 'zp_book($date, [['));
t('  and says why that matters', str_contains(flat($mw), 'the disagreement will be about money'));
t('neither writes to zp_entries directly',
  !str_contains($pe, 'INSERT INTO zp_entries') && !str_contains($mw, 'INSERT INTO zp_entries'));

head('  the day sheet offers only work that exists');
/* THE SCREEN CHANGED SHAPE, THE RULES DID NOT.
   Three dropdowns became one searchable list, so these assertions moved from
   the page's own markup to the index the server builds for it — but each rule
   below is the same rule the old dropdown kept. */
$zpsrc = file_get_contents(__DIR__ . '/app_src/public_html/includes/zprod.php');
t('the options come from the same functions the save uses',
  str_contains($zpsrc, 'zp_remaining($l, $partId, $opId'));
t('finished work is CARRIED and marked, not silently dropped',
  str_contains($zpsrc, "'done' => \$done ? 1 : 0,"));
t('  because "nothing matches" and "that is finished" are different answers',
  str_contains(flat($zpsrc), 'One is a typo to correct; the other is good news.')
  || str_contains(flat($pe), 'One is a typo to correct; the other is good news.'));
t('  and the screen says which it is',
  str_contains($pe, "' already finished.</b><br>Nothing is left to book on '"));
t('  while only OPEN work can actually be picked',
  str_contains($pe, 'return !w.done && hit(w.hay, t);'));
/* this used to live in draw()'s own markup. It now lives in the ONE row
   renderer both lists share, so the in-row list on the "By worker" tab
   finally shows it too — it never did before. */
t('what is left is printed on every option',
  str_contains($pe, "qn(o.left) + '<small>left</small>"));
t('  in the in-row list as well as the top one, from the same renderer',
  substr_count($pe, 'function zeRowHtml(') === 1 && substr_count($pe, 'zePaint(') === 3);
t('an over-book is flagged as you type, before the save',
  str_contains($pe, 'are left at this operation.'));
t('  and it is the SUM that is checked, not each line alone',
  str_contains($pe, 'THE CEILING IS SHARED ON TAB A'));
t('Enter on the quantity starts the next line',
  str_contains($pe, 'if (i === rows.length - 1) zeAddRow();'));

head('  My Work shows what is LEFT, not what was ordered');
t('finished work is not shown as a card', str_contains($mw, 'if ($left <= 0.0001) continue;'));
t('cutting is sorted first', str_contains($mw, 'zp_is_cutting($a[\'stage\']) ? 0 : 1'));
t('  then whatever is furthest behind', str_contains($mw, "return \$a['pct'] <=> \$b['pct'];"));
t('the big number on the card is what is left', str_contains($mw, 'left to do'));
t('  and the reasoning is recorded', str_contains($mw, 'is trivia you have to do arithmetic'));

head('  workers: a code is given, not demanded');
$sw = code_only(lift($src, 'zp_save_worker'));
t('a blank code gets the next free one', str_contains($sw, "\$code = 'W' . str_pad("));
t('  and the loop cannot run away', str_contains($sw, '$n < 100000'));
t('a duplicate code is refused', str_contains($sw, 'WHERE worker_code=? AND id<>?'));
t('the reason a code is auto-given is written down',
  str_contains(flat($src), 'you get W1, w1 and W01 for the same person'));
$dw = code_only(lift($src, 'zp_delete_worker'));
t('a worker with entries is deactivated, not deleted',
  str_contains($dw, 'UPDATE zp_workers SET is_active=0'));
t('  and only one with none is really removed', str_contains($dw, 'DELETE FROM zp_workers WHERE id=?'));
t('  with the reason given to the user', str_contains($src, 'keeps their name on it'));

head('  an empty screen says WHY, and what to press');
foreach (['No order is switched on for production yet',
          'No active workers',
          'no parts with operations yet'] as $why) {
    t("  \"$why\" is explained", str_contains($pe, $why));
}
/* four blockers, not three: "no order switched on" and "orders on but nothing
   matches a product" are different problems with different fixes. */
t('every blocker carries a button to its own fix',
  substr_count($pe, "'l' => ") === substr_count($pe, "'lt' => ")
  && substr_count($pe, "'l' => ") >= 3, substr_count($pe, "'l' => "));

head('THE FLOOR CAN OPEN THE FLOOR\'S OWN SCREEN');
/* A real bug, caught by a test on its way to being retired: I had guarded both
   booking screens with admin-and-colleague only, which locked out the operators
   the screens exist for. */
foreach (['production_entry.php' => $pe, 'production_my_work.php' => $mw] as $f => $c) {
    t("$f admits production staff", str_contains($c, 'is_production_staff()'));
    t("  alongside admin and colleague",
      str_contains($c, '!is_admin() && !is_colleague() && !is_production_staff()'));
}
t('the mistake is written down so it is not repeated',
  str_contains(flat($pe), "locked the floor out of the floor's own page"));
t('Production Workers stays admin/colleague — it is not a floor screen',
  !str_contains($pw, 'is_production_staff()'));

head('  BOOKING A QUANTITY AND SEEING THE WAGE ARE DIFFERENT PERMISSIONS');
/* conflating them is how a wage rate ends up on a screen it should not be on */
t('My Work asks can_see_rates() once', str_contains($mw, '$showMoney = can_see_rates();'));
t('  and the card rate is behind it', str_contains($mw, "<?php if (\$showMoney): ?><span>PKR"));
t('  as is the day total', str_contains($mw, "<?php if (\$showMoney): ?><span>PKR <b>"));
t('  and the confirmation message says nothing about money when hidden',
  str_contains($mw, "? 'Booked — PKR '") && str_contains($mw, ": 'Booked.'"));
t('the day sheet does the same', str_contains($pe, '$showMoney = can_see_rates();'));
t('  including its saved-message', str_contains($pe, "(\$showMoney ? ' — PKR '"));
t('the reason is recorded in both files',
  str_contains(flat($mw), 'how a wage rate ends up on a screen it should not be on')
  && str_contains(flat($pe), 'booking a quantity and seeing the wage are two'));

head('The menu points at the rebuilt pages');
$menu = file_get_contents($B . 'includes/menu.php');
t('Products CSV points at the new file', str_contains($menu, "'href' => 'products_csv.php'"));
t('  not the old one', !str_contains($menu, "'href' => 'product_master_csv.php'"));
t('the old reset tool is off the menu', !str_contains($menu, "'href' => 'production_reset.php'"));
t('  and the reason is recorded, not just the removal',
  str_contains(flat($menu), 'report success while clearing nothing anyone can see'));

echo "\n" . str_repeat('-', 58) . "\n";
echo ($F === 0 ? "ALL PASS" : "FAILURES") . "   $P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
