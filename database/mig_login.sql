-- =====================================================================
-- EXOTIC LANE LIMO - demo data for mig_login.sql
-- ---------------------------------------------------------------------
-- Sign-in credentials and a believable week of work, so every panel has
-- something to show the moment you import this.
--
--   Admin     admin@demo.exoticlane.test    / DemoAdmin!2026
--   Driver    driver@demo.exoticlane.test   / DemoDriver!2026
--   Customer  customer@demo.exoticlane.test / DemoCustomer!2026
--
-- All three share one password per role; the four demo drivers differ only in
-- licence and paperwork dates so the driver dashboard shows real warnings.
--
-- ---------------------------------------------------------------------
-- WHAT THIS FILE WILL AND WILL NOT TOUCH
--
-- It deletes demo rows and inserts demo rows. Nothing else. Real rows you
-- already have are never read, written or removed; on a live database this
-- file is additive.
--
-- The DELETEs use four narrow patterns, and they are safe because of what
-- they match, not because they are short:
--
--   booking_number LIKE 'DM0000__'   the 8-char range this file allocates
--   plate           LIKE 'DEMO-%'    the prefix this file puts on every car
--   code            LIKE 'DEMO%'     the prefix this file puts on coupons
--   email           LIKE '%@demo.exoticlane.test'
--                                    a reserved .test domain. No real person
--                                    has an address there, which is the whole
--                                    reason it is used instead of something
--                                    like example.com - a pattern loose enough
--                                    to match real customers is exactly how a
--                                    demo import eats somebody's data.
--
-- Each prefix is allocated in this file and nowhere else. If you rename them,
-- rename them here and in the SELECTs at the bottom together.
--
-- Re-running is safe. The DELETEs remove this file's own rows first, so a
-- second import refreshes rather than doubling up.
-- ---------------------------------------------------------------------
--
-- IMPORT
--   mysql -u root < database/database.sql      (once, for the schema)
--   mysql -u root < database/mig_login.sql     (this file)
--
-- The passwords below are real bcrypt hashes generated with PHP's
-- password_hash() and each one was checked with password_verify() before it
-- was pasted in. They are not placeholders: the logins work as printed above.
--
-- ⚠ These accounts are public knowledge the moment this file is in a repo.
--   Delete them before anything goes anywhere real.
-- =====================================================================

USE arovabds_ell_db;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 0. Clear this file's own rows, so a re-import refreshes instead of piling
--    up. Children before parents, because of the foreign keys.
--    Only the four prefixes documented in the header are matched.
-- ---------------------------------------------------------------------
-- Cleared by email, not by booking_id. Half of these rows are guest messages
-- with booking_id NULL, and a cleanup keyed on booking_id silently misses every
-- one of them - which shows up as the file piling up five extra rows each time
-- it is imported again.
DELETE FROM notifications        WHERE email LIKE '%@demo.exoticlane.test';
DELETE FROM booking_stops        WHERE booking_id IN (SELECT id FROM bookings WHERE booking_number LIKE 'DM0000__');
DELETE FROM booking_status_logs  WHERE booking_id IN (SELECT id FROM bookings WHERE booking_number LIKE 'DM0000__');
DELETE FROM booking_charges      WHERE booking_id IN (SELECT id FROM bookings WHERE booking_number LIKE 'DM0000__');
DELETE FROM driver_earnings      WHERE booking_id IN (SELECT id FROM bookings WHERE booking_number LIKE 'DM0000__');
DELETE FROM dispatches           WHERE booking_id IN (SELECT id FROM bookings WHERE booking_number LIKE 'DM0000__');
DELETE FROM bookings             WHERE booking_number IN ('DM000001','DM000002','DM000003','DM000004','DM000005','DM000006','DM000007','DM000008');

DELETE FROM driver_payouts   WHERE driver_id IN (SELECT id FROM drivers WHERE email IN ('driver@demo.exoticlane.test','rahim@demo.exoticlane.test','sadia@demo.exoticlane.test','tariq@demo.exoticlane.test'));
DELETE FROM pricing_rates    WHERE vehicle_id IN (SELECT id FROM vehicles WHERE plate LIKE 'DEMO-%');
DELETE FROM vehicles         WHERE plate LIKE 'DEMO-%';
DELETE FROM drivers          WHERE email IN ('driver@demo.exoticlane.test','rahim@demo.exoticlane.test','sadia@demo.exoticlane.test','tariq@demo.exoticlane.test');
DELETE FROM customers        WHERE email IN ('customer@demo.exoticlane.test','imran@demo.exoticlane.test','nusrat@demo.exoticlane.test');
DELETE FROM admins           WHERE email IN ('admin@demo.exoticlane.test','ops@demo.exoticlane.test');
DELETE FROM contact_submissions     WHERE email LIKE '%@demo.exoticlane.test';
DELETE FROM group_event_inquiries   WHERE contact_email LIKE '%@demo.exoticlane.test';
DELETE FROM newsletter_subscribers  WHERE email LIKE '%@demo.exoticlane.test';
DELETE FROM coupons           WHERE code LIKE 'DEMO%';

-- ---------------------------------------------------------------------
-- 1. Admins
--    Two, so the "reviewed by" column on a payout has somebody to name and
--    the audit log shows more than one pair of hands.
-- ---------------------------------------------------------------------
INSERT INTO admins (name, email, password_hash, status) VALUES
  ('Demo Owner',    'admin@demo.exoticlane.test', '$2y$10$o8y/Tmc/A/icx5bXQZGRG.kON9KjarOsJag0rRAk/Uz.zT9FJOkeG', 'active'),
  ('Demo Dispatcher','ops@demo.exoticlane.test',  '$2y$10$o8y/Tmc/A/icx5bXQZGRG.kON9KjarOsJag0rRAk/Uz.zT9FJOkeG', 'active');

-- ---------------------------------------------------------------------
-- 2. Drivers
--    Licence and clearance dates are set relative to today with
--    DATE_ADD(NOW(), INTERVAL n DAY) so the warnings on the driver dashboard
--    stay truthful whenever this file is imported - one licence already
--    expired, one inside 30 days, paperwork pending on a third.
--    ssn_encrypted is left NULL on purpose: this app encrypts it, and a value
--    that only looks encrypted is worse than none.
-- ---------------------------------------------------------------------
INSERT INTO drivers
  (name, email, phone, password_hash, status, reference, license_number, license_expiry,
   date_of_birth, medical_cert_expiry, background_check_date, drug_test_date,
   clearance_submitted_at, description)
VALUES
  ('Demo Driver',  'driver@demo.exoticlane.test',
   '+8801700000001', '$2y$10$sNhm/xIdTNUKUawb43oys.sf0Vhh0Wyip/bXBCHYDsSQEtFoJEmdS', 'active',
   'DRV-DEMO-0001', 'DL-DEMO-0001', DATE_ADD(CURDATE(), INTERVAL 210 DAY),
   '1988-04-12', DATE_ADD(CURDATE(), INTERVAL 300 DAY), DATE_SUB(CURDATE(), INTERVAL 40 DAY),
   DATE_SUB(CURDATE(), INTERVAL 25 DAY), DATE_SUB(CURDATE(), INTERVAL 30 DAY),
   'The account to sign in with. Everything is current, so this driver sees a clean dashboard.'),

  ('Rahim Uddin',  'rahim@demo.exoticlane.test',
   '+8801700000002', '$2y$10$HoJaytgoigxYmblUDLOpPuexcHyPfL5Uo4tMJaL7QFIGkbhUW3W9q', 'active',
   'DRV-DEMO-0002', 'DL-DEMO-0002', DATE_ADD(CURDATE(), INTERVAL 24 DAY),
   '1985-09-30', DATE_ADD(CURDATE(), INTERVAL 160 DAY), DATE_SUB(CURDATE(), INTERVAL 200 DAY),
   DATE_SUB(CURDATE(), INTERVAL 190 DAY), DATE_SUB(CURDATE(), INTERVAL 190 DAY),
   'Licence expires in under a month, on purpose - this is the driver who makes the dashboard amber.'),

  ('Sadia Akter',  'sadia@demo.exoticlane.test',
   '+8801700000003', '$2y$10$6Nkc3wbZ16F66XzxmGTNi.Aoc0Tf1WPezavQiWwsR8FhxigUJWZQG', 'active',
   'DRV-DEMO-0003', 'DL-DEMO-0003', DATE_SUB(CURDATE(), INTERVAL 6 DAY),
   '1991-01-22', DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_SUB(CURDATE(), INTERVAL 370 DAY),
   '2025-11-02', NULL,
   'Licence already expired and the medical is about to. Drives the red state.'),

  ('Tariq Hasan',  'tariq@demo.exoticlane.test',
   '+8801700000004', '$2y$10$6Nkc3wbZ16F66XzxmGTNi.Aoc0Tf1WPezavQiWwsR8FhxigUJWZQG', 'pending',
   'DRV-DEMO-0004', 'DL-DEMO-0004', DATE_ADD(CURDATE(), INTERVAL 400 DAY),
   '1994-07-07', NULL, NULL, NULL, NULL,
   'Still pending, so admin/drivers.php has a row waiting to be approved.');

-- ---------------------------------------------------------------------
-- 3. Vehicles
--    Plate prefix DEMO- is the hook the cleanup at the top uses.
--    photo_path is left NULL: a path to a file that is not in the repo is
--    how you get a broken image in a demo.
-- ---------------------------------------------------------------------
INSERT INTO vehicles
  (category_id, make, model, slug, year, plate, passenger_capacity, luggage_capacity,
   status, insurance_expiry, registration_expiry, inspection_expiry, description)
VALUES
  ((SELECT id FROM vehicle_categories WHERE name = 'Executive Sedan' LIMIT 1),
   'Toyota', 'Camry',  'demo-toyota-camry',  2023, 'DEMO-01', 3, 2, 'active',
   DATE_ADD(CURDATE(), INTERVAL 120 DAY), DATE_ADD(CURDATE(), INTERVAL 400 DAY), DATE_ADD(CURDATE(), INTERVAL 90 DAY),
   'Quiet, air-conditioned, the default for airport runs.'),
  ((SELECT id FROM vehicle_categories WHERE name = 'Executive Sedan' LIMIT 1),
   'Mercedes-Benz', 'E-Class', 'demo-mercedes-e-class', 2024, 'DEMO-02', 3, 3, 'active',
   DATE_ADD(CURDATE(), INTERVAL 260 DAY), DATE_ADD(CURDATE(), INTERVAL 300 DAY), DATE_ADD(CURDATE(), INTERVAL 200 DAY),
   'The upgrade when a client is being collected.'),
  ((SELECT id FROM vehicle_categories WHERE name = 'Luxury SUV' LIMIT 1),
   'Toyota', 'Land Cruiser', 'demo-toyota-land-cruiser', 2023, 'DEMO-03', 6, 4, 'active',
   DATE_ADD(CURDATE(), INTERVAL 95 DAY),  DATE_ADD(CURDATE(), INTERVAL 500 DAY), DATE_ADD(CURDATE(), INTERVAL 150 DAY),
   'Room for a family and a mountain of luggage.'),
  ((SELECT id FROM vehicle_categories WHERE name = 'Luxury SUV' LIMIT 1),
   'Range Rover', 'Vogue', 'demo-range-rover-vogue', 2022, 'DEMO-04', 5, 4, 'active',
   DATE_ADD(CURDATE(), INTERVAL 30 DAY),  DATE_ADD(CURDATE(), INTERVAL 260 DAY), DATE_ADD(CURDATE(), INTERVAL 45 DAY),
   'Insurance and inspection both close to the line.'),
  ((SELECT id FROM vehicle_categories WHERE name = 'Vans & Minivans' LIMIT 1),
   'Toyota', 'Hiace', 'demo-toyota-hiace', 2023, 'DEMO-05', 14, 14, 'active',
   DATE_ADD(CURDATE(), INTERVAL 200 DAY), DATE_ADD(CURDATE(), INTERVAL 340 DAY), DATE_ADD(CURDATE(), INTERVAL 175 DAY),
   'Eighteen seats for a wedding party.'),
  ((SELECT id FROM vehicle_categories WHERE name = 'Party Bus' LIMIT 1),
   'Mercedes-Benz', 'Sprinter', 'demo-mercedes-sprinter', 2024, 'DEMO-06', 18, 12, 'active',
   DATE_ADD(CURDATE(), INTERVAL 310 DAY), DATE_ADD(CURDATE(), INTERVAL 420 DAY), DATE_ADD(CURDATE(), INTERVAL 260 DAY),
   'Onboard bar and restroom. The one people photograph.'),
  ((SELECT id FROM vehicle_categories WHERE name = 'Executive Sedan' LIMIT 1),
   'BMW', '5 Series', 'demo-bmw-5-series', 2022, 'DEMO-07', 3, 2, 'maintenance',
   DATE_ADD(CURDATE(), INTERVAL 60 DAY),  DATE_ADD(CURDATE(), INTERVAL 150 DAY), DATE_ADD(CURDATE(), INTERVAL 20 DAY),
   'Off the road for a service, so the fleet page is not suspiciously perfect.'),
  ((SELECT id FROM vehicle_categories WHERE name = 'Luxury SUV' LIMIT 1),
   'Toyota', 'Fortuner', 'demo-toyota-fortuner', 2021, 'DEMO-08', 7, 3, 'active',
   DATE_ADD(CURDATE(), INTERVAL 15 DAY),  DATE_ADD(CURDATE(), INTERVAL 90 DAY),  DATE_ADD(CURDATE(), INTERVAL 75 DAY),
   'Insurance nearly expired - shows up as a warning.');

-- ---------------------------------------------------------------------
-- 4. Pricing rates, one per drivable car
-- ---------------------------------------------------------------------
INSERT INTO pricing_rates (vehicle_id, per_mile_rate, hourly_rate, active, effective_from)
SELECT id, per_mile, hourly, 1, DATE_SUB(NOW(), INTERVAL 30 DAY)
FROM (
  SELECT (SELECT id FROM vehicles WHERE plate='DEMO-01' LIMIT 1) AS id, 2.40 AS per_mile, 55.00 AS hourly
  UNION ALL SELECT (SELECT id FROM vehicles WHERE plate='DEMO-02' LIMIT 1), 3.20, 75.00
  UNION ALL SELECT (SELECT id FROM vehicles WHERE plate='DEMO-03' LIMIT 1), 3.80, 85.00
  UNION ALL SELECT (SELECT id FROM vehicles WHERE plate='DEMO-04' LIMIT 1), 4.60, 110.00
  UNION ALL SELECT (SELECT id FROM vehicles WHERE plate='DEMO-05' LIMIT 1), 2.90, 70.00
  UNION ALL SELECT (SELECT id FROM vehicles WHERE plate='DEMO-06' LIMIT 1), 5.50, 145.00
  UNION ALL SELECT (SELECT id FROM vehicles WHERE plate='DEMO-08' LIMIT 1), 3.10, 72.00
) AS seed;

-- ---------------------------------------------------------------------
-- 5. Customers
-- ---------------------------------------------------------------------
INSERT INTO customers (name, email, phone, password_hash, status) VALUES
  ('Demo Customer', 'customer@demo.exoticlane.test', '+8801700000101',
   '$2y$10$qjCz5G1/yF9X2/Q8.EYSouz2Olmxk5XnnliUZPtwCT0UoOBG7HrIK', 'active'),
  ('Imran Chowdhury', 'imran@demo.exoticlane.test', '+8801700000102', NULL, 'active'),
  ('Nusrat Jahan',  'nusrat@demo.exoticlane.test', '+8801700000103', NULL, 'active');

-- ---------------------------------------------------------------------
-- 6. Coupons, so Pricing has something to switch on
-- ---------------------------------------------------------------------
INSERT INTO coupons (code, type, value, minimum_subtotal, usage_limit, used_count, starts_at, expires_at, active) VALUES
  ('DEMO10', 'percent', 10.00, 200.00, 100, 4, DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_ADD(NOW(), INTERVAL 50 DAY), 1),
  ('DEMOFLAT', 'fixed', 40.00, 300.00, 25, 1, DATE_SUB(NOW(), INTERVAL 5 DAY),  DATE_ADD(NOW(), INTERVAL 20 DAY), 1),
  ('DEMOEXPIRED', 'percent', 20.00, 0.00, NULL, 9, DATE_SUB(NOW(), INTERVAL 90 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), 0);

-- ---------------------------------------------------------------------
-- 7. Bookings
--    One of every status that matters on the dashboard, dated around today so
--    the "next 7 days" and "this week" panels are not empty. service_type is
--    an enum of point_to_point / airport / hourly - group work lives in
--    group_event_inquiries, not here.
-- ---------------------------------------------------------------------
INSERT INTO bookings
  (booking_number, customer_id, guest_name, guest_email, guest_phone, service_type, trip_type,
   airport_direction, pickup_location, destination_location, pickup_date, pickup_time,
   original_pickup_datetime, passengers, luggage, vehicle_id, mileage, hours,
   status, pricing_status, payment_status, subtotal, discount, tax, total, currency,
   pricing_finalized_at, completed_at, created_at)
VALUES
  ('DM000001', (SELECT id FROM customers WHERE email='customer@demo.exoticlane.test' LIMIT 1),
   'Demo Customer', 'customer@demo.exoticlane.test', '+8801700000101',
   'airport','one_way','to_airport','Shahjalal International Airport','Gulshan 1, Dhaka',
   DATE_ADD(CURDATE(), INTERVAL 1 DAY),'09:30:00', NULL, 2, 2,
   (SELECT id FROM vehicles WHERE plate='DEMO-01' LIMIT 1), 18.40, NULL,
   'awaiting_pricing','awaiting','pending', 0, 0, 0, 0, 'USD', NULL, NULL, NOW()),

  ('DM000002', (SELECT id FROM customers WHERE email='imran@demo.exoticlane.test' LIMIT 1),
   'Imran Chowdhury', 'imran@demo.exoticlane.test', '+8801700000102',
   'airport','one_way','from_airport','Baridhara, Dhaka','Shahjalal International Airport',
   DATE_ADD(CURDATE(), INTERVAL 1 DAY),'14:00:00', NULL, 1, 1,
   (SELECT id FROM vehicles WHERE plate='DEMO-02' LIMIT 1), 19.10, NULL,
   'confirmed','finalized','paid', 61.12, 0, 0, 61.12, 'USD', NOW(), NULL, NOW()),

  ('DM000003', NULL,
   'Nusrat Jahan', 'nusrat@demo.exoticlane.test', '+8801700000103',
   'point_to_point','round_trip',NULL,
   'Lalbagh Fort','Ahsan Manzil', DATE_ADD(CURDATE(), INTERVAL 2 DAY),'11:00:00',
   NULL, 4, 4, (SELECT id FROM vehicles WHERE plate='DEMO-03' LIMIT 1), 44.00, 5.00,
   'assigned','finalized','paid', 581.00, 58.10, 0, 522.90, 'USD', NOW(), NULL, NOW()),

  ('DM000004', (SELECT id FROM customers WHERE email='customer@demo.exoticlane.test' LIMIT 1),
   'Demo Customer', 'customer@demo.exoticlane.test', '+8801700000101',
   'hourly','one_way',NULL,
   'Hotel Sonargao','Hotel Sonargao', DATE_ADD(CURDATE(), INTERVAL 3 DAY),'08:00:00',
   NULL, 6, 3, (SELECT id FROM vehicles WHERE plate='DEMO-05' LIMIT 1), 26.00, 8.00,
   'confirmed','finalized','paid', 787.00, 0, 0, 787.00, 'USD', NOW(), NULL, NOW()),

  ('DM000005', NULL,
   'Walk-in Client', 'walkin@demo.exoticlane.test', '+8801700000199',
   'point_to_point','one_way',NULL,
   'Banani, Dhaka','Dhanmondi 32, Dhaka', CURDATE(),'17:30:00', NULL, 2, 1,
   (SELECT id FROM vehicles WHERE plate='DEMO-01' LIMIT 1), 12.60, NULL,
   'finish','finalized','paid', 30.24, 0, 0, 30.24, 'USD',
   DATE_SUB(NOW(), INTERVAL 3 HOUR), DATE_SUB(NOW(), INTERVAL 2 HOUR), DATE_SUB(NOW(), INTERVAL 5 HOUR)),

  ('DM000006', NULL,
   'Cancelled Client', 'cancelled@demo.exoticlane.test', '+8801700000198',
   'point_to_point','one_way',NULL,
   'Uttara, Dhaka','Mirpur DOHS, Dhaka', DATE_ADD(CURDATE(), INTERVAL 5 DAY),'13:00:00',
   NULL, 3, 2, (SELECT id FROM vehicles WHERE plate='DEMO-02' LIMIT 1), 22.00, NULL,
   'cancelled','finalized','refunded', 70.40, 0, 0, 70.40, 'USD',
   DATE_SUB(NOW(), INTERVAL 2 DAY), NULL, DATE_SUB(NOW(), INTERVAL 1 DAY)),

  ('DM000007', NULL,
   'Event Client', 'event@demo.exoticlane.test', '+8801700000197',
   'point_to_point','one_way',NULL,
   'Hazrat Shahjalal Airport','Purbachal, Dhaka', DATE_SUB(CURDATE(), INTERVAL 2 DAY),'08:15:00',
   NULL, 12, 10, (SELECT id FROM vehicles WHERE plate='DEMO-06' LIMIT 1), 31.00, 4.00,
   'booking_received','awaiting','pending', 0, 0, 0, 0, 'USD', NULL, NULL, DATE_SUB(NOW(), INTERVAL 2 DAY)),

  ('DM000008', NULL,
   'Unpaid Client', 'unpaid@demo.exoticlane.test', '+8801700000196',
   'airport','one_way','to_airport','Shahjalal International Airport','Banani, Dhaka',
   DATE_ADD(CURDATE(), INTERVAL 7 DAY),'23:45:00', NULL, 1, 2,
   (SELECT id FROM vehicles WHERE plate='DEMO-04' LIMIT 1), 20.00, NULL,
   'pending_payment','awaiting','failed', 92.00, 0, 0, 92.00, 'USD', NULL, NULL, NOW());

-- ---------------------------------------------------------------------
-- 8. Booking trail, so the status history on a booking is not empty
-- ---------------------------------------------------------------------
INSERT INTO booking_status_logs (booking_id, old_status, new_status, actor_type, actor_id, note, created_at)
SELECT id, old_s, new_s, who, who_id, note, made
FROM (
  SELECT (SELECT id FROM bookings WHERE booking_number='DM000005') AS id,'pending_payment' AS old_s,'confirmed' AS new_s,'admin' AS who,1 AS who_id,'Payment received by bank transfer.' AS note, DATE_SUB(NOW(), INTERVAL 4 HOUR) AS made
  UNION ALL SELECT (SELECT id FROM bookings WHERE booking_number='DM000005'),'confirmed','assigned','admin',1,'Demo Driver assigned.',DATE_SUB(NOW(), INTERVAL 3 HOUR)
  UNION ALL SELECT (SELECT id FROM bookings WHERE booking_number='DM000005'),'assigned','on_the_way','driver',(SELECT id FROM drivers WHERE email='driver@demo.exoticlane.test'),'Left the garage.',DATE_SUB(NOW(), INTERVAL 3 HOUR)
  UNION ALL SELECT (SELECT id FROM bookings WHERE booking_number='DM000005'),'on_the_way','finish','driver',(SELECT id FROM drivers WHERE email='driver@demo.exoticlane.test'),'Dropped at Dhanmondi 32.',DATE_SUB(NOW(), INTERVAL 2 HOUR)
  UNION ALL SELECT (SELECT id FROM bookings WHERE booking_number='DM000006'),'confirmed','cancelled','customer',(SELECT id FROM customers WHERE email='customer@demo.exoticlane.test'),'Client cancelled; refunded in full.',DATE_SUB(NOW(), INTERVAL 1 DAY)
  UNION ALL SELECT (SELECT id FROM bookings WHERE booking_number='DM000003'),'confirmed','assigned','admin',2,'Rahim Uddin assigned.',DATE_SUB(NOW(), INTERVAL 20 HOUR)
) AS seed;

-- ---------------------------------------------------------------------
-- 9. Stops on the round trip, so the itinerary renders
-- ---------------------------------------------------------------------
INSERT INTO booking_stops (booking_id, stop_order, location)
SELECT id, n, place
FROM (
  SELECT (SELECT id FROM bookings WHERE booking_number='DM000003') AS id, 1 AS n, 'Lalbagh Fort, Dhaka' AS place
  UNION ALL SELECT (SELECT id FROM bookings WHERE booking_number='DM000003'), 2, 'Curzon Park, Dhaka'
  UNION ALL SELECT (SELECT id FROM bookings WHERE booking_number='DM000003'), 3, 'Ahsan Manzil, Dhaka'
) AS seed;

-- ---------------------------------------------------------------------
-- 10. A dispatch, so DM000003 has a driver actually attached
-- ---------------------------------------------------------------------
INSERT INTO dispatches (booking_id, driver_id, vehicle_id, status, assigned_at)
SELECT id,
       (SELECT id FROM drivers WHERE email='rahim@demo.exoticlane.test' LIMIT 1),
       (SELECT id FROM vehicles WHERE plate='DEMO-03' LIMIT 1),
       'assigned', DATE_SUB(NOW(), INTERVAL 20 HOUR)
FROM bookings WHERE booking_number='DM000003' LIMIT 1;

-- ---------------------------------------------------------------------
-- 11. Driver earnings and a payout request
--     A completed trip that paid out, one still owing money, and a driver who
--     has asked to be paid - which is what puts a number on the Payouts tab.
-- ---------------------------------------------------------------------
INSERT INTO driver_earnings (booking_id, driver_id, gross_amount, company_amount, driver_amount, status, earned_at, paid_at)
SELECT id, drv, gross, comp, drv_amt, st, earned, paid
FROM (
  SELECT (SELECT id FROM bookings WHERE booking_number='DM000005') AS id,
         (SELECT id FROM drivers WHERE email='driver@demo.exoticlane.test' LIMIT 1) AS drv,
         30.24 AS gross, 10.58 AS comp, 19.66 AS drv_amt,
         'paid' AS st, DATE_SUB(NOW(), INTERVAL 2 HOUR) AS earned, DATE_SUB(NOW(), INTERVAL 1 HOUR) AS paid
  UNION ALL
  SELECT (SELECT id FROM bookings WHERE booking_number='DM000003'),
         (SELECT id FROM drivers WHERE email='rahim@demo.exoticlane.test' LIMIT 1),
         522.90, 183.02, 339.88, 'unpaid', DATE_SUB(NOW(), INTERVAL 20 HOUR), NULL
) AS seed;

INSERT INTO driver_payouts (driver_id, amount, eligible_at, status, requested_at, notes)
SELECT (SELECT id FROM drivers WHERE email='rahim@demo.exoticlane.test' LIMIT 1),
       339.88, DATE_SUB(NOW(), INTERVAL 19 HOUR), 'requested', DATE_SUB(NOW(), INTERVAL 4 HOUR),
       'Weekly settlement for the wedding run.';

-- ---------------------------------------------------------------------
-- 12. Contact form messages, so admin/notifications.php?view=contact is not
--     empty. Written to look like what people actually send.
-- ---------------------------------------------------------------------
INSERT INTO contact_submissions (name, email, phone, message, status, handled_at, handled_by, created_at)
VALUES
  ('Demo Customer', 'customer@demo.exoticlane.test', '+8801700000101',
   'We land at 22:40 on Friday. Is there someone who can meet the flight and hold the car until customs clears?',
   'handled', DATE_SUB(NOW(), INTERVAL 1 DAY),
   (SELECT id FROM admins WHERE email='admin@demo.exoticlane.test' LIMIT 1), DATE_SUB(NOW(), INTERVAL 1 DAY)),

  ('Arif Chowdhury', 'arif@demo.exoticlane.test', '+8801700000104',
   'Need a 12-seater for a family trip to Cox''s Bazar on 14 December, leaving early morning. What would two vehicles cost for three days?',
   'new', NULL, NULL, DATE_SUB(NOW(), INTERVAL 4 HOUR)),

  ('Nusrat Jahan', 'nusrat@demo.exoticlane.test', '+8801700000103',
   'Do you take card payment at the hotel, or does the driver need cash? Asking because last time it was awkward.',
   'new', NULL, NULL, DATE_SUB(NOW(), INTERVAL 45 MINUTE)),

  ('Farhan Ahmed', 'farhan@demo.exoticlane.test', '+8801700000105',
   'Corporate account enquiry - we move roughly fifteen people a month between offices and the airport. Could someone call me about a monthly rate?',
   'new', NULL, NULL, DATE_SUB(NOW(), INTERVAL 12 MINUTE));

-- ---------------------------------------------------------------------
-- 13. Group and direct-contract enquiries
-- ---------------------------------------------------------------------
INSERT INTO group_event_inquiries
  (event_type, event_dates, vehicle_count, estimated_passengers, locations, schedule,
   special_requirements, contact_name, contact_email, contact_phone, kind, status, quote_amount, admin_notes, created_at)
VALUES
  ('wedding', '21 December', 4, 120, 'Gulshan Club to Tajhat Palace, Rajshahi', '09:00 to 18:00',
   'Two elderly guests need a wheelchair-accessible car. Decorate the lead car.',
   'Rifat Karim', 'rifat@demo.exoticlane.test', '+8801700000106', 'group_event', 'quoted', 185000.00,
   'Quoted four cars plus a spare. Awaiting confirmation.', DATE_SUB(NOW(), INTERVAL 2 DAY)),

  ('conference', '3 to 5 January', 3, 90, 'Bangabandhu International Conference Centre', '07:30 daily',
   'Branded water and a lectern in the lead car.',
   'Sadia Islam', 'sadia.b@demo.exoticlane.test', '+8801700000107', 'group_event', 'new', NULL, NULL,
   DATE_SUB(NOW(), INTERVAL 6 HOUR)),

  ('annual_company', '1 February onward, monthly', 2, 24, 'Head office to client sites across Dhaka', '08:00 to 20:00',
   'Monthly retainer. Same two drivers if possible.',
   'Tanvir Ahmed', 'tanvir@demo.exoticlane.test', '+8801700000108', 'direct_contract', 'confirmed', NULL,
   'Signed. Both drivers assigned.', DATE_SUB(NOW(), INTERVAL 6 DAY)),

  ('birthday', '18 January', 1, 25, 'Banani to Gulshan, round trip', '19:00 to 23:00',
   'Birthday banner on the rear window.',
   'Mitu Rahman', 'mitu@demo.exoticlane.test', '+8801700000109', 'group_event', 'new', NULL, NULL,
   DATE_SUB(NOW(), INTERVAL 25 MINUTE));

-- ---------------------------------------------------------------------
-- 14. Newsletter subscribers
-- ---------------------------------------------------------------------
INSERT INTO newsletter_subscribers (email, status, created_at) VALUES
  ('customer@demo.exoticlane.test', 'active',      DATE_SUB(NOW(), INTERVAL 60 DAY)),
  ('nusrat@demo.exoticlane.test',   'active',      DATE_SUB(NOW(), INTERVAL 34 DAY)),
  ('farhan@demo.exoticlane.test',  'active',      DATE_SUB(NOW(), INTERVAL 9 DAY)),
  ('old.signup@demo.exoticlane.test','unsubscribed', DATE_SUB(NOW(), INTERVAL 120 DAY));

-- ---------------------------------------------------------------------
-- 15. Outbound mail log
--     With no mail server configured these will all fail, which is honest and
--     makes the failure panel on admin/notifications.php meaningful. Mix in a
--     few sent so the counts are not all one colour.
-- ---------------------------------------------------------------------
INSERT INTO notifications (recipient_type, recipient_id, booking_id, template, email, status, error_message, sent_at, created_at)
SELECT who, rid, bid, tpl, mail, st, err, sent, made
FROM (
  SELECT 'customer' AS who, (SELECT id FROM customers WHERE email='customer@demo.exoticlane.test' LIMIT 1) AS rid,
         (SELECT id FROM bookings WHERE booking_number='DM000002' LIMIT 1) AS bid,
         'booking-confirmed' AS tpl, 'customer@demo.exoticlane.test' AS mail, 'sent' AS st,
         NULL AS err, DATE_SUB(NOW(), INTERVAL 2 DAY) AS sent, DATE_SUB(NOW(), INTERVAL 2 DAY) AS made
  UNION ALL SELECT 'customer',(SELECT id FROM customers WHERE email='imran@demo.exoticlane.test' LIMIT 1),(SELECT id FROM bookings WHERE booking_number='DM000002'),'booking-confirmed','imran@demo.exoticlane.test','sent',NULL,DATE_SUB(NOW(), INTERVAL 2 DAY),DATE_SUB(NOW(), INTERVAL 2 DAY)
  UNION ALL SELECT 'customer',(SELECT id FROM customers WHERE email='customer@demo.exoticlane.test' LIMIT 1),(SELECT id FROM bookings WHERE booking_number='DM000003'),'driver-assigned','customer@demo.exoticlane.test','sent',NULL,DATE_SUB(NOW(), INTERVAL 20 HOUR),DATE_SUB(NOW(), INTERVAL 20 HOUR)
  UNION ALL SELECT 'customer',(SELECT id FROM customers WHERE email='customer@demo.exoticlane.test' LIMIT 1),(SELECT id FROM bookings WHERE booking_number='DM000004'),'payment-receipt','customer@demo.exoticlane.test','sent',NULL,DATE_SUB(NOW(), INTERVAL 22 HOUR),DATE_SUB(NOW(), INTERVAL 22 HOUR)
  UNION ALL SELECT 'driver',(SELECT id FROM drivers WHERE email='rahim@demo.exoticlane.test' LIMIT 1),NULL,'trip-assigned','rahim@demo.exoticlane.test','sent',NULL,DATE_SUB(NOW(), INTERVAL 20 HOUR),DATE_SUB(NOW(), INTERVAL 20 HOUR)
  UNION ALL SELECT 'customer',NULL,(SELECT id FROM bookings WHERE booking_number='DM000008'),'payment-request','unpaid@demo.exoticlane.test','failed','Could not connect to mail server: connection refused',NULL,NOW()
  UNION ALL SELECT 'guest',NULL,NULL,'inquiry-received','customer@demo.exoticlane.test','failed','Could not connect to mail server: connection refused',NULL,DATE_SUB(NOW(), INTERVAL 1 DAY)
  UNION ALL SELECT 'guest',NULL,NULL,'inquiry-received','arif@demo.exoticlane.test','failed','Could not connect to mail server: connection refused',NULL,DATE_SUB(NOW(), INTERVAL 4 HOUR)
  UNION ALL SELECT 'guest',NULL,NULL,'inquiry-received','nusrat@demo.exoticlane.test','failed','Could not connect to mail server: connection refused',NULL,DATE_SUB(NOW(), INTERVAL 45 MINUTE)
  UNION ALL SELECT 'guest',NULL,NULL,'inquiry-received','farhan@demo.exoticlane.test','queued',NULL,NULL,DATE_SUB(NOW(), INTERVAL 12 MINUTE)
) AS seed;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- What landed. If any count is 0, the import did not run cleanly.
-- ---------------------------------------------------------------------
SELECT 'admins'              AS tbl, COUNT(*) AS rows_added FROM admins              WHERE email LIKE '%@demo.exoticlane.test'
UNION ALL SELECT 'drivers',        COUNT(*) FROM drivers        WHERE email LIKE '%@demo.exoticlane.test'
UNION ALL SELECT 'vehicles',       COUNT(*) FROM vehicles       WHERE plate LIKE 'DEMO-%'
UNION ALL SELECT 'pricing_rates',  COUNT(*) FROM pricing_rates  WHERE vehicle_id IN (SELECT id FROM vehicles WHERE plate LIKE 'DEMO-%')
UNION ALL SELECT 'customers',      COUNT(*) FROM customers      WHERE email LIKE '%@demo.exoticlane.test'
UNION ALL SELECT 'bookings',       COUNT(*) FROM bookings       WHERE booking_number LIKE 'DM0000__'
UNION ALL SELECT 'dispatches',     COUNT(*) FROM dispatches     WHERE booking_id IN (SELECT id FROM bookings WHERE booking_number LIKE 'DM0000__')
UNION ALL SELECT 'earnings',       COUNT(*) FROM driver_earnings WHERE booking_id IN (SELECT id FROM bookings WHERE booking_number LIKE 'DM0000__')
UNION ALL SELECT 'payouts',        COUNT(*) FROM driver_payouts  WHERE driver_id IN (SELECT id FROM drivers WHERE email LIKE '%@demo.exoticlane.test')
UNION ALL SELECT 'contacts',       COUNT(*) FROM contact_submissions WHERE email LIKE '%@demo.exoticlane.test'
UNION ALL SELECT 'group_inquiries',COUNT(*) FROM group_event_inquiries WHERE contact_email LIKE '%@demo.exoticlane.test'
UNION ALL SELECT 'subscribers',    COUNT(*) FROM newsletter_subscribers WHERE email LIKE '%@demo.exoticlane.test'
UNION ALL SELECT 'notifications',  COUNT(*) FROM notifications WHERE email LIKE '%@demo.exoticlane.test'
UNION ALL SELECT 'coupons',        COUNT(*) FROM coupons WHERE code LIKE 'DEMO%';
