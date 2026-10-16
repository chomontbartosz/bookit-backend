<?php

namespace BookIt;

defined('ABSPATH') || exit;

/**
 * Business rules: doctors, availability and booking. No HTTP here.
 */
final class BookingService
{
    /** A lock older than this is treated as left over from a crashed request. */
    private const LOCK_TTL = 30;

    /** @return list<array{id:int, name:string, specialization:string, visit_duration:int}> */
    public function get_doctors(): array
    {
        $doctors = get_posts([
            'post_type'   => Schema::DOCTOR,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ]);

        return array_map(static fn (\WP_Post $doctor): array => [
            'id'             => $doctor->ID,
            'name'           => $doctor->post_title,
            'specialization' => (string) get_post_meta($doctor->ID, Schema::META_SPECIALIZATION, true),
            'visit_duration' => (int) get_post_meta($doctor->ID, Schema::META_VISIT_DURATION, true),
        ], $doctors);
    }

    public function doctor_exists(int $doctor_id): bool
    {
        return get_post_type($doctor_id) === Schema::DOCTOR && get_post_status($doctor_id) === 'publish';
    }

    /**
     * Future slots of one doctor, each with a computed `booked` flag.
     *
     * @return list<array{date:string, time:string, booked:bool}>
     */
    public function get_availability(int $doctor_id): array
    {
        $booked = $this->get_booked_keys($doctor_id);
        $now = $this->now();

        $future = array_filter(
            $this->get_schedule($doctor_id),
            static fn (array $slot): bool => self::datetime($slot['date'], $slot['time']) > $now
        );

        return array_values(array_map(static fn (array $slot): array => [
            'date'   => $slot['date'],
            'time'   => $slot['time'],
            'booked' => isset($booked[self::slot_key($slot['date'], $slot['time'])]),
        ], $future));
    }

    /**
     * Validates and books a slot. Rules run from cheapest to most expensive.
     *
     * @param array{doctor_id:int, date:string, time:string, patient_name:string, patient_email:string} $input
     * @return int|\WP_Error New appointment ID.
     */
    public function book(array $input)
    {
        ['doctor_id' => $doctor_id, 'date' => $date, 'time' => $time] = $input;
        $name = trim($input['patient_name']);
        $email = trim($input['patient_email']);

        if (!$doctor_id || !preg_match(Schema::DATE_PATTERN, $date) || !preg_match(Schema::TIME_PATTERN, $time)) {
            return Errors::create('invalid_data');
        }
        if ($name === '' || $email === '') {
            return Errors::create('missing_patient_data');
        }
        if (!is_email($email)) {
            return Errors::create('invalid_email');
        }
        if (self::datetime($date, $time) <= $this->now()) {
            return Errors::create('past_date');
        }
        if (!$this->doctor_exists($doctor_id)) {
            return Errors::create('doctor_not_found');
        }
        if (!$this->slot_exists($doctor_id, $date, $time)) {
            return Errors::create('invalid_slot');
        }

        // Lock the slot so two simultaneous requests can't both pass the "is it free?" check.
        $lock = 'bookit_lock_' . md5($doctor_id . '|' . self::slot_key($date, $time));
        if (!$this->acquire_lock($lock)) {
            return Errors::create('slot_taken');
        }

        try {
            if (isset($this->get_booked_keys($doctor_id)[self::slot_key($date, $time)])) {
                return Errors::create('slot_taken');
            }

            $appointment_id = wp_insert_post([
                'post_type'   => Schema::APPOINTMENT,
                'post_status' => 'publish',
                'post_title'  => sprintf('%s %s · %s · %s', $date, $time, get_the_title($doctor_id), sanitize_text_field($name)),
                'meta_input'  => [
                    Schema::META_DOCTOR_ID     => $doctor_id,
                    Schema::META_DATE          => $date,
                    Schema::META_TIME          => $time,
                    Schema::META_PATIENT_NAME  => sanitize_text_field($name),
                    Schema::META_PATIENT_EMAIL => sanitize_email($email),
                ],
            ], true);

            return is_wp_error($appointment_id) ? Errors::create('booking_failed') : $appointment_id;
        } finally {
            delete_option($lock);
        }
    }

    /** @return list<array{date:string, time:string}> */
    private function get_schedule(int $doctor_id): array
    {
        $slots = json_decode((string) get_post_meta($doctor_id, Schema::META_SLOTS, true), true);

        return is_array($slots) ? array_values(array_filter($slots, [self::class, 'is_valid_slot'])) : [];
    }

    private function slot_exists(int $doctor_id, string $date, string $time): bool
    {
        foreach ($this->get_schedule($doctor_id) as $slot) {
            if ($slot['date'] === $date && $slot['time'] === $time) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> "date|time" => true */
    private function get_booked_keys(int $doctor_id): array
    {
        $appointment_ids = get_posts([
            'post_type'   => Schema::APPOINTMENT,
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_key'    => Schema::META_DOCTOR_ID,
            'meta_value'  => $doctor_id,
        ]);

        $keys = [];
        foreach ($appointment_ids as $id) {
            $date = get_post_meta($id, Schema::META_DATE, true);
            $time = get_post_meta($id, Schema::META_TIME, true);
            $keys[self::slot_key($date, $time)] = true;
        }

        return $keys;
    }

    /** `add_option` is atomic (unique option_name), so it works as a lightweight mutex. */
    private function acquire_lock(string $name): bool
    {
        if (add_option($name, time(), '', false)) {
            return true;
        }
        if (time() - (int) get_option($name) > self::LOCK_TTL) {
            delete_option($name);

            return add_option($name, time(), '', false);
        }

        return false;
    }

    /** Current site-local time as "YYYY-MM-DD HH:MM" (WordPress time zone setting). */
    private function now(): string
    {
        return current_time('Y-m-d H:i');
    }

    /** Lookup key for a slot: "YYYY-MM-DD|HH:MM". */
    private static function slot_key(string $date, string $time): string
    {
        return $date . '|' . $time;
    }

    /** Sortable local date-time: "YYYY-MM-DD HH:MM" (same format as now()). */
    private static function datetime(string $date, string $time): string
    {
        return $date . ' ' . $time;
    }

    private static function is_valid_slot(mixed $slot): bool
    {
        return is_array($slot)
            && preg_match(Schema::DATE_PATTERN, (string) ($slot['date'] ?? ''))
            && preg_match(Schema::TIME_PATTERN, (string) ($slot['time'] ?? ''));
    }
}
