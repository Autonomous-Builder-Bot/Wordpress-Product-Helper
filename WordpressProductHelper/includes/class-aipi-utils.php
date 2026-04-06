<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Small shared helpers only.
 */
class AIPI_Utils {
    public static function allowed_stock_statuses() {
        return array( 'instock', 'outofstock', 'onbackorder' );
    }
}
