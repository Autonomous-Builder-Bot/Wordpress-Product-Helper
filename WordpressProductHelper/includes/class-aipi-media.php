<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AIPI_Media {

    public function handle_uploads( array $files ) {
        if ( empty( $files['name'] ) ) {
            return new WP_Error( 'aipi_no_files', __( 'No files uploaded.', 'ai-product-intake' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_ids = array();

        $max_bytes = AIPI_Settings::get_max_upload_bytes();

        foreach ( $files['name'] as $index => $name ) {

            if ( $files['error'][ $index ] !== UPLOAD_ERR_OK ) {
                continue;
            }

            if ( $files['size'][ $index ] > $max_bytes ) {
                continue;
            }

            $file = array(
                'name'     => $files['name'][ $index ],
                'type'     => $files['type'][ $index ],
                'tmp_name' => $files['tmp_name'][ $index ],
                'error'    => $files['error'][ $index ],
                'size'     => $files['size'][ $index ],
            );

            $overrides = array( 'test_form' => false );

            $upload = wp_handle_upload( $file, $overrides );

            if ( isset( $upload['error'] ) ) {
                continue;
            }

            $filetype = wp_check_filetype( $upload['file'] );

            if ( strpos( $filetype['type'], 'image/' ) !== 0 ) {
                continue;
            }

            $attachment = array(
                'post_mime_type' => $filetype['type'],
                'post_title'     => sanitize_file_name( $name ),
                'post_status'    => 'inherit',
            );

            $attach_id = wp_insert_attachment( $attachment, $upload['file'] );

            if ( is_wp_error( $attach_id ) ) {
                continue;
            }

            $this->optimize_image( $attach_id );

            wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $upload['file'] ) );

            $attachment_ids[] = $attach_id;
        }

        if ( empty( $attachment_ids ) ) {
            return new WP_Error( 'aipi_upload_failed', __( 'No valid images uploaded.', 'ai-product-intake' ) );
        }

        return $attachment_ids;
    }

    private function optimize_image( $attachment_id ) {
        $file = get_attached_file( $attachment_id );

        if ( ! $file || ! file_exists( $file ) ) {
            return;
        }

        $editor = wp_get_image_editor( $file );

        if ( is_wp_error( $editor ) ) {
            return;
        }

        $max_dim = AIPI_Settings::get_max_image_dimension();

        $size = $editor->get_size();

        if ( $size['width'] > $max_dim || $size['height'] > $max_dim ) {
            $editor->resize( $max_dim, $max_dim, false );
        }

        $quality = AIPI_Settings::get_jpeg_quality();

        if ( method_exists( $editor, 'set_quality' ) ) {
            $editor->set_quality( $quality );
        }

        $editor->save( $file );
    }
}
