<?php

namespace BookIt;

defined('ABSPATH') || exit;

/**
 * Every name the plugin stores or exposes: the single source of truth.
 */
final class Schema
{
    public const REST_NAMESPACE = 'clinic/v1';

    // Post types.
    public const DOCTOR = 'doctor';
    public const APPOINTMENT = 'appointment';

    // Doctor meta.
    public const META_SPECIALIZATION = 'specialization';
    public const META_VISIT_DURATION = 'visit_duration';
    /** JSON string: [{"date":"YYYY-MM-DD","time":"HH:MM"}]. */
    public const META_SLOTS = 'availability_slots';

    // Appointment meta.
    public const META_DOCTOR_ID = 'doctor_id';
    public const META_DATE = 'date';
    public const META_TIME = 'time';
    public const META_PATIENT_NAME = 'patient_name';
    public const META_PATIENT_EMAIL = 'patient_email';

    // Formats (local site time).
    public const DATE_FORMAT = 'Y-m-d';
    public const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';
    public const TIME_PATTERN = '/^\d{2}:\d{2}$/';
}
