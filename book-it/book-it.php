        <?php
        /**
         * Plugin Name: Book.it
         * Description: Custom visit appointments with headless REST API.
         * Version: 1.1.0
         * Author: bartosz.space
         * Author URI: https://bartosz.space
         */

        if (!defined('ABSPATH')) {
            exit;
        }

        final class BookItClinic
        {
            public function __construct()
            {
                add_action('init', [$this, 'register_post_types']);
                add_action('init', [$this, 'register_meta_fields']);
                add_action('rest_api_init', [$this, 'register_rest_routes']);
            }

            public function register_post_types(): void
            {
                register_post_type('doctor', [
                    'label' => 'Doctors',
                    'public' => false,
                    'show_ui' => true,
                    'show_in_rest' => true,
                    'supports' => ['title', 'custom-fields'],
                ]);

                register_post_type('appointment', [
                    'label' => 'Appointments',
                    'public' => false,
                    'show_ui' => true,
                    'show_in_rest' => false,
                    'supports' => ['title'],
                ]);
            }

            public function register_meta_fields(): void
            {
                register_post_meta('doctor', 'specialization', [
                    'type' => 'string',
                    'single' => true,
                    'show_in_rest' => true,
                ]);

                register_post_meta('doctor', 'visit_duration', [
                    'type' => 'integer',
                    'single' => true,
                    'show_in_rest' => true,
                ]);

                register_post_meta('doctor', 'availability_slots', [
                    'type' => 'string',
                    'single' => true,
                    'show_in_rest' => true,
                ]);
            }

            public function register_rest_routes(): void
            {
                // Lista lekarzy
                register_rest_route('clinic/v1', '/doctors', [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_doctors'],
                ]);

                // Dostępność konkretnego lekarza
                register_rest_route('clinic/v1', '/doctors/(?P<id>\d+)/availability', [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_doctor_availability'],
                ]);

                // Rezerwacja wizyty
                register_rest_route('clinic/v1', '/appointments', [
                    'methods' => 'POST',
                    'callback' => [$this, 'create_appointment'],
                    'permission_callback' => '__return_true',
                    'args' => [
                        'doctor_id' => ['required' => true, 'type' => 'integer'],
                        'date' => ['required' => true, 'type' => 'string'],
                        'time' => ['required' => true, 'type' => 'string'],
                        'patient_name' => ['required' => false, 'type' => 'string'],
                        'patient_email' => ['required' => false, 'type' => 'string'],
                    ],
                ]);
            }

            // GET /doctors
            public function get_doctors()
            {
                $doctors = get_posts([
                    'post_type' => 'doctor',
                    'numberposts' => -1,
                ]);

                return array_map(function ($doctor) {
                    return [
                        'id' => $doctor->ID,
                        'name' => $doctor->post_title,
                        'specialization' => get_post_meta($doctor->ID, 'specialization', true),
                        'visit_duration' => get_post_meta($doctor->ID, 'visit_duration', true),
                    ];
                }, $doctors);
            }

            // GET /doctors/{id}/availability
            public function get_doctor_availability($request)
            {
                $doctor_id = (int) $request['id'];
                $slots_json = get_post_meta($doctor_id, 'availability_slots', true);
                $available_slots = json_decode($slots_json ?: '[]', true);

                // Pobieramy zajęte sloty
                $appointments = get_posts([
                    'post_type' => 'appointment',
                    'meta_query' => [
                        [
                            'key' => 'doctor_id',
                            'value' => $doctor_id,
                        ],
                    ],
                    'numberposts' => -1,
                ]);

                $booked_slots = array_map(function ($a) {
                    return [
                        'date' => get_post_meta($a->ID, 'date', true),
                        'time' => get_post_meta($a->ID, 'time', true),
                    ];
                }, $appointments);

                // Zaznaczamy które sloty są zajęte
                foreach ($available_slots as &$slot) {
                    $slot['booked'] = false;
                    foreach ($booked_slots as $b) {
                        if ($slot['date'] === $b['date'] && $slot['time'] === $b['time']) {
                            $slot['booked'] = true;
                            break;
                        }
                    }
                }

                return $available_slots;
            }

            // POST /appointments
            public function create_appointment(\WP_REST_Request $request)
            {
                $params = $request->get_json_params();

                $doctor_id = intval($params['doctor_id'] ?? 0);
                $date = sanitize_text_field($params['date'] ?? '');
                $time = sanitize_text_field($params['time'] ?? '');
                $patient_name  = sanitize_text_field($params['patient_name'] ?? '');
    $patient_email = sanitize_email($params['patient_email'] ?? '');

    if (empty($patient_name) || empty($patient_email)) {
        return new WP_Error(
            'missing_patient_data',
            'Patient name and email are required',
            ['status' => 400]
        );
    }

    if (!is_email($patient_email)) {
        return new WP_Error(
            'invalid_email',
            'Invalid email address',
            ['status' => 422]
        );
    }


                $today = date('Y-m-d');

        if ($date < $today) {
            return new WP_Error(
                'past_date',
                'Cannot book appointments in the past',
                ['status' => 400]
            );
        }


                if (!$doctor_id || !$date || !$time) {
                    return new WP_Error('invalid_data', 'Missing required fields', ['status' => 400]);
                }

                // Pobieramy dostępne sloty z meta pola
                $slots_json = get_post_meta($doctor_id, 'availability_slots', true);
                $available_slots = json_decode($slots_json ?: '[]', true);

                $slot_exists = false;
                foreach ($available_slots as $slot) {
                    if ($slot['date'] === $date && $slot['time'] === $time) {
                        $slot_exists = true;
                        break;
                    }
                }

                if (!$slot_exists) {
                    return new WP_Error('invalid_slot', 'Selected slot does not exist', ['status' => 400]);
                }

                // Sprawdzenie, czy slot już zajęty
                $existing = get_posts([
                    'post_type' => 'appointment',
                    'meta_query' => [
                        ['key' => 'doctor_id', 'value' => $doctor_id],
                        ['key' => 'date', 'value' => $date],
                        ['key' => 'time', 'value' => $time],
                    ],
                    'numberposts' => -1,
                ]);

                if (!empty($existing)) {
                    return new WP_Error('slot_taken', 'This slot is already booked', ['status' => 409]);
                }

                // Tworzymy appointment
                $appointment_id = wp_insert_post([
                    'post_type' => 'appointment',
                    'post_status' => 'publish',
                    'post_title' => "Visit – Doctor #{$doctor_id} ({$date} {$time})",
                    'post_content' => "Appointment on {$date} at {$time}. Patient: {$patient_name}, Email: {$patient_email}",
                ]);

                add_post_meta($appointment_id, 'doctor_id', $doctor_id);
                add_post_meta($appointment_id, 'date', $date);
                add_post_meta($appointment_id, 'time', $time);
                add_post_meta($appointment_id, 'patient_name', $patient_name);
                add_post_meta($appointment_id, 'patient_email', $patient_email);

                return [
                    'success' => true,
                    'appointment_id' => $appointment_id,
                ];
            }
        }

        new BookItClinic();

        // Wyłącz blokowy edytor dla CPT doctor
        add_filter('use_block_editor_for_post_type', function ($use, $post_type) {
            if ($post_type === 'doctor') return false;
            return $use;
        }, 10, 2);
