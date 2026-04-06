<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Media placeholder.
 *
 * Must:
 * - validate actual image files
 * - enforce size limits
 * - resize/compress safely
 * - preserve PNG transparency
 * - return validated attachment IDs
 */
class AIPI_Media {
    public function handle_uploads( array $files ) {
        // TODO
    }
}
