<?php
/**
 * Book.it - demo availability generator
 *
 * Generates 15 random availability slots for every doctor
 * for the next 30 days.
 *
 * DEMO ONLY.
 * Remove this file when the project goes live with real availability.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generate demo availability for all doctors.
 */
function bookit_generate_demo_availability(): void
{
    $doctors = get_posts([
        'post_type'      => 'doctor',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
    ]);

    if (empty($doctors)) {
        return;
    }

    $times = [
        '08:00',
        '08:30',
        '09:00',
        '09:30',
        '10:00',
    ];

    foreach ($doctors as $doctor) {
        $slots = [];

        while (count($slots) < 15) {
            $date = wp_date(
                'Y-m-d',
                strtotime('+' . wp_rand(0, 29) . ' days')
            );

            $time = $times[array_rand($times)];
            $key = $date . '|' . $time;

            // Avoid duplicate date + time.
            if (isset($slots[$key])) {
                continue;
            }

            $slots[$key] = [
                'date' => $date,
                'time' => $time,
            ];
        }

        $slots = array_values($slots);

        // Sort chronologically.
        usort($slots, function ($a, $b) {
            return strcmp(
                $a['date'] . ' ' . $a['time'],
                $b['date'] . ' ' . $b['time']
            );
        });

        update_post_meta(
            $doctor->ID,
            'availability_slots',
            wp_json_encode($slots)
        );
    }

    update_option(
        'bookit_demo_availability_last_generated',
        time(),
        false
    );
}

/**
 * Daily WP-Cron callback.
 */
add_action(
    'bookit_daily_demo_availability',
    'bookit_generate_demo_availability'
);

/**
 * Schedule the daily cron and keep demo data fresh.
 *
 * WP-Cron runs when WordPress receives traffic, so the freshness
 * check makes sure the first visitor after a new day gets fresh data.
 */
function bookit_demo_availability_bootstrap(): void
{
    if (!wp_next_scheduled('bookit_daily_demo_availability')) {
        wp_schedule_event(
            time() + 60,
            'daily',
            'bookit_daily_demo_availability'
        );
    }

    $last_generated = (int) get_option(
        'bookit_demo_availability_last_generated',
        0
    );

    // Generate immediately on first use or after 24 hours.
    if ($last_generated === 0 || (time() - $last_generated) >= DAY_IN_SECONDS) {
        bookit_generate_demo_availability();
    }
}

add_action(
    'init',
    'bookit_demo_availability_bootstrap',
    20
);
