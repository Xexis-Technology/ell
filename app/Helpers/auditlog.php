<?php
declare(strict_types=1);

/**
 * Reading an audit entry as a sentence.
 *
 * The table stores an action key, an actor id and a JSON metadata blob.
 * That is correct for a machine and unreadable for a person -
 * "payment.refund" with {"amount":25,"reason":"part one"} throws away
 * the reason, which is usually the only part anyone wanted to read.
 *
 * These functions live apart from admin/audit.php so the wording can be
 * reviewed and tested on its own, and so any future surface that shows an
 * entry reads the same sentence rather than inventing its own.
 */

/**
 * Turn a stored action key into a sentence.
 *
 * The metadata is the payload of the entry - "from booking_received to
 * confirmed", "reason: partial refund test" - and a log that shows only the
 * key throws it away. Known shapes are translated; anything unrecognised
 * falls back to reading its metadata plainly rather than pretending to
 * understand it.
 */
function audit_sentence(array $row, array $people = [], array $vehicles = []): array
{
    $action = (string)($row['action'] ?? '');
    $meta = audit_meta($row);
    $num = trim((string)($meta['booking_number'] ?? ''));

    // --- known shapes, most important first --------------------------------
    $known = [
        'booking.created' => ['Booking created', fn() => $num !== '' ? 'Number ' . $num : ''],
        'booking.admin_created' => ['Booking created by staff', fn() => $num !== '' ? 'Number ' . $num : ''],
        'booking.pricing_finalized' => ['Price finalised', fn() => isset($meta['total']) ? format_money((float)$meta['total']) : ''],
        'payment.refund' => ['Refund issued', function () use ($meta) {
            $bits = [];
            if (isset($meta['amount'])) $bits[] = format_money((float)$meta['amount']);
            if (trim((string)($meta['reason'] ?? '')) !== '') $bits[] = (string)$meta['reason'];
            return implode(' · ', $bits);
        }],
        'payment.offline' => ['Payment recorded offline', function () use ($meta) {
            $bits = [];
            if (isset($meta['amount'])) $bits[] = format_money((float)$meta['amount']);
            if (isset($meta['booking'])) $bits[] = 'Booking #' . (int)$meta['booking'];
            return implode(' · ', $bits);
        }],
        'payment.link_created' => ['Payment link created', fn() => ''],
        'payment.link_sent' => ['Payment link sent', fn() => ''],
        'newsletter.subscribed' => ['Newsletter subscriber added', fn() => trim((string)($meta['email'] ?? ''))],
        'contact.submitted' => ['Contact form sent', fn() => trim((string)($meta['email'] ?? ''))],
        'inquiry.created' => ['Inquiry received', fn() => audit_state((string)($meta['kind'] ?? ''))],
        'earnings.created' => ['Earnings recorded', function () use ($meta) {
            $bits = [];
            if (isset($meta['gross'])) $bits[] = 'Gross ' . format_money((float)$meta['gross']);
            if (isset($meta['driver'])) $bits[] = 'Driver ' . format_money((float)$meta['driver']);
            return implode(' · ', $bits);
        }],
        'pricing.mode' => ['Pricing mode changed', fn() => audit_state((string)($meta['mode'] ?? $meta['to'] ?? ''))],
        'pricing.rate_updated' => ['Rates updated', function () use ($meta) {
            $bits = [];
            foreach (['per_mile', 'hourly'] as $k) if (isset($meta[$k])) $bits[] = audit_state($k) . ' ' . format_money((float)$meta[$k]);
            return implode(' · ', $bits);
        }],
        'pricing.charge_saved' => ['Charge type saved', fn() => trim((string)($meta['name'] ?? ''))],
        'pricing.charge_updated' => ['Charge updated', function () use ($meta) {
            $bits = [];
            if (trim((string)($meta['name'] ?? '')) !== '') $bits[] = (string)$meta['name'];
            if (isset($meta['amount'])) $bits[] = format_money((float)$meta['amount']);
            return implode(' · ', $bits);
        }],
        'pricing.charge_deleted' => ['Charge type removed', fn() => trim((string)($meta['name'] ?? $meta['code'] ?? ''))],
        'pricing.coupon_saved' => ['Coupon created', fn() => trim((string)($meta['code'] ?? ''))],
        'pricing.coupon_updated' => ['Coupon changed', function () use ($meta) {
            $bits = [];
            if (trim((string)($meta['code'] ?? '')) !== '') $bits[] = (string)$meta['code'];
            if (isset($meta['value'])) $bits[] = audit_state((string)($meta['type'] ?? '')) . ' ' . rtrim(rtrim(number_format((float)$meta['value'], 2), '0'), '.');
            return implode(' · ', $bits);
        }],
        'pricing.coupon_toggled' => ['Coupon switched', function () use ($meta) {
            return !empty($meta['active']) ? 'on' : 'off';
        }],
        'pricing.coupon_deleted' => ['Coupon deleted', fn() => trim((string)($meta['code'] ?? ''))],
        'pricing.waiting_saved' => ['Waiting rule added', fn() => audit_state((string)($meta['category'] ?? ''))],
        'pricing.waiting_updated' => ['Waiting rule changed', fn() => audit_state((string)($meta['category'] ?? ''))],
        'pricing.waiting_deleted' => ['Waiting rule removed', fn() => audit_state((string)($meta['category'] ?? ''))],
        'vehicle.created' => ['Vehicle added', fn() => audit_subject($row, $people, $vehicles)],
        'vehicle.updated' => ['Vehicle edited', fn() => audit_subject($row, $people, $vehicles)],
        'vehicle.blocked' => ['Vehicle blocked', fn() => ''],
        'vehicle.category_saved' => ['Vehicle category saved', fn() => trim((string)($meta['name'] ?? ''))],
        'vehicle.paperwork_added' => ['Vehicle document added', fn() => trim((string)($meta['name'] ?? ''))],
        'driver.created' => ['Driver added', fn() => audit_subject($row, $people, $vehicles)],
        'driver.updated' => ['Driver edited', fn() => audit_subject($row, $people, $vehicles)],
        'driver.paperwork_added' => ['Driver document added', fn() => trim((string)($meta['name'] ?? ''))],
        'dispatch.assign' => ['Driver and car assigned', function () use ($meta, $people, $vehicles) {
            $bits = [];
            if (!empty($meta['driver'])) $bits[] = $people['driver'][(int)$meta['driver']] ?? ('Driver ' . (int)$meta['driver']);
            if (!empty($meta['vehicle'])) $bits[] = $vehicles[(int)$meta['vehicle']] ?? ('Vehicle ' . (int)$meta['vehicle']);
            return $bits === [] ? 'Unassigned' : implode(' · ', $bits);
        }],
        'content.updated' => ['Page content edited', fn() => audit_subject($row, $people, $vehicles)],
        'settings.operations' => ['Office settings saved', fn() => ''],
        'integration.tested' => ['Integration tested', fn() => audit_subject($row, $people, $vehicles)],
        'integration.keys' => ['Integration keys saved', fn() => audit_subject($row, $people, $vehicles)],
        'auth.login' => ['Signed in', fn() => ''],
        'auth.logout' => ['Signed out', fn() => ''],
        'auth.password_reset' => ['Password reset requested', fn() => audit_subject($row, $people, $vehicles)],
        'auth.register' => ['Account created', fn() => trim((string)($meta['email'] ?? ''))],
    ];
    if (isset($known[$action])) {
        [$verb, $detailFn] = $known[$action];
        return [$verb, (string)$detailFn()];
    }

    // --- status shapes ------------------------------------------------------
    // The subject of a status entry is named by the action prefix, not by the
    // row's entity: trip.status is written against a booking but is about the
    // ride, and "Booking - On board" would be wrong.
    $lead = audit_lead($action, $row);

    // Only a "to" is present: a state the reader cares about on its own. It is
    // not a transition, so it is not drawn as one.
    if (isset($meta['to']) && !isset($meta['from'])) {
        $state = audit_state((string)$meta['to']);
        if ($state !== '') {
            return [$lead . ' — ' . $state, audit_subject($row, $people, $vehicles)];
        }
    }
    // A from/to pair is only a transition when the two ends actually differ.
    if (isset($meta['from'], $meta['to'])) {
        $to = audit_state((string)$meta['to']);
        $from = audit_state((string)$meta['from']);
        if ($from === $to) {
            // Recorded twice at the same state. Saying "arrived -> arrived"
            // is noise; the entry still says the ride was set to Arrived.
            return [$lead . ' — ' . $to, audit_subject($row, $people, $vehicles)];
        }
        return [$lead . ' — ' . $to, $from . ' → ' . $to];
    }
    if (isset($meta['status'])) {
        return [audit_state((string)($row['entity_type'] ?? 'Item')) . ' — ' . audit_state((string)$meta['status']), audit_subject($row, $people, $vehicles)];
    }

    // --- fallback -----------------------------------------------------------
    // An unknown key is read as a phrase. The dot is never shown: a stored key
    // on screen is the thing this page exists to stop doing.
    return [audit_state($action), audit_metadata($meta)];
}

/** What a status entry is about, taken from the action rather than the row. */
function audit_lead(string $action, array $row): string
{
    $prefix = strtok($action, '.');
    $map = [
        'trip' => 'Ride', 'driver' => 'Driver', 'booking' => 'Booking',
        'customer' => 'Customer', 'vehicle' => 'Vehicle', 'dispatch' => 'Dispatch',
    ];
    if (isset($map[$prefix])) return $map[$prefix];
    return audit_state((string)($row['entity_type'] ?? 'Status')) ?: 'Status';
}

/** Value formatting that turns stored keys into something readable. */
function audit_state(string $v): string
{
    // Dots and underscores both become spaces: a stored key must never reach
    // the screen as a key.
    $v = trim(str_replace(['_', '-', '.'], ' ', $v));
    if ($v === '') return '';
    $map = [
        'per mile' => 'Per mile', 'hourly' => 'Hourly',
        'group event' => 'Group event', 'on board' => 'On board',
        'on the way' => 'On the way', 'at pickup location' => 'At pickup',
        'active' => 'Active', 'inactive' => 'Inactive', 'pending' => 'Pending',
        'arrived' => 'Arrived', 'finish' => 'Finished', 'finished' => 'Finished',
        'booking received' => 'Booking received', 'confirmed' => 'Confirmed',
        'pricing finalized' => 'Price finalised', 'cancelled' => 'Cancelled',
    ];
    $k = strtolower($v);
    return $map[$k] ?? ucfirst($v);
}

/** Anything unrecognised, read plainly rather than guessed at. */
function audit_metadata(array $meta): string
{
    $bits = [];
    foreach ($meta as $k => $v) {
        if (is_array($v) || $v === null || $v === '') continue;
        $bits[] = audit_k((string)$k) . ' ' . audit_state((string)$v);
    }
    return implode(' · ', $bits);
}

function audit_k(string $k): string
{
    return ucfirst(str_replace('_', ' ', $k));
}

/**
 * What a change touched, named.
 *
 * "Vehicle 3" is an id; "XTS · ELL-3001" is the thing a reader recognises.
 * Where the row cannot be resolved to a real name the subject is dropped
 * rather than printed as "Payment 1", which tells the reader nothing they did
 * not already have.
 */
function audit_subject(array $row, array $people = [], array $vehicles = []): string
{
    $ent = trim((string)($row['entity_type'] ?? ''));
    $id = $row['entity_id'] ?? null;
    if ($ent === '' || $id === null || $id === '') return '';

    if ($ent === 'vehicle' && isset($vehicles[(int)$id])) return $vehicles[(int)$id];
    if (in_array($ent, ['driver', 'admin', 'customer'], true) && isset($people[$ent][(int)$id])) {
        return $people[$ent][(int)$id];
    }
    // A booking is identified by its number, which lives in the metadata.
    if ($ent === 'booking') {
        $meta = audit_meta($row);
        if (trim((string)($meta['booking_number'] ?? '')) !== '') return 'Booking ' . (string)$meta['booking_number'];
    }
    return '';
}

/** Metadata as an array, or empty when it is absent or malformed. */
function audit_meta(array $row): array
{
    $raw = trim((string)($row['metadata'] ?? ''));
    if ($raw === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function actor_name(array $row, array $people): string
{
    $type = (string)($row['actor_type'] ?? '');
    $id = $row['actor_id'] ?? null;
    if ($type === 'system' || $id === null) return 'System';
    return $people[$type][(int)$id] ?? audit_state($type) . ' ' . (int)$id;
}

/** A device in words, so the column is comparable between rows. */
function audit_device(string $ua): string
{
    if ($ua === '') return '';
    if (stripos($ua, 'PowerShell') !== false) return 'Command line';
    if (stripos($ua, 'HeadlessChrome') !== false) return 'Headless browser';
    if (stripos($ua, 'Chrome') !== false) return 'Chrome';
    if (stripos($ua, 'Safari') !== false) return 'Safari';
    if (stripos($ua, 'Firefox') !== false) return 'Firefox';
    if (stripos($ua, 'Edge') !== false) return 'Edge';
    return 'Browser';
}

function format_money(float $v): string
{
    return '$' . number_format($v, 2);
}
