<?php
declare(strict_types=1);

require __DIR__ . '/../inc/db.php';

$pdo->exec("
CREATE TABLE IF NOT EXISTS service_cars (
  id INT(11) NOT NULL AUTO_INCREMENT,
  service_id INT(11) NOT NULL,
  company_name VARCHAR(255) DEFAULT NULL,
  car_type VARCHAR(255) DEFAULT NULL,
  pickup_date DATE DEFAULT NULL,
  return_date DATE DEFAULT NULL,
  pickup_location VARCHAR(255) DEFAULT NULL,
  return_location VARCHAR(255) DEFAULT NULL,
  driver_name TEXT DEFAULT NULL,
  agency_id INT(11) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_service (service_id),
  KEY idx_agency (agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS service_insurance (
  id INT(11) NOT NULL AUTO_INCREMENT,
  service_id INT(11) NOT NULL,
  provider_name VARCHAR(255) DEFAULT NULL,
  plan_name VARCHAR(255) DEFAULT NULL,
  start_date DATE DEFAULT NULL,
  end_date DATE DEFAULT NULL,
  coverage_amount VARCHAR(100) DEFAULT NULL,
  destination VARCHAR(255) DEFAULT NULL,
  agency_id INT(11) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_service (service_id),
  KEY idx_agency (agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS service_details (
  id INT(11) NOT NULL AUTO_INCREMENT,
  service_id INT(11) NOT NULL,
  title VARCHAR(255) DEFAULT NULL,
  start_date DATE DEFAULT NULL,
  end_date DATE DEFAULT NULL,
  location VARCHAR(255) DEFAULT NULL,
  participants VARCHAR(255) DEFAULT NULL,
  details TEXT DEFAULT NULL,
  agency_id INT(11) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_service (service_id),
  KEY idx_agency (agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "service sales type tables ready\n";
