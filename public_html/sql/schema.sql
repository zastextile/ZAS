CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','colleague','staff') NOT NULL DEFAULT 'colleague',
  department VARCHAR(60) NULL,
  can_see_rates TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shipments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_no VARCHAR(100) NOT NULL,
  invoice_date DATE NULL,
  buyer_name VARCHAR(255) NOT NULL,
  buyer_address TEXT NULL,
  buyer_country VARCHAR(120) NULL,
  destination_port VARCHAR(190) NULL,
  po_no VARCHAR(120) NULL,
  bl_container_no VARCHAR(190) NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'USD',
  payment_terms VARCHAR(120) NULL,
  optional_column_enabled TINYINT(1) NOT NULL DEFAULT 0,
  optional_column_title VARCHAR(120) NULL,
  status ENUM('draft','submitted','approved_locked') NOT NULL DEFAULT 'draft',
  revision_no INT NOT NULL DEFAULT 1,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  total_packages DECIMAL(14,3) NOT NULL DEFAULT 0,
  total_net_weight DECIMAL(14,3) NOT NULL DEFAULT 0,
  total_gross_weight DECIMAL(14,3) NOT NULL DEFAULT 0,
  created_by INT NULL,
  updated_by INT NULL,
  approved_by INT NULL,
  approved_at DATETIME NULL,
  locked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX(invoice_no),
  INDEX(buyer_name),
  INDEX(buyer_country),
  INDEX(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shipment_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shipment_id INT NOT NULL,
  line_no INT NOT NULL,
  product_name VARCHAR(255) NOT NULL,
  des_col TEXT NULL,
  optional_value VARCHAR(255) NULL,
  department VARCHAR(60) NULL,
  qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  unit VARCHAR(40) NOT NULL DEFAULT 'Pcs',
  rate DECIMAL(14,4) NOT NULL DEFAULT 0,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
  INDEX(shipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shipment_charges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shipment_id INT NOT NULL,
  charge_name VARCHAR(190) NOT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS packing_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shipment_id INT NOT NULL,
  line_no INT NOT NULL,
  product_name VARCHAR(255) NOT NULL,
  des_col TEXT NULL,
  optional_value VARCHAR(255) NULL,
  carton_from INT NOT NULL DEFAULT 0,
  carton_to INT NOT NULL DEFAULT 0,
  packages DECIMAL(14,3) NOT NULL DEFAULT 0,
  qty_per_carton DECIMAL(14,3) NOT NULL DEFAULT 0,
  total_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  net_weight DECIMAL(14,3) NOT NULL DEFAULT 0,
  gross_weight DECIMAL(14,3) NOT NULL DEFAULT 0,
  FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
  INDEX(shipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shipment_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shipment_id INT NOT NULL,
  user_id INT NOT NULL,
  assigned_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_assignment (shipment_id, user_id),
  FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shipment_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shipment_id INT NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NULL,
  file_size INT NULL,
  uploaded_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shipment_embeddings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shipment_id INT NOT NULL,
  chunk_type VARCHAR(60) NOT NULL,
  chunk_text MEDIUMTEXT NOT NULL,
  vector_json MEDIUMTEXT NOT NULL,
  model VARCHAR(80) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
  INDEX(shipment_id),
  INDEX(is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shipment_id INT NULL,
  user_id INT NULL,
  user_name VARCHAR(150) NULL,
  user_role VARCHAR(50) NULL,
  section_changed VARCHAR(100) NOT NULL,
  field_changed VARCHAR(120) NOT NULL,
  old_value MEDIUMTEXT NULL,
  new_value MEDIUMTEXT NULL,
  change_reason TEXT NULL,
  ip_address VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(shipment_id),
  INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
