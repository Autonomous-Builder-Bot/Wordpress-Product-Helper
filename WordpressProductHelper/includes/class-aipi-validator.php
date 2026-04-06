<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AIPI_Validator {

    public function validate_intake( array $input ) {
        $errors = array();

        $description = AIPI_Utils::sanitize_textarea( $input['description'] ?? '', 5000 );
        if ( '' === $description ) {
            $errors['description'] = __( 'Description is required.', 'ai-product-intake' );
        }

        $image_ids = AIPI_Utils::normalize_ids( $input['image_ids'] ?? array() );
        if ( empty( $image_ids ) ) {
            $errors['images'] = __( 'At least one image is required.', 'ai-product-intake' );
        }

        if ( ! empty( $errors ) ) {
            return new WP_Error( 'aipi_validation_failed', __( 'Validation failed.', 'ai-product-intake' ), $errors );
        }

        return array(
            'description' => $description,
            'image_ids'   => $image_ids,
        );
    }

    public function validate_review( array $input ) {
        $errors = array();

        $title = AIPI_Utils::sanitize_text( $input['title'] ?? '', 255 );
        if ( '' === $title ) {
            $errors['title'] = __( 'Title is required.', 'ai-product-intake' );
        }

        $description = AIPI_Utils::sanitize_textarea( $input['description'] ?? '', 5000 );
        if ( '' === $description ) {
            $errors['description'] = __( 'Description is required.', 'ai-product-intake' );
        }

        $short_description = AIPI_Utils::sanitize_textarea( $input['short_description'] ?? '', 1000 );

        $tags = AIPI_Utils::sanitize_string_array( $input['tags'] ?? array(), 20, 50 );

        $price = '';
        if ( isset( $input['price'] ) && '' !== $input['price'] ) {
            $price = AIPI_Utils::normalize_decimal( $input['price'] );
            if ( is_wp_error( $price ) ) {
                $errors['price'] = $price->get_error_message();
            }
        }

        $sku = AIPI_Utils::sanitize_text( $input['sku'] ?? '', 100 );
        if ( '' !== $sku && wc_get_product_id_by_sku( $sku ) ) {
            $errors['sku'] = __( 'SKU already exists.', 'ai-product-intake' );
        }

        $stock_status = AIPI_Utils::validate_stock_status( $input['stock_status'] ?? 'instock' );

        $category_id = absint( $input['category_id'] ?? 0 );
        if ( $category_id && ! term_exists( $category_id, 'product_cat' ) ) {
            $errors['category'] = __( 'Invalid category selected.', 'ai-product-intake' );
        }

        $image_ids = AIPI_Utils::get_attachment_image_ids( $input['image_ids'] ?? array() );
        if ( empty( $image_ids ) ) {
            $errors['images'] = __( 'Valid images are required.', 'ai-product-intake' );
        }

        if ( ! empty( $errors ) ) {
            return new WP_Error( 'aipi_validation_failed', __( 'Validation failed.', 'ai-product-intake' ), $errors );
        }

        return array(
            'title'             => $title,
            'description'       => $description,
            'short_description'=> $short_description,
            'tags'              => $tags,
            'price'             => $price,
            'sku'               => $sku,
            'stock_status'      => $stock_status,
            'category_id'       => $category_id,
            'image_ids'         => $image_ids,
        );
    }
}
