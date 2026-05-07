<?php
namespace AIPI;

use RuntimeException;

/**
 * Prepares uploaded images for AI analysis by creating optimised derivatives.
 *
 * The optimisation pipeline enforces limits on image size and dimensions,
 * validates mime types and produces a new file in the uploads directory
 * suitable for sending to the AI service. If an image cannot be processed
 * or does not require optimisation, the original attachment URL is
 * returned. Any errors encountered during optimisation result in the
 * original URL being used so that generation may proceed without
 * interruption.
 */
class ImagePreparationService {
    /**
     * Determine if a derivative path points to a file created by this plugin.
     *
     * Derivative images are stored in a dedicated sub‑directory within the uploads
     * folder and use a deterministic filename that ends with `-<attachmentID>-<suffix>.<ext>`.
     * The suffix may be customised via the `aipi_ai_derivative_suffix` filter.
     * This helper validates that the candidate file lives under the uploads base
     * directory, is inside our subdirectory and matches the naming pattern.
     *
     * @param string $path Candidate file path.
     * @return bool True if the path appears to be a safe plugin derivative.
     */
    private static function is_safe_derivative_path( string $path ): bool {
        $uploads = wp_get_upload_dir();
        if ( empty( $uploads['basedir'] ) || ! is_string( $uploads['basedir'] ) ) {
            return false;
        }
        $base_dir       = realpath( $uploads['basedir'] );
        $candidate_path = realpath( $path );
        if ( false === $base_dir || false === $candidate_path ) {
            return false;
        }
        $normalized_base      = wp_normalize_path( trailingslashit( $base_dir ) );
        $normalized_candidate = wp_normalize_path( $candidate_path );
        // Ensure the candidate resides within the uploads directory.
        if ( 0 !== strpos( $normalized_candidate, $normalized_base ) ) {
            return false;
        }
        // Ensure the candidate is within our dedicated derivative sub‑directory.
        $subdir = trailingslashit( $normalized_base ) . 'aipi-ai';
        if ( 0 !== strpos( $normalized_candidate, trailingslashit( $subdir ) ) ) {
            return false;
        }
        $suffix = apply_filters( 'aipi_ai_derivative_suffix', 'aipi-ai' );
        $suffix = sanitize_file_name( (string) $suffix );
        $basename = basename( $normalized_candidate );
        // Match pattern: <name>-<digits>-<suffix>.<ext>
        return (bool) preg_match( '/-\d+-' . preg_quote( $suffix, '/' ) . '\.[^\/\\]+$/i', $basename );
    }

    /**
     * Prepare a set of attachment IDs for AI use. Returns an array of
     * associative arrays with keys 'id' and 'url'. The 'url' points to
     * either the optimised derivative or the original attachment URL.
     *
     * @param array<int,int> $attachment_ids
     * @return array<int,array{ id:int, url:string }>
     */
    public function prepare_images_for_ai( array $attachment_ids ): array {
        $out = [];
        foreach ( $attachment_ids as $attachment_id ) {
            $url = wp_get_attachment_url( (int) $attachment_id );
            if ( ! $url ) {
                continue;
            }
            $mime = get_post_mime_type( (int) $attachment_id );
            if ( ! is_string( $mime ) || '' === $mime ) {
                $out[] = [ 'id' => (int) $attachment_id, 'url' => $url ];
                continue;
            }
            // Only process common raster image types. Otherwise fall back to original.
            $allowed_mimes = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ];
            if ( ! in_array( $mime, $allowed_mimes, true ) ) {
                $out[] = [ 'id' => (int) $attachment_id, 'url' => $url ];
                continue;
            }
            // Attempt to optimise the image.
            try {
                $path = get_attached_file( (int) $attachment_id );
                if ( ! $path || ! file_exists( $path ) ) {
                    $out[] = [ 'id' => (int) $attachment_id, 'url' => $url ];
                    continue;
                }
                $editor = wp_get_image_editor( $path );
                if ( is_wp_error( $editor ) ) {
                    $out[] = [ 'id' => (int) $attachment_id, 'url' => $url ];
                    continue;
                }
                /** @var \WP_Image_Editor $editor */
                // Determine max dimension from a filter; default to 1024px.
                $max_dim = apply_filters( 'aipi_ai_image_max_dimension', 1024 );
                $size    = $editor->get_size();
                $width   = isset( $size['width'] ) ? (int) $size['width'] : 0;
                $height  = isset( $size['height'] ) ? (int) $size['height'] : 0;
                // Resize if either dimension exceeds the maximum. Maintain aspect ratio.
                if ( $width > $max_dim || $height > $max_dim ) {
                    $editor->resize( $max_dim, $max_dim, false );
                }
                // Set the output quality via a filter; default to 80.
                $quality = apply_filters( 'aipi_ai_image_quality', 80 );
                if ( method_exists( $editor, 'set_quality' ) ) {
                    $editor->set_quality( (int) $quality );
                }
                // Generate a deterministic derivative filename scoped to the
                // attachment ID so different attachments cannot overwrite one
                // another even when the original base filename matches.
                $info          = pathinfo( $path );
                $suffix        = apply_filters( 'aipi_ai_derivative_suffix', 'aipi-ai' );
                $suffix        = sanitize_file_name( (string) $suffix );
                // Derivatives are stored in a dedicated sub‑directory within uploads to avoid
                // clutter and make cleanup safe. Ensure the directory exists.
                $uploads       = wp_get_upload_dir();
                $dest_dir      = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] )
                    ? trailingslashit( $uploads['basedir'] ) . 'aipi-ai'
                    : $info['dirname'];
                if ( ! is_dir( $dest_dir ) ) {
                    wp_mkdir_p( $dest_dir );
                }
                $dest_name     = $info['filename'] . '-' . (int) $attachment_id . '-' . $suffix . '.' . $info['extension'];
                $dest_filename = trailingslashit( $dest_dir ) . $dest_name;
                // Save the resized/optimised image to the deterministic filename.
                // If the file already exists, it will be overwritten.
                $saved = $editor->save( $dest_filename );
                if ( ! is_wp_error( $saved ) && is_array( $saved ) ) {
                    // Use the actual saved path if provided. Some image editors may change the
                    // filename or extension on save. Fallback to our requested path.
                    $dest_path = ( isset( $saved['path'] ) && is_string( $saved['path'] ) && '' !== $saved['path'] )
                        ? $saved['path']
                        : $dest_filename;
                    $dest_path = wp_normalize_path( $dest_path );
                    $upload_dir = wp_get_upload_dir();
                    if ( isset( $upload_dir['basedir'], $upload_dir['baseurl'] ) && '' !== $upload_dir['basedir'] && '' !== $upload_dir['baseurl'] ) {
                        $base_dir_normalized = wp_normalize_path( $upload_dir['basedir'] );
                        // Ensure the derivative path lives within the uploads base before replacing.
                        if ( 0 === strpos( $dest_path, $base_dir_normalized ) ) {
                            $url_for_ai = str_replace( $base_dir_normalized, $upload_dir['baseurl'], $dest_path );
                            update_post_meta( (int) $attachment_id, '_aipi_ai_derivative_path', $dest_path );
                            $out[] = [ 'id' => (int) $attachment_id, 'url' => esc_url_raw( $url_for_ai ) ];
                            continue;
                        }
                    }
                }
                // Fallback: if we did not successfully generate a derivative or could not determine its URL,
                // use the original URL so generation may proceed.
                $out[] = [ 'id' => (int) $attachment_id, 'url' => $url ];
            } catch ( \Throwable $e ) {
                // On any exception, fall back to original URL to avoid blocking generation.
                $out[] = [ 'id' => (int) $attachment_id, 'url' => $url ];
                continue;
            }
        }
        return $out;
    }

    /**
     * Remove generated AI derivatives for a list of attachments.
     *
     * @param array<int,int> $attachment_ids
     */
    public static function cleanup_derivatives_for_attachments( array $attachment_ids ): void {
        foreach ( $attachment_ids as $attachment_id ) {
            self::cleanup_derivative_for_attachment( (int) $attachment_id );
        }
    }

    /**
     * Remove the AI derivative file for a single attachment.
     *
     * This method reads the `_aipi_ai_derivative_path` meta on the attachment and
     * deletes the file if it exists and is within the uploads directory. The
     * meta key is removed regardless of whether the file exists so that
     * repeated calls are idempotent. If the derivative path matches the
     * original attachment path, no deletion occurs to avoid removing
     * primary media.
     *
     * @param int $attachment_id Attachment ID.
     */

    public static function cleanup_derivative_for_attachment( int $attachment_id ): void {
        $path = get_post_meta( $attachment_id, '_aipi_ai_derivative_path', true );
        if ( ! is_string( $path ) || '' === $path ) {
            return;
        }

        $original = get_attached_file( $attachment_id );
        if ( is_string( $original ) && '' !== $original && $original === $path ) {
            delete_post_meta( $attachment_id, '_aipi_ai_derivative_path' );
            return;
        }

        if ( file_exists( $path ) && is_file( $path ) && self::is_safe_derivative_path( $path ) ) {
            wp_delete_file( $path );
        }

        delete_post_meta( $attachment_id, '_aipi_ai_derivative_path' );
    }
}
