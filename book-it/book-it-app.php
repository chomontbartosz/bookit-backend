<?php
/**
 * Plugin Name:       Book.it
 * Description:       Headless appointment booking: experts, availability and bookings via a REST API.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            bartosz.space
 * Author URI:        https://bartosz.space
 */

namespace BookIt;

defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/Schema.php';
require_once __DIR__ . '/includes/Errors.php';
require_once __DIR__ . '/includes/PostTypes.php';
require_once __DIR__ . '/includes/BookingService.php';
require_once __DIR__ . '/includes/RestController.php';
require_once __DIR__ . '/includes/DemoData.php';

// Data model.
add_action('init', [PostTypes::class, 'register']);
add_filter('use_block_editor_for_post_type', [PostTypes::class, 'use_block_editor'], 10, 2);

// REST API.
add_action('rest_api_init', static function (): void {
    (new RestController(new BookingService()))->register_routes();
});

// Demo data. Remove these two lines (and includes/DemoData.php) when real schedules are used.
DemoData::register();
register_deactivation_hook(__FILE__, [DemoData::class, 'unschedule']);
