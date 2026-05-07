<?php
namespace AIPI;

/**
 * Handles communication with the vendor Cloudflare backend. Managed-mode
 * requests use the installation ID and installation token returned during
 * registration. When operating in BYO mode the plugin does not contact the
 * backend and all managed-service methods return immediately.
 */
class LedgerService {
    /**
     * Retrieve public SaaS config from the managed backend. This endpoint must
     * return only non-secret values, such as PayPal client ID, currency, and
     * credit-pack amounts. It intentionally does not require an installation
     * token so the admin UI can load PayPal before or after registration.
     *
     * @return array Result array with public configuration or an error message
     */
    public function getPublicConfig(): array {
        $result = $this->request( 'publicConfig', [], false );

        // Allow Workers that expose /config instead of ?action=publicConfig.
        if ( empty( $result['success'] ) ) {
            $result = $this->request( 'config', [], false );
        }

        return $result;
    }
    /**
     * Register this WordPress installation with the backend. The backend
     * returns the customer identifier, installation identifier, and
     * installation token used for subsequent managed requests. Registration
     * may be invoked multiple times; subsequent calls overwrite the stored
     * identifiers.
     *
     * @return array Result array containing success flag and data or error message
     */
    public function registerInstallation(): array {
        // Build payload using site metadata only. The current backend is
        // authoritative for customer, installation, and token issuance.
        $payload = [
            'site_url'        => home_url(),
            'admin_url'       => admin_url(),
            'site_name'       => get_bloginfo( 'name' ),
            'admin_email'     => get_bloginfo( 'admin_email' ),
            'plugin_version'  => defined( 'AIPI_PRO_VERSION' ) ? (string) AIPI_PRO_VERSION : '',
            'wp_version'      => get_bloginfo( 'version' ),
            'wc_version'      => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
        ];
        $result = $this->request( 'registerInstallation', $payload, false );
        if ( ! empty( $result['success'] ) ) {
            // Accept either a top-level worker response shape or a nested data wrapper.
            $registration = [];
            if ( ! empty( $result['data'] ) && is_array( $result['data'] ) ) {
                $registration = $result['data'];
            } elseif ( is_array( $result ) ) {
                $registration = $result;
            }

            $updates = [];
            if ( ! empty( $registration['customer_id'] ) ) {
                $customer_id = sanitize_text_field( trim( (string) $registration['customer_id'] ) );
                if ( '' !== $customer_id ) {
                    $updates['customer_id'] = $customer_id;
                }
            }
            if ( ! empty( $registration['installation_id'] ) ) {
                $installation_id = sanitize_text_field( trim( (string) $registration['installation_id'] ) );
                if ( '' !== $installation_id ) {
                    $updates['installation_id'] = $installation_id;
                }
            }
            if ( ! empty( $registration['installation_token'] ) ) {
                // Do not sanitise tokens; preserve all characters and trim whitespace.
                $installation_token = trim( (string) $registration['installation_token'] );
                if ( '' !== $installation_token ) {
                    $updates['installation_token'] = $installation_token;
                }
            }
            if ( $updates ) {
                Settings::update_many( $updates );
            }
        }
        return $result;
    }


    /**
     * Disconnect the current installation from the managed backend.
     *
     * The backend invalidates the current installation credentials so this site
     * can be registered again cleanly. In BYO mode this call is a no-op.
     *
     * @return array Result array indicating success or failure
     */
    public function disconnectInstallation(): array {
        if ( Settings::MODE_BYO === Settings::get_mode() ) {
            return [ 'success' => true, 'data' => [] ];
        }
        return $this->request( 'disconnectInstallation', $this->identity_payload() );
    }

    /**
     * Retrieve the current balance or credit allowance. When operating in BYO
     * mode the plugin does not track usage and therefore returns a null
     * balance. In managed mode the backend controls the balance state.
     *
     * @return array Result array with balance information or an error message
     */
    public function getBalance(): array {
        if ( Settings::MODE_BYO === Settings::get_mode() ) {
            return [ 'success' => true, 'data' => [ 'balance' => null ] ];
        }
        return $this->request( 'getBalance', $this->identity_payload() );
    }
    /**
     * Request managed-mode AI generation from the vendor Cloudflare Worker.
     *
     * The OpenAI API key must never be sent to, or stored on, customer WordPress
     * sites. In managed mode the Worker owns the OpenAI secret, performs the AI
     * request server-side, handles billing/reservation logic, and returns only
     * the generated listing and usage metadata.
     *
     * @param array $payload Generation request payload including messages and context.
     * @return array Result array with listing/usage data or an error message
     */
    public function generateListing( array $payload ): array {
        if ( Settings::MODE_BYO === Settings::get_mode() ) {
            return $this->error_result( 'wrong_mode', __( 'Managed generation is only available in managed mode.', 'photo-to-product' ) );
        }
        return $this->request( 'generateListing', array_merge( $this->identity_payload(), $payload ), true, 120 );
    }

    /**
     * Create a PayPal order by canonical pack identifier.
     * The backend validates the pack identifier and resolves the billing amount.
     *
     * @param string $packId Pack identifier returned by getPublicConfig()
     * @return array Result array with success flag and data or error message
     */
    public function createPayPalOrderFromPack( string $packId ): array {
        if ( Settings::MODE_BYO === Settings::get_mode() ) {
            return $this->error_result( 'wrong_mode', __( 'Credit purchases are only available in managed mode.', 'photo-to-product' ) );
        }
        $payload = array_merge( $this->identity_payload(), [ 'pack_id' => $packId ] );
        return $this->request( 'createPayPalOrder', $payload );
    }

    /**
     * Build the base payload used for managed calls.
     *
     * The authenticated installation identifier and installation token are
     * sent in request headers. The JSON payload includes only the current
     * site URL and any operation-specific fields.
     *
     * @return array
     */
    private function identity_payload(): array {
        return [
            'site_url'        => home_url(),
        ];
    }

    /**
     * Perform an HTTP request to the vendor Cloudflare Worker. If $withToken is
     * false then no managed identity headers are included; this is used
     * during the registration step where no customer mapping may exist yet.
     *
     * @param string $action The name of the Worker action
     * @param array  $payload Request payload to send
     * @param bool   $withToken Whether to include managed identity headers
     * @return array Result array with success flag and data or error message
     */
    private function request( string $action, array $payload, bool $withToken = true, int $timeout = 20 ): array {
        $url = (string) Settings::get( 'backend_url', '' );
        if ( '' === $url ) {
            return $this->error_result( 'missing_url', __( 'The managed backend URL is unavailable.', 'photo-to-product' ) );
        }
        $headers = [ 'Content-Type' => 'application/json' ];
        if ( $withToken ) {
            $installation_id = (string) Settings::get( 'installation_id', '' );
            if ( '' === trim( $installation_id ) ) {
                return $this->error_result( 'missing_installation_id', __( 'The installation ID is not configured.', 'photo-to-product' ) );
            }
            $headers['X-AIPI-Installation-Id'] = sanitize_text_field( trim( $installation_id ) );

            $installation_token = (string) Settings::get( 'installation_token', '' );
            if ( '' === trim( $installation_token ) ) {
                return $this->error_result( 'missing_installation_token', __( 'The installation token is not configured.', 'photo-to-product' ) );
            }
            $headers['X-AIPI-Installation-Token'] = $installation_token;
        }
        // Append an action query parameter for simple routing on the backend. If
        // your backend uses a different routing mechanism you can ignore or
        // modify this parameter.
        //
        // Do not double-encode the action parameter. `add_query_arg()`
        // automatically handles encoding, so pre-encoding via rawurlencode()
        // can lead to confusing behaviour and double-encoded values. See
        // https://developer.wordpress.org/reference/functions/add_query_arg/ for
        // details.
        $request_url = add_query_arg( 'action', $action, $url );
        $body        = array_merge( $payload, [
            'timestamp' => time(),
        ] );
        $response = wp_remote_post( $request_url, [
            'headers'            => $headers,
            'body'               => wp_json_encode( $body ),
            'timeout'            => $timeout,
            // Limit redirects to avoid endless loops and reduce attack surface.
            'redirection'        => 3,
            // Reject unsafe or malformed URLs early.
            'reject_unsafe_urls' => true,
            // Explicitly enable SSL verification. WordPress defaults to true but
            // specifying it reinforces intent.
            'sslverify'          => true,
            // Use HTTP/1.1 to improve compatibility with some hosts and proxies.
            'httpversion'        => '1.1',
        ] );
        if ( is_wp_error( $response ) ) {
            return $this->error_result( 'http_error', $response->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );
        if ( $code < 200 || $code >= 300 ) {
            return $this->error_result( 'bad_status', sprintf( __( 'The backend returned HTTP %d.', 'photo-to-product' ), $code ), [ 'http_status' => $code, 'raw' => $raw ] );
        }
        if ( ! is_array( $data ) ) {
            return $this->error_result( 'invalid_json', __( 'The backend returned invalid JSON.', 'photo-to-product' ), [ 'raw' => $raw ] );
        }
        if ( isset( $data['success'] ) && ! $data['success'] ) {
            return $this->error_result( (string) ( $data['code'] ?? 'backend_error' ), (string) ( $data['message'] ?? __( 'The backend request failed.', 'photo-to-product' ) ), $data );
        }
        return [
            'success' => true,
            'data'    => $data,
            'message' => isset( $data['message'] ) ? (string) ( $data['message'] ) : '',
        ];
    }

    /**
     * Construct a standard error result. Having a consistent shape for
     * failures simplifies error handling in controllers and the UI.
     *
     * @param string $code Short error code
     * @param string $message Human‑readable message
     * @param array  $data Additional debug data
     * @return array
     */
    private function error_result( string $code, string $message, array $data = [] ): array {
        return [
            'success' => false,
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ];
    }
}
