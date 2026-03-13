<?php

if (!defined('ABSPATH')) {
    exit;
}

class SWPM_Member_Create_API {
    public static function register_routes() {
        register_rest_route('swpm-ext/v1', '/member/create', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'handle'],
            'permission_callback' => [__CLASS__, 'permission_check'],
        ]);
    }

    public static function permission_check(WP_REST_Request $request) {
        return SWPM_External_API::authenticate_request($request);
    }

    public static function handle(WP_REST_Request $request) {
        global $wpdb;

        $email            = sanitize_email($request->get_param('email'));
        $user_name        = sanitize_user($request->get_param('user_name'));
        $password         = (string) $request->get_param('password');
        $first_name       = sanitize_text_field($request->get_param('first_name'));
        $last_name        = sanitize_text_field($request->get_param('last_name'));
        $membership_level = absint($request->get_param('membership_level'));
        $account_state    = sanitize_text_field($request->get_param('account_state'));

        if (empty($account_state)) {
            $account_state = 'active';
        }

        if (empty($email) || empty($user_name) || empty($password) || empty($membership_level)) {
            return SWPM_External_API::response_error(
                'email, user_name, password, membership_level are required',
                'VALIDATION_ERROR',
                400
            );
        }

        if (!is_email($email)) {
            return SWPM_External_API::response_error(
                'Invalid email format',
                'VALIDATION_ERROR',
                400
            );
        }

        if (strlen($password) < 8) {
            return SWPM_External_API::response_error(
                'Password must be at least 8 characters',
                'VALIDATION_ERROR',
                400
            );
        }

        if (!in_array($account_state, SWPM_External_API::valid_account_states(), true)) {
            return SWPM_External_API::response_error(
                'Invalid account_state',
                'STATUS_INVALID',
                400
            );
        }

        if (!SWPM_External_API::validate_level_exists($membership_level)) {
            return SWPM_External_API::response_error(
                'Membership level not found',
                'LEVEL_NOT_FOUND',
                404
            );
        }

        $members_table = SWPM_External_API::get_members_table();

        $email_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT member_id FROM {$members_table} WHERE email = %s",
                $email
            )
        );

        if ($email_exists) {
            return SWPM_External_API::response_error(
                'Email already exists',
                'EMAIL_DUPLICATE',
                409
            );
        }

        $username_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT member_id FROM {$members_table} WHERE user_name = %s",
                $user_name
            )
        );

        if ($username_exists) {
            return SWPM_External_API::response_error(
                'User name already exists',
                'USERNAME_DUPLICATE',
                409
            );
        }

        $plain_password = $password;
        $password_hash  = wp_hash_password($plain_password);

        $data = [
            'user_name'        => $user_name,
            'password'         => $password_hash,
            'email'            => $email,
            'first_name'       => $first_name,
            'last_name'        => $last_name,
            'membership_level' => $membership_level,
            'member_since'     => current_time('mysql'),
            'account_state'    => $account_state,
        ];

        $formats = [
            '%s', // user_name
            '%s', // password
            '%s', // email
            '%s', // first_name
            '%s', // last_name
            '%d', // membership_level
            '%s', // member_since
            '%s', // account_state
        ];

        $inserted = $wpdb->insert($members_table, $data, $formats);

        if (!$inserted) {
            return SWPM_External_API::response_error(
                'Failed to create member',
                'SYSTEM_ERROR',
                500,
                ['db_error' => $wpdb->last_error]
            );
        }

        $member_id = (int) $wpdb->insert_id;

        return SWPM_External_API::response_success(
            'Member created successfully',
            [
                'member_id'        => $member_id,
                'email'            => $email,
                'user_name'        => $user_name,
                'membership_level' => $membership_level,
                'account_state'    => $account_state,
                'plain_password'   => $plain_password, // 必要なければ消してOK
            ]
        );
    }
}