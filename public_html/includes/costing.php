<?php
/* Shared helpers for the Costing + Proforma modules.
   Included by product_costing.php, costing_import.php, costing_print.php,
   proforma.php, proforma_print.php. */

/* ---- Colleague permission gate (Admin always full; Staff never) ---- */
function costing_perm(string $what): bool {
    $u = current_user();
    if (!$u) return false;
    if ($u['role'] === 'admin') return true;
    if ($u['role'] !== 'colleague') return false;
    return (int)($u['cost_' . $what] ?? 0) === 1;
}
function require_costing(string $what): void {
    if (!costing_perm($what)) { http_response_code(403); exit('You do not have permission for this costing action.'); }
}

/* ---- schema for costing extensions + proforma ---- */
function costing_ensure_schema(): void {
    // costing extension columns (ignore if present)
    $cols = [
        "ALTER TABLE costing_versions ADD COLUMN costing_no VARCHAR(40) NULL",
        "ALTER TABLE costing_versions ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'draft'",
        "ALTER TABLE costing_versions ADD COLUMN revision_no INT NOT NULL DEFAULT 1",
        "ALTER TABLE costing_versions ADD COLUMN revision_reason VARCHAR(300) NULL",
        "ALTER TABLE costing_versions ADD COLUMN remarks TEXT NULL",
        "ALTER TABLE costing_versions ADD COLUMN suggested_price DECIMAL(14,4) NULL DEFAULT 0",
        "ALTER TABLE proforma_invoices ADD COLUMN bank1_details TEXT NULL",
        "ALTER TABLE proforma_invoices ADD COLUMN bank2_details TEXT NULL",
        "ALTER TABLE proforma_invoices ADD COLUMN bank_choice VARCHAR(4) NOT NULL DEFAULT '1'",
        "ALTER TABLE proforma_invoices ADD COLUMN converted_shipment_id INT NULL",
        // Standard Order Acceptance / Terms footer on print — on by default,
        // but some customers don't need to see it, so it's a per-proforma
        // checkbox rather than always-on.
        "ALTER TABLE proforma_invoices ADD COLUMN show_pfooter TINYINT(1) NOT NULL DEFAULT 1",
        // Free text, not an actual date — e.g. "45 days after advance
        // received", not just a calendar date. Was briefly a DATE column;
        // this MODIFY converts any already-saved column to text (existing
        // dates just become their plain string form, nothing is lost).
        "ALTER TABLE proforma_invoices ADD COLUMN delivery_date VARCHAR(120) NULL",
        "ALTER TABLE proforma_invoices MODIFY COLUMN delivery_date VARCHAR(120) NULL",
        // Structured bank fields replace the old single free-text box — a
        // pasted SWIFT code or IBAN with odd spacing used to print exactly
        // as typed; each piece now has its own field so it always prints
        // clean. bank1_details/bank2_details above are kept as a read-only
        // fallback for proformas that already have text saved there.
        "ALTER TABLE proforma_invoices ADD COLUMN bank1_name VARCHAR(160) NULL",
        "ALTER TABLE proforma_invoices ADD COLUMN bank1_branch VARCHAR(160) NULL",
        "ALTER TABLE proforma_invoices ADD COLUMN bank1_title VARCHAR(160) NULL",
        "ALTER TABLE proforma_invoices ADD COLUMN bank1_swift VARCHAR(40) NULL",
        "ALTER TABLE proforma_invoices ADD COLUMN bank1_iban VARCHAR(80) NULL",
        "ALTER TABLE proforma_invoices ADD COLUMN bank2_name VARCHAR(160) NULL",
        "ALTER TABLE proforma_invoices ADD COLUMN bank2_branch VARCHAR(160) NULL",
        "ALTER TABLE proforma_invoices ADD COLUMN bank2_title VARCHAR(160) NULL",
        "ALTER TABLE proforma_invoices ADD COLUMN bank2_swift VARCHAR(40) NULL",
        "ALTER TABLE proforma_invoices ADD COLUMN bank2_iban VARCHAR(80) NULL",
        "ALTER TABLE products ADD COLUMN product_code VARCHAR(60) NULL",
        "ALTER TABLE users ADD COLUMN can_manage_products TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN cost_view TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN cost_create TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN cost_edit TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN cost_import TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN cost_print TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN cost_proforma TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN cost_delete TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($cols as $sql) { try { db()->exec($sql); } catch (Throwable $e) {} }

    try { db()->exec("CREATE TABLE IF NOT EXISTS proforma_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pi_no VARCHAR(40) NOT NULL,
        pi_date DATE NULL,
        costing_version_id INT NULL,
        costing_revision INT NULL,
        product_id INT NULL,
        product_code VARCHAR(60) NULL,
        customer_name VARCHAR(200) NULL,
        customer_address TEXT NULL,
        currency VARCHAR(8) NULL DEFAULT 'USD',
        payment_terms VARCHAR(200) NULL,
        delivery_terms VARCHAR(200) NULL,
        shipment_terms VARCHAR(200) NULL,
        packing_details TEXT NULL,
        validity VARCHAR(120) NULL,
        remarks TEXT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'draft',
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        INDEX(costing_version_id), INDEX(product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    try { db()->exec("CREATE TABLE IF NOT EXISTS proforma_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proforma_id INT NOT NULL,
        product_name VARCHAR(200) NULL,
        description VARCHAR(500) NULL,
        size VARCHAR(80) NULL,
        qty DECIMAL(14,3) NOT NULL DEFAULT 0,
        unit VARCHAR(40) NULL,
        unit_price DECIMAL(16,4) NOT NULL DEFAULT 0,
        amount DECIMAL(16,2) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        INDEX(proforma_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    // Company-wide "last used" bank details — a single shared row, not
    // per-proforma. Every NEW proforma is pre-filled from this on creation,
    // and saving bank fields on ANY proforma updates it, so the next new
    // one starts with whatever was typed most recently instead of blank.
    // A specific proforma can still be edited to use different details for
    // that one order — this is only the starting point for new ones.
    try { db()->exec("CREATE TABLE IF NOT EXISTS company_bank_defaults (
        id TINYINT PRIMARY KEY,
        bank1_name VARCHAR(160) NULL, bank1_branch VARCHAR(160) NULL, bank1_title VARCHAR(160) NULL, bank1_swift VARCHAR(40) NULL, bank1_iban VARCHAR(80) NULL,
        bank2_name VARCHAR(160) NULL, bank2_branch VARCHAR(160) NULL, bank2_title VARCHAR(160) NULL, bank2_swift VARCHAR(40) NULL, bank2_iban VARCHAR(80) NULL,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    try {
        if (!db()->query("SELECT COUNT(*) FROM company_bank_defaults WHERE id=1")->fetchColumn()) {
            db()->prepare("INSERT INTO company_bank_defaults (id,bank1_name,bank1_branch,bank1_title,bank1_swift,bank1_iban,bank2_name,bank2_branch,bank2_title,bank2_swift,bank2_iban) VALUES (1,?,?,?,?,?,?,?,?,?,?)")
                ->execute(['Meezan Bank Limited','Faisalabad, Pakistan','ZAS Textile','MEZNPKKA','PK00 MEZN 0000 0000 0000 0000','Habib Bank Limited (HBL)','Faisalabad, Pakistan','ZAS Textile','HABBPKKA','PK00 HABB 0000 0000 0000 0000']);
        }
    } catch (Throwable $e) {}
}

const COMPANY_BANK_FIELDS = ['bank1_name','bank1_branch','bank1_title','bank1_swift','bank1_iban','bank2_name','bank2_branch','bank2_title','bank2_swift','bank2_iban'];

/* Current shared bank defaults — used to pre-fill a brand new proforma and
   as the print fallback for a proforma whose own fields are empty. */
function company_bank_defaults(): array {
    try {
        $row = db()->query("SELECT * FROM company_bank_defaults WHERE id=1")->fetch();
        if ($row) return $row;
    } catch (Throwable $e) {}
    return array_fill_keys(COMPANY_BANK_FIELDS, '');
}

/* Called on every proforma save with that proforma's 10 bank fields.
   Only overwrites a field in the shared default when this save actually
   has something in it — leaving a field blank on one proforma never
   erases the shared default for everyone else. */
function company_bank_defaults_merge(array $submitted): void {
    $current = company_bank_defaults();
    $merged = [];
    foreach (COMPANY_BANK_FIELDS as $k) { $merged[$k] = (($submitted[$k] ?? '') !== '') ? $submitted[$k] : ($current[$k] ?? ''); }
    try {
        db()->prepare("UPDATE company_bank_defaults SET bank1_name=?,bank1_branch=?,bank1_title=?,bank1_swift=?,bank1_iban=?,bank2_name=?,bank2_branch=?,bank2_title=?,bank2_swift=?,bank2_iban=?,updated_at=NOW() WHERE id=1")
            ->execute(array_values($merged));
    } catch (Throwable $e) {}
}

/* ---- shared A4 print chrome (matches invoice/packing prints) ---- */
function costing_print_head(string $title): void {
    // Same company identity as invoice_print.php's rpt_company_header().
    // config('app_name') is an internal label only ("invoice") — never meant
    // to appear on a customer-facing document — so it's hardcoded here
    // instead of read from config, same as the real Commercial Invoice print.
    $company = 'ZAS TEXTILE';
    $companyTag = 'Textile Exports';
    echo '<!doctype html><html><head><meta charset="utf-8"><title>' . e($title) . '</title><style>
    @page{size:A4;margin:10mm}
    *{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact}
    body{font:12px/1.35 "Segoe UI",Arial,sans-serif;color:#152033;margin:0;background:#fff}
    .doc{max-width:800px;margin:0 auto;padding:6px}
    .ppage{page-break-after:always;break-after:page}
    .ppage:last-of-type{page-break-after:auto;break-after:auto}
    tr{page-break-inside:avoid;break-inside:avoid}
    .head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #0b2a4a;padding-bottom:8px;margin-bottom:10px}
    .head.cont{padding-bottom:6px;margin-bottom:8px}
    .brand{font-size:22px;font-weight:800;color:#0b2a4a;letter-spacing:.5px}
    .brand.sm{font-size:15px}
    .brand small{display:block;font-size:10px;font-weight:600;color:#b8891f;letter-spacing:2px;text-transform:uppercase}
    .brand .addr{display:block;font-size:9px;font-weight:500;color:#5a6b82;letter-spacing:0;text-transform:none;margin-top:3px;max-width:260px}
    .docttl{text-align:right}.docttl h1{margin:0;font-size:19px;color:#0b2a4a;text-transform:uppercase;letter-spacing:1px}
    .docttl .cont-note{font-size:10px;color:#8a97ab;font-style:italic;margin-top:2px}
    .meta{text-align:right;font-size:11px;color:#5a6b82;margin-top:4px}
    .row{display:flex;gap:20px;margin-bottom:9px}.row>div{flex:1}
    .lbl{font-size:9.5px;text-transform:uppercase;letter-spacing:.06em;color:#8a97ab;font-weight:700}
    .val{font-size:12.5px;color:#152033;margin-top:2px}
    .bgrid{display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;margin-top:3px}
    table{width:100%;border-collapse:collapse;margin:6px 0}
    th{background:#0b2a4a;color:#fff;font-size:10px;text-transform:uppercase;letter-spacing:.04em;text-align:left;padding:5px 8px}
    td{padding:3px 8px;border-bottom:1px solid #e3e9f0;font-size:11.5px}
    .num{text-align:right}
    .tot td{border-top:2px solid #0b2a4a;font-weight:800;font-size:12.5px}
    .fwd td{background:rgba(11,42,74,.06);border-top:2px solid #0b2a4a;border-bottom:2px solid #0b2a4a;font-weight:800;color:#0b2a4a}
    .sect{font-size:11px;font-weight:800;color:#0b2a4a;text-transform:uppercase;letter-spacing:.05em;margin:10px 0 4px;border-left:3px solid #b8891f;padding-left:8px}
    .remarks{margin-top:9px;padding:7px 12px;background:#f6f8fb;border:1px solid #e3e9f0;border-radius:6px;font-size:11px;color:#3a4a60}
    .sign{display:flex;justify-content:space-between;margin-top:18px;font-size:11px}
    .sign div{width:40%;border-top:1px solid #98a6b8;padding-top:6px;text-align:center;color:#5a6b82}
    .sigimg{position:absolute;bottom:100%;left:50%;transform:translateX(-50%);max-height:42px;max-width:150px;margin-bottom:2px}
    .pfooter{margin-top:16px;padding-top:10px;border-top:1px solid #e3e9f0;font-size:8.3px;line-height:1.6;color:#8a97ab}
    .pfooter p{margin:0 0 6px}
    .pfooter ol{margin:0;padding-left:15px}
    .pfooter li{margin-bottom:5px}
    .pfooter b{color:#5a6b82;font-weight:700}
    .noprint{margin:14px auto;max-width:800px;text-align:right}
    .btn{background:#0b2a4a;color:#fff;border:0;border-radius:6px;padding:9px 18px;font-weight:700;cursor:pointer;text-decoration:none}
    @media print{.noprint{display:none}}
    </style></head><body>';
    echo '<div class="noprint"><a class="btn" href="javascript:window.print()">Print / Save PDF</a></div>';
    echo '<div class="doc"><div class="head"><div class="brand">' . e($company) . '<small>' . e($companyTag) . '</small><span class="addr">Faisalabad, Pakistan · WhatsApp +92 300 8663721<br>info@zastextiles.com · zastextiles.com</span></div>';
    echo '<div class="docttl"><h1>' . e($title) . '</h1></div></div>';
}
function costing_print_foot(): void { echo '</div></body></html>'; }

function next_doc_no(string $prefix): string {
    return $prefix . '-' . date('ymd') . '-' . substr((string)random_int(100, 999), 0, 3);
}
