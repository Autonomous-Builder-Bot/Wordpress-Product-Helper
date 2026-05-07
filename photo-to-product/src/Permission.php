<?php
namespace AIPI;

use WP_Post;

/**
 * Centralises capability and ownership checks for AI Product Importer Pro.
 * Administrators may manage all jobs, while store managers are limited to
 * their own jobs and the capabilities needed for each action.
 */
class Permission {

    /**
     * The base capability required to access plugin functionality. We use
     * WooCommerce's manage_woocommerce capability for store managers.
     */
    public const BASE_CAPABILITY = 'manage_woocommerce';

    /**
     * Determine whether the current user can manage the plugin at all.
     *
     * @return bool
     */
    public static function user_can_manage(): bool {
        return current_user_can( self::BASE_CAPABILITY );
    }

    /**
     * Determine whether the current user may upload product photos through the plugin.
     *
     * @return bool
     */
    public static function user_can_upload(): bool {
        return self::user_can_manage() && current_user_can( 'upload_files' );
    }

    /**
     * Determine whether the current user may create WooCommerce draft products.
     *
     * @return bool
     */
    public static function user_can_create_products(): bool {
        if ( ! self::user_can_manage() ) {
            return false;
        }

        return current_user_can( 'edit_products' );
    }

    /**
     * Determine whether the current user may manage global plugin settings and
     * managed-service account actions.
     *
     * @return bool
     */
    public static function user_can_manage_settings(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * Determine whether the current user can access the specified job. The
     * user must be able to manage the plugin and either be the job owner or
     * have administrator privileges. WordPress roles should never be passed
     * directly to current_user_can(); instead, use capabilities.
     *
     * @param int $job_id
     * @return bool
     */
    public static function user_can_access_job( int $job_id ): bool {
        if ( ! self::user_can_manage() ) {
            return false;
        }
        $job = get_post( $job_id );
        if ( ! $job instanceof WP_Post || JobRepository::POST_TYPE !== $job->post_type ) {
            return false;
        }
        $owner_id   = (int) get_post_meta( $job_id, '_aipi_job_owner', true );
        $current_id = get_current_user_id();
        // Grant full access to site administrators (manage_options) beyond base capability.
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        return $current_id === $owner_id;
    }
}