<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Draft storage using transients in v1.
 * Each draft token is bound to the current user.
 */
class AIPI_Draft_Store {
    const TRANSIENT_PREFIX = 'aipi_draft_';

    public function create_draft( array $payload ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'aipi_no_user', __( 'A logged-in user is required to create drafts.', 'ai-product-intake' ) );
        }

        $token = wp_generate_password( 32, false, false );
        $data  = array(
            'created_by' => $user_id,
            'created_at' => time(),
            'payload'    => $payload,
        );

        $saved = set_transient(
            $this->get_transient_key( $token ),
            $data,
            AIPI_Settings::get_draft_ttl_minutes() * MINUTE_IN_SECONDS
        );

        if ( ! $saved ) {
            return new WP_Error( 'aipi_draft_not_saved', __( 'The draft could not be saved.', 'ai-product-intake' ) );
        }

        return $token;
    }

    public function get_draft( $token ) {
        $token = AIPI_Utils::sanitize_text( $token, 64 );
        if ( '' === $token ) {
            return new WP_Error( 'aipi_missing_draft_token', __( 'A valid draft token is required.', 'ai-product-intake' ) );
        }

        $draft = get_transient( $this->get_transient_key( $token ) );
        if ( ! is_array( $draft ) || empty( $draft['payload'] ) ) {
            return new WP_Error( 'aipi_draft_not_found', __( 'That draft could not be found or has expired.', 'ai-product-intake' ) );
        }

        $current_user_id = get_current_user_id();
        if ( empty( $draft['created_by'] ) || (int) $draft['created_by'] !== (int) $current_user_id ) {
            return new WP_Error( 'aipi_draft_owner_mismatch', __( 'You are not allowed to access this draft.', 'ai-product-intake' ) );
        }

        return $draft;
    }

    public function delete_draft( $token ) {
        $token = AIPI_Utils::sanitize_text( $token, 64 );
        if ( '' === $token ) {
            return false;
        }

        return delete_transient( $this->get_transient_key( $token ) );
    }

    private function get_transient_key( $token ) {
        return self::TRANSIENT_PREFIX . $token;
    }
}
