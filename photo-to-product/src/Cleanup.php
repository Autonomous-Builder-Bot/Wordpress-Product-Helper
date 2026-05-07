<?php
namespace AIPI;

/**
 * Performs cleanup tasks such as deleting a job post. Media attachments
 * uploaded for a job are deliberately left untouched during cleanup. Any AI
 * derivative files created specifically for generation are removed because
 * they are plugin-owned cache artifacts rather than primary media.
 */
class Cleanup {
    private $repo;

    public function __construct( JobRepository $repo ) {
        $this->repo = $repo;
    }

    /**
     * Delete a job record only. Uploaded media is intentionally left in place
     * because attachments may be reused by created products or by site staff
     * outside this workflow.
     * Finally deletes the job post.
     *
     * @param int $job_id
     */
    public function delete_job( int $job_id ): void {
        $job = $this->repo->get_job( $job_id );
        if ( ! $job ) {
            return;
        }
        $attachments = $this->repo->get_attachments( $job_id );
        ImagePreparationService::cleanup_derivatives_for_attachments( $attachments );

        // Remove provenance metadata from attachments. Leaving the `_aipi_job`
        // meta around after deleting a job can cause confusion when viewing
        // attachments in the media library. We deliberately leave the
        // attachment itself in place, but strip plugin-specific meta.
        foreach ( $attachments as $att_id ) {
            delete_post_meta( (int) $att_id, '_aipi_job' );
        }

        // To avoid accidental media loss, do not delete the attachments here.
        // Media used in products or uploaded via jobs may be reused elsewhere.
        // Only plugin-owned AI derivative files are removed before deleting the job.
        wp_delete_post( $job_id, true );
    }
}