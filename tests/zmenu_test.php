<?php
/* THE MENU SHOWS A LINK ONLY WHEN ITS PAGE OPENS.
 *
 * "I do not want a long, big menu for every user who has no access ...
 *  when a user without access clicks a menu item, the page opens and says
 *  Access Denied."
 *
 * Two faults, one cause: the menu and the pages asked different questions.
 *   - links that showed and then refused: Proforma, Product Costing and the
 *     Logistics Board for colleagues without those rights, Weight for staff
 *     with no shipment, Assembly for production staff;
 *   - a gatekeeper with only Gate ticked saw the whole Store folder, because
 *     that tick sets the legacy inv_view flag and inv_view opened every
 *     stock screen.
 *
 * What this file holds shut, for each kind of user:
 *   EVERY LINK THE MENU OFFERS OPENS. Checked against a table written from
 *   each PAGE's own guard, not from the menu.
 *   EVERY MENU PAGE THAT OPENS IS OFFERED (one stated exception below).
 *   THE GUARDS ARE STILL THERE. If a page's guard changes, this fails and
 *   the menu line must change with it.
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
function lift(string $src, string $from): string {
    $a = strpos($src, $from); if ($a === false) return '';
    $o = strpos($src, '{', $a); $d = 0;
    for ($i = $o, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $d++;
        elseif ($src[$i] === '}') { $d--; if ($d === 0) return substr($src, $a, $i - $a + 1); }
    }
    return '';
}

/* ------------------------------------------------------------- the person */
$U = []; $ZU = []; $SHIPS = [];
function current_user() { global $U; return $U; }
function is_admin() { global $U; return ($U['role'] ?? '') === 'admin'; }
function is_colleague() { global $U; return ($U['role'] ?? '') === 'colleague'; }
function is_staff() { global $U; return ($U['role'] ?? '') === 'staff'; }
function is_production_staff() { global $U; return ($U['role'] ?? '') === 'production_staff'; }
function zu_can($m, $a) { global $ZU; if (is_admin()) return true; return strpos($ZU[$m] ?? '', $a) !== false; }
function inv_perm($w) { global $U; if (is_admin()) return true; return (int)($U['inv_' . $w] ?? 0) === 1; }
function inv_can_see() { return inv_perm('view') || inv_perm('gate') || inv_perm('store') || inv_perm('consume') || inv_perm('master'); }
function assigned_shipment_ids() { global $SHIPS; return (is_admin() || is_colleague()) ? ['ALL'] : $SHIPS; }
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES); }

/* inv_can_open() IS LIFTED, NOT STUBBED — the pages and the menu both call
   it, so a hand-written copy would test the copy. */
$invSrc = (string)file_get_contents($B . 'includes/inventory.php');
$canOpen = lift($invSrc, 'function inv_can_open(');
t('inv_can_open() exists in includes/inventory.php', $canOpen !== '');
eval($canOpen);
require $B . 'includes/menu.php';

/* The legacy flags exactly as zu_save_perms() writes them from the ticks. */
function legacy(array $zu): array {
    $has = function ($mods, $a) use ($zu) { foreach ((array)$mods as $m) if (strpos($zu[$m] ?? '', $a) !== false) return 1; return 0; };
    $inv = ['gate','store','consume','opening','contract','jobwork','invmast','stockrep'];
    $stk = ['gate','store','consume','opening','jobwork'];
    return ['inv_view' => $has($inv, 'v'), 'inv_gate' => $has('gate', 'c') | $has('gate', 'u'),
            'inv_store' => $has('store', 'c') | $has('store', 'u'), 'inv_consume' => $has('consume', 'c') | $has('consume', 'u'),
            'inv_master' => $has('invmast', 'c') | $has('invmast', 'u'), 'inv_post' => $has($stk, 'p'), 'inv_adjust' => $has($stk, 'r')];
}

/* Who may open each page — written from the guard at the top of the PAGE. */
function page_opens(string $href): bool {
    global $U, $ZU, $SHIPS;
    $f = explode('?', $href)[0];
    $r = $U['role']; $admin = $r === 'admin'; $col = $r === 'colleague'; $staff = $r === 'staff'; $prod = $r === 'production_staff';
    $zu = fn($m, $a = 'v') => $admin || strpos($ZU[$m] ?? '', $a) !== false;
    $cost = fn($w) => $admin || ($col && (int)($U['cost_' . $w] ?? 0) === 1);
    $office = !$staff && !$prod;
    $pack = !$prod && ($admin || $col || $SHIPS !== []);
    $prodAll = $admin || $col || $prod;
    $map = [
        'dashboard.php' => !$prod, 'shipment_form.php' => $office, 'shipments.php' => !$prod, 'packing_list.php' => !$prod,
        'm_pack.php' => !$prod, 'weight.php' => $pack, 'proforma.php' => $office && $cost('proforma'), 'import_excel.php' => $office,
        'shipment_board.php' => $office && ($zu('shiplog') || $zu('shippay') || $zu('shipcost')), 'exp_providers.php' => $office,
        'imp_register.php' => $office && $zu('imp'), 'imp_new.php' => $office && $zu('imp', 'c'),
        'imp_efs_recon.php' => $office && $zu('impefs'), 'imp_opening.php' => $office && $zu('impefs'),
        'product_costing.php' => $office && $cost('view'), 'product_master.php' => $admin || $col, 'part_library.php' => $admin || $col,
        'products_csv.php' => $admin || $col, 'set_quantities_csv.php' => $admin || $col, 'cost_audit.php' => $admin, 'costing_items_link.php' => $admin,
        'production_my_work.php' => $prodAll, 'production_dashboard.php' => $prodAll, 'production_entry.php' => $prodAll,
        'production_reports.php' => $prodAll, 'production_workers.php' => $admin || $col, 'production_assembly.php' => $admin || $col,
        'production_pay.php' => $admin || $col, 'production_order_rates.php' => $admin || $col, 'production_amend.php' => $admin,
        'inv_items.php' => $zu('invmast'), 'inv_parties.php' => $zu('invmast'), 'inv_gate.php' => $zu('gate'),
        'inv_store.php' => $zu('store') || $zu('stockrep'), 'inv_consume.php' => $zu('consume'), 'm_gate.php' => inv_perm('gate'),
        'inv_opening.php' => $zu('opening'), 'inv_stock.php' => $zu('stockrep'), 'inv_ledger.php' => $zu('stockrep'),
        'inv_verify.php' => $zu('stockrep'), 'inv_contracts.php' => $zu('contract'), 'inv_ordercost.php' => $zu('contract'),
        'inv_jobwork.php' => $zu('jobwork'), 'inv_exceptions.php' => $zu('stockrep'), 'search.php' => $office,
        'users.php' => $admin, 'user_access.php' => $admin, 'settings.php' => $admin, 'inv_setup.php' => $admin,
        'exp_settings.php' => $admin, 'inv_reset.php' => $admin,
    ];
    if (!array_key_exists($f, $map)) { t("the page table knows $f", false); return false; }
    return $map[$f];
}
function shown(): array {
    $out = [];
    foreach (zas_menu() as $g) {
        if (empty($g['show'])) continue;
        if (!empty($g['href'])) { $out[] = $g['href']; continue; }
        $lists = !empty($g['groups']) ? $g['groups'] : [['items' => $g['items']]];
        foreach ($lists as $sg) foreach ($sg['items'] as $i) if (!empty($i['show'])) $out[] = $i['href'];
    }
    return $out;
}
function every(): array {
    $out = [];
    foreach (zas_menu() as $g) {
        if (!empty($g['href'])) { $out[] = $g['href']; continue; }
        $lists = !empty($g['groups']) ? $g['groups'] : [['items' => $g['items']]];
        foreach ($lists as $sg) foreach ($sg['items'] as $i) $out[] = $i['href'];
    }
    return $out;
}

$people = [
    'Admin'                                => [['role' => 'admin'], [], []],
    'Colleague, nothing extra'             => [['role' => 'colleague'], [], []],
    'Colleague with costing + logistics'   => [['role' => 'colleague', 'cost_proforma' => 1, 'cost_view' => 1], ['shiplog' => 'v'], []],
    'Colleague with EFS only'              => [['role' => 'colleague'], ['impefs' => 'vc'], []],
    'Staff, nothing assigned'              => [['role' => 'staff'], [], []],
    'Staff with two shipments'             => [['role' => 'staff'], [], [12, 15]],
    'Gatekeeper (Gate only)'               => [['role' => 'staff'], ['gate' => 'vcu'], []],
    'Storekeeper'                          => [['role' => 'staff'], ['store' => 'vcudp', 'consume' => 'vcud', 'stockrep' => 'v'], []],
    'Item & party clerk'                   => [['role' => 'staff'], ['invmast' => 'vcu'], []],
    'Contracts clerk'                      => [['role' => 'staff'], ['contract' => 'vcu', 'jobwork' => 'vcu'], []],
    'Opening stock entry'                  => [['role' => 'staff'], ['opening' => 'vcu'], []],
    'Store manager'                        => [['role' => 'staff'], ['gate' => 'vcudpr', 'store' => 'vcudpr', 'consume' => 'vcudpr', 'opening' => 'vcudpr', 'contract' => 'vcud', 'jobwork' => 'vcudpr', 'invmast' => 'vcud', 'stockrep' => 'v'], []],
    'Production staff'                     => [['role' => 'production_staff'], ['prod' => 'vcu'], []],
];
$seen = [];
foreach ($people as $name => [$u, $zu, $ships]) {
    $U = $u + ($u['role'] === 'admin' ? [] : legacy($zu)); $ZU = $zu; $SHIPS = $ships;
    $seen[$name] = shown();
}

/* ===================================================================== */
head('1. Every link the menu offers opens, for every kind of user');
foreach ($people as $name => [$u, $zu, $ships]) {
    $U = $u + ($u['role'] === 'admin' ? [] : legacy($zu)); $ZU = $zu; $SHIPS = $ships;
    $refused = array_values(array_filter($seen[$name], fn($h) => !page_opens($h)));
    t("$name: no link refuses", $refused === [], $refused);
}

head('2. Every menu page that opens is offered');
/* The one exception, on purpose: Shipments, Packing List and Packing on
   Mobile open for any staff login, but with no shipment assigned the list
   is empty, so the menu leaves them out until one is. */
$emptyForStaff = ['shipments.php', 'packing_list.php', 'm_pack.php'];
foreach ($people as $name => [$u, $zu, $ships]) {
    $U = $u + ($u['role'] === 'admin' ? [] : legacy($zu)); $ZU = $zu; $SHIPS = $ships;
    $missing = [];
    foreach (every() as $h) {
        if (!page_opens($h) || in_array($h, $seen[$name], true)) continue;
        if ($u['role'] === 'staff' && !$ships && in_array($h, $emptyForStaff, true)) continue;
        $missing[] = $h;
    }
    t("$name: nothing they may open is hidden", $missing === [], $missing);
}

head('3. The cases that started this');
t('a colleague without Proforma does not see Proforma', !in_array('proforma.php', $seen['Colleague, nothing extra'], true));
t('  nor Product Costing', !in_array('product_costing.php', $seen['Colleague, nothing extra'], true));
t('  nor the Logistics Board', !in_array('shipment_board.php', $seen['Colleague, nothing extra'], true));
t('  and does see Production, which always let colleagues in', in_array('production_entry.php', $seen['Colleague, nothing extra'], true));
t('with the rights ticked, all three come back',
  !array_diff(['proforma.php', 'product_costing.php', 'shipment_board.php'], $seen['Colleague with costing + logistics']));
t('EFS without Imports shows the two EFS links and no register',
  in_array('imp_efs_recon.php', $seen['Colleague with EFS only'], true) && in_array('imp_opening.php', $seen['Colleague with EFS only'], true)
  && !in_array('imp_register.php', $seen['Colleague with EFS only'], true));
t('staff with nothing assigned see the Dashboard and nothing else', $seen['Staff, nothing assigned'] === ['dashboard.php'], $seen['Staff, nothing assigned']);
t('staff with shipments see their packing screens and Weight',
  $seen['Staff with two shipments'] === ['dashboard.php', 'shipments.php', 'packing_list.php', 'm_pack.php', 'weight.php'], $seen['Staff with two shipments']);
t('the GATEKEEPER sees the gate and nothing else of the store',
  $seen['Gatekeeper (Gate only)'] === ['dashboard.php', 'inv_gate.php?dir=in', 'inv_gate.php?dir=out', 'm_gate.php?dir=in'], $seen['Gatekeeper (Gate only)']);
t('the item clerk sees Item Master and Parties only',
  $seen['Item & party clerk'] === ['dashboard.php', 'inv_items.php', 'inv_parties.php'], $seen['Item & party clerk']);
t('opening stock entry sees Opening Stock only', $seen['Opening stock entry'] === ['dashboard.php', 'inv_opening.php'], $seen['Opening stock entry']);
t('production staff do not see Assembly, which refuses them',
  !in_array('production_assembly.php', $seen['Production staff'], true));
t('the store manager sees the whole store folder (22 links)',
  count(array_filter($seen['Store manager'], fn($h) => str_starts_with($h, 'inv_') || str_starts_with($h, 'm_gate'))) === 22);
t('the admin sees every link there is', count($seen['Admin']) === count(every()), [count($seen['Admin']), count(every())]);

head('4. A folder with nothing in it is not drawn');
$U = ['role' => 'staff'] + legacy(['gate' => 'vcu']); $ZU = ['gate' => 'vcu']; $SHIPS = [];
$_SERVER['SCRIPT_NAME'] = '/dashboard.php';
ob_start(); zas_render_menu(); $html = ob_get_clean();
t('the gatekeeper menu draws the store folder with the gate links', str_contains($html, 'Gate Inward') && str_contains($html, 'Gate on Mobile'));
t('  and no stock, contract or master link', !str_contains($html, 'Current Stock') && !str_contains($html, 'All Contracts') && !str_contains($html, 'Item Master'));
t('  and no empty heading for Masters, Stock or Contracts',
  !str_contains($html, '>Masters<') && !str_contains($html, '>Stock<') && !str_contains($html, '>Contracts<'));
t('  and no Sales, Production or Imports folder at all',
  !str_contains($html, 'Sales &amp; Export') && !str_contains($html, '>Production<') && !str_contains($html, '>Imports<'));

head('5. Each page still asks the question its menu line copies');
$guards = [
    'inv_items.php' => "if (!inv_can_open('invmast'))", 'inv_parties.php' => "if (!inv_can_open('invmast'))",
    'inv_gate.php' => "if (!inv_can_open('gate'))", 'inv_gate_print.php' => "if (!inv_can_open('gate'))",
    'inv_store.php' => "if (!inv_can_open('store') && !inv_can_open('stockrep'))", 'inv_consume.php' => "if (!inv_can_open('consume'))",
    'inv_opening.php' => "if (!inv_can_open('opening'))", 'inv_stock.php' => "if (!inv_can_open('stockrep'))",
    'inv_ledger.php' => "if (!inv_can_open('stockrep') && !(!empty(\$_GET['json']) && inv_can_open('consume')))",
    'inv_verify.php' => "if (!inv_can_open('stockrep'))", 'inv_contracts.php' => "if (!inv_can_open('contract'))",
    'inv_contract_print.php' => "if (!inv_can_open('contract'))", 'inv_ordercost.php' => "if (!inv_can_open('contract'))",
    'inv_jobwork.php' => "if (!inv_can_open('jobwork'))", 'inv_exceptions.php' => "if (!inv_can_open('stockrep'))",
    'm_gate.php' => "if (!inv_perm('gate'))",
    'proforma.php' => "require_costing('proforma');", 'product_costing.php' => "require_costing('view');",
    'shipment_board.php' => "if (!exp_can('logistics') && !exp_can('payments') && !exp_can('costs'))",
    'weight.php' => "if (!wt_may_use())",
    'production_assembly.php' => "if (!is_admin() && !is_colleague())",
    'production_entry.php' => "if (!is_admin() && !is_colleague() && !is_production_staff())",
    'imp_register.php' => "imp_require('imp', 'v');", 'imp_new.php' => "imp_require('imp', 'c');",
    'imp_efs_recon.php' => "imp_require('efs', 'v');", 'imp_opening.php' => "imp_require('efs', 'v');",
];
foreach ($guards as $f => $g) t("$f guards with: $g", str_contains(nocomments((string)file_get_contents($B . $f)), $g));
t('no store page still opens on the wide inv_can_see()',
  !array_filter(array_keys(array_filter($guards, fn($g) => str_contains($g, 'inv_can_open'))),
                fn($f) => str_contains(nocomments((string)file_get_contents($B . $f)), 'if (!inv_can_see())')));
$cp = nocomments((string)file_get_contents($B . 'includes/costing.php'));
t('costing_perm() is still admin, or a colleague holding the cost_ flag',
  str_contains($cp, "if (\$u['role'] !== 'colleague') return false;") && str_contains($cp, "(\$u['cost_' . \$what] ?? 0) === 1"));
$ip = nocomments((string)file_get_contents($B . 'includes/imports.php'));
t('imp_can() still turns away staff and production', str_contains(lift($ip, 'function imp_can('), 'is_staff() || is_production_staff()'));
$pk = lift((string)file_get_contents($B . 'includes/packing.php'), 'function pack_may_use(');
t('pack_may_use() is still admin, colleague, or staff with a shipment',
  str_contains($pk, 'is_production_staff()') && str_contains($pk, 'assigned_shipment_ids()'));

head('6. inv_can_open() decides by the tick, not the legacy flag');
$U = ['role' => 'staff'] + legacy(['gate' => 'vcu']); $ZU = ['gate' => 'vcu'];
t('Gate ticked sets the legacy view flag (this is what opened everything)', inv_perm('view') && inv_can_see());
t('  but only the gate opens now', inv_can_open('gate') && !inv_can_open('stockrep') && !inv_can_open('contract') && !inv_can_open('invmast'));
$U = ['role' => 'admin']; $ZU = [];
t('an admin opens everything', inv_can_open('stockrep') && inv_can_open('jobwork'));

echo "\n" . str_repeat('-', 58) . "\n";
echo ($F === 0 ? "ALL PASS" : "FAILURES") . "   $P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
