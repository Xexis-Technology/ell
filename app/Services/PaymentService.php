<?php
declare(strict_types=1);

/**
 * Stripe payments + secure payment links + invoices (last.md §15, §25, §26, §105).
 * Webhook is authoritative. Idempotent. Double-payment protected.
 */
final class PaymentService
{
    public static function createPaymentLink(PDO $pdo, int $bookingId, int $ttlHours = 72): array
    {
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $st = $pdo->prepare('INSERT INTO payment_links (booking_id, token_hash, expires_at) VALUES (?,?,?)');
        $st->execute([$bookingId, $hash, date('Y-m-d H:i:s', time() + $ttlHours * 3600)]);
        audit($pdo, 'system', null, 'payment.link_created', 'booking', $bookingId, null);
        return ['token' => $raw, 'url' => url('services/payment.php?token=' . $raw)];
    }

    public static function resolvePaymentLink(PDO $pdo, string $raw): ?array
    {
        $hash = hash('sha256', $raw);
        $st = $pdo->prepare('SELECT pl.*, b.booking_number, b.total, b.status, b.payment_status FROM payment_links pl JOIN bookings b ON b.id = pl.booking_id WHERE pl.token_hash = ? LIMIT 1');
        $st->execute([$hash]);
        $row = $st->fetch();
        if (!$row) return null;
        if ($row['used_at'] !== null) return null;
        if (strtotime($row['expires_at']) < time()) return null;
        return $row;
    }

    public static function markLinkUsed(PDO $pdo, int $linkId): void
    {
        $pdo->prepare('UPDATE payment_links SET used_at = NOW() WHERE id = ?')->execute([$linkId]);
    }

    /** Create a Stripe PaymentIntent for a booking (server-recalculated total). */
    public static function createIntent(PDO $pdo, int $bookingId): array
    {
        $cfg = require APP_ROOT . '/config/stripe.php';
        if (!$cfg['configured']) {
            return ['error' => 'Online payment is not configured. Please contact us to complete your booking.'];
        }
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1');
        $st->execute([$bookingId]);
        $b = $st->fetch();
        if (!$b) return ['error' => 'Booking not found.'];
        if (in_array($b['status'], ['awaiting_pricing'], true)) return ['error' => 'Price is not finalized yet.'];
        if ($b['payment_status'] === 'paid') return ['error' => 'This booking is already paid.'];

        // Double-payment prevention: reuse pending intent if fresh
        $st = $pdo->prepare('SELECT * FROM payments WHERE booking_id = ? AND provider = "stripe" AND status IN ("pending","processing") ORDER BY id DESC LIMIT 1');
        $st->execute([$bookingId]);
        $existing = $st->fetch();
        if ($existing && $existing['provider_payment_id']) {
            return ['payment_id' => (int)$existing['id'], 'provider_payment_id' => $existing['provider_payment_id']];
        }

        \Stripe\Stripe::setApiKey($cfg['secret']);
        $amount = (int)round((float)$b['total'] * 100);
        if ($amount <= 0) return ['error' => 'Invalid booking total.'];
        try {
            $intent = \Stripe\PaymentIntent::create([
                'amount' => $amount,
                'currency' => strtolower($b['currency'] ?: 'usd'),
                'metadata' => ['booking_id' => (string)$bookingId, 'booking_number' => $b['booking_number']],
                'description' => 'Exotic Lane Limo booking ' . $b['booking_number'],
            ]);
            $pdo->beginTransaction();
            if ($existing) {
                $pdo->prepare('UPDATE payments SET provider_payment_id = ?, amount = ?, status = "processing", updated_at = NOW() WHERE id = ?')
                    ->execute([$intent->id, $b['total'], $existing['id']]);
                $pid = (int)$existing['id'];
            } else {
                $pdo->prepare('INSERT INTO payments (booking_id, provider, provider_payment_id, amount, currency, status, method) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$bookingId, 'stripe', $intent->id, $b['total'], $b['currency'], 'processing', 'card']);
                $pid = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('UPDATE bookings SET payment_status = "processing" WHERE id = ?')->execute([$bookingId]);
            $pdo->commit();
            return ['payment_id' => $pid, 'provider_payment_id' => $intent->id, 'client_secret' => $intent->client_secret];
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            log_error('Stripe intent failed: ' . $ex->getMessage());
            return ['error' => 'Payment service temporarily unavailable.'];
        }
    }

    /** Apply a webhook event idempotently. Returns true if processed (or already seen). */
    public static function applyEvent(PDO $pdo, string $eventId, string $type, array $object): bool
    {
        try {
            $pdo->prepare('INSERT INTO webhook_events (provider, event_id, type, payload) VALUES (?,?,?,?)')
                ->execute(['stripe', $eventId, $type, json_encode($object)]);
        } catch (Throwable) {
            return true; // already processed
        }
        $piId = $object['id'] ?? null;
        if (!$piId) return true;
        $st = $pdo->prepare('SELECT * FROM payments WHERE provider_payment_id = ? LIMIT 1');
        $st->execute([$piId]);
        $pay = $st->fetch();
        if (!$pay) return true;
        $bookingId = (int)$pay['booking_id'];

        $pdo->beginTransaction();
        try {
            if ($type === 'payment_intent.succeeded') {
                $pdo->prepare('UPDATE payments SET status = "paid", paid_at = NOW(), updated_at = NOW() WHERE id = ? AND status != "paid"')->execute([$pay['id']]);
                $pdo->prepare('UPDATE bookings SET payment_status = "paid", status = "booking_received", updated_at = NOW() WHERE id = ?')->execute([$bookingId]);
                $pdo->prepare('INSERT INTO booking_status_logs (booking_id, old_status, new_status, actor_type, note) VALUES (?,?,?,"system",?)')
                    ->execute([$bookingId, 'pending_payment', 'booking_received', 'Stripe webhook ' . $eventId]);
                self::ensureInvoice($pdo, $bookingId);
            } elseif ($type === 'payment_intent.payment_failed') {
                $pdo->prepare('UPDATE payments SET status = "failed", failure_code = ?, updated_at = NOW() WHERE id = ?')->execute([substr((string)($object['last_payment_error']['code'] ?? 'failed'), 0, 120), $pay['id']]);
                $pdo->prepare('UPDATE bookings SET payment_status = "failed", status = "payment_failed", updated_at = NOW() WHERE id = ? AND payment_status != "paid"')->execute([$bookingId]);
            } elseif ($type === 'charge.refunded' || $type === 'payment_intent.canceled') {
                $pdo->prepare('UPDATE payments SET status = "refunded", updated_at = NOW() WHERE id = ?')->execute([$pay['id']]);
                $pdo->prepare('UPDATE bookings SET payment_status = "refunded", status = "refunded", updated_at = NOW() WHERE id = ?')->execute([$bookingId]);
            }
            $pdo->commit();
            audit($pdo, 'system', null, 'payment.webhook', 'payment', (int)$pay['id'], ['event' => $eventId, 'type' => $type]);
            return true;
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            log_error('webhook apply failed: ' . $ex->getMessage());
            return false;
        }
    }

    /** Create invoice once per booking (idempotent). */
    public static function ensureInvoice(PDO $pdo, int $bookingId): int
    {
        $st = $pdo->prepare('SELECT id FROM invoices WHERE booking_id = ? LIMIT 1');
        $st->execute([$bookingId]);
        $row = $st->fetch();
        if ($row) return (int)$row['id'];
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1');
        $st->execute([$bookingId]);
        $b = $st->fetch();
        $inTx = $pdo->inTransaction();
        if (!$inTx) $pdo->beginTransaction();
        try {
            $num = invoice_number($pdo);
            $st = $pdo->prepare('INSERT INTO invoices (booking_id, invoice_number, subtotal, discount, tax, total, currency, payment_status) VALUES (?,?,?,?,?,?,?,?)');
            $st->execute([$bookingId, $num, $b['subtotal'], $b['discount'], $b['tax'], $b['total'], $b['currency'], $b['payment_status'] === 'paid' ? 'paid' : 'pending']);
            $id = (int)$pdo->lastInsertId();
            if (!$inTx) $pdo->commit();
            return $id;
        } catch (Throwable $ex) {
            if (!$inTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $ex;
        }
    }

    /** Admin offline/manual payment record. Stays explicit. */
    public static function recordOffline(PDO $pdo, int $bookingId, float $amount, int $adminId, string $note = ''): array
    {
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1');
        $st->execute([$bookingId]);
        $b = $st->fetch();
        if (!$b) return ['error' => 'Booking not found.'];
        if ($b['payment_status'] === 'paid') return ['error' => 'Already paid.'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO payments (booking_id, provider, amount, currency, status, method, paid_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$bookingId, 'offline', $amount, $b['currency'], 'paid', 'offline', date('Y-m-d H:i:s')]);
            $pid = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE bookings SET payment_status = "paid", status = "booking_received", updated_at = NOW() WHERE id = ?')->execute([$bookingId]);
            $pdo->prepare('INSERT INTO booking_status_logs (booking_id, old_status, new_status, actor_type, actor_id, note) VALUES (?,?,?,?,?,?)')
                ->execute([$bookingId, $b['status'], 'booking_received', 'admin', $adminId, 'Offline payment: ' . substr($note, 0, 200)]);
            self::ensureInvoice($pdo, $bookingId);
            $pdo->commit();
            audit($pdo, 'admin', $adminId, 'payment.offline', 'payment', $pid, ['booking' => $bookingId, 'amount' => $amount]);
            return ['ok' => true, 'payment_id' => $pid];
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['error' => 'Could not record payment.'];
        }
    }
}
