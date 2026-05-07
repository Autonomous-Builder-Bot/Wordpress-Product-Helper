<?php
namespace AIPI;

/**
 * Minimal repository contract required by the state machine.
 */
interface StateRepositoryInterface {
    public function get_status( int $job_id ): string;
    public function set_status( int $job_id, string $status ): void;
}
