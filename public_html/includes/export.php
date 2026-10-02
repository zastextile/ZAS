<?php
/*
  EXPORT SHIPMENT MODULE — shared library.
  =======================================

  Everything here hangs off a shipment that already exists. Nothing in this
  file reads or writes shipment_items, packing_items or shipment_charges:
  the commercial invoice and the packing list are left exactly as they are.

  WHY THE SCHEMA CHECK IS GUARDED BY A MARKER ROW
  -----------------------------------------------
  The convention in this app is CREATE TABLE IF NOT EXISTS plus a run of
  ALTER TABLE ... ADD COLUMN inside try/catch, called at page load and
  guarded by `static $done`. That static only lasts for ONE request — every
  page load is a fresh PHP process, so the whole run of DDL goes to MySQL
  again on every single page. For this module that would be twenty-one
  statements per page load, and each failed ALTER is a round trip plus a
  thrown exception.

  So this module writes a version marker once, and after that the entire
  schema block is skipped on one indexed read of a one-row table. Bump
  EXP_SCHEMA_VERSION and the next page load installs the difference, once.
  zu_ready() in includes/access.php uses the same idea by reading a key off
  the already-loaded user row; this is that idea where no such row exists.
*/

/* The customs and chamber half of the module. Loaded here rather than page by
   page because the tab strip below asks it who may see the CUSTOMS tab, and a
   tab that appears on some screens and not others is worse than no tab.
   Including it costs nothing: the file declares functions and runs no query. */
require_once __DIR__ . '/exportdocs.php';

const EXP_SCHEMA_VERSION = '3';

/* The money shape used everywhere in this module. Amounts are DECIMAL, never
   float — a float cannot hold 0.1 exactly, and a ledger that cannot add up
   is worse than no ledger. */
const EXP_DEC_MONEY = 'DECIMAL(16,2)';
const EXP_DEC_RATE  = 'DECIMAL(12,4)';

function exp_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    /* The cheap path: one read, and we are finished. */
    try {
        $v = db()->query("SELECT v FROM exp_meta WHERE k='schema_version'")->fetchColumn();
        if ($v !== false && (string)$v === EXP_SCHEMA_VERSION) return;
    } catch (Throwable $e) {
        /* table not there yet — first run */
    }

    exp_build_schema();
}

function exp_build_schema(): void {
    $x = function (string $sql): void { try { db()->exec($sql); } catch (Throwable $e) {} };

    $x("CREATE TABLE IF NOT EXISTS exp_meta (
        k VARCHAR(60) NOT NULL PRIMARY KEY,
        v TEXT NULL,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* ---------------------------------------------------------------- masters

       ONE table for every simple list. A port, a payment method, a cost type
       and a document type are all the same shape: a label, an order, an on/off
       switch. Eight tables of three columns each would be eight screens to
       maintain and eight places to get wrong.

       `flags` carries the few per-kind facts that do not deserve a column of
       their own — whether a cost type is a commission, whether a document type
       has draft and final versions. */
    $x("CREATE TABLE IF NOT EXISTS exp_masters (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(30) NOT NULL,
        label VARCHAR(190) NOT NULL,
        code VARCHAR(40) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        flags TEXT NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_kind_label (kind, label),
        INDEX idx_kind_live (kind, is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* -------------------------------------------------------- our own banks

       company_bank_defaults still exists and still works — the proforma falls
       back to it. This is the master that can hold more than two. */
    $x("CREATE TABLE IF NOT EXISTS exp_banks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bank_name VARCHAR(160) NOT NULL,
        branch VARCHAR(160) NULL,
        account_title VARCHAR(160) NULL,
        account_no VARCHAR(80) NULL,
        iban VARCHAR(80) NULL,
        swift VARCHAR(40) NULL,
        currency VARCHAR(10) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_live (is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* --------------------------------------------------- service providers

       A separate master from inv_parties, and the reason is specific:
       inv_gate.php and inv_jobwork.php both call inv_parties('') with no type
       filter, which returns every active party. Putting a freight forwarder in
       there would make it appear in the Gate Inward party picker. Fixing that
       would mean editing working inventory screens.

       inv_party_id is the bridge: a company that genuinely exists on both
       sides is pointed at, not retyped. */
    $x("CREATE TABLE IF NOT EXISTS exp_providers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(190) NOT NULL,
        city VARCHAR(120) NULL,
        phone VARCHAR(60) NULL,
        email VARCHAR(160) NULL,
        ntn VARCHAR(40) NULL,
        address TEXT NULL,
        inv_party_id INT NULL,
        notes TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(name), INDEX(is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* Many-to-many, because one company really does do three jobs. An ENUM on
       the provider row could only ever hold one. */
    $x("CREATE TABLE IF NOT EXISTS exp_provider_roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        provider_id INT NOT NULL,
        role VARCHAR(40) NOT NULL,
        UNIQUE KEY uniq_provider_role (provider_id, role),
        INDEX(role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* ------------------------------------------------------------- logistics

       One row per shipment. These twenty fields could have been columns on
       `shipments`, but every page in the app does SELECT * on that table —
       the shipment list, the prints, the AI embedding builder, the customer
       portal. Widening it makes all of them carry data they never read. */
    $x("CREATE TABLE IF NOT EXISTS shipment_logistics (
        shipment_id INT NOT NULL PRIMARY KEY,
        loading_port_id INT NULL,
        shipping_line_id INT NULL,
        vessel_name VARCHAR(160) NULL,
        voyage_no VARCHAR(60) NULL,
        expected_load_date DATE NULL,
        actual_load_date DATE NULL,
        etd_pakistan DATE NULL,
        eta_destination DATE NULL,
        actual_arrival_date DATE NULL,
        transit_days INT NULL,
        bl_no VARCHAR(120) NULL,
        bl_date DATE NULL,
        bl_stage VARCHAR(10) NULL,
        freight_provider_id INT NULL,
        freight_agreed_amount " . EXP_DEC_MONEY . " NULL,
        freight_agreed_currency VARCHAR(10) NULL,
        freight_quote_ref VARCHAR(120) NULL,
        freight_quote_date DATE NULL,
        last_event VARCHAR(190) NULL,
        last_location VARCHAR(190) NULL,
        tracking_source VARCHAR(60) NULL,
        tracking_updated_at DATETIME NULL,
        notes TEXT NULL,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* ------------------------------------------------------------ containers */
    $x("CREATE TABLE IF NOT EXISTS shipment_containers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shipment_id INT NOT NULL,
        container_no VARCHAR(40) NULL,
        container_type_id INT NULL,
        seal_no VARCHAR(60) NULL,
        vgm_kg DECIMAL(14,3) NULL,
        tare_kg DECIMAL(14,3) NULL,
        cartons DECIMAL(14,3) NULL,
        notes VARCHAR(255) NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
        INDEX(shipment_id), INDEX(container_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* Carton ranges live in their own table, NOT as a container_id column on
       packing_items. shipment_save.php deletes and re-inserts every packing
       row on save, so a column there would be wiped without warning. */
    $x("CREATE TABLE IF NOT EXISTS shipment_container_cartons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        container_id INT NOT NULL,
        shipment_id INT NOT NULL,
        carton_from INT NOT NULL DEFAULT 0,
        carton_to INT NOT NULL DEFAULT 0,
        FOREIGN KEY (container_id) REFERENCES shipment_containers(id) ON DELETE CASCADE,
        INDEX(shipment_id), INDEX(container_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* -------------------------------------------------------------- payments

       amount is in the INVOICE's currency, always. pkr_credited and pkr_rate
       are what the bank actually did, kept for reconciliation and never used
       in the balance — three payments converted at three rates would never
       sum back to the invoice total, and the status would never reach PAID. */
    $x("CREATE TABLE IF NOT EXISTS shipment_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shipment_id INT NOT NULL,
        paid_on DATE NULL,
        amount " . EXP_DEC_MONEY . " NOT NULL DEFAULT 0,
        currency VARCHAR(10) NULL,
        method_id INT NULL,
        bank_id INT NULL,
        reference VARCHAR(190) NULL,
        pkr_credited DECIMAL(18,2) NULL,
        pkr_rate " . EXP_DEC_RATE . " NULL,
        notes TEXT NULL,
        proof_doc_id INT NULL,
        is_void TINYINT(1) NOT NULL DEFAULT 0,
        void_reason TEXT NULL,
        voided_by INT NULL,
        voided_at DATETIME NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
        INDEX(shipment_id), INDEX(paid_on), INDEX(is_void)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* ----------------------------------------------------------------- costs

       pkr_amount is STORED, not computed on read. The rate that applied on the
       bill date is a historical fact; recomputing it from today's fx_rates
       would quietly rewrite last month's cost every time the page opened. */
    $x("CREATE TABLE IF NOT EXISTS shipment_costs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shipment_id INT NOT NULL,
        cost_type_id INT NULL,
        provider_id INT NULL,
        bill_no VARCHAR(120) NULL,
        bill_date DATE NULL,
        currency VARCHAR(10) NOT NULL DEFAULT 'PKR',
        amount " . EXP_DEC_MONEY . " NOT NULL DEFAULT 0,
        fx_rate " . EXP_DEC_RATE . " NULL,
        pkr_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        is_paid TINYINT(1) NOT NULL DEFAULT 0,
        paid_on DATE NULL,
        notes TEXT NULL,
        doc_id INT NULL,
        is_void TINYINT(1) NOT NULL DEFAULT 0,
        void_reason TEXT NULL,
        voided_by INT NULL,
        voided_at DATETIME NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
        INDEX(shipment_id), INDEX(bill_date), INDEX(is_void), INDEX(cost_type_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* ------------------------------------------------------------- documents

       An official document is never overwritten. A new upload of the same type
       is the next version, and the old row is marked superseded and stays
       readable. storage_driver decides where the bytes are: 'r2' for new ones,
       NULL or 'local' for everything already on disk. */
    $x("CREATE TABLE IF NOT EXISTS shipment_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shipment_id INT NOT NULL,
        doc_type_id INT NULL,
        doc_no VARCHAR(120) NULL,
        stage VARCHAR(10) NULL,
        version INT NOT NULL DEFAULT 1,
        original_name VARCHAR(255) NOT NULL,
        storage_driver VARCHAR(20) NOT NULL DEFAULT 'local',
        storage_key VARCHAR(500) NOT NULL,
        mime_type VARCHAR(160) NULL,
        file_size BIGINT NULL,
        superseded_by INT NULL,
        is_archived TINYINT(1) NOT NULL DEFAULT 0,
        archive_reason TEXT NULL,
        archived_by INT NULL,
        archived_at DATETIME NULL,
        notes TEXT NULL,
        legacy_file_id INT NULL,
        uploaded_by INT NULL,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
        INDEX idx_ship_type_ver (shipment_id, doc_type_id, version),
        INDEX(is_archived), INDEX(legacy_file_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* For an install that created shipment_documents before this column
       existed. The column lives on the NEW table on purpose: shipment_files
       is the old, working table and gains nothing. */
    $x("ALTER TABLE shipment_documents ADD COLUMN legacy_file_id INT NULL");
    $x("CREATE INDEX idx_legacy ON shipment_documents (legacy_file_id)");

    /* ------------------------------- customs and chamber documents (Phase 2)

       The commercial invoice stays in shipment_items and is never written
       from here. These are the other two documents, typed by hand: their own
       descriptions, HS codes, quantities and rates.

       Total units are checked against the commercial invoice before a
       document may print. Total value is deliberately free — a CFR invoice
       against an FOB declaration legitimately differs — and every save
       records the difference in the audit log. */
    $x("CREATE TABLE IF NOT EXISTS exp_doc_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shipment_id INT NOT NULL,
        view VARCHAR(10) NOT NULL,
        line_no INT NOT NULL DEFAULT 0,
        description VARCHAR(255) NULL,
        hs_code VARCHAR(40) NULL,
        unit VARCHAR(40) NULL,
        qty DECIMAL(14,3) NOT NULL DEFAULT 0,
        rate DECIMAL(14,4) NOT NULL DEFAULT 0,
        amount " . EXP_DEC_MONEY . " NOT NULL DEFAULT 0,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
        INDEX idx_ship_view_line (shipment_id, view, line_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* The wording you have typed before, globally. Nothing lives in Product
       Master: a customs invoice describes goods to Pakistan Customs, not to a
       buyer, so a new customer inherits the whole vocabulary at once.
       norm_key is the description with every non-alphanumeric stripped and
       the case dropped, which is what makes two spellings one memory. */
    $x("CREATE TABLE IF NOT EXISTS exp_doc_memory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        view VARCHAR(10) NOT NULL,
        norm_key VARCHAR(190) NOT NULL,
        source_text VARCHAR(255) NULL,
        description VARCHAR(255) NOT NULL,
        hs_code VARCHAR(40) NULL,
        unit VARCHAR(40) NULL,
        times_used INT NOT NULL DEFAULT 1,
        last_used_at DATETIME NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_view_key (view, norm_key),
        INDEX idx_view_used (view, times_used)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* ------------------------------------- columns on tables that already
       exist. Every one nullable, so no existing row changes meaning and no
       existing INSERT breaks. */

    /* NOT reusing shipments.status — that ENUM is the document approval state
       (draft / submitted / approved_locked) and nine files drive off it. */
    $x("ALTER TABLE shipments ADD COLUMN logistics_status VARCHAR(40) NULL");
    $x("ALTER TABLE shipments ADD COLUMN bank_id INT NULL");
    $x("ALTER TABLE proforma_invoices ADD COLUMN bank_id INT NULL");

    /* shipment_files gets NO new columns.
     *
     * The plan said it would gain storage_driver, storage_key, doc_type_id,
     * version and superseded_by so old attachments could carry forward. In
     * the build the versioned documents went into their own table instead,
     * and those five columns ended up written by nothing and read by nothing
     * — dead weight on a live table.
     *
     * Old attachments are shown in the Documents tab as read-only legacy rows
     * (exp_legacy_files below), straight from the columns they already have,
     * and they still download through download_file.php exactly as before.
     * No column, no migration, nothing to keep in step.
     *
     * If an install ran schema version 1 before this was corrected, it has
     * those five nullable columns sitting unused. They are harmless and are
     * deliberately not dropped — dropping a column on a live table to tidy up
     * is a bigger risk than leaving an empty one alone. */

    /* products.hs_code is NOT added — it already exists and is already printed
       on the costing reports. These three are the ones that do not. */
    $x("ALTER TABLE products ADD COLUMN customs_description VARCHAR(255) NULL");
    $x("ALTER TABLE products ADD COLUMN customs_unit VARCHAR(40) NULL");
    $x("ALTER TABLE products ADD COLUMN origin_country VARCHAR(80) NULL");

    exp_seed_masters();

    try {
        db()->prepare("INSERT INTO exp_meta (k, v, updated_at) VALUES ('schema_version', ?, NOW())
                       ON DUPLICATE KEY UPDATE v=VALUES(v), updated_at=NOW()")
            ->execute([EXP_SCHEMA_VERSION]);
    } catch (Throwable $e) {}
}

/* ---------------------------------------------------------- the seed lists

   Starting rows, not a permanent list. Every one can be renamed, reordered
   or switched off from Export Masters, and new ones added without code.
   Seeding is INSERT IGNORE against a unique (kind, label), so it never
   duplicates and never overwrites a label you have edited. */
function exp_seed_masters(): void {
    $seed = [
        'port_loading' => ['Karachi Port', 'Port Qasim', 'SAPT', 'QICT', 'KICT'],
        'container_type' => ["20'GP", "40'GP", "40'HC", "45'HC", 'LCL', 'Air'],
        'payment_method' => ['Advance TT', 'TT', 'CAD', 'LC at Sight', 'LC 30 Days',
                             'LC 60 Days', 'LC 90 Days', 'DP', 'DA', 'Open Account',
                             'Mixed Terms', 'Other'],
        'logistics_status' => ['Booking Pending', 'Booked', 'Container Loaded', 'Sailing',
                               'In Transit', 'Arrived', 'Delivered', 'On Hold'],
        'shipping_line' => ['Maersk', 'MSC', 'CMA CGM', 'Hapag-Lloyd', 'ONE',
                            'Evergreen', 'COSCO', 'Other'],
    ];

    /* Cost types. The three commission rows carry is_commission, which is what
       hides them from a user who has cost access but not rate visibility. */
    $costs = [
        ['Ocean Freight', 0], ['Air Freight', 0], ['Clearing Agent', 0],
        ['Customs Charges', 0], ['Transporter Bill', 0], ['Terminal Charges', 0],
        ['THC', 0], ['Documentation Charges', 0], ['Seal', 0], ['VGM', 0],
        ['Inspection', 0], ['Chamber Charges', 0], ['Certificate Charges', 0],
        ['Courier', 0], ['Bank Charges', 0], ['Insurance', 0],
        ['Local Commission', 1], ['Export Commission', 1],
        ['Import / Overseas Commission', 1], ['Other Charges', 0],
    ];

    /* Document types. supports_draft_final marks the ones that really do have
       a draft and a final; the rest just get versions. */
    $docs = [
        ['Customer Commercial Invoice', 1], ['Customs Invoice', 1], ['Chamber Invoice', 1],
        ['Customer Packing List', 1], ['Customs Packing List', 1], ['Chamber Packing List', 1],
        ['BL', 1], ['Certificate of Origin', 1], ['Chamber Documents', 0],
        ['GD', 0], ['Form E', 0], ['Inspection Certificate', 0], ['COC', 0],
        ['Freight Invoice', 0], ['Clearing Agent Bill', 0], ['Transport Bill', 0],
        ['Commission Invoice', 0], ['Bank Documents', 0], ['TT / SWIFT Proof', 0],
        ['LC Copy', 0], ['Buyer PO', 0], ['Sales Contract', 0], ['Insurance', 0],
        ['Other Document', 0],
    ];

    try {
        $ins = db()->prepare("INSERT IGNORE INTO exp_masters (kind, label, sort_order, flags) VALUES (?,?,?,?)");
        foreach ($seed as $kind => $labels) {
            foreach ($labels as $i => $label) $ins->execute([$kind, $label, ($i + 1) * 10, null]);
        }
        foreach ($costs as $i => $row) {
            $ins->execute(['cost_type', $row[0], ($i + 1) * 10,
                           $row[1] ? json_encode(['is_commission' => 1]) : null]);
        }
        foreach ($docs as $i => $row) {
            $ins->execute(['doc_type', $row[0], ($i + 1) * 10,
                           $row[1] ? json_encode(['supports_draft_final' => 1]) : null]);
        }
    } catch (Throwable $e) {}

    /* Carry the two banks already configured for the proforma into the new
       master, so nothing is retyped and the dropdown is not empty on day one. */
    try {
        if (!(int)db()->query("SELECT COUNT(*) FROM exp_banks")->fetchColumn()) {
            $d = db()->query("SELECT * FROM company_bank_defaults WHERE id=1")->fetch();
            if ($d) {
                $ins = db()->prepare("INSERT INTO exp_banks (bank_name, branch, account_title, iban, swift, sort_order) VALUES (?,?,?,?,?,?)");
                foreach ([1, 2] as $n) {
                    $name = trim((string)($d["bank{$n}_name"] ?? ''));
                    if ($name === '') continue;
                    $ins->execute([$name, $d["bank{$n}_branch"] ?? null, $d["bank{$n}_title"] ?? null,
                                   $d["bank{$n}_iban"] ?? null, $d["bank{$n}_swift"] ?? null, $n * 10]);
                }
            }
        }
    } catch (Throwable $e) {}
}

/* ============================================================== masters API */

/* Active rows of one kind, in your order. One indexed read. */
function exp_masters(string $kind, bool $activeOnly = true): array {
    try {
        $sql = "SELECT * FROM exp_masters WHERE kind=?" . ($activeOnly ? " AND is_active=1" : "")
             . " ORDER BY sort_order, label";
        $st = db()->prepare($sql); $st->execute([$kind]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* id => label for one kind, for rendering a saved row without a join. */
function exp_master_map(string $kind): array {
    $out = [];
    foreach (exp_masters($kind, false) as $r) $out[(int)$r['id']] = (string)$r['label'];
    return $out;
}

function exp_master_flag(?array $row, string $flag): bool {
    if (!$row || empty($row['flags'])) return false;
    $f = json_decode((string)$row['flags'], true);
    return is_array($f) && !empty($f[$flag]);
}

/* The cost type ids that are commissions. Used to filter them out of both the
   rows and the totals for a user without rate visibility — not merely to hide
   a column, which would still leak the number through a total. */
function exp_commission_type_ids(): array {
    $ids = [];
    foreach (exp_masters('cost_type', false) as $r) {
        if (exp_master_flag($r, 'is_commission')) $ids[] = (int)$r['id'];
    }
    return $ids;
}

function exp_banks(bool $activeOnly = true): array {
    try {
        $sql = "SELECT * FROM exp_banks" . ($activeOnly ? " WHERE is_active=1" : "")
             . " ORDER BY sort_order, bank_name";
        return db()->query($sql)->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* ============================================================ providers API */

const EXP_ROLES = [
    'freight_forwarder' => 'Freight Forwarder',
    'shipping_line'     => 'Shipping Line',
    'transporter'       => 'Transporter',
    'clearing_agent'    => 'Clearing Agent',
    'commission_agent'  => 'Commission Agent',
    'inspection'        => 'Inspection Company',
    'other'             => 'Other Service',
];

/* Providers, optionally narrowed to one role. A provider with three roles is
   one row returned by three different role queries. */
function exp_providers(string $role = '', bool $activeOnly = true): array {
    try {
        $params = [];
        $sql = "SELECT p.* FROM exp_providers p";
        $where = [];
        if ($role !== '') {
            $sql .= " JOIN exp_provider_roles r ON r.provider_id = p.id AND r.role = ?";
            $params[] = $role;
        }
        if ($activeOnly) $where[] = "p.is_active = 1";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY p.name";
        $st = db()->prepare($sql); $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function exp_provider_map(): array {
    $out = [];
    try {
        foreach (db()->query("SELECT id, name FROM exp_providers")->fetchAll() as $r) {
            $out[(int)$r['id']] = (string)$r['name'];
        }
    } catch (Throwable $e) {}
    return $out;
}

function exp_provider_role_map(array $providerIds): array {
    if (!$providerIds) return [];
    try {
        $in = implode(',', array_fill(0, count($providerIds), '?'));
        $st = db()->prepare("SELECT provider_id, role FROM exp_provider_roles WHERE provider_id IN ($in)");
        $st->execute($providerIds);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[(int)$r['provider_id']][] = (string)$r['role'];
        return $out;
    } catch (Throwable $e) { return []; }
}

/* ============================================================ logistics API */

function exp_logistics(int $shipmentId): array {
    try {
        $st = db()->prepare("SELECT * FROM shipment_logistics WHERE shipment_id=?");
        $st->execute([$shipmentId]);
        $row = $st->fetch();
        if ($row) return $row;
    } catch (Throwable $e) {}
    return ['shipment_id' => $shipmentId];
}

function exp_containers(int $shipmentId): array {
    try {
        $st = db()->prepare("SELECT * FROM shipment_containers WHERE shipment_id=? ORDER BY id");
        $st->execute([$shipmentId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function exp_container_cartons(int $shipmentId): array {
    try {
        $st = db()->prepare("SELECT * FROM shipment_container_cartons WHERE shipment_id=? ORDER BY carton_from");
        $st->execute([$shipmentId]);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[(int)$r['container_id']][] = $r;
        return $out;
    } catch (Throwable $e) { return []; }
}

/* Container cartons against packing cartons. A mismatch here is a loading
   mistake, and it is far cheaper to find now than after the BL is drafted. */
function exp_carton_check(int $shipmentId): array {
    $declared = 0.0; $packed = 0.0;
    try {
        $st = db()->prepare("SELECT COALESCE(SUM(cartons),0) FROM shipment_containers WHERE shipment_id=?");
        $st->execute([$shipmentId]); $declared = (float)$st->fetchColumn();
        $st = db()->prepare("SELECT COALESCE(total_packages,0) FROM shipments WHERE id=?");
        $st->execute([$shipmentId]); $packed = (float)$st->fetchColumn();
    } catch (Throwable $e) {}
    $diff = round($declared - $packed, 3);
    return [
        'declared' => $declared,
        'packed'   => $packed,
        'diff'     => $diff,
        'ok'       => abs($diff) < 0.0005,
        'any'      => $declared > 0 || $packed > 0,
    ];
}

/* ============================================================= payments API */

/* The three numbers, always computed, never stored. A stored "paid" flag is
   the thing that goes stale and then lies. */
function exp_payment_summary(array $shipment): array {
    $id = (int)$shipment['id'];
    $total = (float)($shipment['total_amount'] ?? 0);
    $received = 0.0; $count = 0;
    try {
        $st = db()->prepare("SELECT COALESCE(SUM(amount),0) s, COUNT(*) n FROM shipment_payments WHERE shipment_id=? AND is_void=0");
        $st->execute([$id]);
        $r = $st->fetch();
        $received = (float)$r['s']; $count = (int)$r['n'];
    } catch (Throwable $e) {}

    $balance = round($total - $received, 2);

    if ($received <= 0.0049)              $status = 'UNPAID';
    elseif ($balance > 0.0049)            $status = 'PARTIALLY PAID';
    elseif ($balance < -0.0049)           $status = 'OVERPAID';
    else                                  $status = 'PAID';

    return [
        'currency' => (string)($shipment['currency'] ?? 'USD'),
        'total'    => $total,
        'received' => $received,
        'balance'  => $balance,
        'status'   => $status,
        'count'    => $count,
    ];
}

function exp_payments(int $shipmentId): array {
    try {
        $st = db()->prepare("SELECT * FROM shipment_payments WHERE shipment_id=? ORDER BY paid_on, id");
        $st->execute([$shipmentId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* ================================================================ costs API */

/* Commission rows are removed from the query, not from the markup, when the
   viewer has no rate visibility — so the totals they see exclude them too. */
function exp_costs(int $shipmentId, bool $includeCommission): array {
    try {
        $params = [$shipmentId];
        $sql = "SELECT * FROM shipment_costs WHERE shipment_id=?";
        if (!$includeCommission) {
            $hide = exp_commission_type_ids();
            if ($hide) {
                $sql .= " AND (cost_type_id IS NULL OR cost_type_id NOT IN ("
                      . implode(',', array_fill(0, count($hide), '?')) . "))";
                $params = array_merge($params, $hide);
            }
        }
        $sql .= " ORDER BY bill_date, id";
        $st = db()->prepare($sql); $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function exp_cost_summary(array $costs): array {
    $pkr = 0.0; $unpaidPkr = 0.0; $n = 0;
    foreach ($costs as $c) {
        if ((int)$c['is_void'] === 1) continue;
        $n++;
        $pkr += (float)$c['pkr_amount'];
        if ((int)$c['is_paid'] === 0) $unpaidPkr += (float)$c['pkr_amount'];
    }
    return ['pkr' => round($pkr, 2), 'unpaid_pkr' => round($unpaidPkr, 2), 'count' => $n];
}

/* Agreed freight against the final bill. Returns null when there is nothing to
   compare — showing a zero variance when no bill has arrived would read as
   agreement, which is the opposite of the truth. */
function exp_freight_compare(int $shipmentId, array $log): ?array
{
    $agreed    = $log['freight_agreed_amount'] !== null ? (float)$log['freight_agreed_amount'] : null;
    $agreedCur = (string)($log['freight_agreed_currency'] ?? '');

    $final = null; $finalCur = ''; $finalPkr = 0.0; $finalRate = null; $billNo = '';
    try {
        /* The freight cost rows are the ones whose type label starts with a
           freight word. Matching on the label keeps this working when you
           rename or add a freight type in the master. */
        $ids = [];
        foreach (exp_masters('cost_type', false) as $r) {
            if (stripos((string)$r['label'], 'freight') !== false) $ids[] = (int)$r['id'];
        }
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = db()->prepare("SELECT currency, SUM(amount) a, SUM(pkr_amount) p, MAX(fx_rate) r,
                                        MAX(bill_no) b, COUNT(*) n
                                 FROM shipment_costs
                                 WHERE shipment_id=? AND is_void=0 AND cost_type_id IN ($in)
                                 GROUP BY currency");
            $st->execute(array_merge([$shipmentId], $ids));
            $rows = $st->fetchAll();
            /* One currency is the normal case. Several means we report PKR
               only, because adding USD to EUR would be wrong. */
            if (count($rows) === 1) {
                $final = (float)$rows[0]['a']; $finalCur = (string)$rows[0]['currency'];
                $finalPkr = (float)$rows[0]['p']; $finalRate = $rows[0]['r'] !== null ? (float)$rows[0]['r'] : null;
                $billNo = (string)$rows[0]['b'];
            } elseif (count($rows) > 1) {
                foreach ($rows as $r) $finalPkr += (float)$r['p'];
                $finalCur = 'mixed';
            }
        }
    } catch (Throwable $e) {}

    if ($agreed === null && $final === null && $finalPkr <= 0) return null;

    $agreedPkr = null;
    if ($agreed !== null) {
        $agreedPkr = exp_to_pkr($agreed, $agreedCur ?: 'PKR');
    }

    $varAmt = null; $varPct = null;
    if ($agreed !== null && $final !== null && $agreedCur !== '' && $agreedCur === $finalCur) {
        $varAmt = round($final - $agreed, 2);
        $varPct = $agreed > 0 ? round(($varAmt / $agreed) * 100, 1) : null;
    }
    $varPkr = ($agreedPkr !== null && $finalPkr > 0) ? round($finalPkr - $agreedPkr, 2) : null;

    return [
        'agreed' => $agreed, 'agreed_currency' => $agreedCur, 'agreed_pkr' => $agreedPkr,
        'quote_ref' => (string)($log['freight_quote_ref'] ?? ''),
        'quote_date' => $log['freight_quote_date'] ?? null,
        'final' => $final, 'final_currency' => $finalCur, 'final_pkr' => $finalPkr,
        'final_rate' => $finalRate, 'bill_no' => $billNo,
        'var_amount' => $varAmt, 'var_pct' => $varPct, 'var_pkr' => $varPkr,
        'awaiting_bill' => ($agreed !== null && $final === null && $finalPkr <= 0),
    ];
}

/* Uses the fx_rates table the app already maintains from Settings. No external
   call, no new rate source. */
function exp_to_pkr(float $amount, string $currency): float {
    $currency = strtoupper($currency ?: 'PKR');
    if ($currency === 'PKR') return round($amount, 2);
    try {
        $fx = fx_get_rates();
        $r = (float)($fx[$currency] ?? 0);
        if ($r > 0) return round($amount / $r, 2);
    } catch (Throwable $e) {}
    return 0.0;
}

function exp_pkr_rate(string $currency): float {
    $currency = strtoupper($currency ?: 'PKR');
    if ($currency === 'PKR') return 1.0;
    try {
        $fx = fx_get_rates();
        $r = (float)($fx[$currency] ?? 0);
        if ($r > 0) return round(1 / $r, 4);
    } catch (Throwable $e) {}
    return 0.0;
}

/* ============================================================ documents API */

function exp_documents(int $shipmentId, bool $includeArchived = false): array {
    try {
        $sql = "SELECT * FROM shipment_documents WHERE shipment_id=?"
             . ($includeArchived ? "" : " AND is_archived=0")
             . " ORDER BY doc_type_id, version DESC, id DESC";
        $st = db()->prepare($sql); $st->execute([$shipmentId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* Attachments made before this module existed. Read-only: they are listed in
   the Documents tab so there is ONE place to look for a shipment's paperwork,
   but they keep downloading through download_file.php, which has always
   served them and has always checked the permission. Nothing is copied,
   moved or rewritten. */
function exp_legacy_files(int $shipmentId, bool $onlyUnimported = false): array {
    try {
        $sql = "SELECT f.id, f.original_name, f.stored_name, f.mime_type, f.file_size,
                       f.uploaded_by, f.created_at,
                       (SELECT d.id FROM shipment_documents d
                         WHERE d.legacy_file_id = f.id LIMIT 1) AS imported_as
                FROM shipment_files f WHERE f.shipment_id=?";
        if ($onlyUnimported) {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM shipment_documents d WHERE d.legacy_file_id = f.id)";
        }
        $sql .= " ORDER BY f.id DESC";
        $st = db()->prepare($sql);
        $st->execute([$shipmentId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* ------------------------------------- bringing an old attachment inside

   Gives a file that predates this module a document type, a version and a
   place in the version history — the same shape as anything uploaded today.

   THE ORIGINAL ROW AND THE ORIGINAL FILE ARE NOT TOUCHED. shipment_files
   keeps its row and download_file.php keeps serving it; the new document
   row simply points at the same bytes, or at a fresh copy on R2 when that
   is asked for. Nothing is deleted, so a mistake here costs one archived
   document row and nothing else.

   legacy_file_id is what stops the same file being brought in twice, and it
   lives on the new table rather than on shipment_files, which gains nothing.

   Returns [ok, message]. */
function exp_import_legacy_file(int $shipmentId, int $fileId, int $typeId, ?string $stage, bool $toR2): array
{
    global $config;

    try {
        $st = db()->prepare("SELECT * FROM shipment_files WHERE id=? AND shipment_id=?");
        $st->execute([$fileId, $shipmentId]);
        $f = $st->fetch();
    } catch (Throwable $e) { return [false, 'Could not read that file.']; }

    if (!$f) return [false, 'That file is not on this shipment.'];

    try {
        $st = db()->prepare("SELECT id FROM shipment_documents WHERE legacy_file_id=? LIMIT 1");
        $st->execute([$fileId]);
        if ($st->fetch()) return [false, 'That file has already been brought in.'];
    } catch (Throwable $e) {}

    $typeRow = null;
    foreach (exp_masters('doc_type', false) as $t) if ((int)$t['id'] === $typeId) $typeRow = $t;
    if (!$typeRow) return [false, 'Choose a document type.'];
    if (!exp_master_flag($typeRow, 'supports_draft_final')) $stage = null;
    if ($stage !== null && !in_array($stage, ['draft', 'final'], true)) $stage = null;

    $dir   = rtrim((string)($config['upload_dir'] ?? (__DIR__ . '/../storage/uploads')), '/');
    $local = $dir . '/' . basename((string)$f['stored_name']);
    if (!is_file($local)) {
        return [false, 'The stored file is missing from the server, so there is nothing to bring in. '
                     . 'The old record is left exactly as it is.'];
    }

    /* Default: point at the file already on disk. No copy, no risk. */
    $driver = 'local';
    $key    = basename((string)$f['stored_name']);

    /* Asked for, and possible: put a copy on R2 and point at that instead.
       The local file still stays where it is. */
    if ($toR2 && function_exists('exp_r2_configured') && exp_r2_configured()) {
        $newKey = exp_storage_key($shipmentId, (string)$f['original_name']);
        [$ok, $err] = exp_r2_put($local, $newKey, (string)($f['mime_type'] ?? ''));
        if (!$ok) {
            return [false, 'Could not copy it to R2: ' . $err . ' Nothing was changed.'];
        }
        $driver = 'r2';
        $key    = $newKey;
    }

    $version = exp_next_version($shipmentId, $typeId, $stage);

    try {
        db()->beginTransaction();
        db()->prepare("INSERT INTO shipment_documents
            (shipment_id, doc_type_id, doc_no, stage, version, original_name, storage_driver,
             storage_key, mime_type, file_size, notes, legacy_file_id, uploaded_by, uploaded_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $shipmentId, $typeId, null, $stage, $version,
                (string)$f['original_name'], $driver, $key,
                (string)($f['mime_type'] ?? ''), (int)($f['file_size'] ?? 0),
                'Brought in from the old Files list.',
                $fileId,
                (int)($f['uploaded_by'] ?? 0) ?: (current_user()['id'] ?? null),
                /* The date it was ORIGINALLY attached, not today. A document
                   history that says every old file arrived this afternoon is
                   worse than no history. */
                (string)($f['created_at'] ?? date('Y-m-d H:i:s')),
            ]);
        $newId = (int)db()->lastInsertId();

        db()->prepare("UPDATE shipment_documents SET superseded_by=?
                       WHERE shipment_id=? AND doc_type_id=? AND (stage <=> ?)
                         AND id<>? AND superseded_by IS NULL AND is_archived=0
                         AND version < ?")
            ->execute([$newId, $shipmentId, $typeId, $stage, $newId, $version]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return [false, 'Could not save it: ' . $e->getMessage()];
    }

    try {
        audit_log($shipmentId, 'Document', 'import', (string)$f['original_name'],
                  $typeRow['label'] . ($stage ? ' ' . ucfirst($stage) : '') . ' V' . $version,
                  'Old attachment brought into the document system' . ($driver === 'r2' ? ', copied to R2' : ''));
    } catch (Throwable $e) {}

    return [true, '"' . $f['original_name'] . '" is now ' . $typeRow['label']
                . ($stage ? ' ' . ucfirst($stage) : '') . ' version ' . $version
                . ($driver === 'r2' ? ', stored on R2.' : '.')
                . ' The original file and its old record are untouched.'];
}

/* ------------------------------------------------- linking a document back

   A payment's SWIFT slip and a cost's bill are the two documents people
   actually want to reach from the row itself. The link is one column on the
   payment or cost, set when the document is uploaded from that row.

   exp_attach_target() reads the "for" parameter that the Attach button adds
   to the Documents URL. It is strict about shape and verifies the row really
   belongs to this shipment, so a hand-edited URL cannot staple a document
   onto someone else's payment. */
function exp_attach_target(int $shipmentId, string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '' || !preg_match('~^(pay|cost):(\d+)$~', $raw, $m)) return null;

    $kind = $m[1];
    $rowId = (int)$m[2];
    if ($rowId <= 0) return null;

    $table = $kind === 'pay' ? 'shipment_payments' : 'shipment_costs';
    $col   = $kind === 'pay' ? 'proof_doc_id' : 'doc_id';

    try {
        $st = db()->prepare("SELECT * FROM $table WHERE id=? AND shipment_id=? AND is_void=0");
        $st->execute([$rowId, $shipmentId]);
        $row = $st->fetch();
        if (!$row) return null;
    } catch (Throwable $e) { return null; }

    $label = $kind === 'pay'
        ? 'payment of ' . (string)$row['currency'] . ' ' . number_format((float)$row['amount'], 2)
            . ((string)($row['reference'] ?? '') !== '' ? ' (' . $row['reference'] . ')' : '')
        : 'cost bill ' . ((string)($row['bill_no'] ?? '') !== '' ? $row['bill_no'] : '#' . $rowId);

    return ['kind' => $kind, 'id' => $rowId, 'table' => $table, 'column' => $col, 'label' => $label];
}

/* Writes the link after the document row exists. Permission is the caller's
   job — this is only reached from an upload the caller already allowed. */
function exp_attach_document(array $target, int $docId, int $shipmentId): void {
    try {
        db()->prepare("UPDATE {$target['table']} SET {$target['column']}=? WHERE id=? AND shipment_id=?")
            ->execute([$docId, $target['id'], $shipmentId]);
    } catch (Throwable $e) {}
}

/* doc id => the document row, for every document attached to a payment or a
   cost on this shipment. One query for the whole page rather than one per
   row. */
function exp_attached_docs(int $shipmentId): array {
    $out = [];
    try {
        $st = db()->prepare("SELECT id, original_name, doc_type_id, version, stage, is_archived
                             FROM shipment_documents WHERE shipment_id=?");
        $st->execute([$shipmentId]);
        foreach ($st->fetchAll() as $r) $out[(int)$r['id']] = $r;
    } catch (Throwable $e) {}
    return $out;
}

/* The next version number for this type and stage on this shipment. */
function exp_next_version(int $shipmentId, int $typeId, ?string $stage): int {
    try {
        $st = db()->prepare("SELECT COALESCE(MAX(version),0)+1 FROM shipment_documents
                             WHERE shipment_id=? AND doc_type_id=? AND (stage <=> ?)");
        $st->execute([$shipmentId, $typeId, $stage]);
        return max(1, (int)$st->fetchColumn());
    } catch (Throwable $e) { return 1; }
}

/* ========================================================== permissions API */

/* The four new modules. Read from one place so a page and the tab strip can
   never disagree about who may see what. */
function exp_can(string $area, string $action = 'v'): bool {
    $map = ['logistics' => 'shiplog', 'payments' => 'shippay',
            'costs' => 'shipcost', 'documents' => 'shipdoc'];
    $mod = $map[$area] ?? '';
    if ($mod === '') return false;
    if (is_staff() || is_production_staff()) return false;
    return function_exists('zu_can') ? zu_can($mod, $action) : is_admin();
}

function exp_require(string $area, string $action = 'v'): void {
    if (exp_can($area, $action)) return;
    http_response_code(403);
    exit('You do not have permission for this part of the shipment.');
}

/* ============================================================== the tab strip

   Rendered at the top of shipment_view.php and packing_list.php and on every
   new page. A tab the viewer has no permission for is not drawn at all.

   The header line is ONE summary query. The point of separate pages is that no
   tab pays for another tab's data, and that holds only if this stays cheap. */
function exp_tab_strip(array $shipment, string $active): void
{
    $id = (int)$shipment['id'];
    $tabs = [
        ['key' => 'invoice',   'label' => 'INVOICE',   'href' => 'shipment_view.php?id=' . $id,       'show' => !is_staff()],
        ['key' => 'packing',   'label' => 'PACKING',   'href' => 'packing_list.php?id=' . $id,        'show' => !is_production_staff()],
        ['key' => 'logistics', 'label' => 'LOGISTICS', 'href' => 'shipment_logistics.php?id=' . $id,  'show' => exp_can('logistics')],
        ['key' => 'payments',  'label' => 'PAYMENTS',  'href' => 'shipment_payments.php?id=' . $id,   'show' => exp_can('payments')],
        ['key' => 'costs',     'label' => 'COSTS',     'href' => 'shipment_costs.php?id=' . $id,      'show' => exp_can('costs')],
        ['key' => 'docs',      'label' => 'DOCS',      'href' => 'shipment_documents.php?id=' . $id,  'show' => exp_can('documents')],
        /* Customs and Chamber are two views of one screen, so they share a
           tab rather than taking one each — the strip is already long on a
           phone. They ride on the Shipments & Invoices permission, not a new
           module, because they are presentations of an invoice the user can
           already open. */
        ['key' => 'customs',   'label' => 'CUSTOMS',   'href' => 'shipment_customs.php?id=' . $id, 'show' => expdoc_can_view()],
        ['key' => 'timeline',  'label' => 'TIMELINE',  'href' => 'audit.php?id=' . $id,               'show' => is_admin() || is_colleague()],
    ];

    /* The money line is only built for someone allowed to see money. */
    $pay = null;
    if (can_see_rates() && exp_can('payments')) $pay = exp_payment_summary($shipment);
    ?>
    <style>
    .xtabs{display:flex;gap:4px;overflow-x:auto;margin:0 0 14px;padding-bottom:2px;-webkit-overflow-scrolling:touch}
    .xtabs a{padding:8px 14px;border-radius:10px 10px 0 0;border:1px solid #e3e9f2;border-bottom:none;background:#f6f8fc;color:#5a6b82;font-size:11.5px;font-weight:700;letter-spacing:.04em;text-decoration:none;white-space:nowrap;transition:.15s}
    .xtabs a:hover{background:rgba(14,168,201,.1);color:#0ea8c9}
    .xtabs a.on{background:#fff;color:#0ea8c9;border-color:#cbd5e3;box-shadow:0 -2px 0 #0ea8c9 inset}
    .xhead{padding:14px 18px;border-radius:14px;background:#fff;border:1px solid #e3e9f2;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
    .xhead .t{font-size:15px;font-weight:800;color:#152033}
    .xhead .s{font-size:12px;color:#5a6b82;margin-top:3px}
    .xpill{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
    .xpill.g{background:rgba(22,163,74,.14);color:#16a34a;border:1px solid rgba(22,163,74,.28)}
    .xpill.o{background:rgba(217,119,6,.14);color:#d97706;border:1px solid rgba(217,119,6,.28)}
    .xpill.r{background:rgba(224,67,93,.13);color:#b8283f;border:1px solid rgba(224,67,93,.28)}
    .xpill.b{background:rgba(47,127,224,.14);color:#2f7fe0;border:1px solid rgba(47,127,224,.28)}
    @media(max-width:700px){.xtabs a{padding:8px 11px;font-size:11px}.xhead{padding:12px 14px}}
    </style>
    <div class="xhead">
      <div>
        <div class="t">Shipment <?= e($shipment['invoice_no']) ?></div>
        <div class="s">
          <?= e($shipment['buyer_name']) ?>
          <?php if (!empty($shipment['destination_port'])): ?> &middot; <?= e($shipment['destination_port']) ?><?php endif; ?>
          <?php if ($pay): ?>
            &middot; <?= e(money_fmt($pay['total'], $pay['currency'])) ?>
            <?php if ($pay['balance'] > 0.0049): ?>
              &middot; Bal <?= e(money_fmt($pay['balance'], $pay['currency'])) ?>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <?= status_badge((string)$shipment['status']) ?>
        <?php if (!empty($shipment['logistics_status'])): ?>
          <span class="xpill b"><?= e($shipment['logistics_status']) ?></span>
        <?php endif; ?>
        <?php if ($pay):
            $cls = $pay['status'] === 'PAID' ? 'g' : ($pay['status'] === 'UNPAID' ? 'r' : 'o'); ?>
          <span class="xpill <?= $cls ?>"><?= e($pay['status']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="xtabs">
      <?php foreach ($tabs as $t): if (!$t['show']) continue; ?>
        <a class="<?= $t['key'] === $active ? 'on' : '' ?>" href="<?= e($t['href']) ?>"><?= e($t['label']) ?></a>
      <?php endforeach; ?>
    </div>
    <?php
}

/* ------------------------------------------------------- the shared page gate

   Every new page starts the same way: the shipment must exist, the viewer must
   be allowed to see it, and the area permission must be held. One function so
   no page can forget one of the three. */
function exp_open_shipment(string $area, string $action = 'v'): array
{
    require_login();
    exp_ensure_schema();

    $id = (int)($_GET['id'] ?? $_POST['shipment_id'] ?? 0);
    $st = db()->prepare("SELECT * FROM shipments WHERE id=?");
    $st->execute([$id]);
    $shipment = $st->fetch();

    if (!$shipment || !can_view_shipment($id)) { http_response_code(404); exit('Shipment not found or not assigned.'); }
    exp_require($area, $action);

    return $shipment;
}

/* The shared CSS for the new pages — the app's own card, table and button
   vocabulary, nothing new invented. */
function exp_page_css(): string {
    return '<style>
.xcard{padding:16px 18px;border-radius:16px;background:#fff;border:1px solid #e3e9f2;margin-bottom:12px}
.xcard h2{font-size:14px;margin:0 0 12px;color:#152033}
.xgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
.xlabel{font-size:11.5px;color:#5a6b82;display:block}
.xin{display:block;width:100%;margin-top:5px;padding:9px 11px;border-radius:9px;border:1px solid #cbd5e3;background:#fff;color:#152033;font-size:13px;outline:none;font-family:inherit;box-sizing:border-box}
.xin:focus{border-color:#0ea8c9}
.xin[readonly]{background:#f6f8fc;color:#5a6b82}
.xspan2{grid-column:span 2}
.xbtn{padding:10px 16px;border:none;border-radius:10px;cursor:pointer;font-weight:700;font-size:13px;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);text-decoration:none;display:inline-block}
.xbtn.sec{background:#f6f8fc;color:#152033;border:1px solid #cbd5e3}
.xbtn.red{background:rgba(224,67,93,.12);color:#b8283f;border:1px solid rgba(224,67,93,.3)}
.xbtn.sm{padding:5px 10px;font-size:11.5px;border-radius:8px}
.xtable{width:100%;border-collapse:collapse;font-size:12.5px}
.xtable thead tr{text-align:left;color:#8a97ab;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em}
.xtable th{padding:6px 8px;font-weight:700}
.xtable td{padding:7px 8px;border-top:1px solid #f6f8fc;vertical-align:middle}
.xtable td.num,.xtable th.num{text-align:right;font-variant-numeric:tabular-nums}
.xtable tr.void td{opacity:.5;text-decoration:line-through}
.xwrap{overflow-x:auto}
.xnote{padding:11px 14px;border-radius:12px;background:#f6f8fc;border:1px solid #e3e9f2;font-size:12.5px;color:#33415c;line-height:1.6}
.xwarn{padding:11px 14px;border-radius:12px;background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.28);font-size:12.5px;color:#9a5a06;line-height:1.6}
.xkpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:12px}
.xkpi .k{padding:12px 14px;border-radius:14px;background:#fff;border:1px solid #e3e9f2}
.xkpi .k .l{font-size:9.5px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:700}
.xkpi .k .v{font-size:17px;font-weight:800;margin-top:4px;font-variant-numeric:tabular-nums;color:#152033}
@media(max-width:700px){.xspan2{grid-column:span 1}.xcard{padding:13px 14px}}
</style>';
}
