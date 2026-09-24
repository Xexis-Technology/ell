<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Email notifications (last.md §27, §104).
 * Primary channel: email via PHPMailer/SMTP. Failures logged, never expose
 * internals, never roll back booking/payment on email failure.
 */
final class NotificationService
{
    public const TEMPLATES = ['booking-created','booking-received-awaiting-pricing','payment-received','booking-confirmed','booking-cancelled','refund-processed','driver-assigned','driver-status-update','pickup-time-updated','waiting-charge','password-reset','inquiry-received','payment-link'];

    public static function configured(PDO $pdo): bool
    {
        $cfg = require APP_ROOT . '/config/mail.php';
        return $cfg['host'] !== '' && $cfg['from_address'] !== '';
    }

    public static function send(PDO $pdo, string $template, string $toEmail, string $subject, string $htmlBody, string $recipientType = 'guest', ?int $recipientId = null, ?int $bookingId = null): bool
    {
        if (!in_array($template, self::TEMPLATES, true)) $template = 'booking-created';
        $cfg = require APP_ROOT . '/config/mail.php';
        $status = 'failed';
        $error = null;
        $ref = null;
        try {
            if (!self::configured($pdo)) {
                throw new RuntimeException('SMTP not configured.');
            }
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $cfg['host'];
            $mail->Port = $cfg['port'];
            $mail->SMTPAuth = $cfg['username'] !== '';
            if ($mail->SMTPAuth) {
                $mail->Username = $cfg['username'];
                $mail->Password = $cfg['password'];
            }
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->setFrom($cfg['from_address'], $cfg['from_name']);
            $mail->addAddress($toEmail);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);
            $mail->send();
            $status = 'sent';
            $ref = $mail->getLastMessageID();
        } catch (Throwable $ex) {
            $status = 'failed';
            $error = substr('Email delivery failed.', 0, 255);
            log_error('mail failed [' . $template . ' -> ' . $toEmail . ']: ' . $ex->getMessage());
        }
        try {
            $st = $pdo->prepare('INSERT INTO notifications (recipient_type, recipient_id, booking_id, template, email, status, provider_reference, error_message, sent_at) VALUES (?,?,?,?,?,?,?,?,?)');
            $st->execute([$recipientType, $recipientId, $bookingId, $template, $toEmail, $status, $ref, $error, $status === 'sent' ? date('Y-m-d H:i:s') : null]);
        } catch (Throwable) {
        }
        return $status === 'sent';
    }

    public static function bookingEmail(PDO $pdo, string $template, array $booking, string $toEmail, ?int $recipientId = null, string $recipientType = 'customer', array $extra = []): bool
    {
        $subjects = [
            'booking-created' => 'Your booking ' . ($booking['booking_number'] ?? '') . ' was created',
            'booking-received-awaiting-pricing' => 'Booking received - awaiting final price',
            'payment-received' => 'Payment received for booking ' . ($booking['booking_number'] ?? ''),
            'booking-confirmed' => 'Booking ' . ($booking['booking_number'] ?? '') . ' confirmed',
            'booking-cancelled' => 'Booking ' . ($booking['booking_number'] ?? '') . ' cancelled',
            'refund-processed' => 'Refund processed for booking ' . ($booking['booking_number'] ?? ''),
            'driver-assigned' => 'Driver assigned for booking ' . ($booking['booking_number'] ?? ''),
            'driver-status-update' => 'Trip update for booking ' . ($booking['booking_number'] ?? ''),
            'pickup-time-updated' => 'Pickup time updated for booking ' . ($booking['booking_number'] ?? ''),
            'waiting-charge' => 'Waiting charge notice for booking ' . ($booking['booking_number'] ?? ''),
            'payment-link' => 'Complete payment for booking ' . ($booking['booking_number'] ?? ''),
        ];
        $subject = $extra['subject'] ?? ($subjects[$template] ?? 'Exotic Lane Limo notification');
        $body = $extra['html'] ?? ('<p>Hello,</p><p>Booking <strong>' . e($booking['booking_number'] ?? '') . '</strong>: ' . e($subject) . '.</p><p>View details: <a href="' . e(url('services/booking-confirmation.php?n=' . ($booking['booking_number'] ?? ''))) . '">booking confirmation</a>.</p>');
        $orgName = (string)(setting($pdo, 'org_name', 'Exotic Lane Limo'));
        ob_start();
        require APP_ROOT . '/views/emails/layout.php';
        $layout = (string)ob_get_clean();
        return self::send($pdo, $template, $toEmail, $subject, $layout, $recipientType, $recipientId, isset($booking['id']) ? (int)$booking['id'] : null);
    }
}
