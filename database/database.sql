-- =====================================================================
-- EXOTIC LANE LIMO - Phase 1 application schema (single file)
-- MySQL 8 / InnoDB / utf8mb4. Money = DECIMAL(10,2). Dates = DATETIME (UTC).
-- =====================================================================
CREATE DATABASE IF NOT EXISTS ell_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ell_db;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------- customers ----------------
CREATE TABLE IF NOT EXISTS customers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NULL,
  password_hash VARCHAR(255) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customers_email (email),
  KEY idx_customers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- admins (single permission level, Phase 1) ----------------
CREATE TABLE IF NOT EXISTS admins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- drivers ----------------
CREATE TABLE IF NOT EXISTS drivers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NULL,
  password_hash VARCHAR(255) NULL,
  status ENUM('pending','active','inactive','suspended') NOT NULL DEFAULT 'pending',
  reference VARCHAR(190) NULL,
  ssn_encrypted TEXT NULL,
  payout_reference VARCHAR(255) NULL COMMENT 'payout information reference (e.g. last4 / handle, never full secrets)',
  license_number VARCHAR(80) NULL,
  license_expiry DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_drivers_email (email),
  KEY idx_drivers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- driver_documents ----------------
CREATE TABLE IF NOT EXISTS driver_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  driver_id BIGINT UNSIGNED NOT NULL,
  doc_type VARCHAR(80) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  expiry_date DATE NULL,
  status ENUM('pending','verified','rejected','expired') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_dd_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
  KEY idx_dd_driver (driver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- password_resets (all roles, hashed tokens) ----------------
CREATE TABLE IF NOT EXISTS password_resets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  role ENUM('customer','admin','driver') NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_resets_token (token_hash),
  KEY idx_resets_user (role, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- services ----------------
CREATE TABLE IF NOT EXISTS services (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL COMMENT 'point_to_point|airport|hourly|group_event|direct_contract',
  name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_services_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- vehicle_categories ----------------
CREATE TABLE IF NOT EXISTS vehicle_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  description TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vcat_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- vehicles ----------------
CREATE TABLE IF NOT EXISTS vehicles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  category_id BIGINT UNSIGNED NULL,
  make VARCHAR(80) NOT NULL,
  model VARCHAR(80) NOT NULL,
  year SMALLINT NULL,
  plate VARCHAR(30) NULL,
  passenger_capacity INT NOT NULL DEFAULT 3,
  luggage_capacity INT NOT NULL DEFAULT 2,
  status ENUM('active','inactive','maintenance') NOT NULL DEFAULT 'active',
  is_temporary TINYINT(1) NOT NULL DEFAULT 0,
  insurance_expiry DATE NULL,
  registration_expiry DATE NULL,
  inspection_expiry DATE NULL,
  diamond_sticker_expiry DATE NULL,
  photo_path VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_veh_cat FOREIGN KEY (category_id) REFERENCES vehicle_categories(id) ON DELETE SET NULL,
  KEY idx_veh_status (status),
  KEY idx_veh_cat (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- vehicle_documents ----------------
CREATE TABLE IF NOT EXISTS vehicle_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vehicle_id BIGINT UNSIGNED NOT NULL,
  doc_type VARCHAR(80) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  expiry_date DATE NULL,
  status ENUM('pending','verified','rejected','expired') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vd_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  KEY idx_vd_vehicle (vehicle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- vehicle_blocks (availability) ----------------
CREATE TABLE IF NOT EXISTS vehicle_blocks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vehicle_id BIGINT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  reason VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vb_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  KEY idx_vb_vehicle_time (vehicle_id, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- pricing_rates (per vehicle) ----------------
CREATE TABLE IF NOT EXISTS pricing_rates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vehicle_id BIGINT UNSIGNED NOT NULL,
  per_mile_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  active TINYINT(1) NOT NULL DEFAULT 1,
  effective_from DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  effective_to DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pr_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  KEY idx_pr_vehicle (vehicle_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- additional_charge_types ----------------
CREATE TABLE IF NOT EXISTS additional_charge_types (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL,
  name VARCHAR(150) NOT NULL,
  calculation_type ENUM('flat','per_unit','per_mile','per_hour') NOT NULL DEFAULT 'flat',
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_act_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- coupons ----------------
CREATE TABLE IF NOT EXISTS coupons (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL,
  type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  minimum_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  usage_limit INT NULL,
  used_count INT NOT NULL DEFAULT 0,
  customer_limit INT NULL,
  starts_at DATETIME NULL,
  expires_at DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_coupons_code (code),
  KEY idx_coupons_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- bookings ----------------
CREATE TABLE IF NOT EXISTS bookings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_number CHAR(8) NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  guest_name VARCHAR(150) NULL,
  guest_email VARCHAR(190) NULL,
  guest_phone VARCHAR(40) NULL,
  service_type ENUM('point_to_point','airport','hourly') NOT NULL,
  trip_type ENUM('one_way','round_trip') NOT NULL DEFAULT 'one_way',
  airport_direction ENUM('to_airport','from_airport') NULL,
  pickup_location TEXT NOT NULL,
  destination_location TEXT NOT NULL,
  pickup_date DATE NOT NULL,
  pickup_time TIME NOT NULL,
  original_pickup_datetime DATETIME NULL,
  passengers INT NOT NULL DEFAULT 1,
  luggage INT NOT NULL DEFAULT 0,
  vehicle_id BIGINT UNSIGNED NULL,
  mileage DECIMAL(10,2) NULL,
  hours DECIMAL(5,2) NULL,
  status ENUM('pending_payment','payment_failed','awaiting_pricing','pricing_finalized','booking_received','confirmed','assigned','on_the_way','arrived','at_pickup_location','on_board','finish','cancelled','refunded') NOT NULL DEFAULT 'pending_payment',
  pricing_status ENUM('awaiting','finalized') NOT NULL DEFAULT 'awaiting',
  payment_status ENUM('pending','processing','paid','failed','refunded','partially_refunded','cancelled') NOT NULL DEFAULT 'pending',
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  coupon_id BIGINT UNSIGNED NULL,
  addons_json JSON NULL,
  pricing_finalized_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bookings_number (booking_number),
  CONSTRAINT fk_b_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_b_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
  CONSTRAINT fk_b_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE SET NULL,
  KEY idx_b_customer (customer_id),
  KEY idx_b_status (status),
  KEY idx_b_pickup (pickup_date, pickup_time),
  KEY idx_b_vehicle (vehicle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- booking_stops (max 6 enforced app-side) ----------------
CREATE TABLE IF NOT EXISTS booking_stops (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  stop_order INT NOT NULL,
  location TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bs_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  UNIQUE KEY uq_bs_order (booking_id, stop_order),
  KEY idx_bs_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- booking_charges (immutable snapshot line items) ----------------
CREATE TABLE IF NOT EXISTS booking_charges (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  charge_type VARCHAR(80) NOT NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  source ENUM('pricing_engine','admin_manual','waiting','coupon','tax') NOT NULL DEFAULT 'pricing_engine',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bc_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  KEY idx_bc_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- booking_time_changes ----------------
CREATE TABLE IF NOT EXISTS booking_time_changes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  old_pickup_datetime DATETIME NOT NULL,
  new_pickup_datetime DATETIME NOT NULL,
  changed_by_type ENUM('customer','admin','driver') NOT NULL,
  changed_by_id BIGINT UNSIGNED NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_btc_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  KEY idx_btc_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- booking_status_logs ----------------
CREATE TABLE IF NOT EXISTS booking_status_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  old_status VARCHAR(40) NULL,
  new_status VARCHAR(40) NOT NULL,
  actor_type ENUM('customer','admin','driver','system') NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bsl_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  KEY idx_bsl_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- pricing_revisions (audited re-pricing) ----------------
CREATE TABLE IF NOT EXISTS pricing_revisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  admin_id BIGINT UNSIGNED NULL,
  old_total DECIMAL(10,2) NOT NULL,
  new_total DECIMAL(10,2) NOT NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_prev_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_prev_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL,
  KEY idx_prev_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- coupon_redemptions (per-customer limit enforcement) ----------------
CREATE TABLE IF NOT EXISTS coupon_redemptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  coupon_id BIGINT UNSIGNED NOT NULL,
  booking_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  guest_email VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cr_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cr_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_cr_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  UNIQUE KEY uq_cr_booking (booking_id),
  KEY idx_cr_coupon_customer (coupon_id, customer_id),
  KEY idx_cr_coupon_email (coupon_id, guest_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- payments ----------------
CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  provider ENUM('stripe','offline') NOT NULL DEFAULT 'stripe',
  provider_payment_id VARCHAR(190) NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  status ENUM('pending','processing','paid','failed','refunded','partially_refunded','cancelled') NOT NULL DEFAULT 'pending',
  method VARCHAR(60) NULL,
  failure_code VARCHAR(120) NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pay_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_pay_provider (provider_payment_id),
  KEY idx_pay_booking (booking_id),
  KEY idx_pay_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- payment_links (hash only) ----------------
CREATE TABLE IF NOT EXISTS payment_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pl_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  UNIQUE KEY uq_pl_token (token_hash),
  KEY idx_pl_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- refunds ----------------
CREATE TABLE IF NOT EXISTS refunds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NOT NULL,
  provider_refund_id VARCHAR(190) NULL,
  amount DECIMAL(10,2) NOT NULL,
  status ENUM('pending','succeeded','failed','cancelled') NOT NULL DEFAULT 'pending',
  reason VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ref_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_ref_provider (provider_refund_id),
  KEY idx_ref_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- invoices ----------------
CREATE TABLE IF NOT EXISTS invoices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  invoice_number VARCHAR(30) NOT NULL,
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  payment_status ENUM('pending','paid','failed','refunded','partially_refunded','cancelled') NOT NULL DEFAULT 'pending',
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_inv_number (invoice_number),
  KEY idx_inv_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- dispatches ----------------
CREATE TABLE IF NOT EXISTS dispatches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  driver_id BIGINT UNSIGNED NULL,
  temporary_driver_name VARCHAR(150) NULL,
  temporary_driver_phone VARCHAR(40) NULL,
  vehicle_id BIGINT UNSIGNED NULL,
  temporary_vehicle_snapshot JSON NULL,
  status ENUM('assigned','reassigned','completed','cancelled') NOT NULL DEFAULT 'assigned',
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reassigned_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_d_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_d_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE SET NULL,
  CONSTRAINT fk_d_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
  KEY idx_d_booking (booking_id),
  KEY idx_d_driver (driver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- dispatch_history ----------------
CREATE TABLE IF NOT EXISTS dispatch_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dispatch_id BIGINT UNSIGNED NOT NULL,
  booking_id BIGINT UNSIGNED NOT NULL,
  old_driver_id BIGINT UNSIGNED NULL,
  new_driver_id BIGINT UNSIGNED NULL,
  old_vehicle_id BIGINT UNSIGNED NULL,
  new_vehicle_id BIGINT UNSIGNED NULL,
  actor_type ENUM('admin','system') NOT NULL DEFAULT 'admin',
  actor_id BIGINT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dh_dispatch FOREIGN KEY (dispatch_id) REFERENCES dispatches(id) ON DELETE CASCADE,
  KEY idx_dh_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- waiting_rules ----------------
CREATE TABLE IF NOT EXISTS waiting_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(60) NOT NULL COMMENT 'airport|bus_terminal|train_terminal|cruise_terminal|point_to_point',
  free_minutes INT NOT NULL DEFAULT 15,
  charge_interval_minutes INT NOT NULL DEFAULT 10,
  charge_per_interval DECIMAL(10,2) NOT NULL DEFAULT 15.00,
  active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wr_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- waiting_sessions ----------------
CREATE TABLE IF NOT EXISTS waiting_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(60) NOT NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  free_minutes INT NOT NULL DEFAULT 15,
  billable_minutes INT NOT NULL DEFAULT 0,
  rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status ENUM('active','closed','invoiced','waived') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ws_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  KEY idx_ws_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- driver_status_logs ----------------
CREATE TABLE IF NOT EXISTS driver_status_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  driver_id BIGINT UNSIGNED NOT NULL,
  old_status VARCHAR(40) NULL,
  new_status VARCHAR(40) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dsl_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_dsl_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
  KEY idx_dsl_booking (booking_id, driver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- driver_earnings (exactly one per completed booking) ----------------
CREATE TABLE IF NOT EXISTS driver_earnings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  driver_id BIGINT UNSIGNED NOT NULL,
  gross_amount DECIMAL(10,2) NOT NULL,
  company_amount DECIMAL(10,2) NOT NULL,
  driver_amount DECIMAL(10,2) NOT NULL,
  status ENUM('unpaid','paid','partially_paid') NOT NULL DEFAULT 'unpaid',
  earned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_de_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT,
  CONSTRAINT fk_de_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_de_booking (booking_id),
  KEY idx_de_driver (driver_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- driver_payouts ----------------
CREATE TABLE IF NOT EXISTS driver_payouts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  driver_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  eligible_at DATETIME NOT NULL,
  status ENUM('requested','approved','rejected','paid') NOT NULL DEFAULT 'requested',
  destination_reference VARCHAR(255) NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  paid_at DATETIME NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_dp_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
  CONSTRAINT fk_dp_reviewer FOREIGN KEY (reviewed_by) REFERENCES admins(id) ON DELETE SET NULL,
  KEY idx_dp_driver (driver_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- notifications ----------------
CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recipient_type ENUM('customer','admin','driver','guest') NOT NULL,
  recipient_id BIGINT UNSIGNED NULL,
  booking_id BIGINT UNSIGNED NULL,
  template VARCHAR(80) NOT NULL,
  email VARCHAR(190) NOT NULL,
  status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  provider_reference VARCHAR(190) NULL,
  error_message VARCHAR(255) NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ntf_recipient (recipient_type, recipient_id),
  KEY idx_ntf_booking (booking_id),
  KEY idx_ntf_template (template)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- group_event_inquiries ----------------
CREATE TABLE IF NOT EXISTS group_event_inquiries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(100) NOT NULL,
  event_dates VARCHAR(255) NULL,
  vehicle_count INT NULL,
  estimated_passengers INT NULL,
  locations TEXT NULL,
  schedule TEXT NULL,
  special_requirements TEXT NULL,
  contact_name VARCHAR(150) NOT NULL,
  contact_email VARCHAR(190) NOT NULL,
  contact_phone VARCHAR(40) NULL,
  kind ENUM('group_event','direct_contract') NOT NULL DEFAULT 'group_event',
  status ENUM('new','quoted','confirmed','declined','closed') NOT NULL DEFAULT 'new',
  quote_amount DECIMAL(10,2) NULL,
  admin_notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_gei_status (status, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- content (CMS) ----------------
CREATE TABLE IF NOT EXISTS content (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(120) NOT NULL,
  title VARCHAR(190) NULL,
  body MEDIUMTEXT NULL,
  meta_title VARCHAR(190) NULL,
  meta_description VARCHAR(255) NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_content_slug (slug),
  CONSTRAINT fk_content_admin FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- settings ----------------
CREATE TABLE IF NOT EXISTS settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  skey VARCHAR(120) NOT NULL,
  svalue TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_settings_key (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- audit_logs ----------------
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_type ENUM('customer','admin','driver','system') NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(120) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id BIGINT UNSIGNED NULL,
  metadata JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_actor (actor_type, actor_id),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- webhook_events (idempotency) ----------------
CREATE TABLE IF NOT EXISTS webhook_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider ENUM('stripe') NOT NULL DEFAULT 'stripe',
  event_id VARCHAR(190) NOT NULL,
  type VARCHAR(120) NULL,
  payload JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wh_event (provider, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------- seeds: services ----------------
INSERT INTO services (code, name, description, active) VALUES
('point_to_point','Point-to-Point','Pickup to destination with optional stops.',1),
('airport','Airport Transportation','Airport to/from customer address.',1),
('hourly','Hourly Charter','Chauffeured hire, 2-hour minimum.',1),
('group_event','Group & Event','Group and event transportation inquiries.',1),
('direct_contract','Direct Contract','Direct corporate/contract inquiries.',1)
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- ---------------- seeds: vehicle categories ----------------
INSERT INTO vehicle_categories (name, description, active) VALUES
('Sedan','Executive sedans',1),
('SUV','Premium SUVs',1),
('Luxury SUV','Flagship luxury SUVs',1),
('Luxury Sedan','Flagship luxury sedans',1),
('Van','Vans for groups & luggage',1),
('Limo','Stretch limousines',1)
ON DUPLICATE KEY UPDATE description=VALUES(description);

-- ---------------- seeds: waiting rules ----------------
INSERT INTO waiting_rules (category, free_minutes, charge_interval_minutes, charge_per_interval, active) VALUES
('airport',60,10,15.00,1),
('bus_terminal',30,10,15.00,1),
('train_terminal',30,10,15.00,1),
('cruise_terminal',30,10,15.00,1),
('point_to_point',15,10,15.00,1)
ON DUPLICATE KEY UPDATE free_minutes=VALUES(free_minutes);

-- ---------------- seeds: additional charges ----------------
INSERT INTO additional_charge_types (code, name, calculation_type, amount, active) VALUES
('toll','Toll', 'flat', 0.00, 1),
('parking','Parking','flat', 0.00, 1),
('airport_fee','Airport Fee','flat', 0.00, 1),
('meet_greet','Meet & Greet','flat', 25.00, 1),
('child_seat','Child Seat','flat', 15.00, 1),
('booster_seat','Booster Seat','flat', 10.00, 1),
('waiting_time','Waiting Time','per_unit', 15.00, 1),
('extra_stop','Extra Stop','per_unit', 20.00, 1),
('additional_mileage','Additional Mileage','per_mile', 3.50, 1),
('additional_hours','Additional Hours','per_hour', 95.00, 1),
('custom_fee','Custom Fee','flat', 0.00, 1)
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- ---------------- seeds: default settings ----------------
INSERT INTO settings (skey, svalue) VALUES
('site_title','Exotic Lane Limo | Luxury Chauffeur Service'),
('meta_description','Exotic Lane Limo - premium chauffeur, airport and hourly limo service.'),
('meta_keywords','limo, chauffeur, airport car service, hourly limo'),
('site_logo',''),
('favicon',''),
('og_image',''),
('gsc_verification',''),
('ga_id',''),
('fb_pixel',''),
('robots_rules','User-agent: *\nAllow: /'),
('org_name','Exotic Lane Limo'),
('org_phone',''),
('org_email',''),
('org_address',''),
('social_facebook',''),
('social_instagram',''),
('social_x',''),
('pricing_mode','per_mile'),
('tax_percent','0'),
('currency','USD'),
('pickup_cutoff_hours','2'),
('payout_interval_days','7'),
('maps_enabled','0'),
('lead_time_hours','2')
ON DUPLICATE KEY UPDATE svalue=VALUES(svalue);

-- ---------------- seeds: CMS content ----------------
INSERT INTO content (slug, title, body) VALUES
('terms','Terms of Service','<p>Reservations, hourly minimum (2 hours), waiting policy, Meet &amp; Greet, child/booster seats, passenger and luggage limits, cancellation and no-show, reservation changes, additional stops, toll/parking/facility fees, vehicle substitution, customer conduct, smoking/vaping, food/beverage, alcohol, damage/cleaning, lost &amp; found, delays, service termination, refunds, personal property, service partners, corporate accounts and special events policies are published here by the administrator.</p>'),
('privacy','Privacy Policy','<p>We collect only data needed to provide transportation services. Sensitive compliance data is restricted and excluded from logs.</p>'),
('cancellation','Cancellation Policy','<p>Cancellation requests are handled per the published policy. Contact us before the pickup cutoff for changes.</p>'),
('faq','Frequently Asked Questions','<h2>Frequently asked questions</h2><p>Common questions about reservations, waiting time, child seats and airport pickups.</p>')
ON DUPLICATE KEY UPDATE title=VALUES(title);
