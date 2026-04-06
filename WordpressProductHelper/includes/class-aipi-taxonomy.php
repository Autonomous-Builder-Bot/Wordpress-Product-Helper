<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Taxonomy helper.
 *
 * Default rule:
 * - categories must be existing only
 * - tags may be sanitized and created
 */
class AIPI_Taxonomy {
    public function get_category_options() {
        $terms   = get_terms(
            array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            )
        );
        $options = array();

        if ( is_wp_error( $terms ) ) {
            return $options;
        }

        foreach ( $terms as $term ) {
            $options[] = array(
                'id'   => (int) $term->term_id,
                'name' => $term->name,
            );
        }

        return $options;
    }

    public function sanitize_tags( $tags ) {
        return AIPI_Utils::sanitize_string_array( is_array( $tags ) ? $tags : array(), 20, 50 );
    }
}
