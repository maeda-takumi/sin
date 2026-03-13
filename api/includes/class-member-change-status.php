<?php

if (!defined('ABSPATH')) {
    exit;
}

class SWPM_Member_Change_Status_API {
    public static function register_routes() {
        register_rest_route('swpm-ext/v1', '/member/change-status', [
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

        $member_id         = absint($request->get_param('member_id'));
        $email             = sanitize_email($request->get_param('email'));
        $new_account_state = sanitize_text_field($request->get_param('new_account_state'));

        if ((empty($member_id) && empty($email)) || empty($new_account_state)) {
            return SWPM_External_API::response_error(
                'member_id or email, and new_account_state are required',
                'VALIDATION_ERROR',
                400
            );
        }

        if (!in_array($new_account_state, SWPM_External_API::valid_account_states(), true)) {
            return SWPM_External_API::response_error(
                'Invalid account state',
                'STATUS_INVALID',
                400
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

        $old_state = (string) $member['account_state'];

        if ($old_state === $new_account_state) {
            return SWPM_External_API::response_error(
                'Account state is already the same',
                'NO_CHANGE',
                400
            );
        }

        $members_table = SWPM_External_API::get_members_table();

        $updated = $wpdb->update(
            $members_table,
            ['account_state' => $new_account_state],
            ['member_id' => (int) $member['member_id']],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            return SWPM_External_API::response_error(
                'Failed to update account state',
                'SYSTEM_ERROR',
                500,
                ['db_error' => $wpdb->last_error]
            );
        }

        return SWPM_External_API::response_success(
            'Account status updated successfully',
            [
                'member_id'         => (int) $member['member_id'],
                'email'             => $member['email'],
                'old_account_state' => $old_state,
                'new_account_state' => $new_account_state,
            ]
        );
    }
}