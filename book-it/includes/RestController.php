<?php

namespace BookIt;

defined('ABSPATH') || exit;

/**
 * REST layer: routes, input parsing, rate limiting. Business rules live in BookingService.
 */
final class RestController
{
    /** Max booking attempts per client IP per window. */
    private const RATE_LIMIT = 10;
    private const RATE_WINDOW = 10 * MINUTE_IN_SECONDS;

    public function __construct(private BookingService $service)
    {
    }

    public function register_routes(): void
    {
        register_rest_route(Schema::REST_NAMESPACE, '/doctors', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_doctors'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(Schema::REST_NAMESPACE, '/doctors/(?P<id>\d+)/availability', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_availability'],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => ['type' => 'integer', 'sanitize_callback' => 'absint'],
            ],
        ]);

        // Fields are validated in BookingService so every failure returns our own error code.
        register_rest_route(Schema::REST_NAMESPACE, '/appointments', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'create_appointment'],
            'permission_callback' => '__return_true',
            'args'                => [
                'doctor_id'     => ['type' => 'integer', 'sanitize_callback' => 'absint'],
                'date'          => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'time'          => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'patient_name'  => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'patient_email' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    /** GET /doctors */
    public function get_doctors(): \WP_REST_Response
    {
        return rest_ensure_response($this->service->get_doctors());
    }

    /** GET /doctors/{id}/availability */
    public function get_availability(\WP_REST_Request $request)
    {
        $doctor_id = (int) $request['id'];
        if (!$this->service->doctor_exists($doctor_id)) {
            return Errors::create('doctor_not_found');
        }

        return rest_ensure_response($this->service->get_availability($doctor_id));
    }

    /** POST /appointments */
    public function create_appointment(\WP_REST_Request $request)
    {
        if ($this->is_rate_limited()) {
            return Errors::create('too_many_requests');
        }

        $result = $this->service->book([
            'doctor_id'     => (int) $request->get_param('doctor_id'),
            'date'          => (string) $request->get_param('date'),
            'time'          => (string) $request->get_param('time'),
            'patient_name'  => (string) $request->get_param('patient_name'),
            'patient_email' => (string) $request->get_param('patient_email'),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(['success' => true, 'appointment_id' => $result]);
    }

    private function is_rate_limited(): bool
    {
        $key = 'bookit_rate_' . md5($this->client_ip());
        $attempts = (int) get_transient($key);

        if ($attempts >= self::RATE_LIMIT) {
            return true;
        }
        set_transient($key, $attempts + 1, self::RATE_WINDOW);

        return false;
    }

    /** Behind the Vercel proxy REMOTE_ADDR is Vercel's, so the client is the first X-Forwarded-For entry. */
    private function client_ip(): string
    {
        $forwarded = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        $ip = trim($forwarded[0]) ?: (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return sanitize_text_field(wp_unslash($ip));
    }
}
