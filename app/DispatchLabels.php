<?php
declare(strict_types=1);

/**
 * What a job status is called, and what the driver does next.
 *
 * The database speaks in snake_case plumbing - at_pickup_location, on_the_way -
 * and that language was leaking straight onto the driver's screen as a raw
 * status string next to a dropdown offering every status at once. It is kept
 * here so a wording change is one edit, and so the label a driver reads always
 * matches the button that moves them there.
 */
final class DispatchLabels
{
    /** @return array{label:string,tone:string,icon:string,live:bool,closed:string} */
    public static function step(string $status): array
    {
        return match ($status) {
            'assigned' => ['label' => 'Assigned', 'tone' => 'wait', 'icon' => 'fa-circle', 'live' => false, 'closed' => ''],
            'on_the_way' => ['label' => 'On the way', 'tone' => 'live', 'icon' => 'fa-location-arrow', 'live' => true, 'closed' => ''],
            'arrived' => ['label' => 'Arrived', 'tone' => 'live', 'icon' => 'fa-location-dot', 'live' => true, 'closed' => ''],
            'at_pickup_location' => ['label' => 'At the pickup', 'tone' => 'live', 'icon' => 'fa-person-waiting', 'live' => true, 'closed' => ''],
            'on_board' => ['label' => 'On board', 'tone' => 'live', 'icon' => 'fa-car-side', 'live' => true, 'closed' => ''],
            'finish' => ['label' => 'Finished', 'tone' => 'ok', 'icon' => 'fa-check', 'live' => false, 'closed' => 'Closed'],
            'cancelled' => ['label' => 'Cancelled', 'tone' => 'bad', 'icon' => 'fa-xmark', 'live' => false, 'closed' => 'Called off by the office'],
            'refunded' => ['label' => 'Refunded', 'tone' => 'bad', 'icon' => 'fa-rotate-left', 'live' => false, 'closed' => 'Called off by the office'],
            'payment_failed' => ['label' => 'Payment problem', 'tone' => 'bad', 'icon' => 'fa-triangle-exclamation', 'live' => false, 'closed' => 'On hold at the office'],
            'pending_payment', 'awaiting_pricing', 'pricing_finalized', 'booking_received', 'confirmed' =>
                ['label' => 'At the office', 'tone' => 'wait', 'icon' => 'fa-hourglass-half', 'live' => false, 'closed' => 'Not assigned yet'],
            default => ['label' => ucwords(str_replace('_', ' ', $status)), 'tone' => 'wait', 'icon' => 'fa-circle', 'live' => false, 'closed' => ''],
        };
    }

    /**
     * The single next step, in the driver's own words.
     *
     * The service only ever allows one step forward, so the old dropdown offered
     * six options and the service refused five of them. A button that says what
     * it does is also the same words the guest gets in the email that follows.
     *
     * @return array{to:string,label:string,final:bool}|null
     */
    public static function next(string $status): ?array
    {
        return match ($status) {
            'assigned' => ['to' => 'on_the_way', 'label' => 'I am on the way', 'final' => false],
            'on_the_way' => ['to' => 'arrived', 'label' => 'I have arrived', 'final' => false],
            'arrived' => ['to' => 'at_pickup_location', 'label' => 'I am at the pickup', 'final' => false],
            'at_pickup_location' => ['to' => 'on_board', 'label' => 'Guest is on board', 'final' => false],
            'on_board' => ['to' => 'finish', 'label' => 'Dropped off', 'final' => true],
            default => null,
        };
    }
}