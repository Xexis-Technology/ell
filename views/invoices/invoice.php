<?php
/**
 * Shared invoice document (browser + PDF).
 * Variables: $b (booking+vehicle+invoice row), $charges (line items),
 * $customerName, $customerEmail, $orgName. Returns $html string.
 */
/** @var array $b */ /** @var array $charges */
$rows = '';
foreach ($charges as $c) {
    $rows .= '<p>' . e($c['description']) . ' — $' . money($c['total']) . '</p>';
}
$html = '<h1>Exotic Lane Limo — Invoice ' . e($b['invoice_number'] ?? $b['booking_number']) . '</h1>'
    . '<p>' . e($orgName) . ' · Booking ' . e($b['booking_number']) . '</p>'
    . '<p>Customer: ' . e($customerName) . ' (' . e($customerEmail) . ')</p>'
    . '<p>Trip: ' . e($b['pickup_location']) . ' → ' . e($b['destination_location']) . ' on ' . e($b['pickup_date']) . ' ' . e(substr($b['pickup_time'], 0, 5)) . '</p>'
    . '<p>Vehicle: ' . e(trim(($b['make'] ?? '') . ' ' . ($b['model'] ?? ''))) . '</p><hr>'
    . $rows
    . '<hr><p>Subtotal $' . money($b['subtotal']) . ' · Discount $' . money($b['discount']) . ' · Tax $' . money($b['tax']) . '</p>'
    . '<h2>Total $' . money($b['total']) . ' (' . e($b['payment_status']) . ')</h2>';
