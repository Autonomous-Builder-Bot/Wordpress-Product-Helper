<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Normalize AI output into safe structured data.
 */
class AIPI_AI_Normalizer {
    public function normalize( array $raw ) {
        $data = is_array( $raw ) ? $raw : array();

        return array(
            'title'               => AIPI_Utils::sanitize_text( $data['title'] ?? '', 255 ),
            'description'         => AIPI_Utils::sanitize_textarea( $data['description'] ?? '', 5000 ),
            'short_description'   => AIPI_Utils::sanitize_textarea( $data['short_description'] ?? '', 1000 ),
            'tags'                => AIPI_Utils::sanitize_string_array( $data['tags'] ?? array(), 20, 50 ),
            'category_suggestion' => AIPI_Utils::sanitize_text( $data['category_suggestion'] ?? '', 100 ),
            'missing_fields'      => AIPI_Utils::sanitize_string_array( $data['missing_fields'] ?? array(), 10, 100 ),
        );
    }
}
