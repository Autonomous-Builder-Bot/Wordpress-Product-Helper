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

    public static function sanitize_text( $value, $max_length = 255 ) {
        $value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
        return self::mb_truncate( trim( $value ), $max_length );
    }

    public static function sanitize_textarea( $value, $max_length = 5000 ) {
        $value = is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
        return self::mb_truncate( trim( $value ), $max_length );
    }

    public static function sanitize_string_array( $items, $max_items = 20, $max_length = 50 ) {
        if ( ! is_array( $items ) ) {
            return array();
        }

        $items = array_slice( $items, 0, $max_items );
        $clean = array();

        foreach ( $items as $item ) {
            $item = self::sanitize_text( $item, $max_length );
            if ( '' !== $item ) {
                $clean[] = $item;
            }
        }

        return array_values( array_unique( $clean ) );
    }

    public static function normalize_decimal( $value, $scale = 2 ) {
        if ( '' === $value || null === $value ) {
            return '';
        }

        if ( is_string( $value ) ) {
            $value = str_replace( ',', '', $value );
        }

        if ( ! is_numeric( $value ) ) {
            return new WP_Error( 'aipi_invalid_decimal', __( 'Please enter a valid numeric price.', 'ai-product-intake' ) );
        }

        $value = (float) $value;
        if ( $value < 0 ) {
            return new WP_Error( 'aipi_negative_decimal', __( 'Numeric values cannot be negative.', 'ai-product-intake' ) );
        }

        return number_format( $value, $scale, '.', '' );
    }

    public static function validate_stock_status( $value ) {
        $value = self::sanitize_text( $value, 20 );
        return in_array( $value, self::allowed_stock_statuses(), true ) ? $value : 'instock';
    }

    public static function mb_truncate( $value, $max_length ) {
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $value, 0, $max_length );
        }

        return substr( $value, 0, $max_length );
    }

    public static function truncate_for_log( $value, $max_length = 1000 ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            $value = wp_json_encode( $value );
        }

        if ( ! is_scalar( $value ) ) {
            return '';
        }

        return self::mb_truncate( (string) $value, $max_length );
    }

    public static function maybe_array( $value ) {
        return is_array( $value ) ? $value : array();
    }

    public static function normalize_ids( $value ) {
        $ids = array();

        if ( is_array( $value ) ) {
            $ids = $value;
        } elseif ( is_string( $value ) ) {
            $ids = explode( ',', $value );
        }

        $ids = array_map( 'absint', $ids );
        $ids = array_filter( $ids );

        return array_values( array_unique( $ids ) );
    }

    public static function get_attachment_image_ids( $ids ) {
        $ids   = self::normalize_ids( $ids );
        $valid = array();

        foreach ( $ids as $id ) {
            if ( 'attachment' !== get_post_type( $id ) ) {
                continue;
            }

            $mime = get_post_mime_type( $id );
            if ( ! $mime || 0 !== strpos( $mime, 'image/' ) ) {
                continue;
            }

            $valid[] = $id;
        }

        return $valid;
    }
}
