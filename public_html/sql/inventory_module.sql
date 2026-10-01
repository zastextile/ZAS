-- =====================================================================
-- ZAS TEXTILE — Inventory / Store add-on
-- Reference SQL patch, stage 1.
--
-- YOU DO NOT NEED TO RUN THIS. Opening inv_setup.php and pressing
-- "Install inventory schema" creates everything below automatically,
-- the same self-healing way the rest of the application manages its
-- schema. This file exists so you (or a DBA) can read exactly what the
-- module adds, and apply it by hand if you prefer.
--
-- SAFETY
--   * Every statement is additive. Nothing is dropped, renamed or retyped.
--   * No existing table is modified except `users`, which gains seven
--     permission columns, all defaulting to 0 — so no existing user
--     gains any access on the day this is applied.
--   * No row in shipments, proformas, costings, products, packing or
--     production tables is read, written or altered by this patch.
--   * Every new table is prefixed inv_ . Dropping those tables removes
--     the module completely and leaves the original application intact.
-- =====================================================================

-- ---------------------------------------------------------- masters ---
CREATE TABLE IF NOT EXISTS inv_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(120) NOT NULL,
  kind ENUM('store','floor','fg','jobworker','custody','other') NOT NULL DEFAULT 'store',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_loc_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_parties (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Materials only: fabric, accessories, packing.
-- Finished products are NOT duplicated here — they stay in `products`.
CREATE TABLE IF NOT EXISTS inv_materials (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------- contracts ---
-- One register for all four kinds. jobwork_out = we send our material to a
-- processor. jobwork_in = a customer sends us their material to process.
CREATE TABLE IF NOT EXISTS inv_contracts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_contract_items (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------- gate documents ---
-- Inward and outward share one header table, separated by `direction`,
-- because every field is identical and one register searches better.
CREATE TABLE IF NOT EXISTS inv_gate (
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
  INDEX(direction, gate_date), INDEX(status), INDEX(contract_id),
  INDEX(proforma_id), INDEX(party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_gate_items (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------- store issue and return ---
-- Location movement only. Never a cost or ownership change.
CREATE TABLE IF NOT EXISTS inv_store_move (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_store_move_items (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------- conversion ---
-- The ONLY document that creates finished-product stock.
CREATE TABLE IF NOT EXISTS inv_consumption (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_consumption_items (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------ stock ledger ---
-- Single source of truth. One row per movement. Balances are always
-- computed from this, so no cached figure can ever disagree with it.
-- ownership='customer' rows carry quantity but value_amount stays 0.
CREATE TABLE IF NOT EXISTS inv_stock_ledger (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------- job work charges ---
-- receivable = we processed a customer's material and bill them.
-- payable    = a processor worked on our material and bills us.
CREATE TABLE IF NOT EXISTS inv_jobwork_charges (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------- allocation ---
-- A promise against stock. Never reduces physical quantity.
CREATE TABLE IF NOT EXISTS inv_allocations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------ order charges ---
-- Freight, inspection, commission etc. for Order Costing Control.
CREATE TABLE IF NOT EXISTS inv_order_charges (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------- settings ---
-- Document prefixes, the owner's own stage names, and module defaults.
CREATE TABLE IF NOT EXISTS inv_settings (
  skey VARCHAR(60) PRIMARY KEY,
  sval VARCHAR(300) NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------- user permissions ---
-- All default to 0: nobody gains access when this is applied. Admin is
-- always allowed in code and needs none of these set.
-- MySQL has no "ADD COLUMN IF NOT EXISTS" before 8.0.29, so re-running a
-- line that already exists simply errors and can be ignored — which is
-- exactly what the PHP installer does.
ALTER TABLE users ADD COLUMN inv_view    TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN inv_gate    TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN inv_store   TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN inv_consume TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN inv_post    TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN inv_adjust  TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN inv_master  TINYINT(1) NOT NULL DEFAULT 0;

-- ------------------------------------------------------- seed values ---
INSERT IGNORE INTO inv_locations (code,name,kind) VALUES
  ('MAIN','Main Store','store'),
  ('FLOOR','Production Floor','floor'),
  ('FG','Finished Goods Store','fg'),
  ('JOBWK','At Job Worker','jobworker'),
  ('CUSTODY','Customer Material Custody','custody');

INSERT IGNORE INTO inv_settings (skey,sval,updated_at) VALUES
  ('prefix_gate_in','GIP',NOW()),  ('prefix_gate_out','GOP',NOW()),
  ('prefix_issue','ISS',NOW()),    ('prefix_return','RET',NOW()),
  ('prefix_consumption','CON',NOW()), ('prefix_contract_pur','PC',NOW()),
  ('prefix_contract_sal','SC',NOW()), ('prefix_contract_jw','JW',NOW()),
  ('prefix_jobwork_bill','JWB',NOW()), ('prefix_alloc','ALC',NOW()),
  ('stage1_label','Cutting',NOW()), ('stage2_label','Stitching',NOW()),
  ('stage3_label','Dispatch',NOW()),
  ('default_location','1',NOW()),  ('backdate_days','7',NOW());

-- =====================================================================
-- TO REMOVE THE MODULE COMPLETELY (the application returns to exactly
-- how it was; no original data is involved):
--
--   DROP TABLE IF EXISTS inv_stock_ledger, inv_consumption_items,
--     inv_consumption, inv_store_move_items, inv_store_move,
--     inv_gate_items, inv_gate, inv_contract_items, inv_contracts,
--     inv_jobwork_charges, inv_allocations, inv_order_charges,
--     inv_materials, inv_parties, inv_locations, inv_settings;
--
--   The seven users.inv_* columns may be left in place harmlessly, or
--   dropped one at a time with ALTER TABLE users DROP COLUMN inv_view;
-- =====================================================================
