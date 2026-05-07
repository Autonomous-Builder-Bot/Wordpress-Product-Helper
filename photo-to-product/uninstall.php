<?php
/**
 * Uninstall script for AI Product Importer Pro.
 *
 * When the plugin is deleted via the WordPress admin, this file is loaded.
 * It removes all plugin data including jobs and plugin settings. Media
 * attachments uploaded for jobs are intentionally left in place because
 * they may be reused by products or other content. This avoids
 * inadvertently breaking existing products. Administrators may remove
 * orphaned media manually via the Media Library if desired.
 */

// Exit if accessed directly or if uninstall not triggered by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}


/**
 * Determine whether a derivative path points to a plugin-owned AI image.
 *
 * @param int    $attachment_id Attachment ID associated with the derivative.
 * @param string $path          Candidate derivative path.
 * @return bool
 */
function aipi_is_safe_uninstall_derivative_path( int $attachment_id, string $path ): bool {
    $uploads = wp_get_upload_dir();
    if ( empty( $uploads['basedir'] ) || ! is_string( $uploads['basedir'] ) ) {
        return false;
    }

    $base_dir       = realpath( $uploads['basedir'] );
    $candidate_path = realpath( $path );
    if ( false === $base_dir || false === $candidate_path ) {
        return false;
    }

    $normalized_base      = wp_normalize_path( trailingslashit( $base_dir ) . 'aipi-ai/' );
    $normalized_candidate = wp_normalize_path( $candidate_path );
    if ( 0 !== strpos( $normalized_candidate, $normalized_base ) ) {
        return false;
    }

    $suffix   = apply_filters( 'aipi_ai_derivative_suffix', 'aipi-ai' );
    $suffix   = sanitize_file_name( (string) $suffix );
    $basename = wp_basename( $normalized_candidate );
    $pattern  = '/^.+-' . preg_quote( (string) $attachment_id, '/' ) . '-' . preg_quote( $suffix, '/' ) . '\.(jpg|jpeg|png|webp|gif)$/i';

    return (bool) preg_match( $pattern, $basename );
}

// Delete plugin options.
delete_option( 'aipi_pro_settings' );

// Delete recent plugin logs option. Logs are stored in aipi_recent_logs to aid
// troubleshooting during normal operation. They should be removed when the
// plugin is uninstalled to avoid leaving orphaned data behind. See
// AIPI\Logger for details on log storage and retention.
delete_option( 'aipi_recent_logs' );

// Delete all AIPI jobs. Use a page‑1 loop repeatedly so deleting posts does
// not skip subsequent posts. Do not delete attachments here because
// attachments may be used by products or other content.
$per_page = 50;
do {
    $job_query = new WP_Query( [
        'post_type'              => 'aipi_job',
        'post_status'            => 'any',
        'posts_per_page'         => $per_page,
        'paged'                  => 1,
        'fields'                 => 'ids',
        'orderby'                => 'ID',
        'order'                  => 'ASC',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ] );
    $job_ids = isset( $job_query->posts ) && is_array( $job_query->posts ) ? $job_query->posts : [];
    foreach ( $job_ids as $job_id ) {
        wp_delete_post( (int) $job_id, true );
    }
    wp_reset_postdata();
} while ( ! empty( $job_ids ) );

// Clean up derivative files and provenance metadata left on attachments. Repeatedly
// query page 1 until no matching attachments remain to avoid skipping
// records when deleting within the loop.
$attachment_per_page = 50;
do {
    $att_query = new WP_Query( [
        'post_type'              => 'attachment',
        'post_status'            => 'any',
        'posts_per_page'         => $attachment_per_page,
        'paged'                  => 1,
        'fields'                 => 'ids',
        'orderby'                => 'ID',
        'order'                  => 'ASC',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'meta_query'             => [
            'relation' => 'OR',
            [
                'key'     => '_aipi_ai_derivative_path',
                'compare' => 'EXISTS',
            ],
            [
                'key'     => '_aipi_job',
                'compare' => 'EXISTS',
            ],
        ],
    ] );
    $attachment_ids = isset( $att_query->posts ) && is_array( $att_query->posts ) ? $att_query->posts : [];
    foreach ( $attachment_ids as $att_id ) {
        $att_id = (int) $att_id;
        $derivative_path = get_post_meta( $att_id, '_aipi_ai_derivative_path', true );
        if ( is_string( $derivative_path ) && '' !== $derivative_path ) {
            if ( file_exists( $derivative_path ) && is_file( $derivative_path ) && aipi_is_safe_uninstall_derivative_path( $att_id, $derivative_path ) ) {
                wp_delete_file( $derivative_path );
            }
            delete_post_meta( $att_id, '_aipi_ai_derivative_path' );
        }
        delete_post_meta( $att_id, '_aipi_job' );
    }
    wp_reset_postdata();
} while ( ! empty( $attachment_ids ) );
