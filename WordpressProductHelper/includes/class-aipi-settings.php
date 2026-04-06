<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AIPI_Settings {
    const OPTION_KEY       = 'aipi_settings';
    const API_KEY_CONSTANT = 'AIPI_OPENAI_API_KEY';
    const MENU_SLUG        = 'aipi-settings';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
    }

    public static function register_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'AI Product Intake Settings', 'ai-product-intake' ),
            __( 'AI Intake Settings', 'ai-product-intake' ),
            'manage_options',
            self::MENU_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function register_settings() {
        register_setting(
            'aipi_settings_group',
            self::OPTION_KEY,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
                'default'           => self::get_defaults(),
            )
        );
    }

    public static function get_defaults() {
        return array(
            'api_key'              => '',
            'model'                => 'gpt-4.1',
            'logging_enabled'      => 1,
            'max_upload_mb'        => 10,
            'max_image_dimension'  => 2200,
            'jpeg_quality'         => 82,
            'draft_ttl_minutes'    => 120,
        );
    }

    public static function get_all() {
        $settings = get_option( self::OPTION_KEY, array() );
        return wp_parse_args( is_array( $settings ) ? $settings : array(), self::get_defaults() );
    }

    public static function sanitize_settings( $input ) {
        $input    = is_array( $input ) ? $input : array();
        $defaults = self::get_defaults();
        $current  = self::get_all();

        $settings = array();
        $settings['api_key'] = $current['api_key'];

        if ( ! defined( self::API_KEY_CONSTANT ) && isset( $input['api_key'] ) ) {
            $settings['api_key'] = sanitize_text_field( $input['api_key'] );
        }

        $settings['model'] = isset( $input['model'] ) ? AIPI_Utils::sanitize_text( $input['model'], 100 ) : $defaults['model'];
        $settings['logging_enabled'] = empty( $input['logging_enabled'] ) ? 0 : 1;
        $settings['max_upload_mb'] = max( 1, min( 50, absint( $input['max_upload_mb'] ?? $defaults['max_upload_mb'] ) ) );
        $settings['max_image_dimension'] = max( 800, min( 4000, absint( $input['max_image_dimension'] ?? $defaults['max_image_dimension'] ) ) );
        $settings['jpeg_quality'] = max( 50, min( 95, absint( $input['jpeg_quality'] ?? $defaults['jpeg_quality'] ) ) );
        $settings['draft_ttl_minutes'] = max( 10, min( 1440, absint( $input['draft_ttl_minutes'] ?? $defaults['draft_ttl_minutes'] ) ) );

        return $settings;
    }

    public static function get( $key, $default = null ) {
        $settings = self::get_all();
        return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
    }

    public static function get_api_key() {
        if ( defined( self::API_KEY_CONSTANT ) && constant( self::API_KEY_CONSTANT ) ) {
            return constant( self::API_KEY_CONSTANT );
        }

        return (string) self::get( 'api_key', '' );
    }

    public static function get_model() {
        return (string) self::get( 'model', 'gpt-4.1' );
    }

    public static function is_logging_enabled() {
        return (bool) self::get( 'logging_enabled', 1 );
    }

    public static function get_max_upload_bytes() {
        return absint( self::get( 'max_upload_mb', 10 ) ) * MB_IN_BYTES;
    }

    public static function get_max_image_dimension() {
        return absint( self::get( 'max_image_dimension', 2200 ) );
    }

    public static function get_jpeg_quality() {
        return absint( self::get( 'jpeg_quality', 82 ) );
    }

    public static function get_draft_ttl_minutes() {
        return absint( self::get( 'draft_ttl_minutes', 120 ) );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access these settings.', 'ai-product-intake' ) );
        }

        $settings = self::get_all();
        $using_constant = defined( self::API_KEY_CONSTANT ) && constant( self::API_KEY_CONSTANT );

        include AIPI_PLUGIN_DIR . 'templates/settings-page.php';
    }
}
