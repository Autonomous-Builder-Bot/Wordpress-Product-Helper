<?php
namespace AIPI;

use WP_Post;

/**
 * Repository responsible for creating and managing AI job posts. Each job is
 * stored as a private custom post type with associated metadata for owner,
 * status, attachments, generated listing and errors. Encapsulating post
 * operations in a repository makes it easier to evolve storage without
 * touching consumer code.
 */
class JobRepository implements WorkflowRepositoryInterface {
    /**
     * The custom post type slug used to represent jobs. This post type is
     * registered by the Plugin class and is not publicly queryable.
     */
    public const POST_TYPE = 'aipi_job';

    /**
     * Create a new job and return its post ID. The job is created in
     * private status so that metadata can be added, and the post type is
     * hidden from public queries. The initial status is draft.
     *
     * @param int $owner_id The user ID of the job creator.
     * @return int          The new job ID.
     */
    public function create_job( int $owner_id ): int {
        $post_id = wp_insert_post( [
            'post_title'   => 'AIPI Job ' . current_time( 'Y-m-d H:i:s' ),
            'post_content' => '',
            // Use private status for internal workflow posts. Jobs are not
            // intended to be publicly visible. WordPress will restrict
            // access appropriately.
            'post_status'  => 'private',
            'post_type'    => self::POST_TYPE,
        ] );
        if ( ! $post_id || is_wp_error( $post_id ) ) {
            throw new \RuntimeException( 'Failed to create job.' );
        }
        // Record the job owner.
        update_post_meta( $post_id, '_aipi_job_owner', $owner_id );
        // Initialise status and attachments.
        update_post_meta( $post_id, '_aipi_job_status', StateMachine::STATUS_DRAFT );
        update_post_meta( $post_id, '_aipi_job_attachments', [] );
        // Record creation and last-updated timestamps. These timestamps
        // facilitate retention policies and stale job detection. Use
        // current_time( 'timestamp' ) so the value can be compared with
        // other Unix timestamps.
        $now = current_time( 'timestamp' );
        update_post_meta( $post_id, '_aipi_created_at', $now );
        update_post_meta( $post_id, '_aipi_last_updated', $now );
        return (int) $post_id;
    }

    /**
     * Retrieve a job post by ID. Returns null if the post does not exist or
     * is not of the correct post type.
     *
     * @param int $job_id
     * @return WP_Post|null
     */
    public function get_job( int $job_id ): ?WP_Post {
        $job = get_post( $job_id );
        if ( $job instanceof WP_Post && self::POST_TYPE === $job->post_type ) {
            return $job;
        }
        return null;
    }

    /**
     * Get a meta value for a job, returning a default if not set or empty.
     *
     * @param int    $job_id
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get_meta( int $job_id, string $key, $default = null ) {
        $value = get_post_meta( $job_id, $key, true );
        return '' !== $value && null !== $value ? $value : $default;
    }

    /**
     * Set a meta value for a job. Overwrites existing values.
     *
     * @param int    $job_id
     * @param string $key
     * @param mixed  $value
     */
    public function set_meta( int $job_id, string $key, $value ): void {
        update_post_meta( $job_id, $key, $value );
        // Update the last-updated timestamp whenever any meta is changed.
        $now = current_time( 'timestamp' );
        update_post_meta( $job_id, '_aipi_last_updated', $now );
    }

    /**
     * Add an attachment to a job. Ensures no duplicates. Attachments are
     * stored as an array of IDs on the job meta key _aipi_job_attachments.
     *
     * @param int $job_id
     * @param int $attachment_id
     */
    public function add_attachment( int $job_id, int $attachment_id ): void {
        $attachments = $this->get_meta( $job_id, '_aipi_job_attachments', [] );
        if ( ! is_array( $attachments ) ) {
            $attachments = [];
        }
        if ( ! in_array( $attachment_id, $attachments, true ) ) {
            $attachments[] = $attachment_id;
            // Use set_meta() to ensure last_updated is refreshed.
            $this->set_meta( $job_id, '_aipi_job_attachments', $attachments );
        }
    }

    /**
     * Retrieve the list of attachment IDs for a job. Always returns an array.
     *
     * @param int $job_id
     * @return int[]
     */
    public function get_attachments( int $job_id ): array {
        $attachments = $this->get_meta( $job_id, '_aipi_job_attachments', [] );
        return is_array( $attachments ) ? array_map( 'intval', $attachments ) : [];
    }

    /**
     * Update the job status. Consumer code should call StateMachine::transition
     * before persisting status to enforce valid transitions.
     *
     * @param int    $job_id
     * @param string $status
     */
    public function set_status( int $job_id, string $status ): void {
        update_post_meta( $job_id, '_aipi_job_status', $status );
        // Refresh the last-updated timestamp whenever the job status changes.
        $now = current_time( 'timestamp' );
        update_post_meta( $job_id, '_aipi_last_updated', $now );
    }

    /**
     * Retrieve the current status for a job.
     *
     * @param int $job_id
     * @return string
     */
    public function get_status( int $job_id ): string {
        return (string) $this->get_meta( $job_id, '_aipi_job_status', StateMachine::STATUS_DRAFT );
    }

    /**
     * Acquire a transient lock for a job and return an ownership token.
     *
     * The returned token must be passed back to release_lock() so a stale
     * request cannot release a newer lock owner's lock after the TTL expires.
     * Returns null when the lock is already held by an unexpired owner.
     *
     * @param int    $job_id
     * @param string $lock_name Unique lock name (e.g. 'generate', 'create_product').
     * @param int    $ttl       Lock time-to-live in seconds. Defaults to five minutes.
     * @return string|null Ownership token when acquired, otherwise null.
     */
    public function acquire_lock( int $job_id, string $lock_name, int $ttl = 300 ): ?string {
        $key = '_aipi_lock_' . sanitize_key( $lock_name );
        $now = time();

        if ( function_exists( 'wp_generate_uuid4' ) ) {
            $token = wp_generate_uuid4();
        } else {
            $token = wp_rand() . '-' . $now;
        }
        $lock_value = [
            'token'      => $token,
            'expires_at' => $now + (int) $ttl,
        ];

        $added = add_post_meta( $job_id, $key, $lock_value, true );
        if ( $added ) {
            return $token;
        }

        $existing = get_post_meta( $job_id, $key, true );
        if ( is_array( $existing ) && isset( $existing['expires_at'] ) ) {
            if ( (int) $existing['expires_at'] > $now ) {
                return null;
            }
            $updated = update_post_meta( $job_id, $key, $lock_value, $existing );
            return $updated ? $token : null;
        }

        delete_post_meta( $job_id, $key );
        $added = add_post_meta( $job_id, $key, $lock_value, true );
        return $added ? $token : null;
    }

    /**
     * Check whether a job lock currently exists and has not expired.
     *
     * @param int    $job_id
     * @param string $lock_name
     * @return bool
     */
    public function has_active_lock( int $job_id, string $lock_name ): bool {
        $key      = '_aipi_lock_' . sanitize_key( $lock_name );
        $existing = get_post_meta( $job_id, $key, true );
        return is_array( $existing ) && isset( $existing['expires_at'] ) && (int) $existing['expires_at'] > time();
    }

    /**
     * Release a previously acquired lock only when the caller still owns it.
     *
     * @param int    $job_id
     * @param string $lock_name
     * @param string $token Ownership token returned by acquire_lock().
     */
    public function release_lock( int $job_id, string $lock_name, string $token ): void {
        $key      = '_aipi_lock_' . sanitize_key( $lock_name );
        $existing = get_post_meta( $job_id, $key, true );
        if ( is_array( $existing ) && isset( $existing['token'] ) && hash_equals( (string) $existing['token'], $token ) ) {
            delete_post_meta( $job_id, $key );
        }
    }

    /**
     * Refresh a previously acquired lock when the caller still owns it.
     *
     * @param int    $job_id
     * @param string $lock_name
     * @param string $token
     * @param int    $ttl
     * @return bool
     */
    public function refresh_lock( int $job_id, string $lock_name, string $token, int $ttl = 300 ): bool {
        $key      = '_aipi_lock_' . sanitize_key( $lock_name );
        $existing = get_post_meta( $job_id, $key, true );
        if ( ! is_array( $existing ) || ! isset( $existing['token'] ) || ! hash_equals( (string) $existing['token'], $token ) ) {
            return false;
        }
        $existing['expires_at'] = time() + (int) $ttl;
        update_post_meta( $job_id, $key, $existing );
        return true;
    }
    /**
     * Determine whether the provided token still owns the named lock.
     *
     * @param int    $job_id
     * @param string $lock_name
     * @param string $token
     * @return bool
     */
    public function is_lock_owner( int $job_id, string $lock_name, string $token ): bool {
        $key      = '_aipi_lock_' . sanitize_key( $lock_name );
        $existing = get_post_meta( $job_id, $key, true );
        return is_array( $existing ) && isset( $existing['token'] ) && hash_equals( (string) $existing['token'], $token ) && isset( $existing['expires_at'] ) && (int) $existing['expires_at'] > time();
    }

}
