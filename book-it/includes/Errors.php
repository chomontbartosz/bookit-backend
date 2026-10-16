<?php

namespace BookIt;

defined('ABSPATH') || exit;

/**
 * Every API error: code → HTTP status + message. The frontend translates by `code`.
 */
final class Errors
{
    private const DEFINITIONS = [
        'invalid_data'         => [400, 'Doctor, date and time are required in a valid format.'],
        'missing_patient_data' => [400, 'Patient name and email are required.'],
        'invalid_email'        => [422, 'Invalid email address.'],
        'past_date'            => [400, 'Cannot book appointments in the past.'],
        'invalid_slot'         => [400, 'Selected slot does not exist.'],
        'doctor_not_found'     => [404, 'Doctor not found.'],
        'slot_taken'           => [409, 'This slot is already booked.'],
        'too_many_requests'    => [429, 'Too many booking attempts. Try again later.'],
        'booking_failed'       => [500, 'The appointment could not be saved.'],
    ];

    public static function create(string $code): \WP_Error
    {
        [$status, $message] = self::DEFINITIONS[$code];

        return new \WP_Error($code, $message, ['status' => $status]);
    }
}
