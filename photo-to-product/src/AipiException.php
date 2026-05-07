<?php
namespace AIPI;

use RuntimeException;

/**
 * Exception class that carries a stable error code for AI Product Importer.
 *
 * By attaching a short, machine‑readable error code to each exception we can
 * consistently map internal errors to HTTP status codes and user‑friendly
 * messages without relying on fragile substring matching. Only the
 * human‑readable message should be displayed to the merchant; the code is
 * used internally by the controller to determine the appropriate HTTP
 * response.
 */
class AipiException extends RuntimeException {
    /**
     * Internal error code used for mapping. See Plugin::get_error_http_status().
     *
     * @var string
     */
    private $aipi_code;

    /**
     * Construct a new AipiException.
     *
     * @param string $code    Machine‑readable error code (e.g. invalid_state, missing_input)
     * @param string $message Localised human‑friendly error message
     */
    public function __construct( string $code, string $message ) {
        $this->aipi_code = $code;
        parent::__construct( $message );
    }

    /**
     * Retrieve the internal error code.
     *
     * @return string
     */
    public function getAipiCode(): string {
        return $this->aipi_code;
    }
}