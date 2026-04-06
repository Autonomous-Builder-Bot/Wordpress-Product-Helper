<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Settings placeholder.
 *
 * Required settings:
 * - API key
 * - model
 * - max image upload size
 * - max image dimensions
 * - compression quality
 * - logging enabled
 * - draft TTL
 */
class AIPI_Settings {
    const OPTION_KEY = 'aipi_settings';
    const API_KEY_CONSTANT = 'AIPI_OPENAI_API_KEY';

    public static function get_api_key() {
        if ( defined( self::API_KEY_CONSTANT ) && constant( self::API_KEY_CONSTANT ) ) {
            return constant( self::API_KEY_CONSTANT );
        }

        return '';
    }
}
