<?php
/*
Plugin Name: SWPM External API
Description: External API for Simple Membership
Version: 1.0.0
Author: Custom
*/

if (!defined('ABSPATH')) {
    exit;
}

define('SWPM_EXT_API_DIR', plugin_dir_path(__FILE__));
define('SWPM_EXT_API_URL', plugin_dir_url(__FILE__));

/**
 * とりあえず直書き版
 * 本番では wp-config.php や options に逃がしてもOK
 */
if (!defined('SWPM_EXT_API_TOKEN')) {
    define('SWPM_EXT_API_TOKEN', 'a9f2Kx8Qz1mN7rT4vYp3Lw6BcD');
}

require_once SWPM_EXT_API_DIR . 'includes/class-member-create.php';
require_once SWPM_EXT_API_DIR . 'includes/class-member-change-level.php';
require_once SWPM_EXT_API_DIR . 'includes/class-member-change-status.php';

class SWPM_External_API {
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        SWPM_Member_Create_API::register_routes();
        SWPM_Member_Change_Level_API::register_routes();
        SWPM_Member_Change_Status_API::register_routes();
    }

    public static function authenticate_request(WP_REST_Request $request) {
        $auth_header = $request->get_header('authorization');
        $api_key_header = $request->get_header('x-api-key');

        $token = '';

        if (!empty($auth_header) && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
            $token = trim($matches[1]);
        } elseif (!empty($api_key_header)) {
            $token = trim($api_key_header);
        }

        if (empty($token) || $token !== SWPM_EXT_API_TOKEN) {
            return new WP_Error(
                'unauthorized',
                'Unauthorized',
                ['status' => 401]
            );
        }

        return true;
    }

    public static function get_members_table() {
        global $wpdb;
        return $wpdb->prefix . 'swpm_members_tbl';
    }

    public static function get_levels_table() {
        global $wpdb;
        return $wpdb->prefix . 'swpm_membership_tbl';
    }

    public static function validate_level_exists($level_id) {
        global $wpdb;

        $table = self::get_levels_table();
        $level = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE id = %d",
                $level_id
            )
        );

        return !empty($level);
    }

    public static function find_member($member_id = null, $email = null) {
        global $wpdb;

        $table = self::get_members_table();

        if (!empty($member_id)) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE member_id = %d",
                    $member_id
                ),
                ARRAY_A
            );
        }

        if (!empty($email)) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE email = %s",
                    $email
                ),
                ARRAY_A
            );
        }

        return null;
    }

    public static function valid_account_states() {
        return ['active', 'inactive', 'pending', 'expired'];
    }

    public static function response_success($message, $data = []) {
        return new WP_REST_Response([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], 200);
    }

    public static function response_error($message, $code = 'error', $status = 400, $extra = []) {
        return new WP_REST_Response(array_merge([
            'success'    => false,
            'message'    => $message,
            'error_code' => $code,
        ], $extra), $status);
    }
}

SWPM_External_API::init();