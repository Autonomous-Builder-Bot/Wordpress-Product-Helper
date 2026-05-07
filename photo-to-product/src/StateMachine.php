<?php
namespace AIPI;

use AIPI\AipiException;

/**
 * Finite state machine controlling the lifecycle of an AI product import job.
 * Defining explicit states and transitions prevents operations from being
 * performed out of order. If a transition is invalid an exception is thrown.
 */
class StateMachine {
    // Possible job statuses.
    public const STATUS_DRAFT             = 'draft';
    public const STATUS_PHOTOS_UPLOADED   = 'photos_uploaded';
    public const STATUS_READY_FOR_GENERATION = 'ready_for_generation';
    public const STATUS_GENERATING        = 'generating';
    public const STATUS_GENERATED         = 'generated';
    public const STATUS_CREATING_PRODUCT  = 'creating_product';
    public const STATUS_COMPLETED         = 'completed';
    public const STATUS_FAILED            = 'failed';

    /**
     * Allowed transitions from each state. See STATE_MACHINE.md for the
     * rationale behind these transitions. The failure state may only be
     * retried by returning to ready_for_generation; broad jumps are not
     * permitted as they lead to inconsistent behaviour.
     *
     * @var array<string, string[]>
     */
    private static $transitions = [
        self::STATUS_DRAFT             => [ self::STATUS_PHOTOS_UPLOADED, self::STATUS_READY_FOR_GENERATION, self::STATUS_FAILED ],
        self::STATUS_PHOTOS_UPLOADED   => [ self::STATUS_READY_FOR_GENERATION, self::STATUS_FAILED ],
        self::STATUS_READY_FOR_GENERATION => [ self::STATUS_GENERATING, self::STATUS_FAILED ],
        self::STATUS_GENERATING        => [ self::STATUS_GENERATED, self::STATUS_FAILED ],
        self::STATUS_GENERATED         => [ self::STATUS_CREATING_PRODUCT, self::STATUS_FAILED ],
        // On product-creation failures we revert to generated instead of failed to allow retry.
        self::STATUS_CREATING_PRODUCT  => [ self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_GENERATED ],
        self::STATUS_COMPLETED         => [],
        self::STATUS_FAILED            => [ self::STATUS_READY_FOR_GENERATION ],
    ];

    /**
     * Check if the state machine allows transitioning from current to next.
     *
     * @param string $current
     * @param string $next
     * @return bool
     */
    public static function can_transition( string $current, string $next ): bool {
        return isset( self::$transitions[ $current ] ) && in_array( $next, self::$transitions[ $current ], true );
    }

    /**
     * Perform a status transition for the given job. Calls through to the
     * repository to persist the status. Throws if the transition is invalid.
     *
     * @param StateRepositoryInterface $repo
     * @param int           $job_id
     * @param string        $new_status
     */
    public static function transition( StateRepositoryInterface $repo, int $job_id, string $new_status ): void {
        $current = $repo->get_status( $job_id );
        if ( ! self::can_transition( $current, $new_status ) ) {
            // Surface invalid transitions as a stable invalid_state code so controllers
            // can map this to a client error. This prevents raw exception text
            // from leaking to the UI and aligns with other validation errors.
            throw new AipiException( 'invalid_state', sprintf( __( 'Invalid status transition from %1$s to %2$s.', 'photo-to-product' ), $current, $new_status ) );
        }
        $repo->set_status( $job_id, $new_status );
    }
}