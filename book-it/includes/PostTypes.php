<?php

namespace BookIt;

defined('ABSPATH') || exit;

/**
 * Data model: custom post types and their meta fields.
 */
final class PostTypes
{
    private const DOCTOR_META = [
        Schema::META_SPECIALIZATION => 'string',
        Schema::META_VISIT_DURATION => 'integer',
        Schema::META_SLOTS          => 'string',
    ];

    private const APPOINTMENT_META = [
        Schema::META_DOCTOR_ID     => 'integer',
        Schema::META_DATE          => 'string',
        Schema::META_TIME          => 'string',
        Schema::META_PATIENT_NAME  => 'string',
        Schema::META_PATIENT_EMAIL => 'string',
    ];

    public static function register(): void
    {
        register_post_type(Schema::DOCTOR, [
            'label'        => 'Doctors',
            'public'       => false,
            'show_ui'      => true,
            'show_in_rest' => true,
            'menu_icon'    => 'dashicons-groups',
            'supports'     => ['title', 'custom-fields'],
        ]);

        // Patient data: visible in WP admin only, never through REST.
        register_post_type(Schema::APPOINTMENT, [
            'label'        => 'Appointments',
            'public'       => false,
            'show_ui'      => true,
            'show_in_rest' => false,
            'menu_icon'    => 'dashicons-calendar-alt',
            'supports'     => ['title', 'custom-fields'],
        ]);

        self::register_meta(Schema::DOCTOR, self::DOCTOR_META, true);
        self::register_meta(Schema::APPOINTMENT, self::APPOINTMENT_META, false);
    }

    /** Doctors use the classic editor: plain fields, no blocks. */
    public static function use_block_editor(bool $use, string $post_type): bool
    {
        return $post_type === Schema::DOCTOR ? false : $use;
    }

    /** @param array<string, string> $fields meta key => type */
    private static function register_meta(string $post_type, array $fields, bool $in_rest): void
    {
        foreach ($fields as $key => $type) {
            register_post_meta($post_type, $key, [
                'type'         => $type,
                'single'       => true,
                'show_in_rest' => $in_rest,
            ]);
        }
    }
}
