-- =====================================================================
-- KAMALTUR POS — Local schema (reconstructed from application code)
-- Generated: 2026-09-22 — for local development only.
-- NOTE: The production schema is NOT committed to git. This file was
-- rebuilt by reading every INSERT/SELECT/UPDATE in the codebase, so it
-- may differ in minor ways from the live database on Hostinger.
-- Engine: MariaDB/MySQL — InnoDB, utf8mb4 / utf8mb4_unicode_ci
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- agencies
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agencies (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(255) NOT NULL,
  cnpj           VARCHAR(20)  NULL,
  legal_name     VARCHAR(255) NULL,
  fantasy_name   VARCHAR(255) NULL,
  email          VARCHAR(255) NULL,
  phone          VARCHAR(40)  NULL,
  cep            VARCHAR(10)  NULL,
  street         VARCHAR(255) NULL,
  district       VARCHAR(120) NULL,
  complement     VARCHAR(255) NULL,
  number         VARCHAR(40)  NULL,
  city           VARCHAR(120) NULL,
  uf             VARCHAR(2)   NULL,
  bank_details   TEXT         NULL,
  logo_path      VARCHAR(255) NULL,
  stamp_path     VARCHAR(255) NULL,
  favicon_path   VARCHAR(255) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_agencies_cnpj (cnpj),
  INDEX idx_agencies_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(255) NOT NULL,
  email         VARCHAR(255) NOT NULL,
  login         VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          VARCHAR(30)  NOT NULL DEFAULT 'admin',
  agency_id     INT UNSIGNED NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  cpf           VARCHAR(20)  NULL,
  birth_date    DATE         NULL,
  position      VARCHAR(120) NULL,
  phone         VARCHAR(40)  NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_login (login),
  INDEX idx_users_agency (agency_id),
  INDEX idx_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- clients
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clients (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_type VARCHAR(10)  NOT NULL DEFAULT 'pf',
  name        VARCHAR(255) NOT NULL,
  document    VARCHAR(30)  NULL,
  phone       VARCHAR(40)  NULL,
  email       VARCHAR(255) NULL,
  birth_date  DATE         NULL,
  address     VARCHAR(500) NULL,
  notes       TEXT         NULL,
  employer_id INT UNSIGNED NULL,
  gender      VARCHAR(1)   NULL,
  agency_id   INT UNSIGNED NOT NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_clients_agency (agency_id),
  INDEX idx_clients_document (document),
  INDEX idx_clients_employer (employer_id),
  INDEX idx_clients_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- suppliers
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS suppliers (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_type VARCHAR(10)  NOT NULL DEFAULT 'pj',
  name          VARCHAR(255) NOT NULL,
  document      VARCHAR(30)  NOT NULL,
  phone         VARCHAR(40)  NULL,
  email         VARCHAR(255) NULL,
  address       VARCHAR(500) NULL,
  notes         TEXT         NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  agency_id     INT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_suppliers_agency (agency_id),
  INDEX idx_suppliers_document (document)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- invoices
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoices (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_number     VARCHAR(20)   NOT NULL,
  client_id          INT UNSIGNED  NOT NULL,
  supplier_id        INT UNSIGNED  NULL,
  issue_date         DATE          NOT NULL,
  status             VARCHAR(30)   NOT NULL DEFAULT 'nao pago',
  currency           VARCHAR(5)    NOT NULL DEFAULT 'BRL',
  pnr_code           VARCHAR(20)   NULL,
  travel_date        DATE          NULL,
  scope              VARCHAR(20)   NOT NULL DEFAULT 'nacional',
  passengers_total   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  supplier_tarifa    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  supplier_comissao  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  supplier_liquid    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  supplier_paid      TINYINT(1)    NOT NULL DEFAULT 0,
  service_paid       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_paid         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  margin_value       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount_paid        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  refund_rule        VARCHAR(40)   NOT NULL DEFAULT 'nao reembolsavel',
  change_rule        VARCHAR(40)   NOT NULL DEFAULT 'nao permite',
  agency_id          INT UNSIGNED  NOT NULL,
  created_by         INT UNSIGNED  NULL,
  show_signature     TINYINT(1)    NOT NULL DEFAULT 0,
  total_amount       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_invoices_agency (agency_id),
  INDEX idx_invoices_number (invoice_number),
  INDEX idx_invoices_client (client_id),
  INDEX idx_invoices_supplier (supplier_id),
  INDEX idx_invoices_issue (issue_date),
  INDEX idx_invoices_status (status),
  INDEX idx_invoices_pnr (pnr_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- invoice_trips
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoice_trips (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id           INT UNSIGNED NOT NULL,
  trip_no              INT          NOT NULL DEFAULT 1,
  pnr_code             VARCHAR(20)  NULL,
  travel_date          DATE         NULL,
  supplier_id          INT UNSIGNED NULL,
  currency             VARCHAR(5)   NULL,
  supplier_tarifa      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  supplier_comissao    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  supplier_liquid      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  supplier_pay_status  VARCHAR(20)  NULL,
  supplier_paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  refund_rule          VARCHAR(40)  NULL,
  change_rule          VARCHAR(40)  NULL,
  agency_id            INT UNSIGNED NOT NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_trips_invoice (invoice_id),
  INDEX idx_trips_agency (agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- passengers
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS passengers (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id INT UNSIGNED NOT NULL,
  name       VARCHAR(255) NOT NULL,
  ptype      VARCHAR(10)  NOT NULL DEFAULT 'ADT',
  ticket_no  VARCHAR(40)  NULL,
  value      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  agency_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  INDEX idx_passengers_invoice (invoice_id),
  INDEX idx_passengers_agency (agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- segments
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS segments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id     INT UNSIGNED NOT NULL,
  trip_id        INT UNSIGNED NULL,
  airline_code   VARCHAR(120) NULL,
  flight_no      VARCHAR(20)  NULL,
  `origin`       VARCHAR(255) NULL,
  `destination`  VARCHAR(255) NULL,
  `class`        VARCHAR(80)  NULL,
  baggage        VARCHAR(80)  NULL,
  record_locator VARCHAR(40)  NULL,
  agency_id      INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  INDEX idx_segments_invoice (invoice_id),
  INDEX idx_segments_agency (agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- aux_services
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS aux_services (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id    INT UNSIGNED NOT NULL,
  service_type  VARCHAR(50)  NOT NULL DEFAULT 'other',
  supplier_id   INT UNSIGNED NULL,
  code          VARCHAR(50)  NULL,
  service       VARCHAR(255) NULL,
  start_date    DATE         NULL,
  end_date      DATE         NULL,
  value         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  cost_value    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  supplier_paid TINYINT(1)   NOT NULL DEFAULT 0,
  details       TEXT         NULL,
  agency_id     INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  INDEX idx_aux_invoice (invoice_id),
  INDEX idx_aux_agency (agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- invoice_counters
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoice_counters (
  year      INT          NOT NULL,
  agency_id INT UNSIGNED NOT NULL DEFAULT 0,
  `last`    INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (year, agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- service_sales
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS service_sales (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  agency_id    INT UNSIGNED NOT NULL,
  client_id    INT UNSIGNED NOT NULL,
  supplier_id  INT UNSIGNED NULL,
  created_by   INT UNSIGNED NULL,
  service_type VARCHAR(50)  NOT NULL,
  reference    VARCHAR(120) NULL,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  cost_amount  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency     VARCHAR(5)   NOT NULL DEFAULT 'BRL',
  status       VARCHAR(30)  NOT NULL DEFAULT '',
  notes        TEXT         NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_ss_agency (agency_id),
  INDEX idx_ss_client (client_id),
  INDEX idx_ss_type (service_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- service_hotels
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS service_hotels (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id     INT UNSIGNED NOT NULL,
  hotel_name     VARCHAR(255) NULL,
  hotel_address  VARCHAR(255) NULL,
  stars          VARCHAR(20)  NULL,
  checkin        DATE         NULL,
  checkout       DATE         NULL,
  nights         INT          NULL,
  rooms          VARCHAR(50)  NULL,
  room_type      VARCHAR(120) NULL,
  meal           VARCHAR(120) NULL,
  cancel_policy  TEXT         NULL,
  image          VARCHAR(255) NULL,
  agency_id      INT UNSIGNED NULL,
  PRIMARY KEY (id),
  INDEX idx_hotel_service (service_id),
  INDEX idx_hotel_agency (agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- service_hotel_rooms
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS service_hotel_rooms (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id INT UNSIGNED NOT NULL,
  room_type  VARCHAR(120) NULL,
  guests     VARCHAR(255) NULL,
  meal       VARCHAR(120) NULL,
  beds       VARCHAR(120) NULL,
  quantity   INT          NULL DEFAULT 1,
  agency_id  INT UNSIGNED NULL,
  PRIMARY KEY (id),
  INDEX idx_rooms_service (service_id),
  INDEX idx_rooms_agency (agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- service_guests
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS service_guests (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id INT UNSIGNED NOT NULL,
  full_name  VARCHAR(255) NULL,
  type       VARCHAR(30)  NULL,
  agency_id  INT UNSIGNED NULL,
  PRIMARY KEY (id),
  INDEX idx_guests_service (service_id),
  INDEX idx_guests_agency (agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- service_cars
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS service_cars (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id      INT UNSIGNED NOT NULL,
  company_name    VARCHAR(255) NULL,
  car_type        VARCHAR(255) NULL,
  pickup_date     DATE         NULL,
  return_date     DATE         NULL,
  pickup_location VARCHAR(255) NULL,
  return_location VARCHAR(255) NULL,
  driver_name     TEXT         NULL,
  agency_id       INT UNSIGNED NULL,
  PRIMARY KEY (id),
  INDEX idx_cars_service (service_id),
  INDEX idx_cars_agency (agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- service_insurance
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS service_insurance (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id      INT UNSIGNED NOT NULL,
  provider_name   VARCHAR(255) NULL,
  plan_name       VARCHAR(255) NULL,
  start_date      DATE         NULL,
  end_date        DATE         NULL,
  coverage_amount VARCHAR(100) NULL,
  destination     VARCHAR(255) NULL,
  agency_id       INT UNSIGNED NULL,
  PRIMARY KEY (id),
  INDEX idx_ins_service (service_id),
  INDEX idx_ins_agency (agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- service_details
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS service_details (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id   INT UNSIGNED NOT NULL,
  title        VARCHAR(255) NULL,
  start_date   DATE         NULL,
  end_date     DATE         NULL,
  location     VARCHAR(255) NULL,
  participants VARCHAR(255) NULL,
  details      TEXT         NULL,
  agency_id    INT UNSIGNED NULL,
  PRIMARY KEY (id),
  INDEX idx_details_service (service_id),
  INDEX idx_details_agency (agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- refunds
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS refunds (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id              INT UNSIGNED NULL,
  client_id               INT UNSIGNED NOT NULL,
  passenger_id            INT UNSIGNED NULL,
  supplier_id             INT UNSIGNED NULL,
  type                    VARCHAR(20)  NOT NULL,
  motivo                  VARCHAR(255) NOT NULL,
  descricao               TEXT         NULL,
  valor_pago              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  valor_reembolsavel      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  valor_recebido          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status                  VARCHAR(30)  NOT NULL DEFAULT 'SOLICITADO',
  data_solicitacao        DATE         NULL,
  documento_comprovante   VARCHAR(255) NULL,
  observacoes             TEXT         NULL,
  data_pagamento          DATE         NULL,
  data_atualizacao        DATETIME     NULL,
  agency_id               INT UNSIGNED NOT NULL,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_refunds_agency (agency_id),
  INDEX idx_refunds_client (client_id),
  INDEX idx_refunds_invoice (invoice_id),
  INDEX idx_refunds_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- refund_logs
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS refund_logs (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  refund_id       INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NULL,
  acao            VARCHAR(50)  NOT NULL,
  status_anterior VARCHAR(30)  NULL,
  status_novo     VARCHAR(30)  NULL,
  valor_anterior  DECIMAL(12,2) NULL,
  valor_novo      DECIMAL(12,2) NULL,
  nota            TEXT         NULL,
  agency_id       INT UNSIGNED NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_refundlogs_refund (refund_id),
  INDEX idx_refundlogs_agency (agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- audit_logs (matches inc/audit.php exactly)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agency_id     BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  action        VARCHAR(80) NOT NULL,
  entity_type   VARCHAR(80) NOT NULL,
  entity_id     BIGINT UNSIGNED NOT NULL,
  details_json  TEXT NULL,
  ip_address    VARCHAR(45) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_agency_created (agency_id, created_at),
  INDEX idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;