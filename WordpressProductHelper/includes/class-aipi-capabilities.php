<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Central access-control helper.
 */
class AIPI_Capabilities {
    const CAPABILITY = 'manage_ai_product_intake';

    public static function current_user_can_manage() {
        return current_user_can( self::CAPABILITY ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
    }
}
