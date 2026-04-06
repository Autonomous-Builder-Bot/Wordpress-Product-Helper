<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Draft storage placeholder.
 *
 * Use transients in v1.
 * Each draft token must be tied to the current user.
 */
class AIPI_Draft_Store {
    public function create_draft( array $payload ) {
        // TODO
    }

    public function get_draft( $token ) {
        // TODO
    }

    public function delete_draft( $token ) {
        // TODO
    }
}
