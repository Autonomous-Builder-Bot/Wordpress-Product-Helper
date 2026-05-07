<?php
/**
 * Plugin Name: Photo to Product
 * Description: Turn product photos or descriptions into WooCommerce listings with AI. Upload images, provide a short description or do both and receive a structured draft listing complete with title, descriptions, categories, tags, brand, condition and confidence notes. At least one input (photo or description) is required. The workflow runs from your WordPress admin and never exposes your secrets in the browser.
 * Version: 1.7.1
 * Author: Photo to Product Contributors
 * License: GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: photo-to-product
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AIPI_PRO_VERSION' ) ) {
    define( 'AIPI_PRO_VERSION', '1.7.1' );
}

spl_autoload_register( static function ( $class ) {
    if ( 0 !== strpos( $class, 'AIPI\\' ) ) {
        return;
    }
    $relative = str_replace( '\\', '/', substr( $class, 5 ) );
    $file     = __DIR__ . '/src/' . $relative . '.php';
    if ( file_exists( $file ) ) {
        require $file;
    }
} );

/**
 * Register the internal job post type used by the plugin workflow.
 */
function aipi_pro_register_job_post_type(): void {
    register_post_type( 'aipi_job', [
        'label'           => __( 'AI Jobs', 'photo-to-product' ),
        'public'          => false,
        'show_ui'         => false,
        'capability_type' => 'post',
        'supports'        => [ 'title' ],
        'has_archive'     => false,
        'show_in_rest'    => false,
    ] );
}

/*
 * Activation and deactivation hooks.
 */
if ( function_exists( 'register_activation_hook' ) ) {
    register_activation_hook( __FILE__, function () {
        // Record the current plugin version to aid future upgrades.
        update_option( 'aipi_pro_plugin_version', AIPI_PRO_VERSION, false );
        aipi_pro_register_job_post_type();
        // Flush rewrite rules for the internal job post type.
        flush_rewrite_rules();

                if ( ! get_option( 'aipi_pro_show_setup_notice' ) ) {
            update_option( 'aipi_pro_show_setup_notice', '1', false );
        }

                if ( ! wp_next_scheduled( 'aipi_daily_cleanup' ) ) {
                        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'aipi_daily_cleanup' );
        }
    } );
}

if ( function_exists( 'register_deactivation_hook' ) ) {
    register_deactivation_hook( __FILE__, function () {
                flush_rewrite_rules();

                $timestamp = wp_next_scheduled( 'aipi_daily_cleanup' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'aipi_daily_cleanup' );
        }
    } );
}

add_action( 'plugins_loaded', static function () {
        if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function () {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Photo to Product requires WooCommerce to be installed and active.', 'photo-to-product' ) . '</p></div>';
        } );
        return;
    }
        $plugin = new AIPI\Plugin( __FILE__ );
    $plugin->init();
} );

/*
 * Scheduled cleanup hook.
 *
 * This callback is executed via WP-Cron on the schedule registered during
 * activation. It deletes completed or failed jobs older than a specified
 * retention period and marks long‑running jobs as failed. The default
 * retention period is 30 days and can be adjusted via the
 * `aipi_job_retention_days` filter. Stale jobs stuck in generating or
 * creating_product states for more than two hours are marked as failed and
 * their locks are released.
 */
add_action( 'aipi_daily_cleanup', function () {
    // Ensure WordPress is loaded enough to run queries.
    if ( ! function_exists( 'wp_next_scheduled' ) ) {
        return;
    }
    // Only run in admin or cron context.
    if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
        return;
    }
    // Verify required classes exist before performing cleanup.
    if ( ! class_exists( '\AIPI\JobRepository' ) || ! class_exists( '\AIPI\Cleanup' ) || ! class_exists( '\AIPI\StateMachine' ) ) {
        return;
    }
    // Retention period in days. Filterable so site owners can adjust. Minimum of 1 day.
    $retention_days = (int) apply_filters( 'aipi_job_retention_days', 30 );
    if ( $retention_days < 1 ) {
        $retention_days = 1;
    }
    $cutoff_ts = time() - ( $retention_days * DAY_IN_SECONDS );

    $repo    = new \AIPI\JobRepository();
    $cleanup = new \AIPI\Cleanup( $repo );

    // Delete completed or failed jobs older than the cutoff. Use post_modified_gmt
    // because metadata updates refresh this timestamp. This query fetches only
    // IDs to reduce memory usage.
    $args = [
        'post_type'      => \AIPI\JobRepository::POST_TYPE,
        'post_status'    => 'private',
        'fields'         => 'ids',
        'nopaging'       => true,
        'date_query'     => [
            [
                'column' => 'post_modified_gmt',
                'before' => gmdate( 'Y-m-d H:i:s', $cutoff_ts ),
            ],
        ],
        'meta_query'     => [
            [
                'key'     => '_aipi_job_status',
                'value'   => [ \AIPI\StateMachine::STATUS_COMPLETED, \AIPI\StateMachine::STATUS_FAILED ],
                'compare' => 'IN',
            ],
        ],
    ];
    $query = new WP_Query( $args );
    if ( $query->have_posts() ) {
        foreach ( $query->posts as $job_id ) {
            $cleanup->delete_job( (int) $job_id );
        }
    }
    wp_reset_postdata();

    // Mark stale generating or creating_product jobs as failed when no update has
    // occurred for two hours. This prevents jobs from remaining in an
    // in-progress state indefinitely.
    $stale_cutoff = time() - 2 * HOUR_IN_SECONDS;
    $args2 = [
        'post_type'      => \AIPI\JobRepository::POST_TYPE,
        'post_status'    => 'private',
        'fields'         => 'ids',
        'nopaging'       => true,
        'date_query'     => [
            [
                'column' => 'post_modified_gmt',
                'before' => gmdate( 'Y-m-d H:i:s', $stale_cutoff ),
            ],
        ],
        'meta_query'     => [
            [
                'key'     => '_aipi_job_status',
                'value'   => [ \AIPI\StateMachine::STATUS_GENERATING, \AIPI\StateMachine::STATUS_CREATING_PRODUCT ],
                'compare' => 'IN',
            ],
        ],
    ];
    $query2 = new WP_Query( $args2 );
    if ( $query2->have_posts() ) {
        foreach ( $query2->posts as $job_id ) {
            $job_id = (int) $job_id;
            // Mark as failed and record an error. Do not use state machine
            // transition since the job is stuck and we want to force failure.
            update_post_meta( $job_id, '_aipi_job_status', \AIPI\StateMachine::STATUS_FAILED );
            update_post_meta( $job_id, '_aipi_error_message', __( 'The job timed out and was marked as failed.', 'photo-to-product' ) );
            update_post_meta( $job_id, '_aipi_last_updated', time() );
            // Remove any transient locks associated with generation or product creation.
            delete_post_meta( $job_id, '_aipi_lock_generate' );
            delete_post_meta( $job_id, '_aipi_lock_create_product' );
        }
    }
    wp_reset_postdata();
} );