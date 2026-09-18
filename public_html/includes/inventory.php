<?php
/*
  Inventory / Store add-on — shared schema + core helpers.

  DESIGN RULES (agreed with the owner before development, do not change
  without agreeing them again):

  1. STOCK MOVES IN EXACTLY THREE DOCUMENTS
       Gate Inward      -> material IN
       Consumption      -> material OUT and finished product IN (the only
                           document in the whole system that creates
                           finished-product stock)
       Gate Outward     -> stock OUT
     Store Issue / Store Return move stock between LOCATIONS only — same
     item, same quantity, different place, never a cost or ownership change.

  2. THE PRODUCTION MODULE IS NEVER TOUCHED
     No hook, no trigger, no new column on production_transactions. The
     existing Cutting/Stitching/Dispatch stages are wage + progress tracking
     and are completely stock-neutral. Their names are the owner's to change
     (inv_setting 'stage1_label' etc.) and nothing in here reads them.

  3. ONLY POSTED DOCUMENTS AFFECT STOCK
     draft / verified change nothing. Posting writes to inv_stock_ledger and
     locks the document. Corrections are made by reversal, never by edit.

  4. OWNERSHIP IS SEPARATE FROM LOCATION
     'own'      = our material, counts in quantity AND valuation, wherever it is
                  (including at a job worker).
     'customer' = customer-supplied material we are processing for a fee. Counts
                  in quantity (we are responsible for it) but is NEVER valued.
     Getting this wrong overstates the company's stock value, so every ledger
     row carries it explicitly.

  5. SNAPSHOT, NEVER RE-RESOLVE
     proforma_items.product_name is free text and the production module
     resolves it to a product by fuzzy matching. Any posted stock row stores
     the resolved product_id at posting time so renaming a product later can
     never move historic stock.
*/

/* ---------------------------------------------------------------- schema */
function inv_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    /* --- masters --- */
    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL,
        name VARCHAR(120) NOT NULL,
        kind ENUM('store','floor','fg','jobworker','custody','other') NOT NULL DEFAULT 'store',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_loc_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_parties (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL,
        name VARCHAR(190) NOT NULL,
        party_type ENUM('supplier','customer','jobworker','both') NOT NULL DEFAULT 'supplier',
        city VARCHAR(120) NULL,
        phone VARCHAR(60) NULL,
        ntn VARCHAR(40) NULL,
        address TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_party_code (code),
        INDEX(name), INDEX(party_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* Materials only — fabric, accessories, packing. FINISHED PRODUCTS ARE
       NOT HERE: they stay in the existing `products` table (Product Master)
       and are referenced by product_id. Nothing is duplicated. */
    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_materials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(30) NOT NULL,
        name VARCHAR(190) NOT NULL,
        item_group ENUM('Fabric','Accessories','Packing','Other') NOT NULL DEFAULT 'Fabric',
        material_type VARCHAR(80) NULL,
        stage ENUM('grey','raw','finished','na') NOT NULL DEFAULT 'na',
        composition VARCHAR(190) NULL,
        construction VARCHAR(120) NULL,
        weave_knit VARCHAR(80) NULL,
        gsm DECIMAL(10,2) NULL,
        width VARCHAR(60) NULL,
        colour VARCHAR(120) NULL,
        finish_process VARCHAR(120) NULL,
        uom VARCHAR(20) NOT NULL DEFAULT 'MTR',
        std_rate DECIMAL(16,4) NOT NULL DEFAULT 0,
        reorder_level DECIMAL(16,3) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_mat_code (code),
        INDEX(name), INDEX(item_group), INDEX(is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* --- contracts: purchase / sales / job work (in and out) --- */
    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_contracts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_no VARCHAR(40) NOT NULL,
        contract_type ENUM('purchase','sales','jobwork_out','jobwork_in') NOT NULL,
        contract_date DATE NULL,
        party_id INT NULL,
        proforma_id INT NULL,
        currency VARCHAR(8) NOT NULL DEFAULT 'PKR',
        process VARCHAR(160) NULL,
        wastage_pct DECIMAL(6,3) NOT NULL DEFAULT 0,
        expected_date DATE NULL,
        terms VARCHAR(300) NULL,
        remarks TEXT NULL,
        status ENUM('draft','active','closed','cancelled') NOT NULL DEFAULT 'draft',
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_contract_no (contract_no),
        INDEX(contract_type), INDEX(party_id), INDEX(proforma_id), INDEX(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_contract_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contract_id INT NOT NULL,
        material_id INT NULL,
        product_id INT NULL,
        return_material_id INT NULL,
        description VARCHAR(300) NULL,
        qty DECIMAL(16,3) NOT NULL DEFAULT 0,
        uom VARCHAR(20) NULL,
        rate DECIMAL(16,4) NOT NULL DEFAULT 0,
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        INDEX(contract_id), INDEX(material_id), INDEX(product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* --- gate documents (inward and outward share one header table,
           separated by `direction`, because every field is the same and one
           register is easier to search than two) --- */
    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_gate (
        id INT AUTO_INCREMENT PRIMARY KEY,
        gate_no VARCHAR(40) NOT NULL,
        direction ENUM('in','out') NOT NULL,
        txn_type VARCHAR(40) NOT NULL,
        gate_date DATE NOT NULL,
        gate_time TIME NULL,
        party_id INT NULL,
        party_text VARCHAR(190) NULL,
        contract_id INT NULL,
        proforma_id INT NULL,
        shipment_id INT NULL,
        location_id INT NULL,
        vehicle_no VARCHAR(60) NULL,
        challan_no VARCHAR(80) NULL,
        purpose VARCHAR(190) NULL,
        department VARCHAR(80) NULL,
        prepared_by INT NULL,
        verified_by VARCHAR(120) NULL,
        security_by VARCHAR(120) NULL,
        remarks TEXT NULL,
        status ENUM('draft','verified','posted','reversed') NOT NULL DEFAULT 'draft',
        override_reason TEXT NULL,
        posted_by INT NULL,
        posted_at DATETIME NULL,
        reversed_by INT NULL,
        reversed_at DATETIME NULL,
        reversal_reason TEXT NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_gate_no (gate_no),
        INDEX(direction, gate_date), INDEX(status), INDEX(contract_id), INDEX(proforma_id), INDEX(party_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_gate_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        gate_id INT NOT NULL,
        material_id INT NULL,
        product_id INT NULL,
        description VARCHAR(300) NULL,
        article VARCHAR(160) NULL,
        lot_no VARCHAR(80) NULL,
        qty DECIMAL(16,3) NOT NULL DEFAULT 0,
        uom VARCHAR(20) NULL,
        rate DECIMAL(16,4) NOT NULL DEFAULT 0,
        packing VARCHAR(120) NULL,
        ownership ENUM('own','customer') NOT NULL DEFAULT 'own',
        sort_order INT NOT NULL DEFAULT 0,
        INDEX(gate_id), INDEX(material_id), INDEX(product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* --- store issue / return: location movement only --- */
    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_store_move (
        id INT AUTO_INCREMENT PRIMARY KEY,
        move_no VARCHAR(40) NOT NULL,
        move_type ENUM('issue','return') NOT NULL,
        move_date DATE NOT NULL,
        from_location_id INT NULL,
        to_location_id INT NULL,
        proforma_id INT NULL,
        against_move_id INT NULL,
        department VARCHAR(80) NULL,
        issued_by VARCHAR(120) NULL,
        received_by VARCHAR(120) NULL,
        remarks TEXT NULL,
        status ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft',
        posted_by INT NULL,
        posted_at DATETIME NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_move_no (move_no),
        INDEX(move_type, move_date), INDEX(status), INDEX(proforma_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_store_move_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        move_id INT NOT NULL,
        material_id INT NULL,
        product_id INT NULL,
        lot_no VARCHAR(80) NULL,
        qty DECIMAL(16,3) NOT NULL DEFAULT 0,
        uom VARCHAR(20) NULL,
        condition_note VARCHAR(120) NULL,
        ownership ENUM('own','customer') NOT NULL DEFAULT 'own',
        INDEX(move_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* --- opening stock: where a balance comes from when no document
           created it.

           Until this existed, stock could only arrive through a posted
           gate pass. That is correct for everything that arrives from
           now on, and useless for the day the system is switched on, or
           for an item found on a shelf that was never entered. The
           alternative people reach for is typing a number straight onto
           a balance, which is exactly how a stock figure becomes
           something nobody can explain.

           So it is a DOCUMENT, with the same shape as every other
           document in this module: numbered, dated, draft until posted,
           posted through inv_post_ledger() like everything else, and
           reversible. An opening balance is therefore always traceable
           to a date, a document and a person. --- */
    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_opening (
        id INT AUTO_INCREMENT PRIMARY KEY,
        opening_no VARCHAR(40) NOT NULL,
        opening_date DATE NOT NULL,
        location_id INT NULL,
        remarks TEXT NULL,
        status ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft',
        posted_by INT NULL,
        posted_at DATETIME NULL,
        reversed_by INT NULL,
        reversed_at DATETIME NULL,
        reversal_reason TEXT NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_opening_no (opening_no),
        INDEX(opening_date), INDEX(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* location_id sits on the LINE as well as the header. The header's is
       the default for new lines; the line's is what posts. One physical
       count sheet routinely covers several stores, and splitting it into
       one document per store only to satisfy the table would make the
       paperwork disagree with what was actually counted. */
    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_opening_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        opening_id INT NOT NULL,
        material_id INT NULL,
        product_id INT NULL,
        size_label VARCHAR(80) NULL,
        location_id INT NULL,
        lot_no VARCHAR(80) NULL,
        qty DECIMAL(16,3) NOT NULL DEFAULT 0,
        uom VARCHAR(20) NULL,
        rate DECIMAL(16,4) NOT NULL DEFAULT 0,
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        ownership ENUM('own','customer') NOT NULL DEFAULT 'own',
        owner_party_id INT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        INDEX(opening_id), INDEX(material_id), INDEX(product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* --- consumption: the ONLY converter. inputs = materials consumed,
           outputs = finished products produced (from Product Master). --- */
    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_consumption (
        id INT AUTO_INCREMENT PRIMARY KEY,
        con_no VARCHAR(40) NOT NULL,
        con_date DATE NOT NULL,
        proforma_id INT NULL,
        jobwork_contract_id INT NULL,
        location_id INT NULL,
        department VARCHAR(80) NULL,
        batch_ref VARCHAR(80) NULL,
        remarks TEXT NULL,
        status ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft',
        posted_by INT NULL,
        posted_at DATETIME NULL,
        reversed_by INT NULL,
        reversed_at DATETIME NULL,
        reversal_reason TEXT NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_con_no (con_no),
        INDEX(con_date), INDEX(status), INDEX(proforma_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_consumption_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        con_id INT NOT NULL,
        side ENUM('input','output') NOT NULL,
        material_id INT NULL,
        product_id INT NULL,
        size_label VARCHAR(80) NULL,
        lot_no VARCHAR(80) NULL,
        std_qty DECIMAL(16,3) NOT NULL DEFAULT 0,
        qty DECIMAL(16,3) NOT NULL DEFAULT 0,
        waste_qty DECIMAL(16,3) NOT NULL DEFAULT 0,
        uom VARCHAR(20) NULL,
        rate DECIMAL(16,4) NOT NULL DEFAULT 0,
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        ownership ENUM('own','customer') NOT NULL DEFAULT 'own',
        sort_order INT NOT NULL DEFAULT 0,
        INDEX(con_id, side), INDEX(material_id), INDEX(product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    /* Lot-wise consumption. All additive and nullable, so a document
       saved before these existed still reads and posts exactly as it did.
         location_id   the lot is consumed from where it actually SITS, not
                       from the document's one location
         pick_mode     'fifo' or 'manual' — who chose this lot
         off_standard  1 = this material is not in the product's costing
         short_reason  why the lot was allowed to go below zero */
    try { db()->exec("ALTER TABLE inv_consumption_items ADD COLUMN location_id INT NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_consumption_items ADD COLUMN pick_mode VARCHAR(10) NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_consumption_items ADD COLUMN off_standard TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_consumption_items ADD COLUMN short_reason VARCHAR(300) NULL DEFAULT NULL"); } catch (Throwable $e) {}
    /* Which ORDER LINE an output row satisfies, so "how many of this line
       have already been turned into stock" is a fact rather than a guess
       made by matching product name and size. */
    try { db()->exec("ALTER TABLE inv_consumption_items ADD COLUMN proforma_item_id INT NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_consumption_items ADD INDEX idx_pfitem (proforma_item_id)"); } catch (Throwable $e) {}
    /* The cost review, kept ON the document: what it was priced at, what
       it actually came to, and — when the two are far apart — why. */
    try { db()->exec("ALTER TABLE inv_consumption ADD COLUMN quoted_cost_per_unit DECIMAL(16,4) NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_consumption ADD COLUMN actual_cost_per_unit DECIMAL(16,4) NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_consumption ADD COLUMN cost_variance_pct DECIMAL(10,2) NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_consumption ADD COLUMN cost_variance_reason VARCHAR(400) NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_consumption ADD COLUMN reversed_from INT NULL DEFAULT NULL"); } catch (Throwable $e) {}

    /* Contract-driven gate passes, line prices and sales tax. All additive
       and nullable, so every document saved before this reads and prints
       exactly as it did.
         contract_item_id  which CONTRACT LINE a gate line satisfies, so the
                           balance of a contract is a fact per line rather
                           than one lump figure for the whole contract
         amount            the line's value, stored rather than recomputed
                           from a rate that may later be corrected
         gst_*             sales tax, off unless switched on per document */
    try { db()->exec("ALTER TABLE inv_gate_items ADD COLUMN contract_item_id INT NULL DEFAULT NULL"); } catch (Throwable $e) {}
    /* WHICH CONTRACT THIS LINE IS AGAINST.
     *
     * The contract used to live only on the PASS, so one pass meant one
     * contract. In practice a lorry arrives with goods against three of them,
     * and forcing three passes for one arrival is how the gate register stops
     * matching the gate.
     *
     * NULL means "read the pass's own contract", which is exactly what every
     * line written before this column existed needs — so the balance queries
     * below fall back to g.contract_id and old documents keep reporting the
     * numbers they always did. */
    /* A finished product is stocked by SIZE — the ledger has always had a
       size_label column, but a gate line had nowhere to put one, so a
       product could only ever be received without its size. */
    try { db()->exec("ALTER TABLE inv_gate_items ADD COLUMN size_label VARCHAR(80) NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_store_move_items ADD COLUMN size_label VARCHAR(80) NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_gate_items ADD COLUMN contract_id INT NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_gate_items ADD INDEX idx_gi_contract (contract_id)"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_gate_items ADD INDEX idx_citem (contract_item_id)"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_gate_items ADD COLUMN amount DECIMAL(18,2) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_gate ADD COLUMN gst_applicable TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_gate ADD COLUMN gst_pct DECIMAL(6,3) NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_contracts ADD COLUMN gst_applicable TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_contracts ADD COLUMN gst_pct DECIMAL(6,3) NULL DEFAULT NULL"); } catch (Throwable $e) {}

    /* Job work bills carry LINES, like any other bill. The old single-row
       shape stays exactly where it is and still reads — a bill with no
       lines falls back to its own billed_qty/rate, so nothing already
       raised is disturbed. */
    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_jobwork_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bill_id INT NOT NULL,
        material_id INT NULL,
        product_id INT NULL,
        description VARCHAR(300) NULL,
        process VARCHAR(160) NULL,
        out_gate_item_id INT NULL,
        in_gate_item_id INT NULL,
        sent_qty DECIMAL(16,3) NOT NULL DEFAULT 0,
        returned_qty DECIMAL(16,3) NOT NULL DEFAULT 0,
        accepted_qty DECIMAL(16,3) NOT NULL DEFAULT 0,
        uom VARCHAR(20) NULL,
        rate DECIMAL(16,4) NOT NULL DEFAULT 0,
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        material_rate DECIMAL(16,4) NOT NULL DEFAULT 0,
        note VARCHAR(300) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        INDEX(bill_id), INDEX(out_gate_item_id), INDEX(in_gate_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* charge_basis  what the rate is applied to. It genuinely varies by
                     contract, so it is a default there and editable on
                     every bill.
       adjustment    the negotiated settlement of excess loss. There is no
                     formula for it — it is what the two sides agreed — so
                     it is typed, with a note, never calculated.
       closed_*      a bill or a job that is finished stops appearing as
                     pending anywhere, even with quantity left over. */
    try { db()->exec("ALTER TABLE inv_jobwork_charges ADD COLUMN charge_basis VARCHAR(10) NOT NULL DEFAULT 'returned'"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_jobwork_charges ADD COLUMN adjustment_amount DECIMAL(18,2) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_jobwork_charges ADD COLUMN adjustment_note VARCHAR(300) NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_jobwork_charges ADD COLUMN gst_applicable TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_jobwork_charges ADD COLUMN gst_pct DECIMAL(6,3) NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_jobwork_charges ADD COLUMN settled_date DATE NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_jobwork_charges ADD COLUMN closed_reason VARCHAR(300) NULL DEFAULT NULL"); } catch (Throwable $e) {}

    /* A gate line the owner has decided will never be billed — written off
       by agreement. It then leaves the "not billed yet" list for good. */
    try { db()->exec("ALTER TABLE inv_gate_items ADD COLUMN billing_closed TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_gate_items ADD COLUMN billing_note VARCHAR(300) NULL DEFAULT NULL"); } catch (Throwable $e) {}

    try { db()->exec("ALTER TABLE inv_contracts ADD COLUMN closed_at DATETIME NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_contracts ADD COLUMN closed_by INT NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE inv_contracts ADD COLUMN closed_reason VARCHAR(300) NULL DEFAULT NULL"); } catch (Throwable $e) {}

    /* --- the single source of truth for every quantity in the system --- */
    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_stock_ledger (
        id INT AUTO_INCREMENT PRIMARY KEY,
        txn_date DATE NOT NULL,
        material_id INT NULL,
        product_id INT NULL,
        size_label VARCHAR(80) NULL,
        location_id INT NULL,
        ownership ENUM('own','customer') NOT NULL DEFAULT 'own',
        owner_party_id INT NULL,
        lot_no VARCHAR(80) NULL,
        qty_in DECIMAL(16,3) NOT NULL DEFAULT 0,
        qty_out DECIMAL(16,3) NOT NULL DEFAULT 0,
        rate DECIMAL(16,4) NOT NULL DEFAULT 0,
        value_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        source_type VARCHAR(30) NOT NULL,
        source_id INT NOT NULL,
        source_item_id INT NULL,
        source_no VARCHAR(40) NULL,
        contract_id INT NULL,
        proforma_id INT NULL,
        remarks VARCHAR(300) NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mat (material_id, location_id),
        INDEX idx_prod (product_id, location_id),
        INDEX idx_src (source_type, source_id),
        INDEX idx_date (txn_date),
        INDEX idx_pf (proforma_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* --- job work charges, both directions --- */
    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_jobwork_charges (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bill_no VARCHAR(40) NOT NULL,
        direction ENUM('receivable','payable') NOT NULL,
        bill_date DATE NOT NULL,
        contract_id INT NULL,
        party_id INT NULL,
        gate_id INT NULL,
        their_bill_no VARCHAR(80) NULL,
        description VARCHAR(300) NULL,
        billed_qty DECIMAL(16,3) NOT NULL DEFAULT 0,
        received_qty DECIMAL(16,3) NOT NULL DEFAULT 0,
        uom VARCHAR(20) NULL,
        rate DECIMAL(16,4) NOT NULL DEFAULT 0,
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        currency VARCHAR(8) NOT NULL DEFAULT 'PKR',
        status ENUM('draft','raised','settled','query','cancelled') NOT NULL DEFAULT 'draft',
        remarks TEXT NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_bill_no (bill_no),
        INDEX(direction, bill_date), INDEX(contract_id), INDEX(party_id), INDEX(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* --- allocation: a promise against stock, never a movement --- */
    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_allocations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        alloc_no VARCHAR(40) NOT NULL,
        alloc_date DATE NOT NULL,
        proforma_id INT NULL,
        material_id INT NULL,
        qty DECIMAL(16,3) NOT NULL DEFAULT 0,
        uom VARCHAR(20) NULL,
        status ENUM('active','released','consumed') NOT NULL DEFAULT 'active',
        remarks VARCHAR(300) NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_alloc_no (alloc_no),
        INDEX(proforma_id), INDEX(material_id), INDEX(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* --- other order charges (freight, inspection, commission) for Order
           Costing Control --- */
    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_order_charges (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proforma_id INT NOT NULL,
        charge_group VARCHAR(60) NOT NULL DEFAULT 'Other',
        description VARCHAR(300) NULL,
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        currency VARCHAR(8) NOT NULL DEFAULT 'PKR',
        charge_date DATE NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(proforma_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* --- module settings (document prefixes, stage labels, defaults) --- */
    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_settings (
        skey VARCHAR(60) PRIMARY KEY,
        sval VARCHAR(300) NULL,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    /* --- per-user permissions, matching the existing cost_* flag pattern
           already on the users table. Everyone defaults to 0, so nobody
           gains access on the day this is installed. --- */
    foreach ([
        "ALTER TABLE users ADD COLUMN inv_view TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN inv_gate TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN inv_store TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN inv_consume TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN inv_post TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN inv_adjust TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN inv_master TINYINT(1) NOT NULL DEFAULT 0",
    ] as $sql) { try { db()->exec($sql); } catch (Throwable $e) {} }

    inv_seed_defaults();
}

/* First-run defaults. Every insert is guarded so re-running is harmless. */
function inv_seed_defaults(): void {
    try {
        if (!db()->query("SELECT COUNT(*) FROM inv_locations")->fetchColumn()) {
            $ins = db()->prepare("INSERT INTO inv_locations (code,name,kind) VALUES (?,?,?)");
            foreach ([
                ['MAIN', 'Main Store', 'store'],
                ['FLOOR', 'Production Floor', 'floor'],
                ['FG', 'Finished Goods Store', 'fg'],
                ['JOBWK', 'At Job Worker', 'jobworker'],
                ['CUSTODY', 'Customer Material Custody', 'custody'],
            ] as $r) $ins->execute($r);
        }
    } catch (Throwable $e) {}

    foreach ([
        'prefix_gate_in' => 'GIP', 'prefix_gate_out' => 'GOP',
        'prefix_issue' => 'ISS', 'prefix_return' => 'RET',
        'prefix_consumption' => 'CON', 'prefix_contract_pur' => 'PC',
        'prefix_contract_sal' => 'SC', 'prefix_contract_jw' => 'JW',
        'prefix_jobwork_bill' => 'JWB', 'prefix_alloc' => 'ALC',
        'prefix_opening' => 'OPN',
        'stage1_label' => 'Cutting', 'stage2_label' => 'Stitching', 'stage3_label' => 'Dispatch',
        'default_location' => '1', 'backdate_days' => '7',
        // how far below a lot's balance an ordinary user may consume, with
        // a reason. Beyond it, only an admin may post. See inv_consume_line_check().
        'neg_tolerance_pct' => '10',
        // how much MORE than was sent may come back from a job worker.
        // Quantity can rise a little in processing — their scale, moisture,
        // rounding — but not much. See inv_return_check().
        'return_over_pct' => '10',
        // cost per unit may drift this far from the costing before it has
        // to be explained. A review threshold, never a block.
        'cost_variance_pct' => '20',
        // sales tax offered on contracts and gate passes. Never applied
        // unless the document says it applies — this is only the default
        // rate the tick box fills in.
        'gst_pct' => '18',
        // how far a job work yield may fall before only an admin may post
        'jobwork_loss_ceiling_pct' => '25',
    ] as $k => $v) {
        try { db()->prepare("INSERT IGNORE INTO inv_settings (skey,sval,updated_at) VALUES (?,?,NOW())")->execute([$k, $v]); } catch (Throwable $e) {}
    }
}

/* ------------------------------------------------------------- settings */
function inv_setting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try { foreach (db()->query("SELECT skey,sval FROM inv_settings")->fetchAll() as $r) $cache[$r['skey']] = (string)$r['sval']; }
        catch (Throwable $e) { $cache = []; }
    }
    return $cache[$key] ?? $default;
}

function inv_set_setting(string $key, string $val): void {
    try {
        db()->prepare("INSERT INTO inv_settings (skey,sval,updated_at) VALUES (?,?,NOW())
            ON DUPLICATE KEY UPDATE sval=VALUES(sval), updated_at=NOW()")->execute([$key, $val]);
    } catch (Throwable $e) {}
}

/* The owner's own names for the three production stages. Nothing in this
   module depends on these — they are labels for reports only. */
function inv_stage_label(string $dbStage): string {
    $map = ['Cutting' => 'stage1_label', 'Stitching' => 'stage2_label', 'Dispatch' => 'stage3_label'];
    return isset($map[$dbStage]) ? inv_setting($map[$dbStage], $dbStage) : $dbStage;
}

/* --------------------------------------------------------- permissions */
/* Admin always has everything. Everyone else needs the specific flag —
   same philosophy as the existing costing_perm(). */
function inv_perm(string $what): bool {
    $u = current_user();
    if (!$u) return false;
    if (($u['role'] ?? '') === 'admin') return true;
    return (int)($u['inv_' . $what] ?? 0) === 1;
}

function inv_require(string $what): void {
    if (!inv_perm($what)) { http_response_code(403); exit('You do not have permission for this inventory action.'); }
}

/* Any inventory screen at all */
function inv_can_see(): bool {
    return inv_perm('view') || inv_perm('gate') || inv_perm('store') || inv_perm('consume') || inv_perm('master');
}

/* ---------------------------------------------------- document numbers */
/* PREFIX-YYMM-nnnn, sequence restarting each month. Reads the highest
   existing number for the month rather than keeping a counter, so it can
   never drift out of step with the table it numbers. */
function inv_next_no(string $prefixKey, string $table, string $column): string {
    $prefix = inv_setting($prefixKey, 'DOC');
    $ym = date('ym');
    $like = $prefix . '-' . $ym . '-%';
    $n = 0;
    try {
        $st = db()->prepare("SELECT $column FROM $table WHERE $column LIKE ? ORDER BY id DESC LIMIT 50");
        $st->execute([$like]);
        foreach ($st->fetchAll() as $r) {
            $parts = explode('-', (string)$r[$column]);
            $tail = (int)end($parts);
            if ($tail > $n) $n = $tail;
        }
    } catch (Throwable $e) {}
    return $prefix . '-' . $ym . '-' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
}

/* --------------------------------------------------------------- stock */
/* Current quantity of one material (or product), optionally at one
   location. Always computed from the ledger — there is no cached balance
   that could disagree with it. */
function inv_balance(?int $materialId, ?int $productId = null, ?int $locationId = null, string $ownership = 'own'): float {
    $where = ["ownership = ?"]; $params = [$ownership];
    if ($materialId) { $where[] = "material_id = ?"; $params[] = $materialId; }
    elseif ($productId) { $where[] = "product_id = ?"; $params[] = $productId; }
    else return 0.0;
    if ($locationId) { $where[] = "location_id = ?"; $params[] = $locationId; }
    try {
        $st = db()->prepare("SELECT COALESCE(SUM(qty_in),0) - COALESCE(SUM(qty_out),0) FROM inv_stock_ledger WHERE " . implode(' AND ', $where));
        $st->execute($params);
        return (float)$st->fetchColumn();
    } catch (Throwable $e) { return 0.0; }
}

/* ------------------------------------------------------ lot balances */
/* Every lot of one material that the ledger knows about, one row per
   lot + location + ownership, with the balance, its own value-weighted
   rate, and the dates it first arrived and last moved. Summed live — no
   stored balance exists anywhere in this module.

   `rate` is the lot's OWN cost (value ÷ quantity), not the item's
   standard rate. That is what makes a consumption's cost the real one
   rather than the theoretical one. */
function inv_lot_balances(int $materialId, bool $positiveOnly = true): array {
    if ($materialId <= 0) return [];
    try {
        $st = db()->prepare("SELECT
                COALESCE(lot_no,'') lot_no, location_id, ownership,
                COALESCE(SUM(qty_in),0) - COALESCE(SUM(qty_out),0) bal,
                COALESCE(SUM(value_amount),0) val,
                MIN(CASE WHEN qty_in > 0 THEN txn_date END) first_in,
                MAX(txn_date) last_move
            FROM inv_stock_ledger
            WHERE material_id = ?
            GROUP BY COALESCE(lot_no,''), location_id, ownership
            ORDER BY first_in, lot_no");
        $st->execute([$materialId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $bal = (float)$r['bal'];
            if ($positiveOnly && $bal <= 0.0005) continue;
            $out[] = [
                'lot_no'      => (string)$r['lot_no'],
                'location_id' => (int)$r['location_id'],
                'location'    => inv_location_name((int)$r['location_id']),
                'ownership'   => $r['ownership'],
                'bal'         => $bal,
                'rate'        => $bal > 0 ? round((float)$r['val'] / $bal, 4) : 0.0,
                'first_in'    => $r['first_in'] ?: $r['last_move'],
                'last_move'   => $r['last_move'],
            ];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/* Locations FIFO must never reach into on its own. A job worker is
   holding OUR material but it is not on our floor; custody holds a
   CUSTOMER'S material that is not ours at all. Consuming either has to
   be a deliberate manual choice, never the by-product of a keystroke. */
function inv_fifo_excluded_locations(): array {
    $out = [];
    try {
        foreach (db()->query("SELECT id FROM inv_locations WHERE kind IN ('jobworker','custody')")->fetchAll() as $r) {
            $out[] = (int)$r['id'];
        }
    } catch (Throwable $e) {}
    return $out;
}

/* Oldest lot first, from our own stock on our own floor. Returns the
   allocation plus whatever it could not cover — it never over-allocates
   and never silently reaches somewhere it should not. */
function inv_fifo_allocate(int $materialId, float $want): array {
    $want = max(0.0, $want);
    if ($want <= 0.0005) return ['alloc' => [], 'short' => 0.0];
    $skip = inv_fifo_excluded_locations();
    $alloc = []; $left = $want;
    foreach (inv_lot_balances($materialId, true) as $l) {
        if ($left <= 0.0005) break;
        if ($l['ownership'] !== 'own') continue;
        if (in_array($l['location_id'], $skip, true)) continue;
        $take = min($l['bal'], $left);
        if ($take <= 0.0005) continue;
        $alloc[] = $l + ['qty' => round($take, 3)];
        $left -= $take;
    }
    return ['alloc' => $alloc, 'short' => round(max(0.0, $left), 3)];
}

/* How far below zero an ordinary user may take a lot, as a percentage of
   what that lot actually holds. Beyond it, only an admin may post — and
   either way a reason is required and kept. */
function inv_neg_tolerance_pct(): float {
    $v = (float)inv_setting('neg_tolerance_pct', '10');
    return $v < 0 ? 0.0 : ($v > 100 ? 100.0 : $v);
}

/* Decide what one consumption line is allowed to do, given what the
   ledger says that lot holds RIGHT NOW.

     ok             within the balance — nothing special needed
     tolerance      over, but inside the allowed %  — any user, reason required
     admin_only     beyond the allowed %           — admin only, reason required

   A lot holding nothing has a tolerance of nothing (10% of zero is
   zero), so drawing on an empty lot is always an admin decision. */
function inv_consume_line_check(int $materialId, string $lot, int $locationId, string $ownership, float $qty): array {
    $bal = 0.0;
    foreach (inv_lot_balances($materialId, false) as $l) {
        if ($l['lot_no'] === $lot && $l['location_id'] === $locationId && $l['ownership'] === $ownership) { $bal = $l['bal']; break; }
    }
    $short = round($qty - $bal, 3);
    if ($short <= 0.0005) return ['level' => 'ok', 'bal' => $bal, 'short' => 0.0, 'limit' => 0.0];

    $pct   = inv_neg_tolerance_pct();
    $limit = round(max(0.0, $bal) * $pct / 100, 3);
    return [
        'level' => $short <= $limit + 0.0005 ? 'tolerance' : 'admin_only',
        'bal' => $bal, 'short' => $short, 'limit' => $limit,
    ];
}

/* ------------------------------------------------------------ job work */
/* What actually happened to one lot of material at a processor.

   The rule that matters: the VALUE does not shrink with the quantity, it
   concentrates into what came back. Send 1,000 m worth 212,500, pay
   23,500 to process it, get 940 m back — those 940 m cost 236,000, which
   is 251.06 each, not 212.50. The 60 m that disappeared were paid for by
   the 940 that survived. Any other treatment makes a product look cheaper
   to build than it is, and buries the loss in an expense nobody reads. */
function inv_jobwork_yield(float $sent, float $returned, float $materialRate, float $charge, float $other = 0.0): array {
    $loss    = round($sent - $returned, 3);
    $yield   = $sent > 0 ? $returned / $sent : 0.0;
    $lossPct = $sent > 0 ? ($loss / $sent) * 100 : 0.0;
    $matVal  = round($sent * $materialRate, 2);
    $total   = round($matVal + $charge + $other, 2);
    return [
        'sent' => $sent, 'returned' => $returned,
        'loss' => $loss, 'gain' => $loss < -0.0005 ? round(-$loss, 3) : 0.0,
        'yield_pct' => round($yield * 100, 2),
        'loss_pct'  => round($lossPct, 2),
        'material_value' => $matVal,
        'charge' => round($charge, 2), 'other' => round($other, 2),
        'total_cost' => $total,
        // the whole point: the rate the returned goods now carry
        'new_rate' => $returned > 0 ? round($total / $returned, 4) : 0.0,
        'old_rate' => $materialRate,
        'rate_rise_pct' => $materialRate > 0 && $returned > 0
            ? round((($total / $returned) - $materialRate) / $materialRate * 100, 2) : 0.0,
    ];
}

function inv_jobwork_ceiling_pct(): float {
    $v = (float)inv_setting('jobwork_loss_ceiling_pct', '25');
    return $v <= 0 ? 0.0 : min(100.0, $v);
}

/* Is this yield acceptable, and by whom?
     ok          within the wastage the contract agreed
     tolerance   past it but inside the ceiling — anyone, with a reason
     admin_only  past the ceiling — admin, with a reason
     gain        more came back than went out — always queried

   There is deliberately NO automatic recovery from the processor. The
   owner's instruction was explicit: excess loss is negotiated, not
   computed. The excess is reported so it can be argued about, and the
   settlement is typed into the bill's adjustment. */
function inv_jobwork_check(float $sent, float $returned, float $agreedPct): array {
    $loss    = $sent - $returned;
    $lossPct = $sent > 0 ? ($loss / $sent) * 100 : 0.0;
    $ceiling = inv_jobwork_ceiling_pct();
    $excess  = max(0.0, round($loss - ($sent * $agreedPct / 100), 3));

    if ($loss < -0.0005) return ['level' => 'gain', 'loss_pct' => round($lossPct, 2), 'excess' => 0.0, 'ceiling' => $ceiling];
    if ($lossPct <= $agreedPct + 0.0005) return ['level' => 'ok', 'loss_pct' => round($lossPct, 2), 'excess' => 0.0, 'ceiling' => $ceiling];
    if ($ceiling > 0 && $lossPct > $ceiling + 0.0005) return ['level' => 'admin_only', 'loss_pct' => round($lossPct, 2), 'excess' => $excess, 'ceiling' => $ceiling];
    return ['level' => 'tolerance', 'loss_pct' => round($lossPct, 2), 'excess' => $excess, 'ceiling' => $ceiling];
}

/* The quantity a job work line is charged on. Genuinely varies by
   contract — sometimes what we handed over, sometimes what came back,
   and on inbound work usually what the customer finally accepted after
   the rejection percentage has been explained to them. */
function inv_jobwork_basis_qty(array $line, string $basis): float {
    if ($basis === 'sent')     return (float)$line['sent_qty'];
    if ($basis === 'accepted') return (float)($line['accepted_qty'] ?: $line['returned_qty']);
    return (float)$line['returned_qty'];
}

function inv_jobwork_lines(int $billId): array {
    if ($billId <= 0) return [];
    try {
        $s = db()->prepare("SELECT i.*, m.code mcode, m.name mname, p.name pname
            FROM inv_jobwork_items i
            LEFT JOIN inv_materials m ON m.id = i.material_id
            LEFT JOIN products p ON p.id = i.product_id
            WHERE i.bill_id = ? ORDER BY i.sort_order, i.id");
        $s->execute([$billId]);
        return $s->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* One bill's money, from its lines when it has them and from the old
   single-row fields when it does not. */
function inv_jobwork_totals(array $bill, array $lines): array {
    $basis = $bill['charge_basis'] ?: 'returned';
    $ex = 0.0;
    if ($lines) {
        foreach ($lines as $l) $ex += (float)$l['amount'];
    } else {
        $ex = (float)$bill['amount'];
    }
    $ex  = round($ex, 2);
    $adj = round((float)($bill['adjustment_amount'] ?? 0), 2);
    $net = round($ex + $adj, 2);
    $applies = !empty($bill['gst_applicable']);
    $pct = (float)($bill['gst_pct'] ?? inv_gst_default());
    $gst = $applies ? round($net * $pct / 100, 2) : 0.0;
    return ['basis' => $basis, 'lines_total' => $ex, 'adjustment' => $adj,
            'net' => $net, 'gst' => $gst, 'pct' => $pct, 'applies' => $applies,
            'grand' => round($net + $gst, 2)];
}

/* Gate lines that have gone out or come back and still have no bill
   against them — the "not billed yet" figure. A line is out of this list
   once a bill line points at it, or once someone closes it explicitly
   because it was agreed there would be no charge. */
function inv_jobwork_open_lines(string $direction, int $contractId = 0, int $partyId = 0): array {
    // receivable: we bill for goods we DELIVERED back to the customer
    // payable:    the processor bills us for material he RETURNED
    $type = $direction === 'receivable' ? 'jobwork_delivered' : 'jobwork_return';
    $col  = $direction === 'receivable' ? 'in_gate_item_id' : 'in_gate_item_id';
    $w = ["g.status='posted'", "g.txn_type=?", "gi.billing_closed=0"];
    $p = [$type];
    if ($contractId > 0) { $w[] = 'g.contract_id=?'; $p[] = $contractId; }
    if ($partyId > 0)    { $w[] = 'g.party_id=?';    $p[] = $partyId; }
    try {
        $sql = "SELECT gi.id, gi.gate_id, gi.material_id, gi.product_id, gi.description, gi.qty, gi.uom, gi.rate,
                    g.gate_no, g.gate_date, g.contract_id, g.party_id,
                    m.code mcode, m.name mname, pr.name pname, pt.name party_name,
                    c.contract_no, c.wastage_pct, c.status contract_status, c.process, c.currency,
                    (SELECT COALESCE(SUM(ji.$col IS NOT NULL),0) FROM inv_jobwork_items ji
                       JOIN inv_jobwork_charges jb ON jb.id = ji.bill_id
                      WHERE ji.$col = gi.id AND jb.status <> 'cancelled') billed_times
                FROM inv_gate_items gi
                JOIN inv_gate g ON g.id = gi.gate_id
                LEFT JOIN inv_materials m ON m.id = gi.material_id
                LEFT JOIN products pr ON pr.id = gi.product_id
                LEFT JOIN inv_parties pt ON pt.id = g.party_id
                LEFT JOIN inv_contracts c ON c.id = g.contract_id
                WHERE " . implode(' AND ', $w) . "
                HAVING billed_times = 0
                ORDER BY g.gate_date DESC, gi.id DESC LIMIT 200";
        $s = db()->prepare($sql); $s->execute($p);
        return $s->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* Close a bill for good. Settled means the money is done; cancelled means
   it never counted. Either way it leaves every pending figure. */
function inv_jobwork_close(int $billId, string $newStatus, string $reason): array {
    if (!in_array($newStatus, ['settled', 'cancelled'], true)) return ['ok' => false, 'error' => 'Unknown closing status.'];
    if ($newStatus === 'cancelled' && trim($reason) === '') return ['ok' => false, 'error' => 'A reason is required to cancel a bill.'];
    try {
        $s = db()->prepare("SELECT bill_no, status FROM inv_jobwork_charges WHERE id=?");
        $s->execute([$billId]); $b = $s->fetch();
        if (!$b) return ['ok' => false, 'error' => 'Bill not found.'];
        if (in_array($b['status'], ['settled', 'cancelled'], true)) return ['ok' => false, 'error' => 'That bill is already closed.'];
        db()->prepare("UPDATE inv_jobwork_charges SET status=?, settled_date=?, closed_reason=? WHERE id=?")
            ->execute([$newStatus, $newStatus === 'settled' ? date('Y-m-d') : null,
                       mb_substr(trim($reason), 0, 300) ?: null, $billId]);
        inv_audit('jobwork_' . $newStatus, $billId, $b['bill_no'], $reason);
        return ['ok' => true, 'error' => '', 'bill_no' => $b['bill_no']];
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not close the bill: ' . $e->getMessage()]; }
}

/* Take a gate line off the "not billed yet" list without billing it —
   the write-off case, by agreement. */
function inv_jobwork_close_gate_line(int $gateItemId, string $note): array {
    if (trim($note) === '') return ['ok' => false, 'error' => 'Say why this will not be billed — it stays on the record.'];
    try {
        db()->prepare("UPDATE inv_gate_items SET billing_closed=1, billing_note=? WHERE id=?")
            ->execute([mb_substr(trim($note), 0, 300), $gateItemId]);
        inv_audit('jobwork_line_closed', $gateItemId, 'no further billing', $note);
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not close it: ' . $e->getMessage()]; }
}

/* Close a whole contract. It then disappears from every pending list even
   if quantity remains — which is the point: a job that is finished by
   agreement should not sit in a report forever. */
function inv_contract_close(int $contractId, string $reason): array {
    if (trim($reason) === '') return ['ok' => false, 'error' => 'A reason is required to close a contract.'];
    try {
        $s = db()->prepare("SELECT contract_no, status FROM inv_contracts WHERE id=?");
        $s->execute([$contractId]); $c = $s->fetch();
        if (!$c) return ['ok' => false, 'error' => 'Contract not found.'];
        if ($c['status'] === 'closed') return ['ok' => false, 'error' => 'That contract is already closed.'];
        db()->prepare("UPDATE inv_contracts SET status='closed', closed_at=NOW(), closed_by=?, closed_reason=? WHERE id=?")
            ->execute([(int)(current_user()['id'] ?? 0), mb_substr(trim($reason), 0, 300), $contractId]);
        inv_audit('contract_close', $contractId, $c['contract_no'], $reason);
        return ['ok' => true, 'error' => '', 'contract_no' => $c['contract_no']];
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not close the contract: ' . $e->getMessage()]; }
}

function inv_contract_reopen(int $contractId, string $reason): array {
    if (trim($reason) === '') return ['ok' => false, 'error' => 'A reason is required to reopen a contract.'];
    try {
        db()->prepare("UPDATE inv_contracts SET status='active', closed_at=NULL, closed_by=NULL,
            closed_reason=CONCAT(COALESCE(closed_reason,''), ' | reopened: ', ?) WHERE id=?")
            ->execute([mb_substr(trim($reason), 0, 200), $contractId]);
        inv_audit('contract_reopen', $contractId, '', $reason);
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not reopen: ' . $e->getMessage()]; }
}

/* -------------------------------------------------- contract balance */
/* Every line of a contract with what has actually moved against it.
   `done` counts only POSTED gate passes, and only those that named this
   contract line — so a pass raised outside the contract never quietly
   eats into its balance.

   Older gate lines carry no contract_item_id. They are counted at
   contract level under `unassigned` and reported separately rather than
   spread across lines by guesswork. */
function inv_contract_lines(int $contractId): array {
    if ($contractId <= 0) return [];
    $out = [];
    try {
        $s = db()->prepare("SELECT ci.*, m.code mcode, m.name mname, m.uom muom, p.name pname
            FROM inv_contract_items ci
            LEFT JOIN inv_materials m ON m.id = ci.material_id
            LEFT JOIN products p ON p.id = ci.product_id
            WHERE ci.contract_id = ? ORDER BY ci.sort_order, ci.id");
        $s->execute([$contractId]);
        $lines = $s->fetchAll();
        if (!$lines) return [];

        $done = [];
        /* THE LINE'S OWN CONTRACT, FALLING BACK TO THE PASS'S.
           Keyed on g.contract_id alone — as this was — a line pulled from
           contract B onto a pass headed contract A counted against NEITHER: B
           never saw it, so B read as un-consumed for ever and you would
           over-issue against it with the screen saying it was fine.
           Written as an OR rather than COALESCE(gi.contract_id, g.contract_id)
           so both sides stay index-usable; the ?s are the same id twice. */
        $s2 = db()->prepare("SELECT gi.contract_item_id, COALESCE(SUM(gi.qty),0) q, COALESCE(SUM(gi.amount),0) a
            FROM inv_gate_items gi JOIN inv_gate g ON g.id = gi.gate_id
            WHERE (gi.contract_id = ? OR (gi.contract_id IS NULL AND g.contract_id = ?)) AND g.status = 'posted' AND gi.contract_item_id IS NOT NULL
            GROUP BY gi.contract_item_id");
        $s2->execute([$contractId, $contractId]);
        foreach ($s2->fetchAll() as $r) $done[(int)$r['contract_item_id']] = ['q'=>(float)$r['q'], 'a'=>(float)$r['a']];

        foreach ($lines as $l) {
            $id  = (int)$l['id'];
            $qty = (float)$l['qty'];
            $d   = $done[$id]['q'] ?? 0.0;
            $out[] = [
                'id' => $id,
                'material_id' => (int)$l['material_id'],
                'product_id'  => (int)$l['product_id'],
                'item'        => $l['mcode'] ? $l['mcode'] . ' · ' . $l['mname'] : ($l['pname'] ?: '—'),
                'item_name'   => $l['mname'] ?: ($l['pname'] ?: ''),
                'description' => (string)$l['description'],
                'qty'         => $qty,
                'done'        => $d,
                'balance'     => round(max(0.0, $qty - $d), 3),
                'over'        => $d > $qty + 0.0005,
                'uom'         => $l['uom'] ?: ($l['muom'] ?: ''),
                'rate'        => (float)$l['rate'],
                'amount'      => (float)$l['amount'],
                'value_done'  => $done[$id]['a'] ?? 0.0,
            ];
        }
    } catch (Throwable $e) { return []; }
    return $out;
}

/* ============================================================
   EVERY CONTRACT LINE THIS PARTY HAS OPEN — the per-line picker
   ============================================================

   SCOPED TO THE PARTY, DELIBERATELY. A gate pass names who the goods came
   from or went to, and a contract belongs to exactly one party, so offering
   another party's contracts could only ever produce a wrong link — and a
   wrong link moves a balance on a contract nobody was looking at.

   One flat list with everything the picker searches on, built once per pass
   rather than per keystroke: at a busy gate a screen that waits for the server
   between letters gets abandoned for a paper register.

   A FINISHED LINE IS RETURNED, MARKED — not dropped. Hiding it makes a search
   for it answer "nothing matches", which reads as "that contract does not
   exist" when the truth is "that line is complete". Those need different
   answers. The caller decides how to show it; this decides nothing. */
function inv_party_contract_lines(int $partyId): array {
    if ($partyId <= 0) return [];
    $out = [];
    try {
        $s = db()->prepare("SELECT ci.id, ci.contract_id, ci.material_id, ci.product_id,
                   ci.description, ci.qty, ci.uom, ci.rate,
                   c.contract_no, c.contract_type, c.status,
                   m.code mcode, m.name mname, m.uom muom, p.name pname
              FROM inv_contract_items ci
              JOIN inv_contracts c ON c.id = ci.contract_id
              LEFT JOIN inv_materials m ON m.id = ci.material_id
              LEFT JOIN products p ON p.id = ci.product_id
             WHERE c.party_id = ? AND c.status IN ('draft','active')
             ORDER BY c.contract_no, ci.sort_order, ci.id");
        $s->execute([$partyId]);
        $lines = $s->fetchAll();
        if (!$lines) return [];

        /* what each of those lines has already had through the gate, by the
           SAME rule the contract's own balance uses — if these two disagreed,
           the picker would offer a balance the contract page contradicts */
        $ids = array_map(fn($l) => (int)$l['id'], $lines);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $done = [];
        $s2 = db()->prepare("SELECT gi.contract_item_id, COALESCE(SUM(gi.qty),0) q
              FROM inv_gate_items gi JOIN inv_gate g ON g.id = gi.gate_id
             WHERE gi.contract_item_id IN ($in) AND g.status = 'posted'
             GROUP BY gi.contract_item_id");
        $s2->execute($ids);
        foreach ($s2->fetchAll() as $r) $done[(int)$r['contract_item_id']] = (float)$r['q'];

        foreach ($lines as $l) {
            $id   = (int)$l['id'];
            $qty  = (float)$l['qty'];
            $d    = $done[$id] ?? 0.0;
            $item = $l['mname'] ?: ($l['pname'] ?: '');
            $code = (string)($l['mcode'] ?? '');
            $out[] = [
                'id'          => $id,
                'contract_id' => (int)$l['contract_id'],
                'contract_no' => (string)$l['contract_no'],
                'ctype'       => (string)$l['contract_type'],
                'material_id' => (int)$l['material_id'],
                'product_id'  => (int)$l['product_id'],
                'item'        => $item,
                'code'        => $code,
                'description' => (string)$l['description'],
                'uom'         => $l['uom'] ?: ($l['muom'] ?: ''),
                'rate'        => (float)$l['rate'],
                'qty'         => $qty,
                'done'        => $d,
                'balance'     => round(max(0.0, $qty - $d), 3),
                'complete'    => $d >= $qty - 0.0005,
                /* one folded string, lowercased once here rather than on every
                   keystroke in the browser */
                'hay'         => mb_strtolower(trim($item . ' ' . $code . ' ' . $l['contract_no']
                                 . ' ' . $l['contract_type'] . ' ' . $l['description']
                                 . ' ' . ($l['uom'] ?: $l['muom']))),
            ];
        }
    } catch (Throwable $e) { return []; }
    return $out;
}

/* Which contract a contract LINE belongs to.

   The gate form posts both the line and its contract, but a line pulled
   in by the older "Pull lines from contract" button carries only the
   line. Rather than trust the header — which may since have been changed
   to a different contract — the pair is closed here, from the line
   itself. Returns null when the contract line no longer exists, and the
   caller then stores no link at all rather than a pointer to nothing. */
function inv_contract_of_line(int $lineId): ?int {
    if ($lineId <= 0) return null;
    try {
        $s = db()->prepare("SELECT contract_id FROM inv_contract_items WHERE id=?");
        $s->execute([$lineId]);
        $v = (int)$s->fetchColumn();
        return $v > 0 ? $v : null;
    } catch (Throwable $e) { return null; }
}

/* Gate lines booked against a contract but not against any of its lines —
   older documents, or someone picking the contract without pulling its
   lines. Shown as its own figure so the balance above is never quietly
   wrong. */
function inv_contract_unassigned(int $contractId): array {
    $out = ['qty' => 0.0, 'amount' => 0.0, 'lines' => 0];
    try {
        /* same fallback as inv_contract_lines() above, and it must stay the
           same: if one of them counted a line the other did not, the figures
           on screen would not add up to the contract */
        $s = db()->prepare("SELECT COALESCE(SUM(gi.qty),0) q, COALESCE(SUM(gi.amount),0) a, COUNT(*) n
            FROM inv_gate_items gi JOIN inv_gate g ON g.id = gi.gate_id
            WHERE (gi.contract_id = ? OR (gi.contract_id IS NULL AND g.contract_id = ?)) AND g.status = 'posted' AND gi.contract_item_id IS NULL");
        $s->execute([$contractId, $contractId]);
        $r = $s->fetch();
        $out = ['qty' => (float)$r['q'], 'amount' => (float)$r['a'], 'lines' => (int)$r['n']];
    } catch (Throwable $e) {}
    return $out;
}

/* Every gate pass raised against a contract, newest last, for the
   contract's own ledger. Drafts are shown but marked — they change no
   balance until posted. */
function inv_contract_movements(int $contractId): array {
    if ($contractId <= 0) return [];
    try {
        /* A PASS BELONGS HERE IF ANY OF ITS LINES DOES — and its figures must
           count ONLY those lines, not the whole pass. One lorry can carry three
           contracts; showing all of its quantity under each of them would treble
           the contract's apparent consumption.

           The contract test sits in the JOIN, not the WHERE, on purpose. Moved
           to the WHERE it would turn this into an inner join and drop a pass
           that is headed here but has no lines yet — an empty draft, which is
           exactly the thing somebody is most likely to be looking for. */
        $s = db()->prepare("SELECT g.id, g.gate_no, g.gate_date, g.direction, g.txn_type, g.status,
                COALESCE(SUM(gi.qty),0) qty, COALESCE(SUM(gi.amount),0) amount, COUNT(gi.id) n
            FROM inv_gate g
            LEFT JOIN inv_gate_items gi
                   ON gi.gate_id = g.id
                  AND (gi.contract_id = ? OR (gi.contract_id IS NULL AND g.contract_id = ?))
            WHERE g.contract_id = ? OR gi.id IS NOT NULL
            GROUP BY g.id, g.gate_no, g.gate_date, g.direction, g.txn_type, g.status
            ORDER BY g.gate_date, g.id");
        $s->execute([$contractId, $contractId, $contractId]);
        return $s->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* ------------------------------------------------------- sales tax */
function inv_gst_default(): float {
    $v = (float)inv_setting('gst_pct', '18');
    return $v < 0 ? 0.0 : min(100.0, $v);
}

/* One place that turns a line list into printable totals, so the form,
   the gate pass and the contract print can never show three different
   answers for the same document. */
function inv_totals(array $lines, bool $gstApplies, float $gstPct): array {
    $ex = 0.0;
    foreach ($lines as $l) {
        $ex += isset($l['amount']) && $l['amount'] !== null && $l['amount'] !== ''
            ? (float)$l['amount']
            : (float)($l['qty'] ?? 0) * (float)($l['rate'] ?? 0);
    }
    $ex  = round($ex, 2);
    $gst = $gstApplies ? round($ex * $gstPct / 100, 2) : 0.0;
    return ['excl' => $ex, 'gst' => $gst, 'incl' => round($ex + $gst, 2), 'pct' => $gstPct, 'applies' => $gstApplies];
}

/* -------------------------------------- production ↔ consumption link */
/* What the FLOOR says about one order line, and what has already been
   turned into stock against it. Read-only in both directions: production
   is never written to from here, and stock is never written from a stage
   entry. The two records stay independent — which is precisely what makes
   comparing them worth anything.

   Returns finished / converted / available, plus the wage actually
   recorded on the line and what that comes to per piece.

   ====================================================================
   THIS FUNCTION WAS READING A TABLE NOTHING HAS WRITTEN FOR MONTHS.
   ====================================================================

   It asked production_transactions — the OLD production module. Daily
   Production Entry writes zp_entries. Nothing has written the old table
   since the module was rebuilt, so every field here came back zero, and
   zero is not a harmless wrong answer in this particular place:

     inv_consumption_post() uses `available` as a HARD BLOCK. At zero it
     refused to post ANY consumption carrying an order line — the one
     door into finished goods stock, shut.

     inv_consume.php skips lines where `started` is false, so the
     production picker listed nothing at all.

     `wage_per_unit` fed the cost check, so every order's real cost was
     understated by the whole of its workmanship.

   It also decided the stage by its SPELLING — 'Cutting', 'Stitching',
   'Dispatch' — so a stage the owner typed himself was invisible. Stages
   are rows now and position is what means something: the FIRST stage is
   the one that makes pieces, every later one works on them.

   WHAT "FINISHED" MEANS NOW, AND WHY IT CHANGED.

   The old module booked whole products, so the last stage's quantity WAS
   the finished count. The new one books PARTS, and a 7-piece set is not
   finished because 400 pillow cases exist. So:

     a part is done only when EVERY one of its operations has been booked
     — the minimum across its operations, never the maximum, because a
     cushion cover that is cut but not piped is not a cushion cover;

     the line is finished for as many SETS as its shortest part allows,
     counting how many of that part go into one set at THIS line's size.

   A product with no parts defined keeps the old meaning — the quantity
   at the last stage that has anything on it — so nothing that worked
   before this stops working. */
function inv_line_production(int $proformaItemId): array {
    $out = ['ordered'=>0.0,'cut'=>0.0,'stitched'=>0.0,'dispatched'=>0.0,'finished'=>0.0,
            'converted'=>0.0,'available'=>0.0,'wage'=>0.0,'wage_per_unit'=>0.0,
            'started'=>false,'stages'=>[],'limiting'=>'','by_part'=>[]];
    if ($proformaItemId <= 0) return $out;

    /* Loaded here rather than at the top of the file: inventory.php is
       included by screens that have nothing to do with production, and
       they should not pay for the production schema to be checked. */
    if (!function_exists('zp_part_ops')) {
        $f = __DIR__ . '/zprod.php';
        if (is_file($f)) require_once $f;
    }
    if (!function_exists('zp_part_ops')) return $out;   // production module absent — say nothing rather than guess

    $line = null;
    try {
        $s = db()->prepare("SELECT pi.id item_id, pi.proforma_id, pi.product_id, pi.product_name,
                                   pi.qty ordered_qty, pi.size, pi.product_size_id
                            FROM proforma_items pi WHERE pi.id=?");
        $s->execute([$proformaItemId]);
        $line = $s->fetch() ?: null;
    } catch (Throwable $e) {}
    if (!$line) return $out;
    $out['ordered'] = (float)$line['ordered_qty'];

    /* The same resolver the production module uses, so the two can never
       disagree about which product an order line is. */
    $productId = (int)($line['product_id'] ?? 0);
    if ($productId <= 0 && function_exists('zp_resolve_item')) $productId = zp_resolve_item($line);

    /* ---- what the floor booked, per part, per operation, per stage ---- */
    $booked = [];        // [part_id][op_id] => qty
    $firstStage = function_exists('zp_stage_first_id') ? zp_stage_first_id() : 0;
    try {
        $s = db()->prepare("SELECT COALESCE(e.part_id,0) part_id, e.op_id,
                                   COALESCE(e.stage_id,0) stage_id, e.stage,
                                   SUM(e.qty) q, SUM(e.amount) a
                            FROM zp_entries e
                            WHERE e.proforma_item_id=? AND e.status='active'
                            GROUP BY e.part_id, e.op_id, e.stage_id, e.stage");
        $s->execute([$proformaItemId]);
        foreach ($s->fetchAll() as $r) {
            $q = (float)$r['q'];
            $partId = (int)$r['part_id'];
            $opId   = (int)$r['op_id'];
            $booked[$partId][$opId] = ($booked[$partId][$opId] ?? 0) + $q;
            $out['wage'] += (float)$r['a'];

            $sid  = (int)$r['stage_id'];
            $name = $sid > 0 && function_exists('zp_stage_name') ? zp_stage_name($sid) : (string)$r['stage'];
            if ($name === '' || $name === '—') $name = (string)$r['stage'];
            if ($name === '') $name = 'Unnamed stage';
            if (!isset($out['stages'][$name])) $out['stages'][$name] = ['qty'=>0.0, 'wage'=>0.0];
            $out['stages'][$name]['qty']  += $q;
            $out['stages'][$name]['wage'] += (float)$r['a'];

            /* POSITION, NOT SPELLING. The first stage is the one that makes
               pieces; everything after it works on pieces that exist. */
            if ($firstStage > 0 && $sid === $firstStage) $out['cut'] += $q;
            elseif ($sid > 0 || $name !== '')            $out['stitched'] += $q;
            /* 'dispatched' is kept only for callers written against the old
               module; nothing in the rebuilt one books a dispatch stage. */
            if (strcasecmp($name, 'Dispatch') === 0) { $out['dispatched'] += $q; $out['stitched'] -= $q; }
        }
    } catch (Throwable $e) {}

    $out['started'] = !empty($booked);

    /* ---- how many complete SETS that adds up to ---- */
    $parts = $productId > 0 && function_exists('zp_product_parts') ? zp_product_parts($productId) : [];
    if ($parts) {
        $sizeId = function_exists('zp_line_size_id') ? zp_line_size_id($line) : 0;
        $qtyMap = function_exists('zp_qty_map') ? zp_qty_map($productId) : [];
        $sets = null;
        foreach ($parts as $p) {
            $partId = (int)$p['id'];
            $ops = zp_part_ops($partId, true);
            if (!$ops) continue;          // a part with no operations cannot be measured either way
            /* THE MINIMUM ACROSS OPERATIONS, never the sum and never the max.
               Two stitching operations on one part are two jobs done to the
               SAME pieces — adding them would report double the parts that
               exist, and taking the largest would call a part finished the
               moment its easiest operation was done. */
            $done = null;
            foreach ($ops as $o) {
                $q = (float)($booked[$partId][(int)$o['id']] ?? 0);
                $done = $done === null ? $q : min($done, $q);
            }
            $per = $sizeId > 0 && function_exists('zp_qty_for') ? (float)zp_qty_for($qtyMap, $partId, $sizeId) : 1.0;
            if ($per <= 0) $per = 1.0;
            $mine = (float)$done / $per;
            $out['by_part'][(string)$p['part_name']] = ['done'=>(float)$done, 'per_set'=>$per, 'sets'=>round($mine, 3)];
            if ($sets === null || $mine < $sets) { $sets = $mine; $out['limiting'] = (string)$p['part_name']; }
        }
        $out['finished'] = $sets === null ? 0.0 : round(max(0.0, $sets), 3);
    } else {
        /* NO PARTS DEFINED — the old meaning, kept deliberately. */
        $out['finished'] = $out['dispatched'] > 0 ? $out['dispatched']
                         : ($out['stitched'] > 0 ? $out['stitched'] : 0.0);
    }

    try {
        $s = db()->prepare("SELECT COALESCE(SUM(ci.qty),0) FROM inv_consumption_items ci
            JOIN inv_consumption c ON c.id = ci.con_id
            WHERE ci.proforma_item_id=? AND ci.side='output' AND c.status='posted'");
        $s->execute([$proformaItemId]);
        $out['converted'] = (float)$s->fetchColumn();
    } catch (Throwable $e) {}

    $out['available']     = round(max(0.0, $out['finished'] - $out['converted']), 3);
    $out['wage_per_unit'] = $out['finished'] > 0 ? round($out['wage'] / $out['finished'], 4) : 0.0;
    return $out;
}

/* The per-piece cost this order was PRICED at, split the way the costing
   sheet splits it. Returns nulls when the order carries no costing
   version — an honest "unknown" rather than a zero that would make every
   comparison look catastrophic. */
function inv_quoted_cost(int $proformaId): array {
    $out = ['known'=>false,'material'=>0.0,'wage'=>0.0,'other'=>0.0,'total'=>0.0,'version_id'=>0];
    if ($proformaId <= 0) return $out;
    try {
        $s = db()->prepare("SELECT costing_version_id FROM proforma_invoices WHERE id=?");
        $s->execute([$proformaId]);
        $vid = (int)$s->fetchColumn();
        if ($vid <= 0) return $out;
        $out['version_id'] = $vid;
        $s = db()->prepare("SELECT line_group, COALESCE(SUM(amount),0) amt FROM costing_lines
            WHERE costing_version_id=? GROUP BY line_group");
        $s->execute([$vid]);
        foreach ($s->fetchAll() as $r) {
            $amt = (float)$r['amt'];
            $out['total'] += $amt;
            if ($r['line_group'] === 'Workmanship') $out['wage'] += $amt;
            elseif (in_array($r['line_group'], ['Fabric','Accessories','Packing'], true)) $out['material'] += $amt;
            else $out['other'] += $amt;
        }
        $out['known'] = $out['total'] > 0;
    } catch (Throwable $e) {}
    return $out;
}

/* How far a consumption's cost per unit may drift from the costing before
   it must be explained. A review threshold, not a limit — see
   inv_cost_variance() below. */
function inv_cost_variance_pct(): float {
    $v = (float)inv_setting('cost_variance_pct', '20');
    return $v <= 0 ? 0.0 : min(1000.0, $v);
}

/* Compare one consumption's actual cost per unit against the costing.
   `wage_estimated` is true when the floor has logged nothing yet and the
   costing's own workmanship rate stood in — the screen says so, because a
   zero there would flatter the cost. */
function inv_cost_variance(float $materialCost, float $wagePerUnit, bool $wageEstimated, float $madeQty, array $quoted): array {
    $actMat  = $madeQty > 0 ? $materialCost / $madeQty : 0.0;
    $actWage = $wageEstimated ? $quoted['wage'] : $wagePerUnit;
    $actOth  = $quoted['other'];                 // freight/commission stay as quoted here
    $actual  = $actMat + $actWage + $actOth;
    $pct = ($quoted['known'] && $quoted['total'] > 0) ? (($actual - $quoted['total']) / $quoted['total']) * 100 : 0.0;
    $tol = inv_cost_variance_pct();
    return [
        'actual_material' => round($actMat, 4), 'actual_wage' => round($actWage, 4),
        'actual_other' => round($actOth, 4), 'actual_total' => round($actual, 4),
        'quoted_total' => round($quoted['total'], 4),
        'pct' => round($pct, 2), 'tolerance' => $tol,
        'wage_estimated' => $wageEstimated,
        // only meaningful when there is a costing to compare against
        'breach' => $quoted['known'] && $tol > 0 && abs($pct) > $tol,
    ];
}

/* What is actually on hand for a gate line, at the location the pass is
   being raised from.

   With a lot named, that lot at that location. With no lot named, every
   lot of that material at that location added together — because "send
   200 m of thread from Main Store" is a legitimate instruction that does
   not care which cone it comes off. */
/* THE SAME QUESTION, ASKED FOR A FINISHED PRODUCT TOO.
 *
 * inv_lot_balances() takes a material id, so every screen built on it could
 * only ever answer for a material. The item pickers were widened to carry
 * finished products — keyed "m<id>" or "p<id>" — but this was not, so on a
 * gate pass the picker would show a product with 300 in stock and the
 * Available column on the line beside it stayed on a dash, for ever. The
 * over-issue warning never fired either, because it reads the same number.
 *
 * A PRODUCT'S SUB-KEY IS ITS SIZE, NOT A LOT. Rolls of cloth carry lot
 * numbers; a duvet cover carries "King". They are the same shape of thing —
 * one item split into named piles at a location — so the size is returned in
 * the lot_no field and the screens need no second code path. size_label is
 * carried alongside it for anything that wants to say the right word.
 */
function inv_lot_balances_key(string $key, bool $positiveOnly = true): array {
    [$matId, $prodId] = inv_split_key($key);
    if ($matId > 0) return inv_lot_balances($matId, $positiveOnly);
    if ($prodId <= 0) return [];
    try {
        $st = db()->prepare("SELECT
                COALESCE(size_label,'') lot_no, location_id, ownership,
                COALESCE(SUM(qty_in),0) - COALESCE(SUM(qty_out),0) bal,
                COALESCE(SUM(value_amount),0) val,
                MIN(CASE WHEN qty_in > 0 THEN txn_date END) first_in,
                MAX(txn_date) last_move
            FROM inv_stock_ledger
            WHERE product_id = ?
            GROUP BY COALESCE(size_label,''), location_id, ownership
            ORDER BY first_in, lot_no");
        $st->execute([$prodId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $bal = (float)$r['bal'];
            if ($positiveOnly && $bal <= 0.0005) continue;
            $out[] = [
                'lot_no'      => (string)$r['lot_no'],
                'size_label'  => (string)$r['lot_no'],
                'location_id' => (int)$r['location_id'],
                'location'    => inv_location_name((int)$r['location_id']),
                'ownership'   => $r['ownership'],
                'bal'         => $bal,
                'rate'        => $bal > 0 ? round((float)$r['val'] / $bal, 4) : 0.0,
                'first_in'    => $r['first_in'] ?: $r['last_move'],
                'last_move'   => $r['last_move'],
            ];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/* What is on the floor for one item — material or product — optionally
   narrowed to one lot (a material) or one size (a product). $sub empty means
   the whole pile, which is the answer an operator wants before they have
   said which lot they are taking. */
function inv_available_key(string $key, string $sub, int $locationId, string $ownership = 'own'): float {
    $bal = 0.0;
    foreach (inv_lot_balances_key($key, false) as $l) {
        if ($l['ownership'] !== $ownership) continue;
        if ($locationId > 0 && $l['location_id'] !== $locationId) continue;
        if ($sub !== '' && $l['lot_no'] !== $sub) continue;
        $bal += $l['bal'];
    }
    return round($bal, 3);
}

function inv_available_at(int $materialId, string $lot, int $locationId, string $ownership = 'own'): float {
    $bal = 0.0;
    foreach (inv_lot_balances($materialId, false) as $l) {
        if ($l['ownership'] !== $ownership) continue;
        if ($locationId > 0 && $l['location_id'] !== $locationId) continue;
        if ($lot !== '' && $l['lot_no'] !== $lot) continue;
        $bal += $l['bal'];
    }
    return round($bal, 3);
}

/* Every material's balance at every location, in one query.

   inv_available_at answers for one item; a picker that has to rank two
   hundred items by what is actually on the shelf cannot call it two
   hundred times. Returns [material_id => [location_id => balance]], plus
   a 0 key per material holding the company-wide total, so the caller can
   answer "is there any of this anywhere" without adding it up again. */
function inv_stock_map(string $ownership = 'own'): array {
    return inv_stock_maps($ownership)['qty'];
}

/* Quantity AND value, from the same scan.

   The value half is what lets a gate pass fill its own rate in: value
   divided by quantity is what that stock is actually standing at, which
   is a better answer than the item master's planning rate and costs
   nothing extra to fetch. Returns ['qty' => [...], 'val' => [...]], both
   shaped [material_id => [location_id => number]] with key 0 holding the
   company-wide total. */
function inv_stock_maps(string $ownership = 'own'): array {
    $qty = []; $val = [];
    try {
        $st = db()->prepare("SELECT material_id, location_id,
                COALESCE(SUM(qty_in),0)-COALESCE(SUM(qty_out),0) bal,
                COALESCE(SUM(value_amount),0) val
            FROM inv_stock_ledger
            WHERE ownership = ? AND material_id IS NOT NULL
            GROUP BY material_id, location_id");
        $st->execute([$ownership === 'customer' ? 'customer' : 'own']);
        foreach ($st->fetchAll() as $r) {
            $bal = round((float)$r['bal'], 3);
            if (abs($bal) < 0.0005) continue;
            $m = (int)$r['material_id']; $l = (int)$r['location_id'];
            $v = round((float)$r['val'], 2);
            $qty[$m][$l] = $bal;  $qty[$m][0] = round(($qty[$m][0] ?? 0) + $bal, 3);
            $val[$m][$l] = $v;    $val[$m][0] = round(($val[$m][0] ?? 0) + $v, 2);
        }
    } catch (Throwable $e) {}
    return ['qty' => $qty, 'val' => $val];
}

/* EVERYTHING THAT CAN BE IN STOCK, WITH ITS BALANCE — materials AND
   finished products, in one list.
 *
 * WHY THIS HAD TO EXIST.
 * inv_stock_maps() above carries "AND material_id IS NOT NULL". It was
 * written when only raw material was tracked, and every item picker in
 * the app was built on it — so a finished product could be RECEIVED into
 * stock, could sit in the ledger with a real balance, and was invisible
 * to every screen that asks "what is in stock". You could not sell it,
 * issue it, or see it. That is the bug behind "stock not showing".
 *
 * inv_stock_maps() is left exactly as it is, because a dozen callers
 * depend on its shape [material_id => [location => qty]]. This is a new,
 * wider reader: one row per stockable thing, keyed "m<id>" or "p<id>" so
 * a material and a product can never be confused for one another by an
 * id that happens to match.
 *
 * bal[0] is the company-wide total; bal[<location>] is that floor.
 */
function inv_stock_items(string $ownership = 'own', bool $activeOnly = true): array {
    $own = $ownership === 'customer' ? 'customer' : 'own';
    $bal = []; $val = [];
    try {
        $st = db()->prepare("SELECT material_id, product_id, location_id,
                COALESCE(SUM(qty_in),0)-COALESCE(SUM(qty_out),0) bal,
                COALESCE(SUM(value_amount),0) val
            FROM inv_stock_ledger
            WHERE ownership = ? AND (material_id IS NOT NULL OR product_id IS NOT NULL)
            GROUP BY material_id, product_id, location_id");
        $st->execute([$own]);
        foreach ($st->fetchAll() as $r) {
            $b = round((float)$r['bal'], 3);
            if (abs($b) < 0.0005) continue;
            $k = $r['material_id'] !== null ? 'm' . (int)$r['material_id'] : 'p' . (int)$r['product_id'];
            $l = (int)$r['location_id']; $v = round((float)$r['val'], 2);
            $bal[$k][$l] = round(($bal[$k][$l] ?? 0) + $b, 3);
            $bal[$k][0]  = round(($bal[$k][0]  ?? 0) + $b, 3);
            $val[$k][$l] = round(($val[$k][$l] ?? 0) + $v, 2);
            $val[$k][0]  = round(($val[$k][0]  ?? 0) + $v, 2);
        }
    } catch (Throwable $e) {}

    $out = [];
    try {
        $w = $activeOnly ? 'WHERE is_active=1' : '';
        foreach (db()->query("SELECT id, code, name, item_group, stage, uom, std_rate
                                FROM inv_materials $w ORDER BY code")->fetchAll() as $m) {
            $k = 'm' . (int)$m['id'];
            $out[] = [
                'key' => $k, 'kind' => 'mat', 'id' => (int)$m['id'],
                'code' => (string)$m['code'], 'name' => (string)$m['name'],
                'grp'  => (string)$m['item_group'], 'stage' => (string)$m['stage'],
                'uom'  => (string)$m['uom'], 'rate' => (float)$m['std_rate'],
                'bal'  => $bal[$k] ?? [], 'val' => $val[$k] ?? [], 'sizes' => [],
            ];
        }
    } catch (Throwable $e) {}
    try {
        $w = $activeOnly ? 'WHERE is_active=1' : '';
        foreach (db()->query("SELECT id, name FROM products $w ORDER BY name LIMIT 900")->fetchAll() as $p) {
            $k = 'p' . (int)$p['id'];
            $out[] = [
                'key' => $k, 'kind' => 'prod', 'id' => (int)$p['id'],
                /* products have no code column of their own, so the id is
                   shown — an operator searching "PRD-9" finds it, and it
                   is never mistaken for a material code */
                'code' => 'PRD-' . (int)$p['id'], 'name' => (string)$p['name'],
                'grp'  => 'Finished goods', 'stage' => 'product',
                'uom'  => 'PCS', 'rate' => 0.0,
                'bal'  => $bal[$k] ?? [], 'val' => $val[$k] ?? [],
                'sizes' => function_exists('inv_product_sizes') ? inv_product_sizes((int)$p['id']) : [],
            ];
        }
    } catch (Throwable $e) {}
    return $out;
}

/* Split an "m12" / "p7" key back into ids. One box picks from two
   tables, so the kind travels WITH the id rather than being guessed at
   from which of two fields happens to be filled in. */
function inv_split_key(string $key): array {
    $key = trim($key);
    if ($key === '') return [0, 0];
    $n = (int)substr($key, 1);
    if ($n <= 0) return [0, 0];
    if ($key[0] === 'm') return [$n, 0];
    if ($key[0] === 'p') return [0, $n];
    /* a bare number is an old material id — forms saved before the key
       existed must not silently lose their item */
    return ctype_digit($key) ? [(int)$key, 0] : [0, 0];
}

/* WHO is holding our goods — derived, not stored.

   The obvious way to answer "what is Shaheen holding?" is a party column
   on every ledger row. It turns out not to be needed: a gate pass
   already records the party, and every stock row written by a pass keeps
   source_id pointing back at it. So the party is one join away, and no
   schema changes.

   Returns [party_id => ['name'=>…, 'items'=>[material_id => qty], 'qty'=>…]]
   for one location kind — 'jobworker' for our goods at a mill,
   'custody' for a customer's goods with us.

   Party id 0 is the honest bucket: rows at that location that no gate
   pass wrote (a consumption booked directly there, say). Their quantity
   is real but unattributable, so it is reported separately rather than
   silently spread across the mills. */
function inv_holdings(string $locKind = 'jobworker'): array {
    $loc = inv_location_by_kind($locKind);
    if (!$loc) return [];
    $own = $locKind === 'custody' ? 'customer' : 'own';
    $out = [];
    try {
        /* LEFT JOIN, not JOIN: a row with no pass behind it must still be
           counted, under party 0, or the totals here would quietly
           disagree with the Current Stock screen. */
        $st = db()->prepare("SELECT COALESCE(g.party_id, 0) pid, l.material_id,
                COALESCE(SUM(l.qty_in),0) - COALESCE(SUM(l.qty_out),0) bal,
                COALESCE(SUM(l.value_amount),0) val,
                MIN(CASE WHEN l.qty_in > 0 THEN l.txn_date END) since
            FROM inv_stock_ledger l
            LEFT JOIN inv_gate g
                   ON g.id = l.source_id AND l.source_type IN ('gate','gate_rev')
            WHERE l.location_id = ? AND l.ownership = ? AND l.material_id IS NOT NULL
            GROUP BY COALESCE(g.party_id,0), l.material_id
            HAVING bal > 0.0005");
        $st->execute([$loc, $own]);
        foreach ($st->fetchAll() as $r) {
            $pid = (int)$r['pid'];
            if (!isset($out[$pid])) {
                $out[$pid] = ['party_id' => $pid, 'qty' => 0.0, 'value' => 0.0, 'items' => [],
                              'name' => $pid ? '' : 'Not traceable to a gate pass'];
            }
            $out[$pid]['items'][(int)$r['material_id']] = [
                'qty' => round((float)$r['bal'], 3),
                'value' => round((float)$r['val'], 2),
                'since' => $r['since'],
            ];
            $out[$pid]['qty']   = round($out[$pid]['qty'] + (float)$r['bal'], 3);
            $out[$pid]['value'] = round($out[$pid]['value'] + (float)$r['val'], 2);
        }
        if ($out) {
            $ids = array_filter(array_keys($out));
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $s2 = db()->prepare("SELECT id, name FROM inv_parties WHERE id IN ($in)");
                $s2->execute(array_values($ids));
                foreach ($s2->fetchAll() as $r) $out[(int)$r['id']]['name'] = $r['name'];
            }
            // a party deleted from the master still holds real goods
            foreach ($out as $pid => $row) if ($pid && $row['name'] === '') $out[$pid]['name'] = 'Party #' . $pid . ' (deleted)';
        }
    } catch (Throwable $e) { return []; }
    return $out;
}

/* Lots of one material at one party, for the lot picker on a return pass. */
function inv_holding_lots(int $partyId, int $materialId, string $locKind = 'jobworker'): array {
    $loc = inv_location_by_kind($locKind);
    if (!$loc || $materialId <= 0) return [];
    $own = $locKind === 'custody' ? 'customer' : 'own';
    $out = [];
    try {
        $st = db()->prepare("SELECT COALESCE(l.lot_no,'') lot_no,
                COALESCE(SUM(l.qty_in),0) - COALESCE(SUM(l.qty_out),0) bal,
                COALESCE(SUM(l.value_amount),0) val,
                MIN(CASE WHEN l.qty_in > 0 THEN l.txn_date END) since
            FROM inv_stock_ledger l
            LEFT JOIN inv_gate g
                   ON g.id = l.source_id AND l.source_type IN ('gate','gate_rev')
            WHERE l.location_id = ? AND l.ownership = ? AND l.material_id = ?
              AND COALESCE(g.party_id,0) = ?
            GROUP BY COALESCE(l.lot_no,'')
            HAVING bal > 0.0005
            ORDER BY since, lot_no");
        $st->execute([$loc, $own, $materialId, $partyId]);
        foreach ($st->fetchAll() as $r) {
            $bal = round((float)$r['bal'], 3);
            $out[] = ['lot_no' => (string)$r['lot_no'], 'bal' => $bal,
                      'rate' => $bal > 0 ? round((float)$r['val'] / $bal, 4) : 0.0,
                      'since' => $r['since']];
        }
    } catch (Throwable $e) { return []; }
    return $out;
}

/* Can this quantity leave the gate? Same three-band rule the Consumption
   screen uses, so there is one idea to remember rather than two:

     ok          within what is there
     tolerance   over, but inside the allowed %  — anyone, reason required
     admin_only  beyond the allowed %            — admin, reason required

   Checked against the balance AT THE PASS'S LOCATION. Material sitting on
   the Cutting Floor cannot be issued from Main Store without moving it
   first, which is the point of having locations at all. */
function inv_outward_check(int $materialId, string $lot, int $locationId, string $ownership, float $qty): array {
    $bal   = inv_available_at($materialId, $lot, $locationId, $ownership);
    $short = round($qty - $bal, 3);
    if ($short <= 0.0005) return ['level' => 'ok', 'bal' => $bal, 'short' => 0.0, 'limit' => 0.0];
    $pct   = inv_neg_tolerance_pct();
    $limit = round(max(0.0, $bal) * $pct / 100, 3);
    return [
        'level' => $short <= $limit + 0.0005 ? 'tolerance' : 'admin_only',
        'bal' => $bal, 'short' => $short, 'limit' => $limit,
    ];
}

/* How much more than was sent may come back from a job worker.

   Quantity CAN legitimately rise a little in processing — weighed on
   their scale, moisture, a different rounding — so a small gain is not
   an error. A large one is: it is a keying mistake, or somebody else's
   goods being booked into your stock under your material's name. */
function inv_return_tolerance_pct(): float {
    $v = (float)inv_setting('return_over_pct', '10');
    return $v > 0 ? $v : 10.0;
}

/* Can this quantity come BACK from a party?

   The mirror of inv_outward_check, for the inward direction that had no
   check at all: a job work return, and a customer's processed goods
   going home. Measured against what that party is actually holding,
   which is derived from the passes that sent it to them.

   Same three bands as everywhere else in the module:
     ok          within what they hold
     tolerance   over, but inside the allowed %  — anyone, reason required
     admin_only  beyond it                       — admin, reason required */
function inv_return_check(int $materialId, int $partyId, string $locKind, float $qty): array {
    $held = 0.0;
    $H = inv_holdings($locKind);
    if (isset($H[$partyId]['items'][$materialId])) $held = (float)$H[$partyId]['items'][$materialId]['qty'];
    $over = round($qty - $held, 3);
    if ($over <= 0.0005) return ['level' => 'ok', 'held' => $held, 'over' => 0.0, 'limit' => 0.0];
    $pct   = inv_return_tolerance_pct();
    $limit = round(max(0.0, $held) * $pct / 100, 3);
    return [
        'level' => $over <= $limit + 0.0005 ? 'tolerance' : 'admin_only',
        'held' => $held, 'over' => $over, 'limit' => $limit, 'pct' => $pct,
    ];
}

/* Write one ledger row. This is the ONLY function in the module that is
   allowed to insert into inv_stock_ledger — everything else calls it, so
   there is exactly one place where stock can change. */
function inv_post_ledger(array $r): void {
    $sql = "INSERT INTO inv_stock_ledger
        (txn_date, material_id, product_id, size_label, location_id, ownership, owner_party_id, lot_no,
         qty_in, qty_out, rate, value_amount, source_type, source_id, source_item_id, source_no,
         contract_id, proforma_id, remarks, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $qtyIn = (float)($r['qty_in'] ?? 0);
    $qtyOut = (float)($r['qty_out'] ?? 0);
    $rate = (float)($r['rate'] ?? 0);
    $own = ($r['ownership'] ?? 'own') === 'customer' ? 'customer' : 'own';
    // Customer-owned material is counted in quantity but never valued — see
    // design rule 4 at the top of this file.
    $value = $own === 'customer' ? 0.0 : round(($qtyIn - $qtyOut) * $rate, 2);
    db()->prepare($sql)->execute([
        $r['txn_date'] ?? date('Y-m-d'),
        $r['material_id'] ?? null, $r['product_id'] ?? null, $r['size_label'] ?? null,
        $r['location_id'] ?? null, $own, $r['owner_party_id'] ?? null, $r['lot_no'] ?? null,
        $qtyIn, $qtyOut, $rate, $value,
        $r['source_type'], (int)$r['source_id'], $r['source_item_id'] ?? null, $r['source_no'] ?? null,
        $r['contract_id'] ?? null, $r['proforma_id'] ?? null, $r['remarks'] ?? null,
        (int)(current_user()['id'] ?? 0),
    ]);
}

/* Documents that are saved but have NOT been posted, so they contribute
   nothing to any stock figure. This is the single most common reason for
   "I entered it but the stock is not there", so the stock screens say it
   out loud instead of leaving it to be discovered. */
function inv_pending_counts(): array {
    $out = ['gate' => 0, 'store' => 0, 'consumption' => 0];
    try { $out['gate']        = (int)db()->query("SELECT COUNT(*) FROM inv_gate WHERE status IN ('draft','verified')")->fetchColumn(); } catch (Throwable $e) {}
    try { $out['store']       = (int)db()->query("SELECT COUNT(*) FROM inv_store_move WHERE status='draft'")->fetchColumn(); } catch (Throwable $e) {}
    try { $out['consumption'] = (int)db()->query("SELECT COUNT(*) FROM inv_consumption WHERE status='draft'")->fetchColumn(); } catch (Throwable $e) {}
    return $out;
}

/* Has this document already been posted to the ledger? Guards against a
   double-submitted form or a repeated API call posting stock twice. */
function inv_already_posted(string $sourceType, int $sourceId): bool {
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM inv_stock_ledger WHERE source_type=? AND source_id=?");
        $st->execute([$sourceType, $sourceId]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

/* --------------------------------------------- gate transaction types */
/* Each type declares exactly what it does to stock, so the posting
   routine below never needs a special case written per screen. */
function inv_gate_types(string $dir): array {
    if ($dir === 'in') {
        return [
            'purchase'         => ['label' => 'Purchase',          'own' => 'own',      'move' => 'in',      'contract' => 'purchase',    'note' => 'Material bought from a supplier'],
            'sales_return'     => ['label' => 'Sales return',      'own' => 'own',      'move' => 'in',      'contract' => 'sales',       'note' => 'Goods returned by a customer'],
            'jobwork_return'   => ['label' => 'Job work return',   'own' => 'own',      'move' => 'from_jw', 'contract' => 'jobwork_out', 'note' => 'Our material coming back from a processor'],
            'jobwork_received' => ['label' => 'Job work received', 'own' => 'customer', 'move' => 'to_cust', 'contract' => 'jobwork_in',  'note' => "Customer's material arriving for us to process"],
            'transfer_in'      => ['label' => 'Transfer in',       'own' => 'own',      'move' => 'in',      'contract' => '',            'note' => 'Arriving from another of our own units'],
            'sample_in'        => ['label' => 'Sample in',         'own' => 'own',      'move' => 'in',      'contract' => '',            'note' => 'Sample material received'],
            'other_in'         => ['label' => 'Other',             'own' => 'own',      'move' => 'in',      'contract' => '',            'note' => 'Anything else — remarks required'],
        ];
    }
    return [
        'sale'              => ['label' => 'Sale / Export',      'own' => 'own',      'move' => 'out',     'contract' => 'sales',       'note' => 'Finished goods going to a customer'],
        'purchase_return'   => ['label' => 'Purchase return',    'own' => 'own',      'move' => 'out',     'contract' => 'purchase',    'note' => 'Rejected material back to the supplier'],
        'jobwork_issue'     => ['label' => 'Job work issue',     'own' => 'own',      'move' => 'to_jw',   'contract' => 'jobwork_out', 'note' => 'Our material out for processing — stays ours'],
        'jobwork_delivered' => ['label' => 'Job work delivered', 'own' => 'customer', 'move' => 'from_cust','contract' => 'jobwork_in', 'note' => "Processed goods back to the customer who owns them"],
        'transfer_out'      => ['label' => 'Transfer out',       'own' => 'own',      'move' => 'out',     'contract' => '',            'note' => 'Going to another of our own units'],
        'sample_out'        => ['label' => 'Sample out',         'own' => 'own',      'move' => 'out',     'contract' => '',            'note' => 'Samples to a buyer, agent or lab'],
        'other_out'         => ['label' => 'Other',              'own' => 'own',      'move' => 'out',     'contract' => '',            'note' => 'Scrap, write-off — remarks required'],
    ];
}

function inv_location_by_kind(string $kind): ?int {
    static $c = [];
    if (array_key_exists($kind, $c)) return $c[$kind];
    try {
        $st = db()->prepare("SELECT id FROM inv_locations WHERE kind=? AND is_active=1 ORDER BY id LIMIT 1");
        $st->execute([$kind]);
        $v = $st->fetchColumn();
        return $c[$kind] = ($v !== false ? (int)$v : null);
    } catch (Throwable $e) { return $c[$kind] = null; }
}

/* ---------------------------------------------------------- gate post */
/* Turns a saved gate document into ledger rows. This is the moment stock
   actually changes, and it is the only place a gate pass can do so.
   Returns ['ok'=>bool,'error'=>string].

   Movement shapes, driven entirely by the type table above:
     in        one row  IN  at the document's location
     out       one row  OUT at the document's location
     to_jw     two rows OUT at location, IN at the job-worker location
               (our material, still ours, now outside our walls)
     from_jw   two rows OUT at job worker, IN at the document's location
     to_cust   one row  IN  at custody, ownership=customer, value 0
     from_cust one row  OUT at custody, ownership=customer, value 0 */
function inv_gate_post(int $gateId): array {
    try {
        $st = db()->prepare("SELECT * FROM inv_gate WHERE id=?");
        $st->execute([$gateId]);
        $g = $st->fetch();
        if (!$g) return ['ok' => false, 'error' => 'Gate pass not found.'];
        if ($g['status'] === 'posted')   return ['ok' => false, 'error' => 'This pass is already posted.'];
        if ($g['status'] === 'reversed') return ['ok' => false, 'error' => 'This pass has been reversed and cannot be posted again.'];

        // Belt and braces: even a double-submitted form cannot post twice.
        if (inv_already_posted('gate', $gateId)) return ['ok' => false, 'error' => 'Stock has already been written for this pass.'];

        $st2 = db()->prepare("SELECT * FROM inv_gate_items WHERE gate_id=? ORDER BY sort_order, id");
        $st2->execute([$gateId]);
        $items = $st2->fetchAll();
        if (!$items) return ['ok' => false, 'error' => 'Add at least one item before posting.'];

        $types = inv_gate_types($g['direction']);
        $T = $types[$g['txn_type']] ?? null;
        if (!$T) return ['ok' => false, 'error' => 'Unknown transaction type.'];

        $docLoc  = (int)$g['location_id'] ?: (int)inv_setting('default_location', '1');
        $jwLoc   = inv_location_by_kind('jobworker');
        $custLoc = inv_location_by_kind('custody');
        if ($T['move'] === 'to_jw'   || $T['move'] === 'from_jw')   { if (!$jwLoc)   return ['ok' => false, 'error' => 'No "At Job Worker" location exists. Add one in Inventory Setup.']; }
        if ($T['move'] === 'to_cust' || $T['move'] === 'from_cust') { if (!$custLoc) return ['ok' => false, 'error' => 'No "Customer Material Custody" location exists. Add one in Inventory Setup.']; }

        /* THE CHECK THAT WAS MISSING.
           Anything that takes stock OUT is verified against what is
           actually at that location, right now — not when the draft was
           saved. Movements that bring stock IN are not checked: adding to
           a balance can never overdraw it.

           Same three bands as Consumption: within balance posts; up to
           the allowed % below needs a reason on the pass; beyond it only
           an admin may post. */
        /* Name the items in every message. On a fourteen-line pass,
           "That item has 10 here" repeated fourteen times tells the
           operator nothing about which line to go and fix. Loaded once
           here because BOTH direction checks below need it. */
        $MATNAME = [];
        try {
            foreach (db()->query("SELECT id, code, name FROM inv_materials")->fetchAll() as $m) {
                $MATNAME[(int)$m['id']] = $m['code'] . ' · ' . $m['name'];
            }
        } catch (Throwable $e) {}

        $takesOut = in_array($T['move'], ['out', 'to_jw', 'from_cust'], true);
        if ($takesOut) {
            /* The stated reason. The screen tells the operator to put it in
               Remarks — which is the only free-text box on the pass — so
               Remarks is what is read. override_reason stays supported for
               passes that carry one, but nothing is required to fill both:
               an instruction that points at a field the form does not have
               is not a control, it is a dead end. */
            $why = trim((string)($g['override_reason'] ?? ''));
            if ($why === '') $why = trim((string)($g['remarks'] ?? ''));

            $stop = [];
            foreach ($items as $it) {
                $qty = (float)$it['qty'];
                if ($qty <= 0 || !$it['material_id']) continue;   // finished goods and empty rows skip
                $fromLoc = $T['move'] === 'from_cust' ? ($custLoc ?: $docLoc) : $docLoc;
                $chk = inv_outward_check((int)$it['material_id'], (string)($it['lot_no'] ?? ''), $fromLoc, $T['own'], $qty);
                if ($chk['level'] === 'ok') continue;
                $item  = $it['material_id'] && isset($MATNAME[(int)$it['material_id']])
                       ? $MATNAME[(int)$it['material_id']] : 'That item';
                $what  = ($it['lot_no'] ?? '') !== '' ? "$item lot " . $it['lot_no'] : $item;
                $where = inv_location_name($fromLoc);
                $has   = rtrim(rtrim(number_format($chk['bal'], 3), '0'), '.');
                $over  = rtrim(rtrim(number_format($chk['short'], 3), '0'), '.');
                if ($why === '') {
                    $stop[] = "$what has $has at $where and this pass takes $over more.";
                } elseif ($chk['level'] === 'admin_only' && !is_admin()) {
                    $pct = rtrim(rtrim(number_format(inv_neg_tolerance_pct(), 2), '0'), '.');
                    $stop[] = "$what has $has at $where and this pass takes $over more — beyond the $pct% allowance, so only an admin can post it.";
                }
            }
            if ($stop) {
                $tail = $why === '' ? ' Write why in Remarks and save the pass, then post it.' : '';
                return ['ok' => false, 'error' => implode(' ', $stop) . $tail];
            }
        }

        /* THE CHECK THE INWARD DIRECTION NEVER HAD.

           Gate Outward has always verified that you own what you are
           sending. Coming back was unchecked, so 300 could be booked back
           from a mill that only ever received 200 and nothing said a word.

           You cannot get back materially more than you sent. A little
           more is normal — their scale, moisture, rounding — so the same
           three bands apply, against what that party is actually holding. */
        $comesBack = in_array($T['move'], ['from_jw', 'from_cust'], true);
        if ($comesBack) {
            $pid  = (int)($g['party_id'] ?? 0);
            $kind = $T['move'] === 'from_cust' ? 'custody' : 'jobworker';
            $who  = $pid ? inv_party_name($pid) : '';
            if (!$pid) {
                return ['ok' => false, 'error' => 'Name the '
                    . ($kind === 'custody' ? 'customer whose goods these are' : 'mill these goods are coming back from')
                    . ' before posting. Without it there is nothing to check the quantity against.'];
            }
            $why2 = trim((string)($g['override_reason'] ?? ''));
            if ($why2 === '') $why2 = trim((string)($g['remarks'] ?? ''));

            $stop2 = [];
            foreach ($items as $it) {
                $qty = (float)$it['qty'];
                if ($qty <= 0 || !$it['material_id']) continue;
                $chk = inv_return_check((int)$it['material_id'], $pid, $kind, $qty);
                if ($chk['level'] === 'ok') continue;
                $item = isset($MATNAME[(int)$it['material_id']]) ? $MATNAME[(int)$it['material_id']] : 'That item';
                $has  = rtrim(rtrim(number_format($chk['held'], 3), '0'), '.');
                $over = rtrim(rtrim(number_format($chk['over'], 3), '0'), '.');
                $pct  = rtrim(rtrim(number_format($chk['pct'], 2), '0'), '.');
                if ($why2 === '') {
                    $stop2[] = "$who is holding $has of $item and this pass brings back $over more.";
                } elseif ($chk['level'] === 'admin_only' && !is_admin()) {
                    $stop2[] = "$who is holding $has of $item and this pass brings back $over more — beyond the $pct% allowance, so only an admin can post it.";
                }
            }
            if ($stop2) {
                $tail = $why2 === ''
                    ? ' You cannot receive back more than went out. If it is genuine — weighed at their scale, moisture gain — write why in Remarks and save, then post.'
                    : '';
                return ['ok' => false, 'error' => implode(' ', $stop2) . $tail];
            }
        }

        db()->beginTransaction();
        foreach ($items as $it) {
            $qty = (float)$it['qty'];
            if ($qty <= 0) continue;
            $base = [
                'txn_date'    => $g['gate_date'],
                'material_id' => $it['material_id'] ?: null,
                'product_id'  => $it['product_id'] ?: null,
                /* a finished product is stocked by size, and the gate line
                   can now carry one — without this it would be dropped on
                   the way to the ledger */
                'size_label'  => $it['size_label'] ?? null,
                'lot_no'      => $it['lot_no'] ?: null,
                'rate'        => (float)$it['rate'],
                'ownership'   => $T['own'],
                'owner_party_id' => $T['own'] === 'customer' ? ($g['party_id'] ?: null) : null,
                'source_type' => 'gate',
                'source_id'   => $gateId,
                'source_item_id' => (int)$it['id'],
                'source_no'   => $g['gate_no'],
                'contract_id' => $g['contract_id'] ?: null,
                'proforma_id' => $g['proforma_id'] ?: null,
                'remarks'     => $T['label'],
            ];
            switch ($T['move']) {
                case 'in':
                    inv_post_ledger($base + ['location_id' => $docLoc, 'qty_in' => $qty]);
                    break;
                case 'out':
                    inv_post_ledger($base + ['location_id' => $docLoc, 'qty_out' => $qty]);
                    break;
                case 'to_jw':
                    inv_post_ledger($base + ['location_id' => $docLoc, 'qty_out' => $qty]);
                    inv_post_ledger($base + ['location_id' => $jwLoc,  'qty_in'  => $qty, 'remarks' => 'At job worker']);
                    break;
                case 'from_jw':
                    inv_post_ledger($base + ['location_id' => $jwLoc,  'qty_out' => $qty, 'remarks' => 'Returned by job worker']);
                    inv_post_ledger($base + ['location_id' => $docLoc, 'qty_in'  => $qty]);
                    break;
                case 'to_cust':
                    inv_post_ledger($base + ['location_id' => $custLoc, 'qty_in' => $qty]);
                    break;
                case 'from_cust':
                    inv_post_ledger($base + ['location_id' => $custLoc, 'qty_out' => $qty]);
                    break;
            }
        }
        db()->prepare("UPDATE inv_gate SET status='posted', posted_by=?, posted_at=NOW() WHERE id=?")
            ->execute([(int)(current_user()['id'] ?? 0), $gateId]);
        db()->commit();
        inv_audit('gate_post', $gateId, $g['gate_no'], 'Gate pass posted to stock');
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => 'Posting failed: ' . $e->getMessage()];
    }
}

/* Reverse a posted consumption AND open a fresh draft holding every line
   of it. Correcting one wrong figure then means changing that figure,
   instead of re-keying fourteen material lines by hand.

   It is not an edit and it is not a delete. The posted document stays
   exactly as posted, its reversal stands beside it, and the new draft is
   a third, separate document that has moved nothing until it is posted.
   The chain reads: this happened, it was undone because X, this replaced
   it — which is what an auditor expects to find. */
function inv_consumption_reverse_to_draft(int $conId, string $reason): array {
    $rev = inv_doc_reverse('inv_consumption', 'con_no', 'consumption', $conId, $reason);
    if (!$rev['ok']) return $rev;
    try {
        $s = db()->prepare("SELECT * FROM inv_consumption WHERE id=?"); $s->execute([$conId]);
        $c = $s->fetch();
        if (!$c) return ['ok' => true, 'error' => '', 'new_id' => 0];

        $no = inv_next_no('prefix_consumption', 'inv_consumption', 'con_no');
        db()->beginTransaction();
        db()->prepare("INSERT INTO inv_consumption
            (con_no,con_date,proforma_id,jobwork_contract_id,location_id,department,batch_ref,remarks,status,created_by,reversed_from)
            VALUES (?,?,?,?,?,?,?,?,'draft',?,?)")
            ->execute([$no, date('Y-m-d'), $c['proforma_id'], $c['jobwork_contract_id'], $c['location_id'],
                $c['department'], $c['batch_ref'],
                trim('Replaces ' . $c['con_no'] . '. ' . (string)$c['remarks']),
                (int)(current_user()['id'] ?? 0), $conId]);
        $newId = (int)db()->lastInsertId();

        // copy every line as it stood, including which lot it came from —
        // the point is that only the wrong figure needs changing
        $s2 = db()->prepare("SELECT * FROM inv_consumption_items WHERE con_id=? ORDER BY side, sort_order, id");
        $s2->execute([$conId]);
        $ins = db()->prepare("INSERT INTO inv_consumption_items
            (con_id,side,material_id,product_id,size_label,lot_no,std_qty,qty,waste_qty,uom,rate,amount,ownership,sort_order,
             location_id,pick_mode,off_standard,short_reason,proforma_item_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $n = 0;
        foreach ($s2->fetchAll() as $r) {
            $ins->execute([$newId, $r['side'], $r['material_id'], $r['product_id'], $r['size_label'], $r['lot_no'],
                $r['std_qty'], $r['qty'], $r['waste_qty'], $r['uom'], $r['rate'], $r['amount'], $r['ownership'],
                $r['sort_order'], $r['location_id'] ?? null, $r['pick_mode'] ?? null,
                $r['off_standard'] ?? 0, $r['short_reason'] ?? null, $r['proforma_item_id'] ?? null]);
            $n++;
        }
        db()->commit();
        inv_audit('consumption_reverse_copy', $conId, ['new_id' => $newId, 'no' => $no, 'lines' => $n],
            'Reversed and re-opened as a new draft: ' . mb_substr($reason, 0, 200));
        return ['ok' => true, 'error' => '', 'new_id' => $newId, 'new_no' => $no, 'lines' => $n];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        // the reversal itself already committed and is correct; only the
        // convenience copy failed, so say exactly that
        return ['ok' => true, 'error' => '', 'new_id' => 0,
                'warn' => 'Reversed, but the new draft could not be created: ' . $e->getMessage()];
    }
}

/* What still points at this gate pass. Nothing may be deleted while
   something downstream depends on it, so this is asked first and its
   answer is shown to the operator rather than hidden inside a refusal. */
function inv_gate_links(int $gateId): array {
    $out = ['ledger' => 0, 'jobwork_bills' => 0, 'contract_lines' => 0, 'jobwork_nos' => []];
    try {
        $s = db()->prepare("SELECT COUNT(*) FROM inv_stock_ledger WHERE source_type IN ('gate','gate_rev') AND source_id=?");
        $s->execute([$gateId]); $out['ledger'] = (int)$s->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $s = db()->prepare("SELECT bill_no FROM inv_jobwork_charges WHERE gate_id=?");
        $s->execute([$gateId]);
        foreach ($s->fetchAll() as $r) $out['jobwork_nos'][] = (string)$r['bill_no'];
        $out['jobwork_bills'] = count($out['jobwork_nos']);
    } catch (Throwable $e) {}
    try {
        $s = db()->prepare("SELECT COUNT(*) FROM inv_gate_items WHERE gate_id=? AND contract_item_id IS NOT NULL");
        $s->execute([$gateId]); $out['contract_lines'] = (int)$s->fetchColumn();
    } catch (Throwable $e) {}
    return $out;
}

/* Delete a gate pass for good.

   Three cases, and they are not the same thing:

     draft / verified  nothing ever happened — no ledger rows exist, so
                       there is nothing to undo. Deleted freely.
     posted            it moved stock. Refused: reverse it first, so the
                       correction is on the record before it disappears.
     reversed          the movement and its undo both exist and net to
                       zero. Admin only, reason required, and THE LEDGER
                       ROWS GO WITH IT.

   That last case was chosen deliberately by the owner over hiding the
   pass. It is worth being clear-eyed: deleting a reversed pass removes
   the only record that the entry was made and corrected. Stock stays
   right — the two rows cancelled — but "why did stock move on the 7th"
   becomes unanswerable. The reason text below survives in the audit log,
   which is why it is required and written before anything is removed. */
function inv_gate_delete(int $gateId, string $reason = ''): array {
    try {
        $s = db()->prepare("SELECT * FROM inv_gate WHERE id=?");
        $s->execute([$gateId]); $g = $s->fetch();
        if (!$g) return ['ok' => false, 'error' => 'Gate pass not found.'];

        $links = inv_gate_links($gateId);
        if ($links['jobwork_bills'] > 0) {
            return ['ok' => false, 'error' => 'This pass cannot be deleted — job work bill '
                . implode(', ', $links['jobwork_nos']) . ' was raised against it. Delete or unlink the bill first.'];
        }

        $status = (string)$g['status'];
        if ($status === 'posted') {
            return ['ok' => false, 'error' => 'This pass is posted and has moved stock. Reverse it first — then it can be deleted.'];
        }
        if ($status === 'reversed') {
            if (!is_admin()) return ['ok' => false, 'error' => 'Only an admin can delete a reversed pass.'];
            if (mb_strlen(trim($reason)) < 5) {
                return ['ok' => false, 'error' => 'Deleting a reversed pass removes its stock history for good. Give a reason of at least 5 characters — it is kept in the audit log after the pass is gone.'];
            }
        } elseif (!in_array($status, ['draft', 'verified'], true)) {
            return ['ok' => false, 'error' => 'This pass cannot be deleted in its current state.'];
        }

        // On the record BEFORE the rows go, so the audit survives the delete.
        inv_audit('gate_delete', $gateId, [
            'gate_no' => $g['gate_no'], 'status' => $status, 'direction' => $g['direction'],
            'txn_type' => $g['txn_type'], 'date' => $g['gate_date'],
            'ledger_rows' => $links['ledger'],
        ], trim($reason) !== '' ? $reason : 'Deleted before it moved any stock');

        db()->beginTransaction();
        if ($status === 'reversed') {
            db()->prepare("DELETE FROM inv_stock_ledger WHERE source_type IN ('gate','gate_rev') AND source_id=?")
                ->execute([$gateId]);
        }
        db()->prepare("DELETE FROM inv_gate_items WHERE gate_id=?")->execute([$gateId]);
        db()->prepare("DELETE FROM inv_gate WHERE id=?")->execute([$gateId]);
        db()->commit();

        return ['ok' => true, 'error' => '', 'gate_no' => $g['gate_no'],
                'ledger_removed' => $status === 'reversed' ? $links['ledger'] : 0];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => 'Delete failed: ' . $e->getMessage()];
    }
}

/* Reverse a posted pass. The original rows are never touched or deleted —
   an opposite set is written, so the history reads as what happened
   followed by the correction, which is what an auditor expects. */
function inv_gate_reverse(int $gateId, string $reason): array {
    if (trim($reason) === '') return ['ok' => false, 'error' => 'A reason is required to reverse a posted document.'];
    try {
        $st = db()->prepare("SELECT * FROM inv_gate WHERE id=?");
        $st->execute([$gateId]); $g = $st->fetch();
        if (!$g) return ['ok' => false, 'error' => 'Gate pass not found.'];
        if ($g['status'] !== 'posted') return ['ok' => false, 'error' => 'Only a posted pass can be reversed.'];

        $st2 = db()->prepare("SELECT * FROM inv_stock_ledger WHERE source_type='gate' AND source_id=?");
        $st2->execute([$gateId]); $rows = $st2->fetchAll();
        if (!$rows) return ['ok' => false, 'error' => 'No stock rows found for this pass.'];

        db()->beginTransaction();
        foreach ($rows as $r) {
            inv_post_ledger([
                'txn_date' => date('Y-m-d'),
                'material_id' => $r['material_id'], 'product_id' => $r['product_id'],
                'size_label' => $r['size_label'], 'location_id' => $r['location_id'],
                'ownership' => $r['ownership'], 'owner_party_id' => $r['owner_party_id'],
                'lot_no' => $r['lot_no'],
                'qty_in'  => (float)$r['qty_out'],   // swapped on purpose
                'qty_out' => (float)$r['qty_in'],
                'rate' => (float)$r['rate'],
                'source_type' => 'gate_rev', 'source_id' => $gateId,
                'source_item_id' => $r['source_item_id'], 'source_no' => $g['gate_no'] . '-REV',
                'contract_id' => $r['contract_id'], 'proforma_id' => $r['proforma_id'],
                'remarks' => 'Reversal: ' . mb_substr($reason, 0, 240),
            ]);
        }
        db()->prepare("UPDATE inv_gate SET status='reversed', reversed_by=?, reversed_at=NOW(), reversal_reason=? WHERE id=?")
            ->execute([(int)(current_user()['id'] ?? 0), $reason, $gateId]);
        db()->commit();
        inv_audit('gate_reverse', $gateId, $g['gate_no'], $reason);
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => 'Reversal failed: ' . $e->getMessage()];
    }
}

/* ------------------------------------------------------ opening stock */

/* What the ledger ALREADY holds for exactly this item, store, lot and
   size — the figure the entry screen shows in its "In stock now" column.

   This is the whole safety story of opening stock. An opening entry ADDS
   to what is there; it does not set a balance to a value. So entering an
   opening figure for something that already has stock is how a quantity
   silently doubles, and the only defence is showing the operator that
   balance while they type.

   It is deliberately NOT a refusal. The same item can genuinely have a
   balance at another store, or on another lot, and the entry in front of
   them can be perfectly correct. Marked and explained, never blocked —
   the same rule the gate form uses for a quantity over its contract. */
function inv_opening_onhand(int $matId, int $prodId, int $locId, string $lot, string $size = ''): float {
    try {
        $w = ["source_type <> 'opening_rev'"];
        $p = [];
        if ($matId > 0)  { $w[] = 'material_id = ?'; $p[] = $matId; }
        elseif ($prodId > 0) { $w[] = 'product_id = ?'; $p[] = $prodId; }
        else return 0.0;
        if ($locId > 0) { $w[] = 'location_id = ?'; $p[] = $locId; }
        /* An empty lot is its own bucket, not "any lot". Treating blank as
           a wildcard would report the whole item's balance against a line
           that names one roll, and every such line would look like a
           double count. */
        $w[] = $lot !== '' ? 'lot_no = ?' : "(lot_no IS NULL OR lot_no = '')";
        if ($lot !== '') $p[] = $lot;
        if ($size !== '') { $w[] = 'size_label = ?'; $p[] = $size; }
        $st = db()->prepare("SELECT COALESCE(SUM(qty_in),0) - COALESCE(SUM(qty_out),0)
                               FROM inv_stock_ledger WHERE " . implode(' AND ', $w));
        $st->execute($p);
        return round((float)$st->fetchColumn(), 3);
    } catch (Throwable $e) { return 0.0; }
}

/* Post an opening stock document. Every line is a qty_in at its own
   location, written through the same inv_post_ledger() as everything
   else, so it appears in the Stock Ledger, in Current Stock and in every
   balance the app computes — with its date, its number and its author. */
function inv_opening_post(int $id): array {
    try {
        $st = db()->prepare("SELECT * FROM inv_opening WHERE id=?");
        $st->execute([$id]); $o = $st->fetch();
        if (!$o) return ['ok' => false, 'error' => 'Opening stock document not found.'];
        if ($o['status'] === 'posted')   return ['ok' => false, 'error' => 'This document is already posted.'];
        if ($o['status'] === 'reversed') return ['ok' => false, 'error' => 'This document has been reversed and cannot be posted again.'];
        // even a double-submitted form cannot post twice
        if (inv_already_posted('opening', $id)) return ['ok' => false, 'error' => 'Stock has already been written for this document.'];

        $st2 = db()->prepare("SELECT * FROM inv_opening_items WHERE opening_id=? ORDER BY sort_order, id");
        $st2->execute([$id]); $items = $st2->fetchAll();
        if (!$items) return ['ok' => false, 'error' => 'Add at least one line before posting.'];

        /* A line has to name something and carry a quantity above zero —
           the same rule the form uses when it saves, so what posts is
           exactly what the screen said would post. Opening stock cannot
           be negative: a negative opening balance is not an opening
           balance, it is an adjustment, and that is a different document
           with a different meaning. */
        $real = 0; $bad = [];
        foreach ($items as $it) {
            $q = (float)$it['qty'];
            if ($q < 0) { $bad[] = 'a line has a negative quantity'; continue; }
            if ($q > 0 && ((int)$it['material_id'] > 0 || (int)$it['product_id'] > 0)) $real++;
        }
        if ($bad)   return ['ok' => false, 'error' => 'Opening stock cannot be negative. Use a stock adjustment for a correction downwards.'];
        if (!$real) return ['ok' => false, 'error' => 'No line has both an item and a quantity above zero.'];

        $defLoc = (int)$o['location_id'] ?: (int)inv_setting('default_location', '1');
        db()->beginTransaction();
        foreach ($items as $it) {
            $qty = (float)$it['qty'];
            if ($qty <= 0 || ((int)$it['material_id'] <= 0 && (int)$it['product_id'] <= 0)) continue;
            inv_post_ledger([
                'txn_date'    => $o['opening_date'],
                'material_id' => $it['material_id'] ?: null,
                'product_id'  => $it['product_id'] ?: null,
                'size_label'  => $it['size_label'] ?: null,
                'location_id' => (int)$it['location_id'] ?: $defLoc,
                'ownership'   => $it['ownership'] ?: 'own',
                'owner_party_id' => $it['owner_party_id'] ?: null,
                'lot_no'      => $it['lot_no'] ?: null,
                'qty_in'      => $qty,
                'rate'        => (float)$it['rate'],
                'source_type' => 'opening',
                'source_id'   => $id,
                'source_item_id' => (int)$it['id'],
                'source_no'   => $o['opening_no'],
                'remarks'     => 'Opening stock',
            ]);
        }
        db()->prepare("UPDATE inv_opening SET status='posted', posted_by=?, posted_at=NOW() WHERE id=?")
            ->execute([(int)(current_user()['id'] ?? 0), $id]);
        db()->commit();
        inv_audit('opening_post', $id, $o['opening_no'], 'Opening stock posted');
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => 'Posting failed: ' . $e->getMessage()];
    }
}

/* Reverse a posted opening stock document — same shape as the gate
   reversal: the posted rows stay exactly as posted and an opposite set is
   written beside them, so the history reads as what happened rather than
   as though it never did. */
function inv_opening_reverse(int $id, string $reason): array {
    if (trim($reason) === '') return ['ok' => false, 'error' => 'A reason is required to reverse a posted document.'];
    try {
        $st = db()->prepare("SELECT * FROM inv_opening WHERE id=?");
        $st->execute([$id]); $o = $st->fetch();
        if (!$o) return ['ok' => false, 'error' => 'Opening stock document not found.'];
        if ($o['status'] !== 'posted') return ['ok' => false, 'error' => 'Only a posted document can be reversed.'];

        $st2 = db()->prepare("SELECT * FROM inv_stock_ledger WHERE source_type='opening' AND source_id=?");
        $st2->execute([$id]); $rows = $st2->fetchAll();
        if (!$rows) return ['ok' => false, 'error' => 'No stock rows found for this document.'];

        db()->beginTransaction();
        foreach ($rows as $r) {
            inv_post_ledger([
                'txn_date' => date('Y-m-d'),
                'material_id' => $r['material_id'], 'product_id' => $r['product_id'],
                'size_label' => $r['size_label'], 'location_id' => $r['location_id'],
                'ownership' => $r['ownership'], 'owner_party_id' => $r['owner_party_id'],
                'lot_no' => $r['lot_no'],
                'qty_in'  => (float)$r['qty_out'],     // swapped on purpose
                'qty_out' => (float)$r['qty_in'],
                'rate' => (float)$r['rate'],
                'source_type' => 'opening_rev', 'source_id' => $id,
                'source_item_id' => $r['source_item_id'], 'source_no' => $o['opening_no'] . '-REV',
                'remarks' => 'Reversal: ' . mb_substr($reason, 0, 240),
            ]);
        }
        db()->prepare("UPDATE inv_opening SET status='reversed', reversed_by=?, reversed_at=NOW(), reversal_reason=? WHERE id=?")
            ->execute([(int)(current_user()['id'] ?? 0), $reason, $id]);
        db()->commit();
        inv_audit('opening_reverse', $id, $o['opening_no'], $reason);
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => 'Reversal failed: ' . $e->getMessage()];
    }
}

/* Every stockable thing, materials and finished products in one list,
   for the opening stock picker. A product carries its sizes so the form
   can ask for one; a material carries its stage so the operator can see
   at a glance whether they are opening grey, raw or finished goods. */
function inv_opening_items(): array {
    $out = [];
    try {
        foreach (db()->query("SELECT id, code, name, item_group, stage, uom, std_rate
                                FROM inv_materials WHERE is_active=1 ORDER BY code")->fetchAll() as $m) {
            $out[] = [
                'key' => 'm' . (int)$m['id'], 'kind' => 'mat', 'id' => (int)$m['id'],
                'code' => (string)$m['code'], 'name' => (string)$m['name'],
                'grp' => (string)$m['item_group'], 'stage' => (string)$m['stage'],
                'uom' => (string)$m['uom'], 'rate' => (float)$m['std_rate'], 'sizes' => [],
            ];
        }
    } catch (Throwable $e) {}
    try {
        foreach (db()->query("SELECT id, name FROM products WHERE is_active=1 ORDER BY name LIMIT 800")->fetchAll() as $p) {
            $out[] = [
                'key' => 'p' . (int)$p['id'], 'kind' => 'prod', 'id' => (int)$p['id'],
                'code' => 'PRD-' . (int)$p['id'], 'name' => (string)$p['name'],
                'grp' => 'Finished goods', 'stage' => 'product',
                'uom' => 'PCS', 'rate' => 0.0, 'sizes' => inv_product_sizes((int)$p['id']),
            ];
        }
    } catch (Throwable $e) {}
    return $out;
}

/* Sizes a product is made in.

   THE TABLE IS product_sizes, AND ONLY product_sizes. I first wrote this
   against zp_sizes, which looks like the right table and is not: it is a
   dead copy left on disk for its history, read by nothing. includes/
   zprod.php carries the whole story — putting the production module on
   its own size table meant a size typed in Product Master never reached
   the seventeen files that read product_sizes. Reading it here would have
   quietly reintroduced exactly that split, on a screen that sets opening
   balances.

   One query for every product, cached for the request: the picker asks
   for sizes once per product and there may be hundreds.

   An empty list is not a failure. It means nobody has set sizes on that
   product, and the size box is then free text — which is the honest
   answer rather than an empty dropdown that cannot be filled in. */
function inv_product_sizes(int $productId): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query("SELECT product_id, size_label FROM product_sizes
                                   ORDER BY product_id, COALESCE(sort_order,0), id")->fetchAll() as $r)
                $cache[(int)$r['product_id']][] = (string)$r['size_label'];
        } catch (Throwable $e) { $cache = []; }
    }
    return $cache[$productId] ?? [];
}

/* Posted quantity already moved against one contract, in the direction
   that contract is progressed by. Used for the balance panel and the
   over-dispatch warning. */
function inv_contract_done(int $contractId, string $dir): float {
    try {
        $st = db()->prepare("SELECT COALESCE(SUM(gi.qty),0) FROM inv_gate g
            JOIN inv_gate_items gi ON gi.gate_id=g.id
            WHERE g.status='posted' AND g.contract_id=? AND g.direction=?");
        $st->execute([$contractId, $dir]);
        return (float)$st->fetchColumn();
    } catch (Throwable $e) { return 0.0; }
}

function inv_contract_qty(int $contractId): float {
    try {
        $st = db()->prepare("SELECT COALESCE(SUM(qty),0) FROM inv_contract_items WHERE contract_id=?");
        $st->execute([$contractId]);
        return (float)$st->fetchColumn();
    } catch (Throwable $e) { return 0.0; }
}

/* ------------------------------------------- store issue / return post */
/* Pure location movement: OUT of one place, IN to another, same item and
   same ownership. Never a cost change, never a conversion — which is why
   an issue can never cause a costing error. */
function inv_store_post(int $moveId): array {
    try {
        $s = db()->prepare("SELECT * FROM inv_store_move WHERE id=?"); $s->execute([$moveId]);
        $m = $s->fetch();
        if (!$m) return ['ok' => false, 'error' => 'Document not found.'];
        if ($m['status'] !== 'draft') return ['ok' => false, 'error' => 'Only a draft can be posted.'];
        if (inv_already_posted('store', $moveId)) return ['ok' => false, 'error' => 'Stock has already been written for this document.'];
        if (!$m['from_location_id'] || !$m['to_location_id']) return ['ok' => false, 'error' => 'Both a from and a to location are required.'];
        if ((int)$m['from_location_id'] === (int)$m['to_location_id']) return ['ok' => false, 'error' => 'From and to locations must be different.'];

        $s2 = db()->prepare("SELECT * FROM inv_store_move_items WHERE move_id=?"); $s2->execute([$moveId]);
        $items = $s2->fetchAll();
        if (!$items) return ['ok' => false, 'error' => 'Add at least one item before posting.'];

        db()->beginTransaction();
        foreach ($items as $it) {
            $qty = (float)$it['qty']; if ($qty <= 0) continue;
            $base = [
                'txn_date' => $m['move_date'],
                'material_id' => $it['material_id'] ?: null, 'product_id' => $it['product_id'] ?: null,
                /* a finished product is stocked by size; without this the
                   size was dropped on the way to the ledger and the two
                   halves of the same balance would never meet */
                'size_label' => $it['size_label'] ?? null,
                'lot_no' => $it['lot_no'] ?: null, 'ownership' => $it['ownership'],
                'source_type' => 'store', 'source_id' => $moveId, 'source_item_id' => (int)$it['id'],
                'source_no' => $m['move_no'], 'proforma_id' => $m['proforma_id'] ?: null,
                'remarks' => $m['move_type'] === 'issue' ? 'Issued to ' . inv_location_name((int)$m['to_location_id'])
                                                         : 'Returned to ' . inv_location_name((int)$m['to_location_id']),
            ];
            inv_post_ledger($base + ['location_id' => (int)$m['from_location_id'], 'qty_out' => $qty]);
            inv_post_ledger($base + ['location_id' => (int)$m['to_location_id'],   'qty_in'  => $qty]);
        }
        db()->prepare("UPDATE inv_store_move SET status='posted', posted_by=?, posted_at=NOW() WHERE id=?")
            ->execute([(int)(current_user()['id'] ?? 0), $moveId]);
        db()->commit();
        inv_audit('store_post', $moveId, $m['move_no'], 'Store movement posted');
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => 'Posting failed: ' . $e->getMessage()];
    }
}

/* --------------------------------------------------- consumption post */
/* THE CONVERTER. The only document in the system that creates finished
   product stock. Inputs go out, outputs come in, in one posting, so the
   two halves can never drift apart.

   For inbound job work the customer supplies the material and still owns
   the finished goods, so both sides carry ownership='customer' and neither
   is valued. */
function inv_consumption_post(int $conId): array {
    try {
        $s = db()->prepare("SELECT * FROM inv_consumption WHERE id=?"); $s->execute([$conId]);
        $c = $s->fetch();
        if (!$c) return ['ok' => false, 'error' => 'Document not found.'];
        if ($c['status'] !== 'draft') return ['ok' => false, 'error' => 'Only a draft can be posted.'];
        if (inv_already_posted('consumption', $conId)) return ['ok' => false, 'error' => 'Stock has already been written for this document.'];

        $s2 = db()->prepare("SELECT * FROM inv_consumption_items WHERE con_id=? ORDER BY side, sort_order, id");
        $s2->execute([$conId]); $items = $s2->fetchAll();
        $ins = array_filter($items, fn($i) => $i['side'] === 'input');
        $out = array_filter($items, fn($i) => $i['side'] === 'output');
        if (!$ins)  return ['ok' => false, 'error' => 'Add at least one material consumed.'];
        if (!$out)  return ['ok' => false, 'error' => 'Add at least one finished product produced.'];

        $loc   = (int)$c['location_id'] ?: (int)inv_setting('default_location', '1');
        $fgLoc = inv_location_by_kind('fg') ?: $loc;

        /* THE CHECK THAT ACTUALLY COUNTS.
           The entry screen already stops an over-draw, but a browser can
           always be bypassed, so every input line is re-checked here
           against what the ledger holds at this exact moment — which may
           also have moved since the draft was saved. Within the tolerance
           a reason must be on the line; beyond it, only an admin may post. */
        $stop = [];
        foreach ($ins as $it) {
            $need = (float)$it['qty'] + (float)$it['waste_qty'];
            if ($need <= 0 || !$it['material_id']) continue;
            $chk = inv_consume_line_check(
                (int)$it['material_id'], (string)($it['lot_no'] ?? ''),
                (int)($it['location_id'] ?: $loc), $it['ownership'], $need
            );
            if ($chk['level'] === 'ok') continue;
            $lotLabel = ($it['lot_no'] ?? '') !== '' ? 'lot ' . $it['lot_no'] : 'the no-lot stock';
            $where = inv_location_name((int)($it['location_id'] ?: $loc));
            $overBy = rtrim(rtrim(number_format($chk['short'], 3), '0'), '.');
            $has    = rtrim(rtrim(number_format($chk['bal'], 3), '0'), '.');
            if (trim((string)($it['short_reason'] ?? '')) === '') {
                $stop[] = "$lotLabel at $where holds $has and this takes $overBy more — a reason is required on that line.";
                continue;
            }
            if ($chk['level'] === 'admin_only' && !is_admin()) {
                $pct = rtrim(rtrim(number_format(inv_neg_tolerance_pct(), 2), '0'), '.');
                $stop[] = "$lotLabel at $where holds $has and this takes $overBy more — beyond the $pct% allowance, so only an admin can post it.";
            }
        }
        if ($stop) return ['ok' => false, 'error' => implode(' ', $stop)];

        /* You cannot turn into stock more pieces than the floor finished.
           This one IS a hard block: production and the store are two
           independent records, and stock that production never made is
           the single thing that must be impossible. Re-checked here
           because the floor may have moved since the draft was saved. */
        foreach ($out as $it) {
            $pfItem = (int)($it['proforma_item_id'] ?? 0);
            if ($pfItem <= 0) continue;                    // not tied to an order line
            $qty = (float)$it['qty']; if ($qty <= 0) continue;
            $p = inv_line_production($pfItem);
            // what THIS document already contributes is not yet posted, so
            // it is not in `converted` — compare against it directly
            if ($qty > $p['available'] + 0.0005) {
                $ask = rtrim(rtrim(number_format($qty, 3), '0'), '.');
                $av  = rtrim(rtrim(number_format($p['available'], 3), '0'), '.');
                $fin = rtrim(rtrim(number_format($p['finished'], 3), '0'), '.');
                $cv  = rtrim(rtrim(number_format($p['converted'], 3), '0'), '.');
                return ['ok' => false, 'error' => "This would convert $ask pieces but only $av are available — "
                    . "production has finished $fin and $cv are already converted. Log the production first, or reduce the quantity."];
            }
        }

        /* A cost per unit far from the costing must carry an explanation.
           It is never blocked — a real price rise has to be recordable —
           but it cannot be posted silently. */
        if (($c['cost_variance_pct'] ?? null) !== null && trim((string)($c['cost_variance_reason'] ?? '')) === '') {
            $tol = inv_cost_variance_pct();
            if ($tol > 0 && abs((float)$c['cost_variance_pct']) > $tol) {
                $p = rtrim(rtrim(number_format(abs((float)$c['cost_variance_pct']), 2), '0'), '.');
                return ['ok' => false, 'error' => "Cost per unit is $p% away from the costing this order was priced on. "
                    . "Open the document and give a reason before posting — it is kept on the record."];
            }
        }

        db()->beginTransaction();
        foreach ($ins as $it) {
            $qty = (float)$it['qty'] + (float)$it['waste_qty'];   // wastage leaves stock too
            if ($qty <= 0) continue;
            inv_post_ledger([
                'txn_date' => $c['con_date'], 'material_id' => $it['material_id'] ?: null,
                'product_id' => $it['product_id'] ?: null, 'lot_no' => $it['lot_no'] ?: null,
                // consumed from where the lot actually sits, not from the
                // document's single location (older rows have no location
                // of their own and fall back to it, exactly as before)
                'location_id' => (int)($it['location_id'] ?: $loc), 'ownership' => $it['ownership'],
                'qty_out' => $qty, 'rate' => (float)$it['rate'],
                'source_type' => 'consumption', 'source_id' => $conId, 'source_item_id' => (int)$it['id'],
                'source_no' => $c['con_no'], 'proforma_id' => $c['proforma_id'] ?: null,
                'contract_id' => $c['jobwork_contract_id'] ?: null,
                'remarks' => 'Consumed' . ((float)$it['waste_qty'] > 0 ? ' (incl. ' . rtrim(rtrim(number_format((float)$it['waste_qty'], 3), '0'), '.') . ' waste)' : ''),
            ]);
        }
        foreach ($out as $it) {
            $qty = (float)$it['qty']; if ($qty <= 0) continue;
            inv_post_ledger([
                'txn_date' => $c['con_date'], 'material_id' => $it['material_id'] ?: null,
                'product_id' => $it['product_id'] ?: null, 'size_label' => $it['size_label'] ?: null,
                'location_id' => $fgLoc, 'ownership' => $it['ownership'],
                'qty_in' => $qty, 'rate' => (float)$it['rate'],
                'source_type' => 'consumption', 'source_id' => $conId, 'source_item_id' => (int)$it['id'],
                'source_no' => $c['con_no'], 'proforma_id' => $c['proforma_id'] ?: null,
                'contract_id' => $c['jobwork_contract_id'] ?: null,
                'remarks' => 'Produced',
            ]);
        }
        db()->prepare("UPDATE inv_consumption SET status='posted', posted_by=?, posted_at=NOW() WHERE id=?")
            ->execute([(int)(current_user()['id'] ?? 0), $conId]);
        db()->commit();
        inv_audit('consumption_post', $conId, $c['con_no'], 'Consumption posted — materials out, product in');
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => 'Posting failed: ' . $e->getMessage()];
    }
}

/* Generic reversal for store / consumption documents. Same principle as
   the gate reversal: opposite entries, original left untouched. */
function inv_doc_reverse(string $table, string $noCol, string $sourceType, int $id, string $reason): array {
    if (trim($reason) === '') return ['ok' => false, 'error' => 'A reason is required to reverse a posted document.'];
    if (!in_array($table, ['inv_store_move', 'inv_consumption'], true)) return ['ok' => false, 'error' => 'Unknown document type.'];
    try {
        $s = db()->prepare("SELECT * FROM `$table` WHERE id=?"); $s->execute([$id]); $d = $s->fetch();
        if (!$d) return ['ok' => false, 'error' => 'Document not found.'];
        if ($d['status'] !== 'posted') return ['ok' => false, 'error' => 'Only a posted document can be reversed.'];
        $s2 = db()->prepare("SELECT * FROM inv_stock_ledger WHERE source_type=? AND source_id=?");
        $s2->execute([$sourceType, $id]); $rows = $s2->fetchAll();
        if (!$rows) return ['ok' => false, 'error' => 'No stock rows found for this document.'];

        db()->beginTransaction();
        foreach ($rows as $r) {
            inv_post_ledger([
                'txn_date' => date('Y-m-d'),
                'material_id' => $r['material_id'], 'product_id' => $r['product_id'],
                'size_label' => $r['size_label'], 'location_id' => $r['location_id'],
                'ownership' => $r['ownership'], 'owner_party_id' => $r['owner_party_id'], 'lot_no' => $r['lot_no'],
                'qty_in' => (float)$r['qty_out'], 'qty_out' => (float)$r['qty_in'],   // swapped
                'rate' => (float)$r['rate'],
                'source_type' => $sourceType . '_rev', 'source_id' => $id,
                'source_no' => $d[$noCol] . '-REV',
                'contract_id' => $r['contract_id'], 'proforma_id' => $r['proforma_id'],
                'remarks' => 'Reversal: ' . mb_substr($reason, 0, 240),
            ]);
        }
        db()->prepare("UPDATE `$table` SET status='reversed' WHERE id=?")->execute([$id]);
        db()->commit();
        inv_audit($sourceType . '_reverse', $id, $d[$noCol], $reason);
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => 'Reversal failed: ' . $e->getMessage()];
    }
}

/* ------------------------------------------- standard bill of material */
/* What one piece of a product should consume, taken from the costing you
   already keep. Workmanship is skipped — it is labour, not material.
   A line saved since Product Costing gained its Item Master pick-list
   carries the item it IS (costing_lines.material_id) — that is used and
   nothing is guessed. Older lines are only a typed name, so those still
   fall back to matching by name; an unmatched line is still returned so
   the operator can see it and pick the item once. */
function inv_std_materials(int $productId, ?int $costingVersionId = null): array {
    if ($productId <= 0 && !$costingVersionId) return [];
    $vid = $costingVersionId;
    try {
        if (!$vid) {
            $s = db()->prepare("SELECT id FROM costing_versions WHERE product_id=? ORDER BY id DESC LIMIT 1");
            $s->execute([$productId]); $v = $s->fetchColumn();
            $vid = $v !== false ? (int)$v : 0;
        }
        if (!$vid) return [];
        try {
            $s = db()->prepare("SELECT line_group,item_name,quantity,unit,rate,material_id FROM costing_lines
                WHERE costing_version_id=? AND line_group <> 'Workmanship' AND item_name IS NOT NULL AND item_name <> ''
                ORDER BY sort_order, id");
            $s->execute([$vid]);
        } catch (Throwable $e) {   // column not present yet — older install
            $s = db()->prepare("SELECT line_group,item_name,quantity,unit,rate FROM costing_lines
                WHERE costing_version_id=? AND line_group <> 'Workmanship' AND item_name IS NOT NULL AND item_name <> ''
                ORDER BY sort_order, id");
            $s->execute([$vid]);
        }
        $lines = $s->fetchAll();
        if (!$lines) return [];

        $mats = []; $byId = [];
        foreach (inv_materials(true) as $m) { $mats[strtolower(trim($m['name']))] = $m; $byId[(int)$m['id']] = $m; }

        $out = [];
        foreach ($lines as $l) {
            $nm = trim((string)$l['item_name']);
            $mid = (int)($l['material_id'] ?? 0);
            $m = $mid > 0 ? ($byId[$mid] ?? null) : null;   // what the costing SAYS it is
            $linked = $m !== null;
            if (!$m) {                                      // legacy line: fall back to the name
                $key = strtolower($nm);
                $m = $mats[$key] ?? null;
                if (!$m) {   // loose match: contains, either direction
                    foreach ($mats as $k2 => $cand) {
                        if ($k2 !== '' && (strpos($k2, $key) !== false || strpos($key, $k2) !== false)) { $m = $cand; break; }
                    }
                }
            }
            $out[] = [
                'item_name'   => $nm,
                'group'       => $l['line_group'],
                'material_id' => $m ? (int)$m['id'] : 0,
                'material'    => $m ? $m['code'] . ' · ' . $m['name'] : null,
                'linked'      => $linked,   // true = stated by the costing, false = guessed from the name
                'per_piece'   => (float)$l['quantity'],
                'uom'         => $m ? $m['uom'] : trim((string)$l['unit']),
                'rate'        => $m && (float)$m['std_rate'] > 0 ? (float)$m['std_rate'] : (float)$l['rate'],
            ];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/* EVERY DEPARTMENT ANYONE HAS EVER TYPED.
 *
 * A department is not a master record in this system — it is free text on a
 * gate pass, a store issue and a consumption. So the only honest list is the
 * one the documents themselves have written, gathered from all three and
 * de-duplicated on the trimmed spelling.
 *
 * THAT IS ALSO ITS WEAKNESS, AND IT IS WORTH SAYING OUT LOUD: "Stitching
 * Floor", "stitching floor" and "Stitching Flr" are three departments to this
 * list, because they are three departments to the database. Making it a
 * master list — typed once in Inventory Setup and chosen from a box — is a
 * small change and the right one; until then this at least shows what is
 * really on the documents rather than pretending there is a tidy list. */
function inv_departments(): array {
    $out = [];
    $ask = function (string $table) use (&$out) {
        try {
            $rows = db()->query("SELECT DISTINCT TRIM(department) d FROM $table
                                 WHERE department IS NOT NULL AND TRIM(department) <> ''")->fetchAll();
            foreach ($rows as $r) { $d = (string)$r['d']; if ($d !== '') $out[$d] = true; }
        } catch (Throwable $e) {}
    };
    $ask('inv_store_move');
    $ask('inv_consumption');
    $ask('inv_gate');
    $list = array_keys($out);
    /* Case-insensitive sort, so "packing" does not land miles from
       "Packing" in the dropdown the operator is scanning. */
    usort($list, fn($a, $b) => strcasecmp($a, $b));
    return $list;
}

/* ------------------------------------------------------------- lookups */
function inv_locations(bool $activeOnly = true): array {
    try {
        $sql = "SELECT * FROM inv_locations" . ($activeOnly ? " WHERE is_active=1" : "") . " ORDER BY id";
        return db()->query($sql)->fetchAll();
    } catch (Throwable $e) { return []; }
}

function inv_location_name(?int $id): string {
    if (!$id) return '—';
    static $cache = null;
    if ($cache === null) { $cache = []; foreach (inv_locations(false) as $l) $cache[(int)$l['id']] = $l['name']; }
    return $cache[$id] ?? '—';
}

/* ------------------------------------------------------- item groups */
/* The groups a NEW item can be created in. Packing was removed at the
   owner's instruction: in this business a poly bag and a carton are
   consumed exactly like a label or a thread, so a third bucket only split
   the same list two ways.

   The database ENUM deliberately still allows 'Packing'. Dropping it from
   the column would blank every existing packing item and every costing
   line that still says Packing — including the ones that decide the
   Net / Packing weight split on printed costing sheets. Legacy rows keep
   working and keep printing until you move them yourself, from
   Inventory Setup. */
function inv_item_groups(): array {
    return ['Fabric', 'Accessories', 'Other'];
}

/* Anything arriving as 'Packing' — an old row, an alias, an import — is
   read as Accessories. One place, so no screen can drift from another. */
function inv_group_norm(?string $g): string {
    $g = trim((string)$g);
    if ($g === 'Packing') return 'Accessories';
    return in_array($g, ['Fabric', 'Accessories', 'Other'], true) ? $g : 'Other';
}

/* How many rows still carry the retired group, so Inventory Setup can
   offer to move them instead of rewriting anyone's data silently. */
function inv_legacy_packing_counts(): array {
    $out = ['items' => 0, 'costing_lines' => 0];
    try { $out['items'] = (int)db()->query("SELECT COUNT(*) FROM inv_materials WHERE item_group='Packing'")->fetchColumn(); } catch (Throwable $e) {}
    try { $out['costing_lines'] = (int)db()->query("SELECT COUNT(*) FROM costing_lines WHERE line_group='Packing'")->fetchColumn(); } catch (Throwable $e) {}
    return $out;
}

function inv_materials(bool $activeOnly = true, string $group = ''): array {
    try {
        $where = []; $params = [];
        if ($activeOnly) $where[] = "is_active=1";
        if ($group !== '') { $where[] = "item_group=?"; $params[] = $group; }
        $sql = "SELECT * FROM inv_materials" . ($where ? " WHERE " . implode(' AND ', $where) : "") . " ORDER BY item_group, name";
        $st = db()->prepare($sql); $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* Create a party from anywhere — the Parties page, or the + Add new
   party box on a gate pass. One function so a party made at the gate is
   indistinguishable from one made on the master screen: same code
   series, same validation, same duplicate check. */
function inv_party_create(string $name, string $type): array {
    $name = trim($name);
    if (mb_strlen($name) < 2) return ['ok' => false, 'error' => 'Type the party name first.'];
    $types = ['supplier', 'customer', 'jobworker', 'both'];
    if (!in_array($type, $types, true)) $type = 'supplier';

    try {
        // Same name already there? Hand it back rather than making a twin —
        // the whole point of a party record is that there is only one.
        $s = db()->prepare("SELECT id, name, party_type FROM inv_parties WHERE LOWER(name)=LOWER(?) LIMIT 1");
        $s->execute([$name]);
        if ($e = $s->fetch()) {
            return ['ok' => true, 'existing' => true, 'id' => (int)$e['id'],
                    'name' => $e['name'], 'party_type' => $e['party_type'],
                    'error' => '', 'note' => 'That party already existed and has been selected.'];
        }

        $p = ['supplier' => 'SUP', 'customer' => 'CUS', 'jobworker' => 'JWP', 'both' => 'PTY'][$type];
        $n = 0;
        foreach (db()->query("SELECT code FROM inv_parties WHERE code LIKE '$p-%'")->fetchAll() as $r) {
            $tail = (int)substr((string)$r['code'], strlen($p) + 1);
            if ($tail > $n) $n = $tail;
        }
        $code = $p . '-' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);

        db()->prepare("INSERT INTO inv_parties (code,name,party_type,is_active,created_by) VALUES (?,?,?,1,?)")
            ->execute([$code, $name, $type, (int)(current_user()['id'] ?? 0)]);
        $id = (int)db()->lastInsertId();
        inv_audit('party_create', 0, ['id' => $id, 'code' => $code, 'name' => $name, 'type' => $type],
            'Created from the gate pass screen');
        return ['ok' => true, 'existing' => false, 'id' => $id, 'code' => $code,
                'name' => $name, 'party_type' => $type, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not create the party: ' . $e->getMessage()];
    }
}

/* One party's name, for a message. Cached because a posting loop over
   fourteen lines should not run fourteen queries to say the same word. */
function inv_party_name(?int $id): string {
    static $c = [];
    $id = (int)$id; if ($id <= 0) return '';
    if (array_key_exists($id, $c)) return $c[$id];
    $c[$id] = '';
    try {
        $s = db()->prepare("SELECT name FROM inv_parties WHERE id=?");
        $s->execute([$id]);
        $c[$id] = (string)($s->fetchColumn() ?: '');
    } catch (Throwable $e) {}
    return $c[$id];
}

function inv_parties(string $type = '', bool $activeOnly = true): array {
    try {
        $where = []; $params = [];
        if ($activeOnly) $where[] = "is_active=1";
        if ($type !== '') { $where[] = "(party_type=? OR party_type='both')"; $params[] = $type; }
        $sql = "SELECT * FROM inv_parties" . ($where ? " WHERE " . implode(' AND ', $where) : "") . " ORDER BY name";
        $st = db()->prepare($sql); $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* ------------------------------------------------------- master seeding */
/* Builds the Item Master from fabric/accessory/packing lines already saved
   in existing costings, normalised through the existing
   costing_item_aliases dictionary. Returns what it would create; only
   writes when $apply is true. Re-running never duplicates, because the
   generated code is derived from the normalised name. */
function inv_seed_materials_preview(bool $apply = false): array {
    $found = [];
    try {
        $rows = db()->query("SELECT line_group, item_name, unit, AVG(rate) avg_rate, COUNT(*) uses
            FROM costing_lines
            WHERE item_name IS NOT NULL AND item_name <> '' AND line_group <> 'Workmanship'
            GROUP BY line_group, item_name, unit")->fetchAll();
    } catch (Throwable $e) { return []; }

    $aliases = [];
    try {
        foreach (db()->query("SELECT alias_norm, standard_item, standard_group, standard_unit FROM costing_item_aliases")->fetchAll() as $a) {
            $aliases[$a['alias_norm']] = $a;
        }
    } catch (Throwable $e) {}

    $existing = [];
    try { foreach (db()->query("SELECT LOWER(name) n FROM inv_materials")->fetchAll() as $m) $existing[$m['n']] = true; }
    catch (Throwable $e) {}

    // Packing folds into Accessories — inv_group_norm() is the only place
    // that mapping is written, so the seed can never disagree with a screen
    $seen = [];
    foreach ($rows as $r) {
        $rawName = trim((string)$r['item_name']);
        if ($rawName === '') continue;
        $norm = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $rawName)));
        $name = $rawName; $group = inv_group_norm($r['line_group']); $unit = trim((string)$r['unit']);
        if (isset($aliases[$norm])) {
            $name = $aliases[$norm]['standard_item'] ?: $rawName;
            // a Workmanship alias is a service, not a material — leave the
            // line's own group alone rather than filing it under Other
            $ag = (string)($aliases[$norm]['standard_group'] ?? '');
            if ($ag !== '' && $ag !== 'Workmanship') $group = inv_group_norm($ag);
            if (!empty($aliases[$norm]['standard_unit'])) $unit = $aliases[$norm]['standard_unit'];
        }
        $key = strtolower($name);
        if (isset($seen[$key])) { $seen[$key]['uses'] += (int)$r['uses']; continue; }
        $seen[$key] = [
            'name' => $name, 'item_group' => $group, 'uom' => $unit !== '' ? strtoupper(substr($unit, 0, 20)) : 'PCS',
            'std_rate' => round((float)$r['avg_rate'], 4), 'uses' => (int)$r['uses'],
            'exists' => isset($existing[$key]),
        ];
    }
    $found = array_values($seen);
    usort($found, fn($a, $b) => $b['uses'] <=> $a['uses']);

    if ($apply) {
        $prefixes = ['Fabric' => 'FB', 'Accessories' => 'AC', 'Packing' => 'PK', 'Other' => 'OT'];
        foreach ($found as $f) {
            if ($f['exists']) continue;
            $p = $prefixes[$f['item_group']] ?? 'OT';
            try {
                $n = (int)db()->query("SELECT COUNT(*) FROM inv_materials WHERE code LIKE '$p-%'")->fetchColumn();
                $code = $p . '-' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
                db()->prepare("INSERT INTO inv_materials (code,name,item_group,uom,std_rate,created_by) VALUES (?,?,?,?,?,?)")
                    ->execute([$code, $f['name'], $f['item_group'], $f['uom'], $f['std_rate'], (int)(current_user()['id'] ?? 0)]);
            } catch (Throwable $e) {}
        }
    }
    return $found;
}

/* Audit shim — same audit_logs table and helper the rest of the app uses,
   with 0 as the shipment id exactly like production_audit() does. */
function inv_audit(string $action, $old, $new, string $reason = ''): void {
    try {
        audit_log(0, 'Inventory', $action,
            is_array($old) ? json_encode($old) : (string)$old,
            is_array($new) ? json_encode($new) : (string)$new, $reason);
    } catch (Throwable $e) {}
}

function inv_num($v): float {
    $s = preg_replace('/[^0-9.\-]/', '', (string)$v);
    return is_numeric($s) ? (float)$s : 0.0;
}
