<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Phase1Test extends TestCase
{
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        $cfg = require APP_ROOT . '/config/database.php';
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['host'], $cfg['port']),
            $cfg['user'],
            $cfg['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec('DROP DATABASE IF EXISTS ell_test');
        $pdo->exec('CREATE DATABASE ell_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $mysql = 'C:\\xampp\\mysql\\bin\\mysql.exe';
        $tmp = sys_get_temp_dir() . '/ell_schema_test.sql';
        $sql = file_get_contents(APP_ROOT . '/database/database.sql');
        $sql = preg_replace('/CREATE DATABASE.*?;/s', '', $sql);
        $sql = preg_replace('/USE ell_db;/', '', $sql);
        file_put_contents($tmp, $sql);
        $cmd = sprintf('"%s" -u %s --password=%s --database=ell_test -e "source %s" 2>&1', $mysql, escapeshellarg($cfg['user']), $cfg['pass'], $tmp);
        // user/pass without quoting issues on local root/empty: use simple form
        $cmd = '"C:\\xampp\\mysql\\bin\\mysql.exe" -u root --database=ell_test -e "source ' . str_replace('\\', '/', $tmp) . '"';
        exec($cmd, $out, $code);
        if ($code !== 0) {
            throw new RuntimeException('Schema import failed: ' . implode("\n", $out));
        }
        $pdo->exec('USE ell_test');
        self::$pdo = $pdo;
        // seed one vehicle + rate
        $pdo->exec("INSERT INTO vehicle_categories (name) VALUES ('Sedan') ON DUPLICATE KEY UPDATE name=VALUES(name)");
        $cat = (int)$pdo->query("SELECT id FROM vehicle_categories WHERE name='Sedan'")->fetch()['id'];
        $pdo->exec("INSERT INTO vehicles (category_id, make, model, year, passenger_capacity, luggage_capacity, status) VALUES ($cat,'Test','Sedan',2024,3,3,'active')");
        $vid = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO pricing_rates (vehicle_id, per_mile_rate, hourly_rate, active) VALUES ($vid, 5.00, 100.00, 1)");
        $pdo->exec("INSERT INTO coupons (code, type, value, active) VALUES ('TEST10','percent',10.00,1) ON DUPLICATE KEY UPDATE value=VALUES(value)");
    }

    public function testUrlHelper(): void
    {
        $this->assertStringStartsWith('http', url('admin/dashboard.php'));
        $this->assertStringEndsWith('/admin/dashboard.php', url('admin/dashboard.php'));
        $this->assertStringEndsWith('/admin/dashboard.php', url('/admin/dashboard.php'));
        $this->assertTrue(defined('APP_ROOT') && is_dir(APP_ROOT));
    }

    public function testBookingNumberFormat(): void
    {
        $n = generate_booking_number(self::$pdo);
        $this->assertMatchesRegularExpression('/^[0-9]{8}$/', $n);
        $this->assertGreaterThanOrEqual(10000000, (int)$n);
    }

    public function testPricingPerMile(): void
    {
        self::$pdo->exec("UPDATE settings SET svalue='per_mile' WHERE skey='pricing_mode'");
        self::$pdo->exec("UPDATE settings SET svalue='0' WHERE skey='tax_percent'");
        $vid = (int)self::$pdo->query('SELECT id FROM vehicles LIMIT 1')->fetch()['id'];
        $calc = PricingService::calculate(self::$pdo, [
            'service_type' => 'point_to_point', 'vehicle_id' => $vid,
            'passengers' => 2, 'luggage' => 1, 'stops' => [],
            'mileage' => 10, 'addons' => [], 'coupon_code' => '',
        ]);
        $this->assertArrayNotHasKey('errors', $calc);
        $this->assertEquals(50.00, $calc['subtotal']);
        $this->assertEquals(50.00, $calc['total']);
    }

    public function testPricingHourlyMinAndCouponTax(): void
    {
        self::$pdo->exec("UPDATE settings SET svalue='hourly' WHERE skey='pricing_mode'");
        setting(self::$pdo, 'x', null, true); // clear settings cache
        self::$pdo->exec("UPDATE settings SET svalue='10' WHERE skey='tax_percent'");
        $vid = (int)self::$pdo->query('SELECT id FROM vehicles LIMIT 1')->fetch()['id'];
        $calc = PricingService::calculate(self::$pdo, [
            'service_type' => 'hourly', 'vehicle_id' => $vid,
            'passengers' => 1, 'luggage' => 0, 'stops' => [],
            'hours' => 1, // below minimum
            'addons' => [], 'coupon_code' => '',
        ]);
        $this->assertArrayHasKey('errors', $calc); // hourly minimum enforced
        $calc = PricingService::calculate(self::$pdo, [
            'service_type' => 'hourly', 'vehicle_id' => $vid,
            'passengers' => 1, 'luggage' => 0, 'stops' => [],
            'hours' => 2, 'addons' => ['meet_greet' => true],
            'coupon_code' => 'TEST10',
        ]);
        $this->assertArrayNotHasKey('errors', $calc);
        // base 200 + meet&greet 25 = 225 subtotal; 10% coupon = 22.50; taxable 202.50; tax 10% = 20.25; total 222.75
        $this->assertEquals(225.00, $calc['subtotal']);
        $this->assertEquals(22.50, $calc['discount']);
        $this->assertEquals(20.25, $calc['tax']);
        $this->assertEquals(222.75, $calc['total']);
        self::$pdo->exec("UPDATE settings SET svalue='per_mile' WHERE skey='pricing_mode'");
        self::$pdo->exec("UPDATE settings SET svalue='0' WHERE skey='tax_percent'");
        setting(self::$pdo, 'x', null, true);
    }

    public function testMaxStops(): void
    {
        $vid = (int)self::$pdo->query('SELECT id FROM vehicles LIMIT 1')->fetch()['id'];
        $calc = PricingService::calculate(self::$pdo, [
            'service_type' => 'point_to_point', 'vehicle_id' => $vid,
            'passengers' => 1, 'luggage' => 0,
            'stops' => ['a','b','c','d','e','f','g'], 'mileage' => 5,
            'addons' => [], 'coupon_code' => '',
        ]);
        $this->assertArrayHasKey('errors', $calc);
    }

    public function testMapsDisabledAwaitingPricing(): void
    {
        self::$pdo->exec("UPDATE settings SET svalue='0' WHERE skey='maps_enabled'");
        self::$pdo->exec("UPDATE settings SET svalue='per_mile' WHERE skey='pricing_mode'");
        setting(self::$pdo, 'x', null, true);
        $vid = (int)self::$pdo->query('SELECT id FROM vehicles LIMIT 1')->fetch()['id'];
        $res = BookingService::create(self::$pdo, [
            'service_type' => 'point_to_point', 'trip_type' => 'one_way',
            'pickup_location' => 'A', 'destination_location' => 'B',
            'pickup_date' => date('Y-m-d', strtotime('+3 days')), 'pickup_time' => '10:00',
            'passengers' => 1, 'luggage' => 0, 'vehicle_id' => $vid,
            'stops' => [], 'addons' => [], 'coupon_code' => '',
            'guest_name' => 'Guest', 'guest_email' => 'g@example.com',
        ], null);
        $this->assertArrayNotHasKey('errors', $res);
        $this->assertEquals('awaiting_pricing', $res['status']);
        // finalize pricing as admin would
        self::$pdo->exec("INSERT INTO admins (name, email, password_hash, status) VALUES ('T','admint@example.com','x','active') ON DUPLICATE KEY UPDATE name=VALUES(name)");
        $aid = (int)self::$pdo->query("SELECT id FROM admins WHERE email='admint@example.com'")->fetch()['id'];
        $r = BookingService::finalizePricing(self::$pdo, (int)$res['booking_id'], ['vehicle_id' => $vid, 'mileage' => 12], $aid);
        $this->assertArrayNotHasKey('error', $r);
        $row = self::$pdo->query('SELECT status, pricing_status, total FROM bookings WHERE id = ' . (int)$res['booking_id'])->fetch();
        $this->assertEquals('pricing_finalized', $row['status']);
        $this->assertEquals('finalized', $row['pricing_status']);
        $this->assertEquals(60.00, (float)$row['total']);
    }

    public function testCouponCustomerLimit(): void
    {
        $pdo = self::$pdo;
        setting($pdo, 'x', null, true);
        $pdo->exec("INSERT INTO coupons (code, type, value, customer_limit, active) VALUES ('ONCE1','fixed',5.00,1,1) ON DUPLICATE KEY UPDATE value=VALUES(value), customer_limit=1, active=1");
        $pdo->exec("INSERT INTO customers (name, email, status) VALUES ('Coupon User','coupon.user@example.com','active') ON DUPLICATE KEY UPDATE name=VALUES(name)");
        $cid = (int)$pdo->query("SELECT id FROM customers WHERE email='coupon.user@example.com'")->fetch()['id'];
        $pdo->exec("DELETE FROM coupon_redemptions WHERE customer_id=$cid");
        $vid = (int)$pdo->query('SELECT id FROM vehicles LIMIT 1')->fetch()['id'];
        $base = [
            'service_type' => 'point_to_point', 'trip_type' => 'one_way',
            'pickup_location' => 'A', 'destination_location' => 'B',
            'pickup_date' => date('Y-m-d', strtotime('+6 days')), 'pickup_time' => '10:00',
            'passengers' => 1, 'luggage' => 0, 'vehicle_id' => $vid,
            'mileage' => 10, 'stops' => [], 'addons' => [], 'coupon_code' => 'ONCE1',
        ];
        $r1 = BookingService::create($pdo, $base, $cid);
        $this->assertArrayNotHasKey('errors', $r1);
        $red = (int)$pdo->query("SELECT COUNT(*) c FROM coupon_redemptions WHERE coupon_id=(SELECT id FROM coupons WHERE code='ONCE1') AND customer_id=$cid")->fetch()['c'];
        $this->assertEquals(1, $red);
        $r2 = BookingService::create($pdo, $base, $cid);
        $this->assertArrayHasKey('errors', $r2); // per-customer limit enforced
        $this->assertArrayHasKey('coupon_code', $r2['errors']);
    }

    public function testEarningsSplitAndDuplicate(): void
    {
        $pdo = self::$pdo;
        $pdo->exec("INSERT INTO drivers (name, email, status) VALUES ('E','earn@example.com','active') ON DUPLICATE KEY UPDATE name=VALUES(name)");
        $did = (int)$pdo->query("SELECT id FROM drivers WHERE email='earn@example.com'")->fetch()['id'];
        $vid = (int)$pdo->query('SELECT id FROM vehicles LIMIT 1')->fetch()['id'];
        $pdo->exec("DELETE FROM driver_earnings WHERE booking_id IN (SELECT id FROM bookings WHERE booking_number='50000001')");
        $pdo->exec("DELETE FROM bookings WHERE booking_number='50000001'");
        $pdo->exec("INSERT INTO bookings (booking_number, service_type, pickup_location, destination_location, pickup_date, pickup_time, vehicle_id, status, payment_status, subtotal, total) VALUES ('50000001','point_to_point','A','B','" . date('Y-m-d', strtotime('+4 days')) . "','10:00',$vid,'finish','paid',5000,5000)");
        $bid = (int)$pdo->lastInsertId();
        $r1 = EarningsService::createForBooking($pdo, $bid, $did);
        $this->assertArrayNotHasKey('error', $r1);
        $row = $pdo->query("SELECT * FROM driver_earnings WHERE booking_id=$bid")->fetch();
        $this->assertEquals(5000.00, (float)$row['gross_amount']);
        $this->assertEquals(1000.00, (float)$row['company_amount']);
        $this->assertEquals(4000.00, (float)$row['driver_amount']);
        $r2 = EarningsService::createForBooking($pdo, $bid, $did); // retry-safe
        $this->assertTrue($r2['ok']);
        $count = (int)$pdo->query("SELECT COUNT(*) c FROM driver_earnings WHERE booking_id=$bid")->fetch()['c'];
        $this->assertEquals(1, $count);
    }

    public function testPayoutEligibility(): void
    {
        $pdo = self::$pdo;
        $did = (int)$pdo->query("SELECT id FROM drivers WHERE email='earn@example.com'")->fetch()['id'];
        $pdo->exec("DELETE FROM driver_payouts WHERE driver_id=$did");
        $elig = EarningsService::payoutEligible($pdo, $did);
        $this->assertTrue($elig['eligible']);
        $pdo->exec("INSERT INTO driver_payouts (driver_id, amount, eligible_at, status) VALUES ($did, 100, NOW(), 'requested')");
        $elig2 = EarningsService::payoutEligible($pdo, $did);
        $this->assertFalse($elig2['eligible']); // 7-day rule
    }

    public function testPickupCutoff(): void
    {
        $pdo = self::$pdo;
        $vid = (int)$pdo->query('SELECT id FROM vehicles LIMIT 1')->fetch()['id'];
        // booking 1 hour from now
        $dt = time() + 3600;
        $pdo->exec("DELETE FROM bookings WHERE booking_number='50000002'");
        $pdo->exec("INSERT INTO bookings (booking_number, service_type, pickup_location, destination_location, pickup_date, pickup_time, vehicle_id, status, payment_status, total) VALUES ('50000002','point_to_point','A','B','" . date('Y-m-d', $dt) . "','" . date('H:i', $dt) . "',$vid,'confirmed','paid',100)");
        $bid = (int)$pdo->lastInsertId();
        $r = BookingService::updatePickupTime($pdo, $bid, date('Y-m-d', time() + 86400), '12:00', 'admin', null);
        $this->assertArrayHasKey('error', $r); // inside 2h cutoff
    }

    public function testWebhookIdempotency(): void
    {
        $pdo = self::$pdo;
        $vid = (int)$pdo->query('SELECT id FROM vehicles LIMIT 1')->fetch()['id'];
        $pdo->exec("DELETE FROM payments WHERE booking_id IN (SELECT id FROM bookings WHERE booking_number='50000003')");
        $pdo->exec("DELETE FROM invoices WHERE booking_id IN (SELECT id FROM bookings WHERE booking_number='50000003')");
        $pdo->exec("DELETE FROM webhook_events WHERE event_id='evt_1'");
        $pdo->exec("DELETE FROM bookings WHERE booking_number='50000003'");
        $pdo->exec("INSERT INTO bookings (booking_number, service_type, pickup_location, destination_location, pickup_date, pickup_time, vehicle_id, status, payment_status, total) VALUES ('50000003','point_to_point','A','B','" . date('Y-m-d', strtotime('+5 days')) . "','10:00',$vid,'pending_payment','processing',100)");
        $bid = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO payments (booking_id, provider, provider_payment_id, amount, status) VALUES ($bid,'stripe','pi_test_123',100,'processing')");
        $this->assertTrue(PaymentService::applyEvent($pdo, 'evt_1', 'payment_intent.succeeded', ['id' => 'pi_test_123']));
        $this->assertTrue(PaymentService::applyEvent($pdo, 'evt_1', 'payment_intent.succeeded', ['id' => 'pi_test_123'])); // replay
        $count = (int)$pdo->query("SELECT COUNT(*) c FROM invoices WHERE booking_id=$bid")->fetch()['c'];
        $this->assertEquals(1, $count); // invoice created exactly once
        $st = $pdo->query("SELECT payment_status FROM bookings WHERE id=$bid")->fetch();
        $this->assertEquals('paid', $st['payment_status']);
    }

    public function testWaitingCharge(): void
    {
        $pdo = self::$pdo;
        $c = WaitingService::calculateCharge($pdo, 'airport', 75);
        $this->assertEquals(60, $c['free']);
        $this->assertEquals(15, $c['billable']);
        $this->assertEquals(30.00, $c['charge']); // 2 intervals x $15
    }

    public function testNewsletterSubscribe(): void
    {
        $pdo = self::$pdo;
        $pdo->exec("DELETE FROM newsletter_subscribers WHERE email='nl@example.com'");
        $pdo->prepare('INSERT INTO newsletter_subscribers (email, status) VALUES (?, "active") ON DUPLICATE KEY UPDATE status = "active", unsubscribed_at = NULL')->execute(['nl@example.com']);
        $pdo->prepare('INSERT INTO newsletter_subscribers (email, status) VALUES (?, "active") ON DUPLICATE KEY UPDATE status = "active", unsubscribed_at = NULL')->execute(['nl@example.com']);
        $count = (int)$pdo->query("SELECT COUNT(*) c FROM newsletter_subscribers WHERE email='nl@example.com'")->fetch()['c'];
        $this->assertEquals(1, $count); // no duplicates
        $pdo->exec("UPDATE newsletter_subscribers SET status='unsubscribed', unsubscribed_at=NOW() WHERE email='nl@example.com'");
        $st = $pdo->query("SELECT status FROM newsletter_subscribers WHERE email='nl@example.com'")->fetch();
        $this->assertEquals('unsubscribed', $st['status']);
    }
}
