<?php
namespace AIPI;

/**
 * Repository contract used by workflow services.
 */
interface WorkflowRepositoryInterface extends StateRepositoryInterface {
    /**
     * @return array<int,int>
     */
    public function get_attachments( int $job_id ): array;

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get_meta( int $job_id, string $key, $default = '' );

    /**
     * @param mixed $value
     */
    public function set_meta( int $job_id, string $key, $value ): void;

    public function acquire_lock( int $job_id, string $name, int $ttl = 300 ): ?string;

    public function release_lock( int $job_id, string $name, string $token ): void;

    public function refresh_lock( int $job_id, string $name, string $token, int $ttl = 300 ): bool;

    public function is_lock_owner( int $job_id, string $name, string $token ): bool;
}

