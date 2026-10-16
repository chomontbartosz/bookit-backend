<?php

namespace BookIt;

defined('ABSPATH') || exit;

/**
 * DEMO ONLY: gives every doctor random slots so the public demo never runs out of dates.
 * Remove this file (and its lines in book-it-app.php) when real schedules are used.
 */
final class DemoData
{
    private const CRON_HOOK = 'bookit_daily_demo_availability';
    private const LAST_RUN_OPTION = 'bookit_demo_availability_last_generated';

    private const SLOTS_PER_DOCTOR = 15;
    private const DAYS_AHEAD = 30;
    private const TIMES = ['08:00', '08:30', '09:00', '09:30', '10:00'];

    public static function register(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'generate']);
        add_action('init', [self::class, 'ensure_fresh'], 20);
    }

    /**
     * Schedules the daily cron and regenerates data older than 24 h.
     * WP-Cron only runs on traffic, so this check covers quiet days.
     */
    public static function ensure_fresh(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK);
        }

        $last_run = (int) get_option(self::LAST_RUN_OPTION, 0);
        if (time() - $last_run >= DAY_IN_SECONDS) {
            self::generate();
        }
    }

    public static function generate(): void
    {
        $doctor_ids = get_posts([
            'post_type'   => Schema::DOCTOR,
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);

        foreach ($doctor_ids as $doctor_id) {
            update_post_meta($doctor_id, Schema::META_SLOTS, wp_json_encode(self::random_slots()));
        }

        update_option(self::LAST_RUN_OPTION, time(), false);
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /** @return list<array{date:string, time:string}> Unique, chronologically sorted. */
    private static function random_slots(): array
    {
        $slots = [];
        while (count($slots) < self::SLOTS_PER_DOCTOR) {
            $date = wp_date(Schema::DATE_FORMAT, strtotime('+' . wp_rand(0, self::DAYS_AHEAD - 1) . ' days'));
            $time = self::TIMES[array_rand(self::TIMES)];
            $slots["{$date} {$time}"] = ['date' => $date, 'time' => $time];
        }
        ksort($slots);

        return array_values($slots);
    }
}
