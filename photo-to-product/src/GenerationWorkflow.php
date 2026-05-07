<?php
namespace AIPI;

use RuntimeException;
use AIPI\AipiException;

/**
 * Handles the end-to-end workflow of generating a product listing for a job.
 */
class GenerationWorkflow {
    /** @var WorkflowRepositoryInterface */
    private $repo;

    /** @var Generator */
    private $generator;

    /** @var LedgerService */
    private $ledger;

    public function __construct( WorkflowRepositoryInterface $repo, Generator $generator, LedgerService $ledger ) {
        $this->repo      = $repo;
        $this->generator = $generator;
        $this->ledger    = $ledger;
    }

    /**
     * Perform a full listing generation workflow for the given job.
     *
     * @param int    $job_id
     * @param string $description
     * @param bool   $use_images
     * @return array<string,mixed>
     * @throws RuntimeException
     */
    public function execute( int $job_id, string $description, bool $use_images = true ): array {
        $desc              = trim( (string) $description );
        $attachments       = $this->repo->get_attachments( $job_id );
        $images_requested  = $use_images && ! empty( $attachments );
        $preflight_context = [
            'requested_use_images'  => $images_requested,
            'used_images'           => $images_requested,
            'reachable_image_count' => $images_requested ? count( $attachments ) : 0,
            'attachment_count'      => count( $attachments ),
        ];

        if ( '' === $desc && ! $images_requested ) {
            throw new AipiException( 'missing_input', __( 'Please provide a description, at least one photo, or both.', 'photo-to-product' ) );
        }

        if ( StateMachine::STATUS_READY_FOR_GENERATION !== $this->repo->get_status( $job_id ) ) {
            throw new AipiException( 'invalid_state', __( 'Job is not ready for generation.', 'photo-to-product' ) );
        }

        $attempt_id = '';
        $lock_token = $this->repo->acquire_lock( $job_id, 'generate', 900 );
        if ( ! $lock_token ) {
            throw new AipiException( 'in_progress', __( 'Generation is already in progress for this job.', 'photo-to-product' ) );
        }

        try {
            if ( Settings::MODE_MANAGED === Settings::get_mode() ) {
                // In managed mode, the Cloudflare Worker owns the OpenAI key and
                // performs the credit reservation/finalization. Do not perform local
                // billing steps here because the Worker generation endpoint
                // handles reservation and billing in one server-side flow.
                $attempt_id        = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'aipi_', true );
                $preflight_context = $this->generator->estimate_generation_context( $job_id, $desc, $use_images );
                $this->repo->set_meta( $job_id, '_aipi_generation_attempt_id', $attempt_id );
                $this->repo->set_meta( $job_id, '_aipi_generation_context', $preflight_context );
            }

            $this->repo->refresh_lock( $job_id, 'generate', $lock_token, 900 );
            $listing = $this->generator->generate( $job_id, $desc, $images_requested, $lock_token );
            $this->repo->refresh_lock( $job_id, 'generate', $lock_token, 900 );

            if ( Settings::MODE_MANAGED === Settings::get_mode() ) {
                // The Worker already logged usage and updated credits while serving
                // generateListing. Keep the local warning empty unless the Worker
                // response itself failed, which Generator surfaces as an exception.
                $this->repo->set_meta( $job_id, '_aipi_ledger_warning', '' );
            }

            return $listing;
        } catch ( \Throwable $e ) {
            // In managed mode the Worker handles failed-generation usage/refund
            // accounting. In BYO mode there is no managed ledger to update.
            throw $e;
        } finally {
            $this->repo->release_lock( $job_id, 'generate', $lock_token );
        }
    }
}
