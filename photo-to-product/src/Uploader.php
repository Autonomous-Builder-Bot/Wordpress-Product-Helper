<?php
namespace AIPI;

use WP_Error;
use AIPI\AipiException;

/**
 * Handles image uploads for a job. Validates uploaded files to ensure they
 * are images and enforces a simple file count/size limit. Images are
 * inserted into the media library via WordPress APIs and attached to the
 * job. When the first upload occurs the job transitions to photos_uploaded.
 */
class Uploader {
    private $repo;

    /**
     * Maximum number of files allowed per upload request. This prevents
     * accidental huge uploads. Adjust as needed.
     */
    private const MAX_FILES = 10;

    /**
     * Maximum file size in bytes. 5MB per image by default. Modify for your
     * server constraints.
     */
    private const MAX_FILE_SIZE = 5_000_000;

    /**
     * Maximum total attachments allowed per job. Prevents unbounded growth
     * across multiple upload requests.
     */
    private const MAX_ATTACHMENTS_PER_JOB = 10;

    public function __construct( JobRepository $repo ) {
        $this->repo = $repo;
    }

    /**
     * Process uploaded image files for a job. Accepts the $_FILES entry for
     * photos. Throws on error. On success returns an array of new
     * attachment IDs and transitions the job to photos_uploaded if it was
     * previously draft.
     *
     * @param int   $job_id
     * @param array $files
     * @return int[]
     */
    public function upload( int $job_id, array $files ): array {
        // Ensure job exists and is in a state that allows uploads.
        $status = $this->repo->get_status( $job_id );
        if ( ! in_array( $status, [ StateMachine::STATUS_DRAFT, StateMachine::STATUS_PHOTOS_UPLOADED ], true ) ) {
            throw new AipiException( 'invalid_state', __( 'Cannot upload photos in the current job state.', 'photo-to-product' ) );
        }
        if ( empty( $files['name'] ) ) {
            throw new AipiException( 'missing_input', __( 'No files were provided.', 'photo-to-product' ) );
        }
        // Acquire an upload lock to prevent concurrent upload requests from racing.
        $lock_token = $this->repo->acquire_lock( $job_id, 'upload', 300 );
        if ( ! $lock_token ) {
            throw new AipiException( 'in_progress', __( 'An upload is already in progress for this job.', 'photo-to-product' ) );
        }
        // Normalise file array. Accept multiple file uploads.
        $count = is_array( $files['name'] ) ? count( $files['name'] ) : 1;
        if ( $count > self::MAX_FILES ) {
            $this->repo->release_lock( $job_id, 'upload', $lock_token );
            throw new AipiException( 'too_many_files', __( 'Too many files were uploaded at once.', 'photo-to-product' ) );
        }
        // Determine current attachments. We retrieve this once to avoid stale reads.
        $original_attachments = $this->repo->get_attachments( $job_id );
        if ( count( $original_attachments ) + $count > self::MAX_ATTACHMENTS_PER_JOB ) {
            $this->repo->release_lock( $job_id, 'upload', $lock_token );
            throw new AipiException( 'too_many_attachments', __( 'Too many attachments were uploaded for this job.', 'photo-to-product' ) );
        }
        $created              = [];
        $created_files        = [];
        $attachment_ids       = [];
        try {
            for ( $i = 0; $i < $count; $i++ ) {
                // Refresh lock periodically and verify ownership. Extend the lock
                // so long-running uploads remain protected. If we lose the lock
                // mid-iteration, abort to avoid inconsistent state.
                $this->repo->refresh_lock( $job_id, 'upload', $lock_token, 300 );
                if ( ! $this->repo->is_lock_owner( $job_id, 'upload', $lock_token ) ) {
                    throw new AipiException( 'in_progress', __( 'Upload lock expired before processing completed.', 'photo-to-product' ) );
                }
                $file_array = [
                    'name'     => is_array( $files['name'] ) ? $files['name'][ $i ] : $files['name'],
                    'type'     => is_array( $files['type'] ) ? $files['type'][ $i ] : $files['type'],
                    'tmp_name' => is_array( $files['tmp_name'] ) ? $files['tmp_name'][ $i ] : $files['tmp_name'],
                    'error'    => is_array( $files['error'] ) ? $files['error'][ $i ] : $files['error'],
                    'size'     => is_array( $files['size'] ) ? $files['size'][ $i ] : $files['size'],
                ];
                if ( $file_array['error'] !== UPLOAD_ERR_OK ) {
                    throw new AipiException( 'upload_error', __( 'An upload error occurred.', 'photo-to-product' ) );
                }
                if ( $file_array['size'] > self::MAX_FILE_SIZE ) {
                    throw new AipiException( 'file_too_large', __( 'A file exceeds the maximum size.', 'photo-to-product' ) );
                }
                // Validate the file's actual MIME type using wp_check_filetype_and_ext.
                require_once ABSPATH . 'wp-admin/includes/file.php';
                $check = wp_check_filetype_and_ext( $file_array['tmp_name'], $file_array['name'] );
                if ( empty( $check['type'] ) || 0 !== strpos( $check['type'], 'image/' ) ) {
                    throw new AipiException( 'invalid_file_type', __( 'Only image uploads are allowed.', 'photo-to-product' ) );
                }
                // Use wp_handle_upload to move the file to the uploads directory.
                $upload = wp_handle_upload( $file_array, [ 'test_form' => false ] );
                if ( ! empty( $upload['error'] ) ) {
                    throw new AipiException( 'upload_error', is_string( $upload['error'] ) && '' !== $upload['error'] ? sanitize_text_field( $upload['error'] ) : __( 'An upload error occurred.', 'photo-to-product' ) );
                }
                if ( ! empty( $upload['file'] ) && is_string( $upload['file'] ) ) {
                    $created_files[] = $upload['file'];
                }
                // Insert attachment. Use a human‑readable title derived from the
                // original filename without the extension. Sanitise the name
                // to strip unsafe characters while preserving spaces. Do not
                // reuse sanitize_file_name() here because it is designed
                // primarily for filesystem safety and produces awkward titles.
                $raw_name  = wp_basename( (string) $file_array['name'] );
                $title     = pathinfo( $raw_name, PATHINFO_FILENAME );
                // Replace underscores and dashes with spaces for readability.
                $title     = str_replace( [ '_', '-' ], ' ', $title );
                $title     = sanitize_text_field( $title );
                $attachment = [
                    'guid'           => $upload['url'],
                    'post_mime_type' => $upload['type'],
                    'post_title'     => $title,
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                ];
                $attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
                if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
                    throw new AipiException( 'upload_insert_failed', __( 'Failed to insert the uploaded attachment.', 'photo-to-product' ) );
                }
                // Generate attachment metadata.
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
                wp_update_attachment_metadata( $attachment_id, $attach_data );
                // Record provenance: mark which job this attachment came from.
                update_post_meta( $attachment_id, '_aipi_job', $job_id );
                $this->repo->add_attachment( $job_id, (int) $attachment_id );
                $attachment_ids[] = (int) $attachment_id;
                $created[]        = (int) $attachment_id;
            }
            // Transition from draft to photos_uploaded if necessary.
            if ( StateMachine::STATUS_DRAFT === $status ) {
                StateMachine::transition( $this->repo, $job_id, StateMachine::STATUS_PHOTOS_UPLOADED );
            }
            return $attachment_ids;
        } catch ( \Throwable $e ) {
            // Roll back attachments that were created during this upload.
            foreach ( $created as $att_id ) {
                wp_delete_attachment( $att_id, true );
            }
            foreach ( $created_files as $file_path ) {
                if ( is_string( $file_path ) && '' !== $file_path && file_exists( $file_path ) ) {
                    wp_delete_file( $file_path );
                }
            }
            // Restore original attachment list on the job to avoid partial state.
            $this->repo->set_meta( $job_id, '_aipi_job_attachments', $original_attachments );
            throw $e;
        } finally {
            // Always release the upload lock.
            $this->repo->release_lock( $job_id, 'upload', $lock_token );
        }
    }
}