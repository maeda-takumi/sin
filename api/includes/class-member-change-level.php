<?php

if (!defined('ABSPATH')) {
    exit;
}

class SWPM_Member_Change_Level_API {
    public static function register_routes() {
        register_rest_route('swpm-ext/v1', '/member/change-level', [
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

        $member_id             = absint($request->get_param('member_id'));
        $email                 = sanitize_email($request->get_param('email'));
        $new_membership_level  = absint($request->get_param('new_membership_level'));

        if ((empty($member_id) && empty($email)) || empty($new_membership_level)) {
            return SWPM_External_API::response_error(
                'member_id or email, and new_membership_level are required',
                'VALIDATION_ERROR',
                400
            );
        }

        if (!SWPM_External_API::validate_level_exists($new_membership_level)) {
            return SWPM_External_API::response_error(
                'Membership level not found',
                'LEVEL_NOT_FOUND',
                404
            );
        }

        $member = SWPM_External_API::find_member($member_id, $email);

        if (!$member) {
            return SWPM_External_API::response_error(
                'Member not found',
                'MEMBER_NOT_FOUND',
                404
            );
        }

        $old_level = (int) $member['membership_level'];

        if ($old_level === $new_membership_level) {
            return SWPM_External_API::response_error(
                'Membership level is already the same',
                'NO_CHANGE',
                400
            );
        }

        $members_table = SWPM_External_API::get_members_table();

        $updated = $wpdb->update(
            $members_table,
            ['membership_level' => $new_membership_level],
            ['member_id' => (int) $member['member_id']],
            ['%d'],
            ['%d']
        );

        if ($updated === false) {
            return SWPM_External_API::response_error(
                'Failed to update membership level',
                'SYSTEM_ERROR',
                500,
                ['db_error' => $wpdb->last_error]
            );
        }

        return SWPM_External_API::response_success(
            'Membership level updated successfully',
            [
                'member_id'             => (int) $member['member_id'],
                'email'                 => $member['email'],
                'old_membership_level'  => $old_level,
                'new_membership_level'  => $new_membership_level,
            ]
        );
    }
}