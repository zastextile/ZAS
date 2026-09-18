<?php
/* USER ACCESS — what a person may do, and when.
 *
 * WHAT WAS THERE BEFORE, AND WHY IT WAS NOT ENOUGH.
 * Rights lived in two places and neither could express the thing an
 * office actually needs. users.php held the role and seven cost_* flags;
 * inv_setup.php held seven inv_* flags. Between them they could say
 * "may use the store" but never "may create and update, but not delete",
 * and never "weekdays, office hours, and the temp auditor's login stops
 * working on 31 October".
 *
 * THE SHAPE NOW.
 *   WHAT   one row per user per module, holding a string of letters from
 *          "vcudpr" — view, create, update, delete, post, reverse.
 *   WHEN   six columns on `users`: a date range, the days of the week,
 *          an hour window, and what happens outside it.
 *
 * NOBODY LOSES ACCESS ON THE DAY THIS IS INSTALLED. The first time a
 * user is seen, their existing cost_* and inv_* flags are read across
 * into the new table, so everyone keeps exactly what they had. The
 * marker is a column on the user, so it happens once and never undoes a
 * later edit.
 *
 * AND THE OLD FLAGS KEEP WORKING. zu_save_perms() writes BOTH — the new
 * rows and the legacy columns — so inv_perm() and every cost_* check in
 * the app carry on unchanged. One screen, two stores kept in step. That
 * is deliberate: if anything about the new system surprises you, nothing
 * has been lost, and the old checks are still the ones guarding the old
 * screens.
 */

/* ---------------------------------------------------------- definitions */

/* Every area of the app, and which actions actually mean something for
   it. "post" and "reverse" exist only on documents that write the stock
   ledger; offering them on Product Master would be a tick-box that does
   nothing. The keys are stored in the database, so they are short and
   they do not change. */
function zu_modules(): array {
    return [
        ['g' => 'Sales & Export',    'k' => 'ship',     'n' => 'Shipments & Invoices',  'a' => 'vcud'],
        ['g' => 'Sales & Export',    'k' => 'proforma', 'n' => 'Proforma / Orders',     'a' => 'vcud'],
        ['g' => 'Sales & Export',    'k' => 'packing',  'n' => 'Packing Lists',         'a' => 'vcud'],
        ['g' => 'Costing',           'k' => 'costing',  'n' => 'Product Costing',       'a' => 'vcud'],
        ['g' => 'Costing',           'k' => 'fcost',    'n' => 'Final Costing',         'a' => 'vcud'],
        ['g' => 'Costing',           'k' => 'pmaster',  'n' => 'Product Master & Parts','a' => 'vcud'],
        ['g' => 'Production',        'k' => 'prod',     'n' => 'Daily Production Entry','a' => 'vcud'],
        ['g' => 'Production',        'k' => 'prodrate', 'n' => 'Operations & Rates',    'a' => 'vcud'],
        ['g' => 'Production',        'k' => 'prodrep',  'n' => 'Production Reports',    'a' => 'v'],
        ['g' => 'Store & Inventory', 'k' => 'gate',     'n' => 'Gate Inward / Outward', 'a' => 'vcudpr'],
        ['g' => 'Store & Inventory', 'k' => 'store',    'n' => 'Store Issue & Return',  'a' => 'vcudpr'],
        ['g' => 'Store & Inventory', 'k' => 'consume',  'n' => 'Consumption',           'a' => 'vcudpr'],
        ['g' => 'Store & Inventory', 'k' => 'opening',  'n' => 'Opening Stock',         'a' => 'vcudpr'],
        ['g' => 'Store & Inventory', 'k' => 'contract', 'n' => 'Contracts',             'a' => 'vcud'],
        ['g' => 'Store & Inventory', 'k' => 'jobwork',  'n' => 'Job Work Billing',      'a' => 'vcudpr'],
        ['g' => 'Store & Inventory', 'k' => 'invmast',  'n' => 'Item & Party Masters',  'a' => 'vcud'],
        ['g' => 'Store & Inventory', 'k' => 'stockrep', 'n' => 'Stock Reports & Health','a' => 'v'],
        ['g' => 'Administration',    'k' => 'admin',    'n' => 'Users & Setup',         'a' => 'vcud'],
    ];
}

function zu_actions(): array {
    return [
        'v' => ['n' => 'View',    't' => 'Open the screen and read it'],
        'c' => ['n' => 'Create',  't' => 'Start a new document or record'],
        'u' => ['n' => 'Update',  't' => 'Change one that already exists'],
        'd' => ['n' => 'Delete',  't' => 'Remove a draft. A posted document is never deleted — it is reversed'],
        'p' => ['n' => 'Post',    't' => 'Commit it to stock. This is the one that moves quantity'],
        'r' => ['n' => 'Reverse', 't' => 'Undo a posted document by writing its opposite'],
    ];
}

/* Which modules a given letter is allowed on — used when a whole column
   is ticked, so "post everywhere" cannot invent a post right on a screen
   that has no posting. */
function zu_module_actions(string $key): string {
    foreach (zu_modules() as $m) if ($m['k'] === $key) return $m['a'];
    return '';
}

/* ---------------------------------------------------------------- schema */
function zu_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try { db()->exec("CREATE TABLE IF NOT EXISTS zu_perm (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        module VARCHAR(24) NOT NULL,
        actions VARCHAR(12) NOT NULL DEFAULT '',
        updated_by INT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_module (user_id, module),
        INDEX(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* The window is one-to-one with a user, so it is columns rather than
       a table. Everything defaults to "no limit", so installing this
       changes nothing for anybody. */
    foreach ([
        "ALTER TABLE users ADD COLUMN acc_from DATE NULL DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN acc_until DATE NULL DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN acc_days VARCHAR(8) NOT NULL DEFAULT '1234567'",
        "ALTER TABLE users ADD COLUMN acc_t1 TIME NULL DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN acc_t2 TIME NULL DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN acc_outside ENUM('readonly','block') NOT NULL DEFAULT 'readonly'",
        /* the marker that stops the one-time read-across running twice */
        "ALTER TABLE users ADD COLUMN acc_seeded TINYINT(1) NOT NULL DEFAULT 0",
    ] as $sql) { try { db()->exec($sql); } catch (Throwable $e) {} }
}

/* ------------------------------------------------- the one-time read-across
   Turns the flags a user already has into the new rows, ONCE. Without
   this, installing the new system would silently take everyone's access
   away until an admin re-ticked 108 boxes for every person. */
function zu_seed_user(array $u): void {
    $id = (int)($u['id'] ?? 0);
    if ($id <= 0 || (int)($u['acc_seeded'] ?? 0) === 1) return;

    $f  = fn($k) => (int)($u[$k] ?? 0) === 1;
    $map = [];
    $add = function (string $mod, string $acts) use (&$map) {
        $cur = $map[$mod] ?? '';
        foreach (str_split($acts) as $a) if (strpos($cur, $a) === false) $cur .= $a;
        $allowed = zu_module_actions($mod);
        $map[$mod] = implode('', array_filter(str_split('vcudpr'),
            fn($a) => strpos($cur, $a) !== false && strpos($allowed, $a) !== false));
    };

    /* costing */
    if ($f('cost_view'))     { $add('costing', 'v'); $add('fcost', 'v'); $add('pmaster', 'v'); }
    if ($f('cost_create'))   { $add('costing', 'vc'); }
    if ($f('cost_import'))   { $add('costing', 'vc'); }
    if ($f('cost_edit'))     { $add('costing', 'vu'); $add('fcost', 'vu'); $add('pmaster', 'vu'); }
    if ($f('cost_delete'))   { $add('costing', 'vd'); }
    if ($f('cost_print'))    { $add('costing', 'v'); }
    if ($f('cost_proforma')) { $add('proforma', 'vcu'); $add('ship', 'v'); $add('packing', 'v'); }

    /* inventory. inv_view is "may look at the module at all", so it opens
       viewing everywhere in it — which is exactly what inv_can_see() has
       always done. */
    $invMods = ['gate','store','consume','opening','contract','jobwork','invmast','stockrep'];
    if ($f('inv_view'))    foreach ($invMods as $m) $add($m, 'v');
    if ($f('inv_gate'))    { $add('gate', 'vcud'); $add('opening', 'vcud'); }
    if ($f('inv_store'))   { $add('store', 'vcud'); }
    if ($f('inv_consume')) { $add('consume', 'vcud'); }
    if ($f('inv_master'))  { $add('invmast', 'vcud'); $add('contract', 'vcud'); $add('opening', 'vcud'); }
    if ($f('inv_post'))    foreach (['gate','store','consume','opening','jobwork'] as $m) $add($m, 'vp');
    if ($f('inv_adjust'))  foreach (['gate','store','consume','opening','jobwork'] as $m) $add($m, 'vr');
    if ($f('inv_view') || $f('inv_gate') || $f('inv_store') || $f('inv_consume') || $f('inv_master'))
        $add('stockrep', 'v');

    /* the production role carried its own access, and it has to survive */
    if (($u['role'] ?? '') === 'production_staff') {
        $add('prod', 'vcu'); $add('prodrep', 'v');
    }
    if (($u['role'] ?? '') === 'colleague' || ($u['role'] ?? '') === 'staff') {
        /* these roles could always open the sales screens; taking that
           away on upgrade day would be a change nobody asked for */
        $add('ship', 'v'); $add('proforma', 'v'); $add('packing', 'v');
    }

    try {
        db()->beginTransaction();
        $ins = db()->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?,?,?)
                              ON DUPLICATE KEY UPDATE actions=VALUES(actions)");
        foreach ($map as $mod => $acts) if ($acts !== '') $ins->execute([$id, $mod, $acts]);
        db()->prepare("UPDATE users SET acc_seeded=1 WHERE id=?")->execute([$id]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); }
}

/* THE SCHEMA CHECK THAT COSTS NOTHING ONCE IT HAS RUN.
 *
 * current_user() already does SELECT * on the row, so if acc_seeded is a
 * key in it, the columns exist and there is nothing to do — no query, no
 * ALTER attempts, on any normal page load. Only a login that predates the
 * upgrade pays for the real check, and only once. */
function zu_ready(?array $u = null): void {
    static $ok = false;
    if ($ok) return;
    $u = $u ?? current_user();
    if (is_array($u) && array_key_exists('acc_seeded', $u)) { $ok = true; return; }
    zu_ensure_schema();
    $ok = true;
}

/* ---------------------------------------------------------------- reading */
function zu_perm_map(int $userId, bool $fresh = false): array {
    static $cache = [];
    if (!$fresh && isset($cache[$userId])) return $cache[$userId];
    zu_ready();

    /* The one-time read-across happens the first time anyone asks about
       this person, not in a migration script somebody has to remember to
       run. If it has already happened, acc_seeded says so and this costs
       one indexed lookup. */
    try {
        $s = db()->prepare("SELECT * FROM users WHERE id=?");
        $s->execute([$userId]);
        $row = $s->fetch();
        if ($row && (int)($row['acc_seeded'] ?? 0) !== 1) zu_seed_user($row);
    } catch (Throwable $e) {}

    $out = [];
    try {
        $s = db()->prepare("SELECT module, actions FROM zu_perm WHERE user_id=?");
        $s->execute([$userId]);
        foreach ($s->fetchAll() as $r) $out[(string)$r['module']] = (string)$r['actions'];
    } catch (Throwable $e) {}
    return $cache[$userId] = $out;
}

/* MAY THIS PERSON DO THIS?
 *
 * Three questions in order, and the order is the point:
 *   1. is the account an admin      — yes to everything, always
 *   2. are they inside their window — outside it, nothing that writes
 *   3. do they hold the letter      — the actual right
 *
 * The window is checked BEFORE the right. A storekeeper who may post,
 * at 2am, may not post. */
function zu_can(string $module, string $action, ?int $userId = null): bool {
    $u = $userId === null ? current_user() : null;
    if ($userId === null) {
        if (!$u) return false;
        if (($u['role'] ?? '') === 'admin') return true;
        $userId = (int)$u['id'];
        if ($action !== 'v' && zu_window_state() !== 'yes') return false;
    }
    $have = zu_perm_map($userId)[$module] ?? '';
    return strpos($have, $action) !== false;
}

function zu_require(string $module, string $action): void {
    if (zu_can($module, $action)) return;
    http_response_code(403);
    $A = zu_actions();
    exit('You do not have permission to ' . strtolower($A[$action]['n'] ?? $action) . ' here.');
}

/* --------------------------------------------------------------- the window
 *
 * Returns one of:
 *   yes  inside the window, or no window is set
 *   ro   outside it, and the setting says they may look but not change
 *   no   outside it, and the setting says no access at all
 *   off  the account is switched off entirely
 *
 * Times are compared in the app's own timezone. config.php sets
 * Asia/Karachi and bootstrap.php applies it before anything else runs,
 * so "09:00" on this screen is nine in the morning where the office is,
 * not on whatever clock the server keeps. */
function zu_window_state(?array $u = null): string {
    $u = $u ?? current_user();
    if (!$u) return 'no';
    if ((int)($u['is_active'] ?? 1) !== 1) return 'off';
    /* AN ADMIN IS NEVER LOCKED OUT BY A WINDOW. A rule that can shut out
       the last administrator is a locked building with the keys inside. */
    if (($u['role'] ?? '') === 'admin') return 'yes';

    $out  = ($u['acc_outside'] ?? 'readonly') === 'block' ? 'no' : 'ro';
    $today = date('Y-m-d');
    if (!empty($u['acc_from'])  && $today < $u['acc_from'])  return $out;
    if (!empty($u['acc_until']) && $today > $u['acc_until']) return $out;

    $days = (string)($u['acc_days'] ?? '1234567');
    if ($days !== '' && strpos($days, (string)(int)date('N')) === false) return $out;

    $t1 = (string)($u['acc_t1'] ?? ''); $t2 = (string)($u['acc_t2'] ?? '');
    if ($t1 !== '' && $t2 !== '') {
        $now = date('H:i:s');
        /* A window that ends before it starts runs through midnight — a
           night shift. Treating it as an empty window would silently give
           night staff no access at all. */
        $in = $t1 <= $t2 ? ($now >= $t1 && $now <= $t2) : ($now >= $t1 || $now <= $t2);
        if (!$in) return $out;
    }
    return 'yes';
}

/* One sentence saying what the window means, for the admin screen and for
   the message a locked-out user is shown. */
function zu_window_words(array $u): string {
    $D = ['1'=>'Mon','2'=>'Tue','3'=>'Wed','4'=>'Thu','5'=>'Fri','6'=>'Sat','7'=>'Sun'];
    $days = (string)($u['acc_days'] ?? '1234567');
    $when = ($days === '' || $days === '1234567') ? 'any day'
          : ($days === '12345' ? 'Mon–Fri'
          : ($days === '123456' ? 'Mon–Sat'
          : implode(', ', array_map(fn($n) => $D[$n] ?? $n, str_split($days)))));
    $t1 = (string)($u['acc_t1'] ?? ''); $t2 = (string)($u['acc_t2'] ?? '');
    $hrs = ($t1 !== '' && $t2 !== '') ? ' between ' . substr($t1, 0, 5) . ' and ' . substr($t2, 0, 5)
                                     : ' at any hour';
    $range = '';
    if (!empty($u['acc_from']) && !empty($u['acc_until'])) $range = ' Access runs ' . $u['acc_from'] . ' to ' . $u['acc_until'] . '.';
    elseif (!empty($u['acc_until'])) $range = ' Access ends ' . $u['acc_until'] . '.';
    elseif (!empty($u['acc_from']))  $range = ' Access starts ' . $u['acc_from'] . '.';
    return $when . $hrs . '.' . $range;
}

/* ---------------------------------------------------------------- writing */

/* Save one user's whole grid.
 *
 * It writes the new rows AND the legacy cost_ and inv_ columns, because
 * (spelled out rather than as a wildcard pair: the slash after an
 * asterisk closes this comment, which is how the first version of this
 * file failed to parse)
 * every existing screen still asks the legacy ones. Keeping them in step
 * from the one place they are edited is what makes this safe to install:
 * the old checks go on working, and they never disagree with what the
 * admin ticked. */
function zu_save_perms(int $userId, array $map, int $by = 0): array {
    zu_ensure_schema();
    if ($userId <= 0) return ['ok' => false, 'error' => 'No user.'];
    try {
        $s = db()->prepare("SELECT role FROM users WHERE id=?"); $s->execute([$userId]);
        $role = (string)$s->fetchColumn();
    } catch (Throwable $e) { $role = ''; }
    /* An admin's rights are not editable here — there is nothing to
       express, and a half-saved admin is a locked-out admin. */
    if ($role === 'admin') return ['ok' => false, 'error' => 'Admin accounts are not limited here. Change the role on the Users screen first.'];

    $clean = [];
    foreach (zu_modules() as $m) {
        $want = (string)($map[$m['k']] ?? '');
        $acts = implode('', array_filter(str_split('vcudpr'),
            fn($a) => strpos($want, $a) !== false && strpos($m['a'], $a) !== false));
        /* Any right at all implies being able to open the screen. "Create
           but cannot view" is a right nobody can use. */
        if ($acts !== '' && strpos($acts, 'v') === false) $acts = 'v' . $acts;
        if ($acts !== '') $clean[$m['k']] = $acts;
    }

    $hasAny = function (array $mods, string $a) use ($clean) {
        foreach ($mods as $m) if (strpos($clean[$m] ?? '', $a) !== false) return 1;
        return 0;
    };
    $invMods   = ['gate','store','consume','opening','contract','jobwork','invmast','stockrep'];
    $stockMods = ['gate','store','consume','opening','jobwork'];
    $legacy = [
        'cost_view'     => $hasAny(['costing','fcost','pmaster'], 'v'),
        'cost_create'   => $hasAny(['costing'], 'c'),
        'cost_edit'     => $hasAny(['costing','fcost','pmaster'], 'u'),
        'cost_delete'   => $hasAny(['costing'], 'd'),
        'cost_import'   => $hasAny(['costing'], 'c'),
        'cost_print'    => $hasAny(['costing','fcost'], 'v'),
        'cost_proforma' => $hasAny(['proforma'], 'v'),
        'inv_view'      => $hasAny($invMods, 'v'),
        'inv_gate'      => $hasAny(['gate'], 'c') | $hasAny(['gate'], 'u'),
        'inv_store'     => $hasAny(['store'], 'c') | $hasAny(['store'], 'u'),
        'inv_consume'   => $hasAny(['consume'], 'c') | $hasAny(['consume'], 'u'),
        'inv_master'    => $hasAny(['invmast'], 'c') | $hasAny(['invmast'], 'u'),
        'inv_post'      => $hasAny($stockMods, 'p'),
        'inv_adjust'    => $hasAny($stockMods, 'r'),
    ];

    try {
        db()->beginTransaction();
        db()->prepare("DELETE FROM zu_perm WHERE user_id=?")->execute([$userId]);
        $ins = db()->prepare("INSERT INTO zu_perm (user_id, module, actions, updated_by) VALUES (?,?,?,?)");
        foreach ($clean as $mod => $acts) $ins->execute([$userId, $mod, $acts, $by ?: null]);
        $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($legacy)));
        $vals = array_values($legacy); $vals[] = 1; $vals[] = $userId;
        db()->prepare("UPDATE users SET $sets, acc_seeded=? WHERE id=?")->execute($vals);
        db()->commit();
        zu_perm_map($userId, true);
        if (function_exists('cache_bump')) cache_bump('users');
        return ['ok' => true, 'error' => '', 'modules' => count($clean)];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => 'Could not save the permissions.'];
    }
}

function zu_save_window(int $userId, array $w, int $by = 0): array {
    zu_ensure_schema();
    try {
        $s = db()->prepare("SELECT role FROM users WHERE id=?"); $s->execute([$userId]);
        if ((string)$s->fetchColumn() === 'admin')
            return ['ok' => false, 'error' => 'Admin accounts are never limited by hours.'];
    } catch (Throwable $e) {}

    $days = preg_replace('/[^1-7]/', '', (string)($w['days'] ?? '1234567'));
    /* NO DAYS AT ALL IS NOT A SETTING, IT IS A LOCKOUT. Somebody clearing
       every day probably meant "no limit"; storing it literally would shut
       the person out permanently with no message that explains it. */
    if ($days === '') $days = '1234567';
    $t1 = trim((string)($w['t1'] ?? '')); $t2 = trim((string)($w['t2'] ?? ''));
    /* One time without the other cannot be compared, so both or neither. */
    if ($t1 === '' || $t2 === '') { $t1 = ''; $t2 = ''; }
    $from  = trim((string)($w['from'] ?? ''));
    $until = trim((string)($w['until'] ?? ''));
    if ($from !== '' && $until !== '' && $until < $from)
        return ['ok' => false, 'error' => 'The end date is before the start date.'];

    try {
        db()->prepare("UPDATE users SET acc_from=?, acc_until=?, acc_days=?, acc_t1=?, acc_t2=?, acc_outside=? WHERE id=?")
            ->execute([$from ?: null, $until ?: null, $days, $t1 ?: null, $t2 ?: null,
                       ($w['outside'] ?? 'readonly') === 'block' ? 'block' : 'readonly', $userId]);
        if (function_exists('cache_bump')) cache_bump('users');
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save the access window.'];
    }
}

/* ------------------------------------------------------------- enforcement
 *
 * Called from verify_csrf() in includes/helpers.php, which every write
 * handler in the app already goes through. That is the honest place for
 * it: one gate, not fifty, and a screen that forgets to ask is still
 * covered.
 *
 * Reads only. A GET is never blocked here — being read-only means being
 * able to read. */
function zu_guard_write(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $u = current_user();
    if (!$u || ($u['role'] ?? '') === 'admin') return;
    $state = zu_window_state($u);
    if ($state === 'yes') return;
    http_response_code(403);
    $words = zu_window_words($u);
    exit('Outside your working hours, so nothing can be changed right now. '
       . 'You may work ' . $words . ' Anything you had open is still on screen — '
       . 'copy it somewhere safe before leaving this page.');
}
