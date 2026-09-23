CREATE TABLE roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  is_system_role TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(150) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  force_password_change TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_users_email (email), KEY idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_roles (
  user_id BIGINT UNSIGNED NOT NULL, role_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id INT UNSIGNED NOT NULL, permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE geography_datasets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dataset_name VARCHAR(150) NOT NULL,
  version VARCHAR(32) NOT NULL,
  reference_date DATE NOT NULL,
  source_name VARCHAR(255) NOT NULL,
  source_file VARCHAR(255) NOT NULL,
  source_sha256 CHAR(64) NOT NULL,
  crs_epsg INT UNSIGNED NOT NULL,
  identifier_scheme VARCHAR(32) NOT NULL,
  expected_entities SMALLINT UNSIGNED NOT NULL,
  imported_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_geography_dataset_version (dataset_name, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE municipalities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ekatte_code VARCHAR(10) NOT NULL,
  name VARCHAR(150) NOT NULL,
  name_latin VARCHAR(150) NULL,
  district_code VARCHAR(10) NULL,
  nuts3_2024 VARCHAR(10) NULL,
  nuts3_2027 VARCHAR(10) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_municipalities_ekatte (ekatte_code), KEY idx_municipalities_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE municipality_boundaries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  municipality_id INT UNSIGNED NOT NULL,
  geography_dataset_id INT UNSIGNED NOT NULL,
  geometry MULTIPOLYGON NOT NULL,
  source_feature_identifier VARCHAR(10) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_boundary_dataset_municipality (geography_dataset_id, municipality_id),
  KEY idx_boundary_municipality (municipality_id),
  SPATIAL INDEX spx_boundary_geometry (geometry),
  CONSTRAINT fk_boundary_municipality FOREIGN KEY (municipality_id) REFERENCES municipalities(id) ON DELETE RESTRICT,
  CONSTRAINT fk_boundary_dataset FOREIGN KEY (geography_dataset_id) REFERENCES geography_datasets(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE signal_statuses (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(100) NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL,
  is_terminal TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_signal_statuses_code (code), UNIQUE KEY uq_signal_statuses_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE signal_categories (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_signal_categories_code (code), KEY idx_signal_categories_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE municipality_contacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  municipality_id INT UNSIGNED NOT NULL,
  signal_category_id SMALLINT UNSIGNED NULL,
  contact_type ENUM('signals','registry','official','fallback') NOT NULL,
  contact_name VARCHAR(150) NULL,
  email VARCHAR(255) NOT NULL,
  priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  verified_at DATETIME NULL,
  last_verified_at DATETIME NULL,
  source_url VARCHAR(2048) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  KEY idx_contacts_selection (municipality_id, signal_category_id, contact_type, is_active, priority),
  CONSTRAINT fk_contact_municipality FOREIGN KEY (municipality_id) REFERENCES municipalities(id) ON DELETE RESTRICT,
  CONSTRAINT fk_contact_category FOREIGN KEY (signal_category_id) REFERENCES signal_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE signals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_reference CHAR(26) NOT NULL,
  reporter_email VARCHAR(255) NOT NULL,
  signal_status_id SMALLINT UNSIGNED NOT NULL,
  signal_category_id SMALLINT UNSIGNED NULL,
  municipality_id INT UNSIGNED NULL,
  municipality_contact_id BIGINT UNSIGNED NULL,
  municipality_name_snapshot VARCHAR(150) NULL,
  recipient_email_snapshot VARCHAR(255) NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  gps_source VARCHAR(32) NOT NULL DEFAULT 'exif_original',
  gps_accuracy DECIMAL(10,2) NULL,
  captured_at_local DATETIME NOT NULL,
  captured_timezone_unknown TINYINT(1) NOT NULL DEFAULT 1,
  submitted_at DATETIME NOT NULL,
  description TEXT NULL,
  email_validated_at DATETIME NULL,
  dispatch_prepared_at DATETIME NULL,
  dispatched_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_signals_public_reference (public_reference),
  KEY idx_signals_status_created (signal_status_id, created_at),
  KEY idx_signals_municipality_created (municipality_id, created_at),
  KEY idx_signals_category_created (signal_category_id, created_at),
  KEY idx_signals_submission (submitted_at),
  CONSTRAINT fk_signal_status FOREIGN KEY (signal_status_id) REFERENCES signal_statuses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_signal_category FOREIGN KEY (signal_category_id) REFERENCES signal_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_signal_municipality FOREIGN KEY (municipality_id) REFERENCES municipalities(id) ON DELETE RESTRICT,
  CONSTRAINT fk_signal_contact FOREIGN KEY (municipality_contact_id) REFERENCES municipality_contacts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE signal_photos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  signal_id BIGINT UNSIGNED NOT NULL,
  storage_key VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  sha256_hash CHAR(64) NOT NULL,
  width INT UNSIGNED NOT NULL,
  height INT UNSIGNED NOT NULL,
  exif_datetime_original VARCHAR(32) NOT NULL,
  exif_metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_signal_photos_signal (signal_id), UNIQUE KEY uq_signal_photos_storage_key (storage_key),
  KEY idx_signal_photos_hash (sha256_hash),
  CONSTRAINT fk_photo_signal FOREIGN KEY (signal_id) REFERENCES signals(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE signal_email_validations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  signal_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  token_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  issued_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  resend_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_sent_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_validation_signal_version (signal_id, token_version),
  UNIQUE KEY uq_validation_token_hash (token_hash), KEY idx_validation_expiry (expires_at, consumed_at),
  CONSTRAINT fk_validation_signal FOREIGN KEY (signal_id) REFERENCES signals(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE signal_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  signal_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_code VARCHAR(100) NOT NULL,
  previous_status_id SMALLINT UNSIGNED NULL,
  new_status_id SMALLINT UNSIGNED NULL,
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_events_signal_created (signal_id, created_at),
  CONSTRAINT fk_event_signal FOREIGN KEY (signal_id) REFERENCES signals(id) ON DELETE RESTRICT,
  CONSTRAINT fk_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_event_previous_status FOREIGN KEY (previous_status_id) REFERENCES signal_statuses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_event_new_status FOREIGN KEY (new_status_id) REFERENCES signal_statuses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE email_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  signal_id BIGINT UNSIGNED NOT NULL,
  municipality_contact_id BIGINT UNSIGNED NULL,
  purpose ENUM('validation','municipality_dispatch','citizen_final') NOT NULL,
  recipient_email VARCHAR(255) NOT NULL,
  recipient_hash CHAR(64) NOT NULL,
  template_code VARCHAR(100) NOT NULL,
  payload JSON NOT NULL,
  state ENUM('pending','leased','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  idempotency_key CHAR(64) NOT NULL,
  attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL,
  lease_owner VARCHAR(100) NULL,
  lease_until DATETIME NULL,
  sent_at DATETIME NULL,
  provider_message_id VARCHAR(255) NULL,
  last_error_code VARCHAR(100) NULL,
  last_error_summary VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email_idempotency (idempotency_key),
  UNIQUE KEY uq_email_logical (signal_id, purpose, recipient_hash),
  KEY idx_email_due (state, available_at), KEY idx_email_lease (lease_until),
  CONSTRAINT fk_email_signal FOREIGN KEY (signal_id) REFERENCES signals(id) ON DELETE RESTRICT,
  CONSTRAINT fk_email_contact FOREIGN KEY (municipality_contact_id) REFERENCES municipality_contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(150) NOT NULL,
  entity_type VARCHAR(100) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  status ENUM('success','failed') NOT NULL DEFAULT 'success',
  severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(1024) NULL,
  request_method VARCHAR(10) NULL,
  route VARCHAR(255) NULL,
  session_id VARCHAR(128) NULL,
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_entity_created (entity_type, entity_id, created_at), KEY idx_audit_user_created (user_id, created_at), KEY idx_audit_action_created (action, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifier_hash CHAR(64) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_identifier_created (identifier_hash, created_at), KEY idx_login_ip_created (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(150) NOT NULL,
  setting_value TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
