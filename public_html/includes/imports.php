<?php
/*
  IMPORTS — Phase 1: the import register and each import's own tabs.

  afnan approved the demo: "approved proceed phase 1". What this file holds:
    - the imp_ tables (their own, never rows in `shipments`: the export list,
      dashboard, prints, AI search and customer portal all read that table)
    - who may do what (five permission rows in Users & Setup, nobody has them
      until ticked; Admin always does)
    - the figures every tab shows: totals per unit (never different units
      added together), what is paid and payable, when the balance falls due,
      where the shipment stands, the free-time alert, landed cost, and the
      EFS obligation per line
    - documents on Cloudflare R2 (or this server's disk), versioned like the
      export documents
    - the import's own timeline

  REUSED, NOT COPIED: suppliers are the inventory Parties; service providers,
  banks, ports, container types and shipping lines are the export masters;
  storage is includes/storage.php; exchange rates are includes/fx.php.

  Phase 2 (customs/GD lines, "Receive into Stock" through Gate Inward,
  landed cost on stock) and Phase 3 (EFS reconciliation) build on the line
  ids kept here. Nothing here moves stock.
*/
require_once __DIR__ . '/export.php';
require_once __DIR__ . '/storage.php';
if (is_file(__DIR__ . '/inventory.php')) require_once __DIR__ . '/inventory.php';

const IMP_SCHEMA_VERSION = '1';
const IMP_MAX_INVOICES   = 3;      /* afnan: "keep option free up to 3 max" */
const IMP_EFS_WASTE      = 5.0;    /* the government's wastage allowance, editable per line */
const IMP_EFS_MONTHS     = 9;      /* export within, from the GD / customs release — editable per import */

const IMP_CATEGORIES = ['Fiber', 'Fabric', 'Foam', 'PVC', 'Bedding', 'Accessories', 'Other'];
const IMP_UNITS      = ['kg', 'm', 'yd', 'pcs', 'set', 'roll', 'bale', 'carton'];
const IMP_PKG_TYPES  = ['Bale', 'Carton', 'Roll', 'Bag', 'Pallet', 'Other'];
const IMP_PAY_METHODS = ['Advance', 'TT', 'CAD', 'LC', 'DP', 'DA', 'Open account', 'Mixed'];
const IMP_INCOTERMS  = ['FOB', 'CFR', 'CIF', 'EXW', 'FCA', 'CPT', 'CIP', 'DAP', 'DDP'];
const IMP_BASIS      = ['bl' => 'BL date', 'arrival' => 'Arrival date', 'invoice' => 'Invoice date', 'fixed' => 'Agreed fixed date'];
/* the sign each kind has on the supplier balance */
const IMP_PAY_KINDS  = [
    'payment'       => ['Payment to supplier', 1],
    'credit_note'   => ['Supplier credit note', 1],
    'refund'        => ['Refund from supplier', -1],
    'before_golive' => ['Paid before go-live', 1],
];
/* Specifications per category — all optional, shown only for that category. */
const IMP_SPECS = [
    'Fiber'       => [['polymer', 'Polymer / type'], ['denier', 'Denier / dtex'], ['cut', 'Cut length'], ['virgin', 'Virgin / recycled', ['', 'Virgin', 'Recycled']],
                      ['hollow', 'Hollow / solid', ['', 'Solid', 'Hollow', 'Hollow conjugated']], ['silicon', 'Siliconized', ['', 'Yes', 'No']]],
    'Fabric'      => [['composition', 'Composition'], ['weave', 'Weave / knit'], ['width', 'Width'], ['gsm', 'GSM'], ['finish', 'Finish'], ['colour', 'Colour'], ['roll_len', 'Roll length']],
    'Foam'        => [['type', 'Type'], ['grade', 'Grade'], ['density', 'Density'], ['thickness', 'Thickness'], ['dims', 'Dimensions'], ['form', 'Form', ['', 'Sheet', 'Block', 'Roll', 'Crumb']], ['condition', 'Condition']],
    'PVC'         => [['type', 'Type', ['', 'Film', 'Sheet', 'Coated fabric']], ['backing', 'Backing'], ['thickness', 'Thickness'], ['width', 'Width'], ['colour', 'Colour'], ['roll_len', 'Roll length']],
    'Bedding'     => [['size', 'Size'], ['composition', 'Composition'], ['construction', 'Construction / TC'], ['gsm', 'GSM'], ['colour', 'Colour'], ['pcs_set', 'Pieces per set']],
    'Accessories' => [['detail', 'Description'], ['size', 'Size'], ['colour', 'Colour']],
    'Other'       => [['notes', 'Notes']],
];

/* ================================================================= schema */
function imp_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    exp_ensure_schema();
    try {
        $v = db()->prepare("SELECT v FROM exp_meta WHERE k='imp_schema_version'");
        $v->execute();
        if ((string)$v->fetchColumn() === IMP_SCHEMA_VERSION) return;
    } catch (Throwable $e) {}
    $x = static function (string $sql): void { try { db()->exec($sql); } catch (Throwable $e) { error_log('imp schema: ' . $e->getMessage()); } };

    $x("CREATE TABLE IF NOT EXISTS imp_imports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ref VARCHAR(30) NOT NULL,
        employee VARCHAR(120) NULL,
        supplier_party_id INT NULL,
        supplier_name VARCHAR(190) NOT NULL DEFAULT '',
        origin_country VARCHAR(80) NULL,
        shipped_from VARCHAR(120) NULL,
        contract_no VARCHAR(80) NULL,
        contract_date DATE NULL,
        incoterm VARCHAR(10) NULL,
        incoterm_place VARCHAR(120) NULL,
        currency VARCHAR(10) NOT NULL DEFAULT 'USD',
        import_type VARCHAR(10) NOT NULL DEFAULT 'ordinary',
        pay_method VARCHAR(30) NULL,
        advance_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
        credit TINYINT(1) NOT NULL DEFAULT 0,
        credit_days INT NOT NULL DEFAULT 0,
        maturity_basis VARCHAR(10) NOT NULL DEFAULT 'bl',
        fixed_due_date DATE NULL,
        due_override DATE NULL,
        due_override_reason VARCHAR(300) NULL,
        lc TINYINT(1) NOT NULL DEFAULT 0,
        lc_no VARCHAR(80) NULL, lc_bank VARCHAR(120) NULL, lc_expiry DATE NULL, lc_last_ship DATE NULL,
        fta TINYINT(1) NOT NULL DEFAULT 0,
        fta_agreement VARCHAR(120) NULL, fta_form VARCHAR(60) NULL, fta_cert_no VARCHAR(80) NULL, fta_cert_date DATE NULL,
        commission TINYINT(1) NOT NULL DEFAULT 0,
        com_beneficiary VARCHAR(160) NULL, com_rate_pct DECIMAL(8,3) NOT NULL DEFAULT 0,
        is_opening TINYINT(1) NOT NULL DEFAULT 0,
        already_in_stock TINYINT(1) NOT NULL DEFAULT 0,
        efs_months INT NOT NULL DEFAULT " . IMP_EFS_MONTHS . ",
        alloc_basis VARCHAR(10) NOT NULL DEFAULT 'kg',
        manual_status VARCHAR(20) NULL,
        notes TEXT NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_imp_ref (ref), INDEX(supplier_party_id), INDEX(import_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    /* one supplier invoice per GD — up to three */
    $x("CREATE TABLE IF NOT EXISTS imp_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        import_id INT NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        invoice_no VARCHAR(80) NULL,
        invoice_date DATE NULL,
        gd_no VARCHAR(80) NULL,
        gd_date DATE NULL,
        INDEX(import_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    /* the invoice lines. Stable ids: Phase 2 customs lines and receipts and
       Phase 3 EFS point at these. kg is always kept, whatever it is billed in. */
    $x("CREATE TABLE IF NOT EXISTS imp_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        import_id INT NOT NULL,
        invoice_id INT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        category VARCHAR(20) NOT NULL DEFAULT 'Other',
        material_id INT NULL,
        product_id INT NULL,
        description VARCHAR(300) NOT NULL DEFAULT '',
        hs_code VARCHAR(20) NULL,
        supplier_ref VARCHAR(80) NULL,
        bill_unit VARCHAR(10) NOT NULL DEFAULT 'kg',
        bill_qty DECIMAL(16,3) NOT NULL DEFAULT 0,
        rate DECIMAL(16,4) NOT NULL DEFAULT 0,
        net_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
        gross_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
        packages INT NOT NULL DEFAULT 0,
        specs TEXT NULL,
        efs_valuation DECIMAL(16,4) NULL,
        efs_waste_pct DECIMAL(6,3) NULL,
        efs_va_pct DECIMAL(6,3) NULL,
        INDEX(import_id), INDEX(hs_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $x("CREATE TABLE IF NOT EXISTS imp_logistics (
        import_id INT NOT NULL PRIMARY KEY,
        booking_no VARCHAR(80) NULL, booking_date DATE NULL,
        forwarder_id INT NULL, shipping_line_id INT NULL,
        vessel VARCHAR(120) NULL, voyage VARCHAR(40) NULL,
        pol VARCHAR(120) NULL, transship VARCHAR(120) NULL, pod VARCHAR(120) NULL,
        etd DATE NULL, eta DATE NULL, departed DATE NULL, arrival DATE NULL,
        bl_no VARCHAR(80) NULL, bl_date DATE NULL, bl_type VARCHAR(30) NULL, bl_released TINYINT(1) NOT NULL DEFAULT 0,
        gd_filed DATE NULL, customs_release DATE NULL, do_date DATE NULL,
        port_gate_out DATE NULL, factory_gate_in DATE NULL, empty_return DATE NULL,
        free_days INT NOT NULL DEFAULT 0, terminal_free_days INT NOT NULL DEFAULT 0,
        freight_agreed DECIMAL(16,2) NULL, freight_currency VARCHAR(10) NULL,
        last_event VARCHAR(190) NULL, last_location VARCHAR(190) NULL, tracking_at DATETIME NULL,
        notes TEXT NULL,
        updated_by INT NULL, updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $x("CREATE TABLE IF NOT EXISTS imp_containers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        import_id INT NOT NULL,
        container_no VARCHAR(30) NULL,
        size_type VARCHAR(30) NULL,
        seal_no VARCHAR(60) NULL,
        notes VARCHAR(255) NULL,
        INDEX(import_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $x("CREATE TABLE IF NOT EXISTS imp_packing (
        id INT AUTO_INCREMENT PRIMARY KEY,
        import_id INT NOT NULL,
        line_id INT NOT NULL,
        pkg_type VARCHAR(20) NOT NULL DEFAULT 'Bale',
        serial_from INT NOT NULL DEFAULT 0,
        serial_to INT NOT NULL DEFAULT 0,
        packages INT NOT NULL DEFAULT 0,
        net_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
        gross_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
        roll_len DECIMAL(12,2) NULL,
        container_id INT NULL,
        INDEX(import_id), INDEX(line_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    /* amount is in the IMPORT's currency; the PKR side is what the bank did */
    $x("CREATE TABLE IF NOT EXISTS imp_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        import_id INT NOT NULL,
        kind VARCHAR(20) NOT NULL DEFAULT 'payment',
        paid_on DATE NULL,
        amount DECIMAL(16,2) NOT NULL DEFAULT 0,
        bank_id INT NULL,
        pkr_debit DECIMAL(18,2) NULL,
        rate DECIMAL(16,6) NULL,
        bank_charges_pkr DECIMAL(18,2) NOT NULL DEFAULT 0,
        other_pkr DECIMAL(18,2) NOT NULL DEFAULT 0,
        reference VARCHAR(120) NULL,
        notes VARCHAR(300) NULL,
        is_void TINYINT(1) NOT NULL DEFAULT 0, void_reason VARCHAR(300) NULL, voided_by INT NULL, voided_at DATETIME NULL,
        created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(import_id), INDEX(is_void)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $x("CREATE TABLE IF NOT EXISTS imp_costs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        import_id INT NOT NULL,
        cost_type_id INT NULL,
        kind VARCHAR(10) NOT NULL DEFAULT 'service',
        provider_id INT NULL,
        bill_no VARCHAR(80) NULL, bill_date DATE NULL,
        currency VARCHAR(10) NOT NULL DEFAULT 'PKR',
        est_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
        amount DECIMAL(16,2) NULL,
        fx_rate DECIMAL(16,6) NULL,
        is_paid TINYINT(1) NOT NULL DEFAULT 0, paid_on DATE NULL, pay_ref VARCHAR(120) NULL,
        notes VARCHAR(300) NULL,
        is_void TINYINT(1) NOT NULL DEFAULT 0, void_reason VARCHAR(300) NULL, voided_by INT NULL, voided_at DATETIME NULL,
        created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(import_id), INDEX(is_void)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $x("CREATE TABLE IF NOT EXISTS imp_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        import_id INT NOT NULL,
        doc_type_id INT NULL,
        line_id INT NULL,
        doc_no VARCHAR(120) NULL,
        version INT NOT NULL DEFAULT 1,
        original_name VARCHAR(255) NOT NULL,
        storage_driver VARCHAR(20) NOT NULL DEFAULT 'local',
        storage_key VARCHAR(500) NOT NULL,
        mime_type VARCHAR(160) NULL,
        file_size BIGINT NULL,
        superseded_by INT NULL,
        is_archived TINYINT(1) NOT NULL DEFAULT 0, archive_reason VARCHAR(300) NULL, archived_by INT NULL, archived_at DATETIME NULL,
        notes VARCHAR(300) NULL,
        uploaded_by INT NULL, uploaded_at DATETIME NULL,
        INDEX(import_id), INDEX(doc_type_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $x("CREATE TABLE IF NOT EXISTS imp_audit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        import_id INT NOT NULL DEFAULT 0,
        user_id INT NULL, user_name VARCHAR(120) NULL,
        action VARCHAR(60) NOT NULL,
        detail TEXT NULL,
        reason VARCHAR(300) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(import_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    /* what was still owed for EFS on the go-live date, one row per GD line */
    $x("CREATE TABLE IF NOT EXISTS imp_opening_efs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        gd_no VARCHAR(80) NOT NULL DEFAULT '',
        gd_date DATE NULL,
        hs_code VARCHAR(20) NULL,
        product VARCHAR(190) NOT NULL DEFAULT '',
        imported_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
        valuation DECIMAL(16,4) NOT NULL DEFAULT 0,
        waste_pct DECIMAL(6,3) NOT NULL DEFAULT " . IMP_EFS_WASTE . ",
        va_pct DECIMAL(6,3) NOT NULL DEFAULT 0,
        exported_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
        exported_value DECIMAL(16,2) NOT NULL DEFAULT 0,
        months INT NOT NULL DEFAULT " . IMP_EFS_MONTHS . ",
        notes VARCHAR(300) NULL,
        created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL, updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $x("CREATE TABLE IF NOT EXISTS imp_settings (
        k VARCHAR(60) NOT NULL PRIMARY KEY,
        v TEXT NULL,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    imp_seed_masters();
    try {
        db()->prepare("REPLACE INTO exp_meta (k, v, updated_at) VALUES ('imp_schema_version', ?, NOW())")->execute([IMP_SCHEMA_VERSION]);
    } catch (Throwable $e) {}
}

/* Import document types and cost types — their own lists (kinds imp_doc_type
   and imp_cost_type), so the export dropdowns are not touched. Edited on the
   same Export Settings → Lists screen. A cost type flagged "duty" is a duty or
   tax paid through the agent, kept apart from his service charges. */
function imp_seed_masters(): void
{
    $docs = ['Supplier Commercial Invoice', 'Supplier Packing List', 'Purchase Contract / PO', 'Proforma from Supplier', 'BL',
             'GD', 'FTA / Origin Certificate', 'Test Report / Specification', 'Delivery Order', 'Insurance',
             'Freight Invoice', 'Clearing Agent Bill', 'Transport Bill', 'Bank Debit Advice', 'TT / SWIFT Proof', 'LC Copy',
             'NOC / Approval', 'Weighbridge Slip', 'Other Document'];
    $costs = [['Ocean Freight', 0], ['Clearing Agent Service', 0], ['Customs Duty & Taxes (via agent)', 1], ['Delivery Order Charges', 0],
              ['Terminal / Port Charges', 0], ['Port Storage', 0], ['Demurrage / Detention', 0], ['Transport Port → Factory', 0],
              ['Inspection', 0], ['Insurance', 0], ['Courier', 0], ['Bank Charges', 0], ['Commission', 0], ['Other Charges', 0]];
    try {
        $ins = db()->prepare("INSERT IGNORE INTO exp_masters (kind, label, sort_order, flags) VALUES (?,?,?,?)");
        foreach ($docs as $i => $d) $ins->execute(['imp_doc_type', $d, ($i + 1) * 10, null]);
        foreach ($costs as $i => $c) $ins->execute(['imp_cost_type', $c[0], ($i + 1) * 10, $c[1] ? json_encode(['is_duty' => 1]) : null]);
    } catch (Throwable $e) {}
}

/* ============================================================ permissions */
/* area => module key in Users & Setup (includes/access.php, group Imports) */
const IMP_AREAS = ['imp' => 'imp', 'payments' => 'imppay', 'costs' => 'impcost', 'documents' => 'impdoc', 'efs' => 'impefs'];

function imp_can(string $area, string $action = 'v'): bool
{
    $mod = IMP_AREAS[$area] ?? '';
    if ($mod === '') return false;
    if (function_exists('is_staff') && (is_staff() || is_production_staff())) return false;
    return function_exists('zu_can') ? zu_can($mod, $action) : is_admin();
}
function imp_require(string $area, string $action = 'v'): void
{
    if (imp_can($area, $action)) return;
    http_response_code(403);
    exit('You do not have permission for this part of the import.');
}
/* Every import page: logged in, the import exists, the area permission held. */
function imp_open(string $area = 'imp', string $action = 'v'): array
{
    require_login();
    imp_ensure_schema();
    $id = (int)($_GET['id'] ?? $_POST['import_id'] ?? 0);
    $s = db()->prepare("SELECT * FROM imp_imports WHERE id=?");
    $s->execute([$id]);
    $imp = $s->fetch();
    if (!$imp) { http_response_code(404); exit('Import not found.'); }
    imp_require('imp', 'v');
    imp_require($area, $action);
    return $imp;
}

/* ================================================================ helpers */
function imp_num($v): float { return (float)str_replace([',', ' '], '', (string)$v); }
function imp_date($v): ?string
{
    $v = trim((string)$v);
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}
function imp_add_days(?string $iso, int $days): ?string
{
    if (!$iso) return null;
    return date('Y-m-d', strtotime($iso . ' +' . $days . ' days'));
}
function imp_add_months(?string $iso, int $m): ?string
{
    if (!$iso) return null;
    $d = new DateTime($iso);
    $d->modify('+' . $m . ' months');
    return $d->format('Y-m-d');
}
function imp_dmy(?string $iso): string { return $iso ? date('d M Y', strtotime($iso)) : '—'; }
function imp_today(): string { return date('Y-m-d'); }

/* The next reference: IMP-26-0001, one series per year, checked against the
   references already used. */
function imp_next_ref(): string
{
    $p = 'IMP-' . date('y') . '-';
    $n = 0;
    try {
        $s = db()->prepare("SELECT ref FROM imp_imports WHERE ref LIKE ?");
        $s->execute([$p . '%']);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $r) $n = max($n, (int)substr((string)$r, strlen($p)));
    } catch (Throwable $e) {}
    return $p . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
}

function imp_log(int $importId, string $action, string $detail = '', string $reason = ''): void
{
    try {
        $u = current_user();
        db()->prepare("INSERT INTO imp_audit (import_id, user_id, user_name, action, detail, reason, created_at) VALUES (?,?,?,?,?,?,NOW())")
            ->execute([$importId, $u['id'] ?? null, $u['name'] ?? 'System', mb_substr($action, 0, 60), $detail, mb_substr($reason, 0, 300) ?: null]);
    } catch (Throwable $e) { error_log('imp_log: ' . $e->getMessage()); }
}

function imp_rows(string $table, int $importId, string $order = 'id'): array
{
    try {
        $s = db()->prepare("SELECT * FROM $table WHERE import_id=? ORDER BY $order");
        $s->execute([$importId]);
        return $s->fetchAll();
    } catch (Throwable $e) { return []; }
}
function imp_lines(int $id): array { return imp_rows('imp_lines', $id, 'sort_order, id'); }
function imp_invoices(int $id): array { return imp_rows('imp_invoices', $id, 'sort_order, id'); }
function imp_containers(int $id): array { return imp_rows('imp_containers', $id, 'id'); }
function imp_packing(int $id): array { return imp_rows('imp_packing', $id, 'line_id, serial_from, id'); }
function imp_payments(int $id): array { return imp_rows('imp_payments', $id, 'paid_on, id'); }
function imp_costs(int $id): array { return imp_rows('imp_costs', $id, 'bill_date, id'); }
function imp_logistics(int $id): array
{
    try {
        $s = db()->prepare("SELECT * FROM imp_logistics WHERE import_id=?");
        $s->execute([$id]);
        if ($r = $s->fetch()) return $r;
    } catch (Throwable $e) {}
    return ['import_id' => $id, 'free_days' => 0, 'terminal_free_days' => 0, 'bl_released' => 0];
}

/* ================================================================ figures */
function imp_line_amount(array $l): float { return round((float)$l['bill_qty'] * (float)$l['rate'], 2); }

/* One total per unit — kg and metres are never added together. Net kg is a
   separate total because every line keeps it. */
function imp_totals(array $lines): array
{
    $t = ['value' => 0.0, 'kg' => 0.0, 'gross' => 0.0, 'packages' => 0, 'by_unit' => []];
    foreach ($lines as $l) {
        $t['value'] += imp_line_amount($l);
        $t['kg'] += (float)$l['net_kg'];
        $t['gross'] += (float)$l['gross_kg'];
        $t['packages'] += (int)$l['packages'];
        $u = (string)$l['bill_unit'];
        $t['by_unit'][$u] = ($t['by_unit'][$u] ?? 0) + (float)$l['bill_qty'];
    }
    $t['value'] = round($t['value'], 2);
    return $t;
}
function imp_by_unit_text(array $byUnit): string
{
    $o = [];
    foreach ($byUnit as $u => $q) $o[] = rtrim(rtrim(number_format($q, 3), '0'), '.') . ' ' . $u;
    return implode(' · ', $o);
}

/* Paid and payable, in the import's currency. A refund from the supplier
   raises the balance again; a credit note lowers it like a payment. PKR is
   never used for the balance — three payments at three rates must still add
   up to the invoice. */
function imp_paid_summary(array $imp, array $lines, ?array $payments = null): array
{
    $total = imp_totals($lines)['value'];
    $payments = $payments ?? imp_payments((int)$imp['id']);
    $settled = 0.0; $charges = 0.0;
    foreach ($payments as $p) {
        if ((int)$p['is_void'] === 1) continue;
        $sign = IMP_PAY_KINDS[$p['kind']][1] ?? 1;
        $settled += $sign * (float)$p['amount'];
        $charges += (float)$p['bank_charges_pkr'] + (float)$p['other_pkr'];
    }
    $bal = round($total - $settled, 2);
    $status = $settled <= 0.0049 ? 'UNPAID' : ($bal > 0.0049 ? 'PART PAID' : ($bal < -0.0049 ? 'OVERPAID' : 'PAID'));
    return ['currency' => (string)$imp['currency'], 'total' => $total, 'settled' => round($settled, 2), 'balance' => $bal,
            'status' => $status, 'bank_charges_pkr' => round($charges, 2)];
}

/* When the balance falls due: credit days counted from the chosen date, or a
   fixed date; an override (with its reason in the timeline) wins. */
function imp_maturity(array $imp, ?array $log = null, ?array $invoices = null): array
{
    if ((int)$imp['credit'] !== 1 && empty($imp['due_override'])) return ['date' => null, 'from' => '', 'estimated' => false];
    if (!empty($imp['due_override'])) return ['date' => $imp['due_override'], 'from' => 'set by hand: ' . (string)$imp['due_override_reason'], 'estimated' => false];
    $log = $log ?? imp_logistics((int)$imp['id']);
    $basis = (string)$imp['maturity_basis'];
    $days = (int)$imp['credit_days'];
    if ($basis === 'fixed') return ['date' => $imp['fixed_due_date'] ?: null, 'from' => 'agreed fixed date', 'estimated' => false];
    $est = false;
    if ($basis === 'arrival') { $from = $log['arrival'] ?? null; if (!$from) { $from = $log['eta'] ?? null; $est = true; } $lbl = $est ? 'expected arrival (ETA)' : 'arrival date'; }
    elseif ($basis === 'invoice') { $invoices = $invoices ?? imp_invoices((int)$imp['id']); $from = $invoices[0]['invoice_date'] ?? null; $lbl = 'invoice date'; }
    else { $from = $log['bl_date'] ?? null; $lbl = 'BL date'; }
    if (!$from) return ['date' => null, 'from' => 'waits for the ' . $lbl, 'estimated' => true];
    return ['date' => imp_add_days($from, $days), 'from' => $lbl . ' ' . imp_dmy($from) . ' + ' . $days . ' days', 'estimated' => $est];
}

/* Where it stands, read from the dates already typed — nobody keeps a status
   field up to date by hand. Closed / cancelled are the only manual ones. */
const IMP_STAGES = ['contract' => 'Contract', 'booked' => 'Booked', 'sailing' => 'Sailing', 'arrived' => 'Arrived', 'customs' => 'In customs',
                    'released' => 'Released', 'left_port' => 'Left port', 'at_factory' => 'At factory'];
function imp_stage(array $imp, ?array $log = null): array
{
    if (($imp['manual_status'] ?? '') === 'closed') return ['key' => 'closed', 'label' => 'Closed', 'pill' => 'g'];
    if (($imp['manual_status'] ?? '') === 'cancelled') return ['key' => 'cancelled', 'label' => 'Cancelled', 'pill' => 'r'];
    $log = $log ?? imp_logistics((int)$imp['id']);
    $k = 'contract';
    if (!empty($log['booking_no']))      $k = 'booked';
    if (!empty($log['etd']) || !empty($log['bl_date']) || !empty($log['departed'])) $k = 'sailing';
    if (!empty($log['arrival']))         $k = 'arrived';
    if (!empty($log['gd_filed']))        $k = 'customs';
    if (!empty($log['customs_release'])) $k = 'released';
    if (!empty($log['port_gate_out']))   $k = 'left_port';
    if (!empty($log['factory_gate_in'])) $k = 'at_factory';
    $pill = ['contract' => 'n', 'booked' => 'n', 'sailing' => 'b', 'arrived' => 'o', 'customs' => 'o', 'released' => 'b', 'left_port' => 'b', 'at_factory' => 'g'][$k];
    $label = IMP_STAGES[$k];
    if ($k === 'sailing' && !empty($log['eta'])) $label .= ' · ETA ' . imp_dmy($log['eta']);
    return ['key' => $k, 'label' => $label, 'pill' => $pill];
}

/* Free time at the port: counted from arrival (or ETA until arrival is typed). */
function imp_last_free_day(array $log): ?string
{
    $from = !empty($log['arrival']) ? $log['arrival'] : ($log['eta'] ?? null);
    if (!$from || (int)($log['free_days'] ?? 0) <= 0) return null;
    return imp_add_days($from, (int)$log['free_days']);
}

/* What needs attention on this import, worst first. */
function imp_alerts(array $imp, ?array $log = null, ?array $sum = null): array
{
    $out = [];
    if (in_array($imp['manual_status'] ?? '', ['closed', 'cancelled'], true)) return $out;
    $log = $log ?? imp_logistics((int)$imp['id']);
    $today = imp_today();
    $lfd = imp_last_free_day($log);
    if ($lfd && empty($log['empty_return'])) {
        $left = (int)round((strtotime($lfd) - strtotime($today)) / 86400);
        if ($left < 0) $out[] = ['r', 'Free time ended ' . (-$left) . ' day' . ($left === -1 ? '' : 's') . ' ago — demurrage running'];
        elseif ($left <= 3) $out[] = ['r', 'Last free day ' . imp_dmy($lfd) . ' — ' . $left . ' day' . ($left === 1 ? '' : 's') . ' left'];
    }
    $m = imp_maturity($imp, $log);
    if ($m['date'] && $sum && $sum['balance'] > 0.0049) {
        $left = (int)round((strtotime($m['date']) - strtotime($today)) / 86400);
        if ($left < 0) $out[] = ['r', 'Payment overdue since ' . imp_dmy($m['date'])];
        elseif ($left <= 7) $out[] = ['o', 'Payment due ' . imp_dmy($m['date'])];
    }
    if ((int)$imp['lc'] === 1 && !empty($imp['lc_expiry'])) {
        $left = (int)round((strtotime($imp['lc_expiry']) - strtotime($today)) / 86400);
        if ($left >= 0 && $left <= 10) $out[] = ['o', 'LC expires ' . imp_dmy($imp['lc_expiry'])];
    }
    return $out;
}

/* PKR per unit of the import's currency, for the goods' PKR value: the
   weighted rate of what the bank actually paid; else today's rate. */
function imp_pkr_rate(array $imp, ?array $payments = null): array
{
    $cur = (string)$imp['currency'];
    if ($cur === 'PKR') return ['rate' => 1.0, 'source' => 'PKR'];
    $payments = $payments ?? imp_payments((int)$imp['id']);
    $amt = 0.0; $pkr = 0.0;
    foreach ($payments as $p) {
        if ((int)$p['is_void'] === 1 || (float)$p['rate'] <= 0 || (float)$p['amount'] <= 0) continue;
        if (($p['kind'] ?? 'payment') === 'refund') continue;
        $amt += (float)$p['amount']; $pkr += (float)$p['amount'] * (float)$p['rate'];
    }
    if ($amt > 0) return ['rate' => $pkr / $amt, 'source' => 'rate the bank paid'];
    if (function_exists('fx_get_rates')) {
        $r = fx_get_rates();
        if (!empty($r[$cur]) && (float)$r[$cur] > 0) return ['rate' => 1 / (float)$r[$cur], 'source' => "today's rate"];
    }
    return ['rate' => 0.0, 'source' => 'no rate yet'];
}

/* A cost in PKR: the actual bill when there is one, else the estimate. */
function imp_cost_pkr(array $c): float
{
    $a = $c['amount'] !== null && $c['amount'] !== '' ? (float)$c['amount'] : (float)$c['est_amount'];
    return round($c['currency'] === 'PKR' ? $a : $a * (float)$c['fx_rate'], 2);
}

/* Landed cost, provisional while any bill is still an estimate. Costs are
   shared by net kg or by value; the last line takes the rounding so the
   shares add up exactly. Bank charges from the payments are counted once,
   here, as a cost. */
function imp_landed(array $imp, array $lines, ?array $costs = null, ?array $payments = null, ?string $basis = null): array
{
    $basis = $basis ?? (string)$imp['alloc_basis'];
    $costs = $costs ?? imp_costs((int)$imp['id']);
    $payments = $payments ?? imp_payments((int)$imp['id']);
    $rate = imp_pkr_rate($imp, $payments);
    $total = 0.0; $provisional = false;
    foreach ($costs as $c) {
        if ((int)$c['is_void'] === 1) continue;
        if ($c['amount'] === null || $c['amount'] === '') $provisional = true;
        $total += imp_cost_pkr($c);
    }
    $bank = 0.0;
    foreach ($payments as $p) if ((int)$p['is_void'] === 0) $bank += (float)$p['bank_charges_pkr'] + (float)$p['other_pkr'];
    $total = round($total + $bank, 2);
    $weights = [];
    foreach ($lines as $l) $weights[] = $basis === 'value' ? imp_line_amount($l) : (float)$l['net_kg'];
    $sumW = array_sum($weights);
    $rows = []; $left = $total; $n = count($lines);
    foreach (array_values($lines) as $i => $l) {
        $share = $i === $n - 1 ? $left : ($sumW > 0 ? round($total * $weights[$i] / $sumW, 2) : 0.0);
        $left = round($left - $share, 2);
        $goods = round(imp_line_amount($l) * $rate['rate'], 2);
        $landed = $goods + $share;
        $rows[] = ['line' => $l, 'goods_pkr' => $goods, 'share' => $share, 'landed' => $landed,
                   'per_kg' => (float)$l['net_kg'] > 0 ? $landed / (float)$l['net_kg'] : null,
                   'per_unit' => (float)$l['bill_qty'] > 0 ? $landed / (float)$l['bill_qty'] : null];
    }
    return ['rows' => $rows, 'costs_pkr' => $total, 'bank_pkr' => round($bank, 2), 'rate' => $rate, 'provisional' => $provisional, 'basis' => $basis];
}

/* The EFS obligation of one line: export at least (imported kg − wastage %)
   of material, in goods worth at least the full imported value (+ value
   addition % for that HS code). afnan's example: 100 kg × USD 3.50 → 95 kg,
   USD 350. Valuation is the customs value per kg (from the GD in Phase 2);
   until then the invoice value per kg. */
function imp_efs_line(array $l): array
{
    $kg = (float)$l['net_kg'];
    $perKg = $kg > 0 ? imp_line_amount($l) / $kg : 0.0;
    $val = $l['efs_valuation'] !== null && $l['efs_valuation'] !== '' ? (float)$l['efs_valuation'] : $perKg;
    $waste = $l['efs_waste_pct'] !== null && $l['efs_waste_pct'] !== '' ? (float)$l['efs_waste_pct'] : IMP_EFS_WASTE;
    $va = $l['efs_va_pct'] !== null && $l['efs_va_pct'] !== '' ? (float)$l['efs_va_pct'] : 0.0;
    $imported = round($kg * $val, 2);
    return ['kg' => $kg, 'valuation' => $val, 'from_invoice' => $l['efs_valuation'] === null || $l['efs_valuation'] === '',
            'waste' => $waste, 'va' => $va, 'imported' => $imported,
            'need_kg' => round($kg * (1 - $waste / 100), 3), 'need_value' => round($imported * (1 + $va / 100), 2)];
}
/* The EFS clock starts when customs releases the goods (else the GD date). */
function imp_efs_deadline(array $imp, ?array $log = null, ?array $invoices = null): array
{
    $log = $log ?? imp_logistics((int)$imp['id']);
    $invoices = $invoices ?? imp_invoices((int)$imp['id']);
    $from = $log['customs_release'] ?? null; $lbl = 'customs release';
    if (!$from) { foreach ($invoices as $iv) if (!empty($iv['gd_date'])) { $from = $iv['gd_date']; $lbl = 'GD date'; break; } }
    return ['date' => $from ? imp_add_months($from, (int)$imp['efs_months']) : null, 'from' => $from ? $lbl . ' ' . imp_dmy($from) : 'starts at customs release / GD date'];
}
function imp_opening_calc(array $r): array
{
    $imp = round((float)$r['imported_kg'] * (float)$r['valuation'], 2);
    $needKg = (float)$r['imported_kg'] * (1 - (float)$r['waste_pct'] / 100);
    $needVal = $imp * (1 + (float)$r['va_pct'] / 100);
    return ['imported_value' => $imp, 'left_kg' => round($needKg - (float)$r['exported_kg'], 3),
            'left_value' => round($needVal - (float)$r['exported_value'], 2),
            'deadline' => !empty($r['gd_date']) ? imp_add_months($r['gd_date'], (int)$r['months']) : null];
}

/* =============================================================== settings */
function imp_setting(string $k, string $default = ''): string
{
    try {
        $s = db()->prepare("SELECT v FROM imp_settings WHERE k=?");
        $s->execute([$k]);
        $v = $s->fetchColumn();
        return $v === false || $v === null ? $default : (string)$v;
    } catch (Throwable $e) { return $default; }
}
function imp_set_setting(string $k, string $v): void
{
    db()->prepare("DELETE FROM imp_settings WHERE k=?")->execute([$k]);
    db()->prepare("INSERT INTO imp_settings (k, v, updated_at) VALUES (?,?,NOW())")->execute([$k, $v]);
}
function imp_opening_locked(): bool { return imp_setting('opening_locked_at') !== ''; }

/* The admin whose password this is, or null — for the document delete. */
function imp_admin_for_password(string $pw): ?array
{
    if ($pw === '') return null;
    try {
        foreach (db()->query("SELECT id, name, password_hash FROM users WHERE role='admin' AND is_active=1")->fetchAll() as $u) {
            if (password_verify($pw, (string)$u['password_hash'])) return $u;
        }
    } catch (Throwable $e) {}
    return null;
}

/* ================================================================ storage */
/* The same storage as the export documents (R2 when configured, this
   server's disk when not), under imports/ so the two never mix. */
function imp_store_upload(int $importId, string $tmpPath, string $originalName, string $mime): array
{
    $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($originalName, PATHINFO_EXTENSION)));
    $key = 'imports/' . $importId . '/' . date('Y/m') . '/' . bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
    if (exp_storage_driver() === 'r2') {
        [$ok, $err] = exp_r2_put($tmpPath, $key, $mime);
        return $ok ? [true, 'r2', $key, ''] : [false, 'r2', $key, $err];
    }
    global $config;
    $dir = rtrim((string)($config['upload_dir'] ?? (__DIR__ . '/../storage/uploads')), '/');
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $target = $dir . '/' . basename($key);
    if (!@move_uploaded_file($tmpPath, $target) && !@rename($tmpPath, $target)) return [false, 'local', $key, 'Could not write the file to disk.'];
    return [true, 'local', basename($key), ''];
}

/* ============================================================ the header */
function imp_page_css(): string
{
    return exp_page_css() . '<style>
.xtabs{display:flex;gap:4px;overflow-x:auto;margin:0 0 14px;padding-bottom:2px;-webkit-overflow-scrolling:touch}
.xtabs a{padding:8px 13px;border-radius:10px 10px 0 0;border:1px solid #e3e9f2;border-bottom:none;background:#f6f8fc;color:#5a6b82;font-size:11.5px;font-weight:700;letter-spacing:.04em;text-decoration:none;white-space:nowrap}
.xtabs a:hover{background:rgba(14,168,201,.1);color:#0ea8c9}
.xtabs a.on{background:#fff;color:#0ea8c9;border-color:#cbd5e3;box-shadow:0 -2px 0 #0ea8c9 inset}
.xtabs a .ph{font-size:9px;color:#8a97ab;margin-left:4px}
.xhead{padding:14px 18px;border-radius:14px;background:#fff;border:1px solid #e3e9f2;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.xhead .t{font-size:15px;font-weight:800;color:#152033}
.xhead .s{font-size:12px;color:#5a6b82;margin-top:3px;font-variant-numeric:tabular-nums}
.xpill{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;display:inline-block}
.xpill.g{background:rgba(22,163,74,.14);color:#16a34a;border:1px solid rgba(22,163,74,.28)}
.xpill.o{background:rgba(217,119,6,.14);color:#d97706;border:1px solid rgba(217,119,6,.28)}
.xpill.r{background:rgba(224,67,93,.13);color:#b8283f;border:1px solid rgba(224,67,93,.28)}
.xpill.b{background:rgba(47,127,224,.14);color:#2f7fe0;border:1px solid rgba(47,127,224,.28)}
.xpill.v{background:rgba(109,91,208,.12);color:#5a49c2;border:1px solid rgba(109,91,208,.28)}
.xpill.n{background:#f6f8fc;color:#5a6b82;border:1px solid #e3e9f2}
.xbad{padding:11px 14px;border-radius:12px;background:rgba(224,67,93,.08);border:1px solid rgba(224,67,93,.28);font-size:12.5px;color:#b8283f;line-height:1.6}
.xok{padding:11px 14px;border-radius:12px;background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.28);font-size:12.5px;color:#127a3f;line-height:1.6}
.xin.num{text-align:right;font-variant-numeric:tabular-nums}
.xtable .xin{margin-top:0;padding:7px 8px;font-size:12.5px}
.xtable tfoot td{font-weight:800;border-top:2px solid #e3e9f2}
.tog{display:flex;align-items:center;gap:9px;font-size:13px;font-weight:600;cursor:pointer;padding:8px 0;color:#152033}
.tog input{width:18px;height:18px;accent-color:#0ea8c9}
.reveal{border-left:3px solid rgba(14,168,201,.35);padding:4px 0 4px 14px;margin:2px 0 10px}
.calc{font-variant-numeric:tabular-nums;font-weight:800}
.imp-muted{color:#5a6b82}.imp-small{font-size:11.5px}
.flow{display:flex;flex-wrap:wrap;gap:4px;align-items:center;font-size:11px;font-weight:700}
.flow span{padding:4px 9px;border-radius:20px;background:#f6f8fc;border:1px solid #e3e9f2;color:#8a97ab}
.flow span.done{background:rgba(22,163,74,.1);color:#16a34a;border-color:rgba(22,163,74,.28)}
.flow span.now{background:rgba(14,168,201,.12);color:#0b8aa6;border-color:rgba(14,168,201,.4)}
.flow i{color:#cbd5e3;font-style:normal}
.spec{display:flex;flex-wrap:wrap;gap:8px;padding:8px 8px 12px;background:#fafbfd}
.spec label{font-size:10.5px;color:#5a6b82;display:flex;flex-direction:column;gap:3px;min-width:110px;flex:1}
.spec input,.spec select{padding:6px 8px;border:1px solid #cbd5e3;border-radius:8px;font-size:12px;font-family:inherit}
@media(max-width:700px){.xtabs a{padding:8px 11px;font-size:11px}.xhead{padding:12px 14px}}
/* on a phone the page keeps the phone\'s width: a wide table scrolls inside
   its own box instead of stretching the whole page (import pages only) */
@media(max-width:900px){.app{grid-template-columns:minmax(0,1fr)!important}main.content{min-width:0;max-width:100vw}}
</style>';
}

/* The header and tabs every import page shows: reference, supplier, value,
   payable, stage — the same strip as an export shipment. */
function imp_tab_strip(array $imp, string $active): void
{
    $id = (int)$imp['id'];
    $lines = imp_lines($id);
    $sum = imp_paid_summary($imp, $lines);
    $log = imp_logistics($id);
    $st = imp_stage($imp, $log);
    $tabs = [
        ['overview', 'OVERVIEW', 'imp_view.php', imp_can('imp')],
        ['invoice', 'INVOICE', 'imp_invoice.php', imp_can('imp')],
        ['packing', 'PACKING', 'imp_packing.php', imp_can('imp')],
        ['logistics', 'LOGISTICS', 'imp_logistics.php', imp_can('imp')],
        ['payments', 'PAYMENTS', 'imp_payments.php', imp_can('payments')],
        ['costs', 'COSTS', 'imp_costs.php', imp_can('costs')],
        ['docs', 'DOCS', 'imp_documents.php', imp_can('documents')],
        ['customs', 'CUSTOMS', 'imp_phase.php', imp_can('imp'), 'P2', '&tab=customs'],
        ['gatein', 'GATE IN', 'imp_phase.php', imp_can('imp'), 'P2', '&tab=gatein'],
        ['efs', 'EFS', 'imp_efs.php', imp_can('efs'), 'P3'],
        ['timeline', 'TIMELINE', 'imp_timeline.php', imp_can('imp')],
    ];
    ?>
    <div class="xhead">
      <div>
        <div class="t">Import <?= e($imp['ref']) ?><?= (int)$imp['is_opening'] === 1 ? ' <span class="xpill n" style="vertical-align:middle">opening</span>' : '' ?></div>
        <div class="s"><?= e($imp['supplier_name']) ?><?= !empty($imp['shipped_from']) ? ' &middot; ' . e($imp['shipped_from']) : '' ?><?= !empty($log['pod']) ? ' → ' . e($log['pod']) : '' ?>
          &middot; <?= e(money_fmt($sum['total'], $sum['currency'])) ?>
          <?php if ($sum['balance'] > 0.0049): ?> &middot; Payable <?= e(money_fmt($sum['balance'], $sum['currency'])) ?><?php endif; ?></div>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <span class="xpill <?= $imp['import_type'] === 'efs' ? 'v' : 'n' ?>"><?= $imp['import_type'] === 'efs' ? 'EFS' : 'Ordinary' ?></span>
        <span class="xpill <?= e($st['pill']) ?>"><?= e($st['label']) ?></span>
        <span class="xpill <?= $sum['status'] === 'PAID' ? 'g' : ($sum['status'] === 'UNPAID' ? 'r' : 'o') ?>"><?= e($sum['status']) ?></span>
      </div>
    </div>
    <div class="xtabs">
      <?php foreach ($tabs as $t): if (!$t[3]) continue; ?>
        <a class="<?= $t[0] === $active ? 'on' : '' ?>" href="<?= e($t[2] . '?id=' . $id . ($t[5] ?? '')) ?>"><?= e($t[1]) ?><?= !empty($t[4]) ? '<span class="ph">' . e($t[4]) . '</span>' : '' ?></a>
      <?php endforeach; ?>
    </div>
    <?php
}

/* ========================================================== form helpers */
function imp_input(string $label, string $name, $value, string $type = 'text', string $extra = ''): string
{
    $cls = $type === 'number' ? 'xin num' : 'xin';
    $t = $type === 'number' ? 'text" inputmode="decimal' : $type;
    return '<label class="xlabel">' . $label . '<input class="' . $cls . '" type="' . $t . '" name="' . e($name) . '" value="' . e((string)($value ?? '')) . '"' . $extra . '></label>';
}
function imp_select(string $label, string $name, array $options, $value, string $extra = '', bool $assoc = false): string
{
    $h = '<label class="xlabel">' . $label . '<select class="xin" name="' . e($name) . '"' . $extra . '>';
    foreach ($options as $k => $v) {
        $key = $assoc ? (string)$k : (string)$v;
        $h .= '<option value="' . e($key) . '"' . ((string)$value === $key ? ' selected' : '') . '>' . e((string)$v) . '</option>';
    }
    return $h . '</select></label>';
}
/* Suppliers are the inventory Parties (supplier or both) — one list for the
   whole app, so the import supplier is the same party Gate Inward uses. */
function imp_suppliers(): array
{
    $out = [];
    if (!function_exists('inv_parties')) return $out;
    foreach (inv_parties('', true) as $p) if (in_array($p['party_type'], ['supplier', 'both'], true)) $out[(int)$p['id']] = (string)$p['name'];
    return $out;
}
const IMP_CURRENCIES = ['USD', 'EUR', 'GBP', 'CNY', 'AED', 'PKR'];
