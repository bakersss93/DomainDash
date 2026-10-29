
-- Core tables (subset; see migrations for details)
CREATE TABLE clients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_name VARCHAR(255) NOT NULL,
  abn VARCHAR(64) NULL,
  halopsa_reference VARCHAR(128) NULL,
  itglue_org_id VARCHAR(64) NULL,
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);

CREATE TABLE client_user (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);

CREATE TABLE domains (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NULL,
  name VARCHAR(255) UNIQUE NOT NULL,
  status VARCHAR(64) NULL,
  expiry_date DATE NULL,
  auto_renew TINYINT(1) DEFAULT 0,
  name_servers JSON NULL,
  dns_config INT NULL,
  registry_id VARCHAR(128) NULL,
  transfer_status VARCHAR(64) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);

CREATE TABLE dns_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  domain_id BIGINT UNSIGNED NOT NULL,
  record_id VARCHAR(64) NULL,
  host VARCHAR(255) NOT NULL,
  type VARCHAR(16) NOT NULL,
  content TEXT NOT NULL,
  ttl INT DEFAULT 3600,
  prio INT DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);

CREATE TABLE hosting_services (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NULL,
  domain_id BIGINT UNSIGNED NULL,
  hoid VARCHAR(64) NULL,
  plan VARCHAR(128) NULL,
  username VARCHAR(64) NULL,
  server VARCHAR(128) NULL,
  disk_limit_mb INT NULL,
  disk_usage_mb INT NULL,
  bandwidth_limit_mb INT NULL,
  bandwidth_used_mb INT NULL,
  ip_address VARCHAR(64) NULL,
  next_renewal_due DATE NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);

CREATE TABLE ssl_certificates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NULL,
  domain_id BIGINT UNSIGNED NULL,
  cert_id VARCHAR(64) NULL,
  common_name VARCHAR(255) NULL,
  product_name VARCHAR(255) NULL,
  start_date DATE NULL,
  expire_date DATE NULL,
  status VARCHAR(64) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);

CREATE TABLE settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(191) UNIQUE NOT NULL,
  `value` LONGTEXT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);

CREATE TABLE api_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL,
  key_hash VARCHAR(255) NOT NULL,
  allowed_ips TEXT NULL,
  rate_limit_per_hour INT DEFAULT 360,
  scopes JSON NULL,
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);

CREATE TABLE api_access_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  api_key_id BIGINT UNSIGNED NULL,
  ip VARCHAR(64) NULL,
  method VARCHAR(8) NULL,
  path VARCHAR(255) NULL,
  status SMALLINT NULL,
  user_agent VARCHAR(255) NULL,
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
