<?php
namespace AIPI;

use AIPI\AipiException;

/**
 * OpenAIClient centralises all outbound calls to OpenAI’s chat completions API.
 *
 * All other parts of the plugin should interact with this client rather than
 * calling wp_remote_post() directly. The client applies consistent request
 * options, validates responses, normalises errors and avoids leaking
 * provider-specific details to callers. A minimal key‑validation method is
 * provided to test BYO keys using the same model selection as generation.
 */
class OpenAIClient {
    /**
     * Base URL for the OpenAI chat completion endpoint. Versioned URLs
     * are hardcoded here to avoid scattering strings throughout the codebase.
     */
    public const API_URL = 'https://api.openai.com/v1/chat/completions';

    /**
     * Perform a chat completion request using an explicit API key and body. All
     * callers should build a payload that conforms to OpenAI’s API schema.
     *
     * This method enforces a consistent set of WordPress HTTP options (limited
     * redirects, SSL verification, HTTP/1.1 and unsafe URL rejection) and
     * decodes the JSON response. It throws AipiException on transport errors
     * or invalid responses. Callers must handle AipiException and map
     * appropriate user‑facing messages.
     *
     * @param string $api_key The secret bearer token for OpenAI.
     * @param array  $body    JSON‑serialisable payload for the chat API.
     * @return array Decoded JSON response from OpenAI.
     * @throws AipiException When the request fails or the response cannot be decoded.
     */
    public static function send_request( string $api_key, array $body ): array {
        $api_key = trim( (string) $api_key );
        if ( '' === $api_key ) {
            throw new AipiException( 'missing_api_key', __( 'OpenAI API key is not configured.', 'photo-to-product' ) );
        }
        $args = [
            'headers'            => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'               => wp_json_encode( $body ),
            'timeout'            => 90,
            'redirection'        => 2,
            'reject_unsafe_urls' => true,
            'sslverify'          => true,
            'httpversion'        => '1.1',
        ];
        $response = wp_remote_post( self::API_URL, $args );
        if ( is_wp_error( $response ) ) {
            // Log transport‑level error internally when debug logging is enabled.
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                Logger::log( 'error', 'OpenAI transport error.', [ 'error' => $response->get_error_message() ] );
            }
            throw new AipiException( 'ai_request_failed', __( 'Request to AI service failed.', 'photo-to-product' ) );
        }
        $status_code = wp_remote_retrieve_response_code( $response );
        $raw_body    = wp_remote_retrieve_body( $response );
        $decoded     = json_decode( $raw_body, true );
        if ( ! is_array( $decoded ) ) {
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                Logger::log( 'error', 'OpenAI returned an undecodable response.' );
            }
            throw new AipiException( 'ai_response_invalid', __( 'Failed to parse AI response.', 'photo-to-product' ) );
        }
        if ( $status_code < 200 || $status_code >= 300 ) {
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                Logger::log( 'error', 'OpenAI returned a non‑success HTTP status.', [ 'http_status' => $status_code ] );
            }
            throw new AipiException( 'ai_request_failed', __( 'AI service returned an error.', 'photo-to-product' ) );
        }
        return $decoded;
    }

    /**
     * Validate the provided BYO API key by issuing a minimal chat completion
     * request. This uses the same model resolution logic as generation to
     * ensure the test exercise reflects the configured model.
     *
     * The method returns an array containing success status, a machine‑
     * readable code and a user‑facing message. Detailed provider errors
     * are not exposed to the caller; they are logged internally.
     *
     * @param string $key The BYO OpenAI API key to validate.
     * @return array{success:bool,code:string,message:string}
     */
    public static function validate_api_key( string $key ): array {
        $model = apply_filters( 'aipi_openai_model', Generator::MODEL );
        $body  = [
            'model'       => $model,
            'messages'    => [
                [ 'role' => 'system', 'content' => 'You are validating an API key. Respond with a single word.' ],
                [ 'role' => 'user',   'content' => 'ping' ],
            ],
            'max_tokens'  => 1,
            'temperature' => 0,
        ];
        try {
            $decoded = self::send_request( $key, $body );
        } catch ( AipiException $e ) {
            // Return a generic failure without surfacing provider messages.
            return [
                'success' => false,
                'code'    => $e->getAipiCode(),
                'message' => __( 'The API key could not be validated.', 'photo-to-product' ),
            ];
        }
        // A valid response must include a non‑empty choices array.
        if ( isset( $decoded['choices'] ) && is_array( $decoded['choices'] ) && ! empty( $decoded['choices'] ) ) {
            return [
                'success' => true,
                'code'    => 'ok',
                'message' => __( 'API key validated successfully.', 'photo-to-product' ),
            ];
        }
        return [
            'success' => false,
            'code'    => 'invalid_response',
            'message' => __( 'The API key could not be validated.', 'photo-to-product' ),
        ];
    }
}