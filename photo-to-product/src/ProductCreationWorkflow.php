<?php
namespace AIPI;

use AIPI\AipiException;

/**
 * Orchestrates product creation under a workflow-level lock.
 */
class ProductCreationWorkflow {
    /** @var WorkflowRepositoryInterface */
    private $repo;
    /** @var ProductCreator */
    private $creator;

    public function __construct( WorkflowRepositoryInterface $repo, ProductCreator $creator ) {
        $this->repo    = $repo;
        $this->creator = $creator;
    }

    /**
     * Create a product for the given job while holding the full-operation lock.
     *
     * @param int $job_id
     * @return int
     */
    public function execute( int $job_id ): int {
        $status = $this->repo->get_status( $job_id );
        if ( StateMachine::STATUS_GENERATED !== $status ) {
            throw new AipiException( 'invalid_state', __( 'Job is not ready for product creation.', 'photo-to-product' ) );
        }

        $lock_token = $this->repo->acquire_lock( $job_id, 'create_product', 900 );
        if ( ! $lock_token ) {
            throw new AipiException( 'in_progress', __( 'Product creation is already in progress for this job.', 'photo-to-product' ) );
        }

        try {
            $this->repo->refresh_lock( $job_id, 'create_product', $lock_token, 900 );
            $product_id = $this->creator->create( $job_id, $lock_token );
            $this->repo->refresh_lock( $job_id, 'create_product', $lock_token, 900 );
            return $product_id;
        } finally {
            $this->repo->release_lock( $job_id, 'create_product', $lock_token );
        }
    }
}
