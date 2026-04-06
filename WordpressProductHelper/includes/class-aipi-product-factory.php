<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AIPI_Product_Factory {

    public function create_product( array $validated ) {
        if ( ! class_exists( 'WC_Product_Simple' ) ) {
            return new WP_Error( 'aipi_wc_missing', __( 'WooCommerce is not available.', 'ai-product-intake' ) );
        }

        try {
            $product = new WC_Product_Simple();

            $product->set_name( $validated['title'] );
            $product->set_description( $validated['description'] );
            $product->set_short_description( $validated['short_description'] );
            $product->set_status( 'draft' );

            if ( ! empty( $validated['price'] ) ) {
                $product->set_regular_price( $validated['price'] );
            }

            if ( ! empty( $validated['sku'] ) ) {
                $product->set_sku( $validated['sku'] );
            }

            if ( ! empty( $validated['stock_status'] ) ) {
                $product->set_stock_status( $validated['stock_status'] );
            }

            $product_id = $product->save();

            if ( ! $product_id ) {
                return new WP_Error( 'aipi_product_save_failed', __( 'Failed to save product.', 'ai-product-intake' ) );
            }

            // Set category
            if ( ! empty( $validated['category_id'] ) ) {
                wp_set_object_terms( $product_id, array( (int) $validated['category_id'] ), 'product_cat' );
            }

            // Set tags
            if ( ! empty( $validated['tags'] ) ) {
                wp_set_object_terms( $product_id, $validated['tags'], 'product_tag' );
            }

            // Set images
            if ( ! empty( $validated['image_ids'] ) ) {
                set_post_thumbnail( $product_id, $validated['image_ids'][0] );

                if ( count( $validated['image_ids'] ) > 1 ) {
                    update_post_meta( $product_id, '_product_image_gallery', implode( ',', array_slice( $validated['image_ids'], 1 ) ) );
                }
            }

            return $product_id;

        } catch ( Exception $e ) {
            return new WP_Error( 'aipi_product_exception', $e->getMessage() );
        }
    }
}
