<?php
namespace AIPI;

/**
 * Stores and validates Photo to Product settings.
 * Secrets are saved server-side only and are never localized to browser JS.
 */
class Settings {
    public const OPTION_GROUP = 'aipi_pro_settings';
    public const OPTION_NAME  = 'aipi_pro_settings';

    public const MODE_MANAGED = 'managed';
    public const MODE_BYO     = 'byo_key';

    /**
     * Vendor‑controlled SaaS infrastructure.
     *
     * This constant defines the base URL for the managed Cloudflare Worker
     * backend. Keep the trailing slash so query parameters are appended
     * correctly via add_query_arg().
     */
    public const MANAGED_BACKEND_URL = 'https://ai-product-importer-worker.colindmcintyre.workers.dev/';
    public const PAYPAL_ENVIRONMENT  = 'live';

    public const PRIVACY_POLICY_URL   = 'https://autonomous-builder-bot.github.io/photo-to-product-site/privacy.html';
    public const TERMS_OF_SERVICE_URL = 'https://autonomous-builder-bot.github.io/photo-to-product-site/terms.html';
    public const SUPPORT_URL          = 'https://autonomous-builder-bot.github.io/photo-to-product-site/support.html';

    public static function defaults(): array {
        /*
         * Managed-mode infrastructure is vendor-controlled. The backend URL
         * and PayPal environment are fixed in code so customers cannot
         * accidentally point billing or credit requests at the wrong service.
         * Customer-controlled secrets, such as BYO OpenAI keys, remain stored
         * server-side only.
         */
        return [
            /*
             * Account mode selects between using your own OpenAI API key (BYO) or
             * connecting to an external service (managed). BYO is the safe
             * default so that the plugin works out of the box without any
             * external secrets. Managed mode uses the vendor Cloudflare Worker and
             * requires registration of this installation.
             */
            'account_mode'       => self::MODE_BYO,
            // Vendor Cloudflare Worker URL that mediates ledger and credit requests.
            'backend_url'        => self::MANAGED_BACKEND_URL,
            // Unique identifiers returned from registration. These identify the
            // customer and installation but do not grant access on their own.
            'customer_id'        => '',
            'installation_id'    => '',
            // Installation token issued by the backend. Managed requests use
            // this token together with the installation ID for authentication.
            'installation_token' => '',
            // Derived connection status. See derive_connection_status() for possible values.
            'connection_status'  => 'not_connected',
            // Optional OpenAI key for BYO mode. Blank by default.
            'byo_openai_key'     => '',
            // PayPal client ID is loaded from the vendor Worker public config endpoint.
            'paypal_client_id'   => '',
            // Vendor-managed PayPal environment. Production builds use live.
            'paypal_environment' => self::PAYPAL_ENVIRONMENT,
            // Selected OpenAI model. Defaults to the plugin's default model. Administrators
            // may override this via the settings page. See sanitize() for allowed values.
            'openai_model'       => \AIPI\Generator::MODEL,
            // Controls whether AI-suggested product categories are ignored,
            // assigned only when existing, or created automatically.
            'category_assignment_mode' => 'existing_only',
            // Controls whether AI-suggested product tags are ignored, assigned
            // only when existing, or created automatically.
            'tag_assignment_mode'      => 'existing_only',
        ];
    }

    /**
     * Encrypt a sensitive value using a site-specific key. The key is
     * derived from WordPress authentication salts to ensure encrypted
     * secrets cannot be reused across sites. Modern encryption is used
     * when available: libsodium secretbox is preferred, then AES‑256‑GCM via
     * OpenSSL, and finally AES‑256‑CBC with an HMAC fallback. The algorithm
     * identifier is encoded into the payload. The result is base64 encoded
     * with an "ENC2:" prefix for storage. If encryption cannot be performed
     * safely, a RuntimeException is thrown. Empty strings are returned
     * unchanged without encryption.
     *
     * @param string $plain Plaintext secret
     * @return string Encrypted and encoded secret
     */
    public static function encrypt_secret( string $plain ): string {
        // Modern encryption with authenticated mode. When libsodium is available
        // use it to provide authenticated encryption. Otherwise fall back to
        // AES‑256‑GCM when supported by OpenSSL, and finally to AES‑256‑CBC
        // with an HMAC. A prefix of "ENC2:" marks the new format. The first
        // byte of the decoded payload encodes which algorithm was used:
        // 0 = libsodium secretbox, 1 = AES‑256‑GCM, 2 = AES‑256‑CBC + HMAC.
        if ( '' === $plain ) {
            return '';
        }
        // Build key material from WordPress salts when available. The
        // concatenation of AUTH_KEY and SECURE_AUTH_KEY provides a
        // high‑entropy key unique to this installation. Fall back to
        // the site URL if salts are unavailable.
        $key_material = '';
        if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
            $key_material .= AUTH_KEY;
        }
        if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
            $key_material .= SECURE_AUTH_KEY;
        }
        if ( '' === $key_material ) {
            $key_material = (string) home_url();
        }
        // Derive a 32‑byte key via SHA‑256.
        $key = substr( hash( 'sha256', $key_material, true ), 0, 32 );
        // Prefer libsodium if available.
        if ( function_exists( 'sodium_crypto_secretbox' ) ) {
            $nonce   = random_bytes( \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $cipher  = sodium_crypto_secretbox( $plain, $nonce, $key );
            $payload = chr( 0 ) . $nonce . $cipher;
            return 'ENC2:' . base64_encode( $payload );
        }
        // Fall back to AES‑256‑GCM if supported.
        if ( function_exists( 'openssl_get_cipher_methods' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods( true ), true ) ) {
            $iv  = random_bytes( 12 );
            $tag = '';
            $ciphertext = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
            if ( false === $ciphertext ) {
                throw new \RuntimeException( 'Failed to encrypt secret for storage.' );
            }
            $payload = chr( 1 ) . $iv . $tag . $ciphertext;
            return 'ENC2:' . base64_encode( $payload );
        }
        // Final fallback: AES‑256‑CBC with HMAC.
        if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'hash_hmac' ) ) {
            throw new \RuntimeException( 'Secure secret storage is unavailable on this server.' );
        }
        $iv         = random_bytes( 16 );
        $ciphertext = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        if ( false === $ciphertext ) {
            throw new \RuntimeException( 'Failed to encrypt secret for storage.' );
        }
        $hmac    = hash_hmac( 'sha256', $iv . $ciphertext, $key, true );
        $payload = chr( 2 ) . $iv . $hmac . $ciphertext;
        return 'ENC2:' . base64_encode( $payload );
    }

    /**
     * Decrypt a value previously produced by encrypt_secret(). If the
     * value does not start with an encrypted prefix it is returned unchanged.
     * Encrypted values that cannot be decrypted return an empty string.
     * Empty strings return empty.
     *
     * @param string $value Encrypted or plaintext value
     * @return string Decrypted plaintext, plaintext input, or empty on encrypted failure
     */
    public static function decrypt_secret( string $value ): string {
        if ( '' === $value ) {
            return '';
        }
        // Support both current and older encrypted value prefixes.
        if ( 0 === strpos( $value, 'ENC2:' ) ) {
            $encoded = substr( $value, 5 );
            $decoded = base64_decode( $encoded, true );
            if ( false === $decoded || strlen( $decoded ) < 1 ) {
                return '';
            }
            $algo = ord( $decoded[0] );
            $data = substr( $decoded, 1 );
            // Rebuild key material as in encrypt_secret().
            $key_material = '';
            if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
                $key_material .= AUTH_KEY;
            }
            if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
                $key_material .= SECURE_AUTH_KEY;
            }
            if ( '' === $key_material ) {
                $key_material = (string) home_url();
            }
            $key = substr( hash( 'sha256', $key_material, true ), 0, 32 );
            try {
                if ( 0 === $algo && function_exists( 'sodium_crypto_secretbox_open' ) ) {
                    $nonce_len = \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
                    if ( strlen( $data ) <= $nonce_len ) {
                        return '';
                    }
                    $nonce  = substr( $data, 0, $nonce_len );
                    $cipher = substr( $data, $nonce_len );
                    $plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
                    if ( false === $plain ) {
                        return '';
                    }
                    return $plain;
                }
                if ( 1 === $algo && function_exists( 'openssl_decrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods( true ), true ) ) {
                    if ( strlen( $data ) <= 28 ) {
                        return '';
                    }
                    $iv   = substr( $data, 0, 12 );
                    $tag  = substr( $data, 12, 16 );
                    $ciphertext = substr( $data, 28 );
                    $plain = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '' );
                    if ( false === $plain ) {
                        return '';
                    }
                    return $plain;
                }
                if ( 2 === $algo && function_exists( 'openssl_decrypt' ) && function_exists( 'hash_hmac' ) ) {
                    if ( strlen( $data ) <= 48 ) {
                        return '';
                    }
                    $iv   = substr( $data, 0, 16 );
                    $hmac = substr( $data, 16, 32 );
                    $ciphertext = substr( $data, 48 );
                    $calc = hash_hmac( 'sha256', $iv . $ciphertext, $key, true );
                    if ( ! hash_equals( $hmac, $calc ) ) {
                        return '';
                    }
                    $plain = openssl_decrypt( $ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
                    if ( false === $plain ) {
                        return '';
                    }
                    return $plain;
                }
            } catch ( \Throwable $e ) {
                return '';
            }
            return '';
        }
        // Legacy AES‑CBC without integrity.
        if ( 0 === strpos( $value, 'ENC:' ) ) {
            $encoded = substr( $value, 4 );
            $decoded = base64_decode( $encoded, true );
            if ( false === $decoded || strlen( $decoded ) <= 16 ) {
                return '';
            }
            $iv         = substr( $decoded, 0, 16 );
            $ciphertext = substr( $decoded, 16 );
            // Rebuild key material as in encrypt_secret().
            $key_material = '';
            if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
                $key_material .= AUTH_KEY;
            }
            if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
                $key_material .= SECURE_AUTH_KEY;
            }
            if ( '' === $key_material ) {
                $key_material = (string) home_url();
            }
            $key = substr( hash( 'sha256', $key_material, true ), 0, 32 );
            if ( ! function_exists( 'openssl_decrypt' ) ) {
                return '';
            }
            $plain = openssl_decrypt( $ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
            if ( false === $plain ) {
                return '';
            }
            return $plain;
        }
        // Unrecognised format, return as is.
        return $value;
    }


    /**
     * Log an internal settings-related failure when debug logging is enabled.
     *
     * @param string $message
     * @return void
     */
    private static function debug_log( string $message ): void {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            Logger::log( 'warning', 'Settings notice.', [ 'message' => $message ] );
        }
    }

    public function init(): void {
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function register_settings(): void {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize' ],
                'default'           => self::defaults(),
            ]
        );
    }

    public function sanitize( $input ): array {
        $current = self::get_all();
        $input   = is_array( $input ) ? $input : [];

        // Allow administrators to clear recent logs as part of a settings
        // submission. Clearing logs does not modify any settings values; it
        // simply removes the stored log entries from the database. If the
        // clear_logs flag is set, clear the logs immediately and report the
        // outcome as a settings notice. The input value is a string when
        // submitted via an HTML form; treat any truthy value as a request to
        // clear logs.
        if ( isset( $input['clear_logs'] ) && trim( (string) $input['clear_logs'] ) ) {
            // Remove the logs from the database. Use the Logger API rather
            // than delete_option() directly so any future changes to log
            // storage are respected.
            Logger::clear();
            add_settings_error(
                self::OPTION_NAME,
                'aipi_logs_cleared',
                __( 'Recent plugin logs were cleared.', 'photo-to-product' ),
                'updated'
            );
            // Unset the flag so it does not interfere with option merging below.
            unset( $input['clear_logs'] );
        }

        $mode = isset( $input['account_mode'] ) ? sanitize_key( wp_unslash( $input['account_mode'] ) ) : ( $current['account_mode'] ?? self::MODE_BYO );
        if ( ! in_array( $mode, [ self::MODE_MANAGED, self::MODE_BYO ], true ) ) {
            $mode = self::MODE_BYO;
        }

        $out = $current;
        $out['account_mode']    = $mode;
        // Backend URL is sanitised as a URL. It may contain query parameters
        // depending on your deployment. Leaving it blank disables managed mode.
        $out['backend_url'] = self::MANAGED_BACKEND_URL;
        // Installation credentials can be refreshed by the backend registration flow
        // or manually restored by an administrator if they have previously saved
        // the issued values.
        $out['installation_token'] = $current['installation_token'];

        // Preserve or update the BYO OpenAI key. The UI may provide two
        // separate controls: a password field for entering a new key and a
        // checkbox for clearing the stored key. A constant override takes
        // precedence over stored settings. When a clear flag is present, the
        // stored key is removed entirely. When the password field contains a
        // non-empty value, it is encrypted and stored. Otherwise the
        // existing value is left untouched. Encrypted values (ENC/ENC2) are
        // stored unchanged. Empty password fields no longer implicitly
        // clear the key to avoid accidental data loss when saving settings.
        $clear_byo = isset( $input['clear_byo_openai_key'] ) && trim( (string) $input['clear_byo_openai_key'] ) === '1';
        if ( $clear_byo ) {
            // Explicitly clear the stored key. This always wins, even if a
            // constant is defined. Clearing the stored value allows the
            // constant to take precedence automatically.
            $out['byo_openai_key'] = '';
        } elseif ( isset( $input['byo_openai_key'] ) && '' !== trim( (string) $input['byo_openai_key'] ) ) {
            $raw_key = trim( (string) wp_unslash( $input['byo_openai_key'] ) );
            if ( defined( 'AIPI_OPENAI_KEY' ) && AIPI_OPENAI_KEY ) {
                // Do not persist a key when a constant is defined. Clear the stored value so that the constant takes precedence.
                $out['byo_openai_key'] = '';
            } elseif ( 0 === strpos( $raw_key, 'ENC:' ) || 0 === strpos( $raw_key, 'ENC2:' ) ) {
                $out['byo_openai_key'] = $raw_key;
            } else {
                try {
                    $out['byo_openai_key'] = self::encrypt_secret( $raw_key );
                } catch ( \Throwable $e ) {
                    add_settings_error(
                        self::OPTION_NAME,
                        'aipi_byo_key_encrypt_failed',
                        __( 'Could not securely save the OpenAI API key. The existing key was kept unchanged.', 'photo-to-product' ),
                        'error'
                    );
                    // Do not overwrite the existing key on failure.
                    $out['byo_openai_key'] = $current['byo_openai_key'];
                }
            }
        } else {
            // No changes submitted; preserve existing value to avoid accidental clearing.
            $out['byo_openai_key'] = $current['byo_openai_key'];
        }
        // Customer and installation IDs are never overridden with empty strings; preserve if input is blank.
        $out['category_assignment_mode'] = isset( $input['category_assignment_mode'] )
            ? self::sanitize_taxonomy_assignment_mode( (string) wp_unslash( $input['category_assignment_mode'] ) )
            : ( $current['category_assignment_mode'] ?? 'existing_only' );
        $out['tag_assignment_mode'] = isset( $input['tag_assignment_mode'] )
            ? self::sanitize_taxonomy_assignment_mode( (string) wp_unslash( $input['tag_assignment_mode'] ) )
            : ( $current['tag_assignment_mode'] ?? 'existing_only' );
        // Customer/installation identifiers are managed by dedicated backend
        // registration flows, not by manual settings form submission. Preserve
        // any existing values exactly as stored; the backend is authoritative
        // for installation IDs in managed mode.
        if ( isset( $input['customer_id'] ) ) {
            $customer_id = sanitize_text_field( trim( (string) wp_unslash( $input['customer_id'] ) ) );
            $out['customer_id'] = '' !== $customer_id ? $customer_id : $current['customer_id'];
        } else {
            $out['customer_id'] = $current['customer_id'];
        }

        if ( isset( $input['installation_id'] ) ) {
            $installation_id = sanitize_text_field( trim( (string) wp_unslash( $input['installation_id'] ) ) );
            $out['installation_id'] = '' !== $installation_id ? $installation_id : $current['installation_id'];
        } else {
            $out['installation_id'] = $current['installation_id'];
        }

        if ( isset( $input['installation_token'] ) && '' !== trim( (string) $input['installation_token'] ) ) {
            $token_raw = trim( (string) wp_unslash( $input['installation_token'] ) );
            if ( 0 === strpos( $token_raw, 'ENC:' ) || 0 === strpos( $token_raw, 'ENC2:' ) ) {
                $out['installation_token'] = $token_raw;
            } else {
                try {
                    $out['installation_token'] = self::encrypt_secret( $token_raw );
                } catch ( \Throwable $e ) {
                    self::debug_log( 'Failed to encrypt installation token during sanitize().' );
                    $out['installation_token'] = $current['installation_token'];
                }
            }
        }

        // PayPal public config is supplied by the managed backend. Do not let
        // customer sites override vendor payment settings from wp-admin.
        $out['paypal_client_id']   = '';
        $out['paypal_environment'] = self::PAYPAL_ENVIRONMENT;
        $out['connection_status'] = self::derive_connection_status( $out );

        // Sanitize the selected OpenAI model. Restrict to the plugin's supported list,
        // while allowing site owners to extend that list via a filter if needed.
        $recommended_models = self::get_supported_openai_models();
        $selected_model = isset( $input['openai_model'] )
            ? self::sanitize_openai_model( (string) wp_unslash( $input['openai_model'] ) )
            : '';
        $custom_model   = isset( $input['openai_model_custom'] )
            ? self::sanitize_openai_model( (string) wp_unslash( $input['openai_model_custom'] ) )
            : '';

        if ( '' !== $custom_model ) {
            $out['openai_model'] = $custom_model;
        } elseif ( '' !== $selected_model && in_array( $selected_model, $recommended_models, true ) ) {
            $out['openai_model'] = $selected_model;
        } else {
            $out['openai_model'] = $current['openai_model'] ?? \AIPI\Generator::MODEL;
        }

        return $out;
    }


    /**
     * Return the recommended OpenAI model identifiers shown in the settings UI.
     *
     * The built-in list stays conservative so the default UI does not imply
     * every possible model is guaranteed to be available on every account.
     * Administrators can still enter a custom model ID, and developers may
     * extend this recommended list via the filter below.
     *
     * @return array<int,string>
     */
    public static function get_supported_openai_models(): array {
        $models = [
            \AIPI\Generator::MODEL,
            'gpt-4.1',
            'gpt-4.1-mini',
        ];

        $models = apply_filters( 'aipi_supported_openai_models', $models );
        if ( ! is_array( $models ) ) {
            return [ \AIPI\Generator::MODEL ];
        }

        $models = array_values(
            array_unique(
                array_filter(
                    array_map( [ __CLASS__, 'sanitize_openai_model' ], $models ),
                    static function ( $model ) {
                        return '' !== $model;
                    }
                )
            )
        );

        return $models ?: [ \AIPI\Generator::MODEL ];
    }

    /**
     * Sanitize an OpenAI model identifier for storage and comparison.
     *
     * @param string $model
     * @return string
     */

    /**
     * Allowed taxonomy assignment modes for AI-suggested categories and tags.
     *
     * @return array<string,string>
     */
    public static function get_taxonomy_assignment_modes(): array {
        return [
            'disabled'          => __( 'Don’t use', 'photo-to-product' ),
            'existing_only'     => __( 'Use existing only', 'photo-to-product' ),
            'create_if_missing' => __( 'Create missing', 'photo-to-product' ),
        ];
    }

    /**
     * Sanitize taxonomy assignment mode values.
     *
     * @param string $mode Raw assignment mode.
     * @return string Sanitized assignment mode.
     */
    public static function sanitize_taxonomy_assignment_mode( string $mode ): string {
        $allowed = array_keys( self::get_taxonomy_assignment_modes() );
        $mode    = sanitize_key( $mode );

        return in_array( $mode, $allowed, true ) ? $mode : 'existing_only';
    }

    public static function sanitize_openai_model( string $model ): string {
        return strtolower( trim( sanitize_text_field( $model ) ) );
    }


    /**
     * Determine whether a saved model matches the recommended list.
     *
     * @param string $model
     * @return bool
     */
    public static function is_recommended_openai_model( string $model ): bool {
        return in_array( self::sanitize_openai_model( $model ), self::get_supported_openai_models(), true );
    }

    /**
     * Build a stable site identity basis from the current WordPress site.
     *
     * The installation identity is derived from the canonical home URL and,
     * for multisite, the current blog ID. This keeps the installation ID
     * reproducible without requiring a random secret or opaque token.
     *
     * @return string
     */
    public static function get_installation_identity_basis(): string {
        $site_url = (string) home_url( '/' );
        $site_url = strtolower( trim( $site_url ) );
        $site_url = preg_replace( '#^https?://#', '', $site_url );
        $site_url = untrailingslashit( $site_url );

        if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_current_blog_id' ) ) {
            $site_url .= '|blog:' . (string) get_current_blog_id();
        }

        return $site_url;
    }

    /**
     * Return the current installation ID exactly as provided by the managed
     * backend registration flow. The backend is authoritative for
     * managed-mode identity.
     *
     * @return string
     */
    public static function get_installation_id(): string {
        $installation_id = (string) self::get( 'installation_id', '' );

        return sanitize_text_field( trim( $installation_id ) );
    }

    public static function derive_connection_status( array $settings ): string {
        // BYO mode: require an actually usable key, either from a constant override
        // or from a decryptable stored secret.
        if ( self::MODE_BYO === ( $settings['account_mode'] ?? '' ) ) {
            return self::has_usable_byo_key( $settings ) ? 'byo_key_configured' : 'byo_key_missing';
        }
        // Managed mode requires all backend-issued identifiers needed by the
        // current worker contract: customer ID, installation ID, and a usable
        // installation token for authenticated requests.
        if ( empty( $settings['customer_id'] ) || empty( $settings['installation_id'] ) || ! self::has_usable_secret_value( $settings['installation_token'] ?? '' ) ) {
            return 'pending_registration';
        }
        return 'configured';
    }


    /**
     * Determine whether BYO mode currently has a usable API key.
     *
     * A constant override takes precedence over stored settings. Stored secrets
     * must be non-empty and decryptable when encrypted.
     */
    private static function has_usable_byo_key( array $settings ): bool {
        if ( defined( 'AIPI_OPENAI_KEY' ) && AIPI_OPENAI_KEY ) {
            return true;
        }
        return self::has_usable_secret_value( $settings['byo_openai_key'] ?? '' );
    }

    /**
     * Determine whether a secret-like value is present and usable.
     *
     * Plaintext values are treated as usable when non-empty. Encrypted values
     * must successfully decrypt; malformed ciphertext markers are treated as
     * unusable so the UI does not claim configuration is valid when it is not.
     */
    private static function has_usable_secret_value( $value ): bool {
        if ( ! is_string( $value ) ) {
            return false;
        }
        $raw = trim( $value );
        if ( '' === $raw ) {
            return false;
        }
        // If the value does not look like an encrypted secret, treat it as present.
        if ( 0 !== strpos( $raw, 'ENC:' ) && 0 !== strpos( $raw, 'ENC2:' ) ) {
            return true;
        }
        $decrypted = self::decrypt_secret( $raw );
        return '' !== $decrypted && $decrypted !== $raw;
    }

    public static function get_all(): array {
        $saved    = get_option( self::OPTION_NAME, [] );
        $settings = wp_parse_args( is_array( $saved ) ? $saved : [], self::defaults() );

        // Force vendor-controlled infrastructure even when older saved options
        // contain blank or user-entered values.
        $settings['backend_url']        = self::MANAGED_BACKEND_URL;
        $settings['paypal_client_id']   = '';
        $settings['paypal_environment'] = self::PAYPAL_ENVIRONMENT;
        $settings['connection_status'] = self::derive_connection_status( $settings );

        return $settings;
    }

    public static function get_managed_backend_url(): string {
        return self::MANAGED_BACKEND_URL;
    }

    public static function get_paypal_environment(): string {
        return self::PAYPAL_ENVIRONMENT;
    }

    public static function get( string $key, $default = '' ) {
        $settings = self::get_all();
        if ( ! array_key_exists( $key, $settings ) ) {
            return $default;
        }
        $value = $settings[ $key ];
        // Decrypt sensitive values before returning them. Secrets are stored
        // encrypted; non-encrypted values are returned unchanged. The BYO
        // OpenAI key may be overridden by a constant via get_byo_openai_key().
        if ( 'byo_openai_key' === $key || 'installation_token' === $key ) {
            $raw       = is_string( $value ) ? $value : '';
            $decrypted = self::decrypt_secret( $raw );
            if ( '' !== $raw && ( 0 === strpos( $raw, 'ENC:' ) || 0 === strpos( $raw, 'ENC2:' ) ) && '' === $decrypted ) {
                self::debug_log( sprintf( 'Unable to decrypt stored secret for %s.', $key ) );
            }
            return $decrypted;
        }
        return $value;
    }

    public static function update_many( array $values ): void {
        $settings = self::get_all();
        // Merge incoming values into current settings. Before persisting
        // secrets, encrypt them if they have changed and are not already
        // encrypted. Additionally, if a constant is defined for the BYO
        // OpenAI key, never persist a key in the database.
        foreach ( $values as $key => $value ) {
            switch ( $key ) {
                case 'byo_openai_key':
                    // If a constant is defined, ignore any update and remove any stored value.
                    if ( defined( 'AIPI_OPENAI_KEY' ) && AIPI_OPENAI_KEY ) {
                        unset( $settings['byo_openai_key'] );
                        continue 2;
                    }
                    $trimmed = is_string( $value ) ? trim( $value ) : '';
                    if ( '' === $trimmed ) {
                        // Explicitly clear the stored key when an empty value is provided.
                        $settings['byo_openai_key'] = '';
                        continue 2;
                    }
                    // Encrypt unless it already appears encrypted (ENC/ENC2 prefix).
                    if ( 0 === strpos( $trimmed, 'ENC:' ) || 0 === strpos( $trimmed, 'ENC2:' ) ) {
                        $settings['byo_openai_key'] = $trimmed;
                    } else {
                        try {
                            $settings['byo_openai_key'] = self::encrypt_secret( $trimmed );
                        } catch ( \Throwable $e ) {
                            self::debug_log( 'Failed to encrypt BYO OpenAI key during update_many().' );
                            continue 2;
                        }
                    }
                    continue 2;
                case 'installation_token':
                    $trimmed = is_string( $value ) ? trim( $value ) : '';
                    if ( '' === $trimmed ) {
                        // Explicitly clear the stored token when an empty value is provided.
                        $settings['installation_token'] = '';
                        continue 2;
                    }
                    if ( 0 === strpos( $trimmed, 'ENC:' ) || 0 === strpos( $trimmed, 'ENC2:' ) ) {
                        $settings['installation_token'] = $trimmed;
                    } else {
                        try {
                            $settings['installation_token'] = self::encrypt_secret( $trimmed );
                        } catch ( \Throwable $e ) {
                            self::debug_log( 'Failed to encrypt installation token during update_many().' );
                            continue 2;
                        }
                    }
                    continue 2;
                default:
                    $settings[ $key ] = $value;
            }
        }
        // Recalculate connection status before persisting.
        $settings['connection_status'] = self::derive_connection_status( $settings );
        update_option( self::OPTION_NAME, $settings, false );
    }

    public static function get_mode(): string {
        // Default to BYO mode when no value is set. This encourages safe operation
        // without any external service configuration. If an invalid value is found,
        // fall back to BYO as well.
        $mode = (string) self::get( 'account_mode', self::MODE_BYO );
        return in_array( $mode, [ self::MODE_MANAGED, self::MODE_BYO ], true ) ? $mode : self::MODE_BYO;
    }

    public static function is_managed_mode(): bool {
        return self::MODE_MANAGED === self::get_mode();
    }

    public static function get_byo_openai_key(): string {
        // Allow a global constant to override the saved API key. If defined
        // and non-empty, this constant takes precedence and the stored
        // value is ignored. This enables secure provisioning of keys via
        // wp-config.php without persisting them to the database.
        if ( defined( 'AIPI_OPENAI_KEY' ) && AIPI_OPENAI_KEY ) {
            return (string) AIPI_OPENAI_KEY;
        }
        // Fall back to the saved value, which may be encrypted. The get()
        // method will decrypt automatically.
        return (string) self::get( 'byo_openai_key', '' );
    }


    /**
     * Build a simple diagnostics snapshot for administrators.
     *
     * @return array<int,array<string,string>>
     */
    public static function get_diagnostics(): array {
        $settings = self::get_all();
        $backend  = trim( (string) ( $settings['backend_url'] ?? '' ) );
        $mode     = self::get_mode();

        return [
            [
                'label'  => __( 'WooCommerce active', 'photo-to-product' ),
                'status' => class_exists( 'WooCommerce' ) ? __( 'OK', 'photo-to-product' ) : __( 'Missing', 'photo-to-product' ),
                'detail' => class_exists( 'WooCommerce' ) ? __( 'WooCommerce is loaded.', 'photo-to-product' ) : __( 'WooCommerce must be active for this plugin to work.', 'photo-to-product' ),
            ],
            [
                'label'  => __( 'Account mode', 'photo-to-product' ),
                'status' => $mode,
                'detail' => self::MODE_MANAGED === $mode ? __( 'Managed billing and credit checks are enabled.', 'photo-to-product' ) : __( 'Bring-your-own-key mode is enabled.', 'photo-to-product' ),
            ],
            [
                'label'  => __( 'OpenAI key availability', 'photo-to-product' ),
                'status' => self::has_usable_byo_key( $settings ) ? __( 'Configured', 'photo-to-product' ) : __( 'Missing', 'photo-to-product' ),
                'detail' => self::has_usable_byo_key( $settings ) ? __( 'A usable BYO OpenAI key is available.', 'photo-to-product' ) : __( 'No usable BYO OpenAI key is currently available.', 'photo-to-product' ),
            ],
            [
                'label'  => __( 'Managed backend', 'photo-to-product' ),
                'status' => '' !== $backend ? __( 'Configured', 'photo-to-product' ) : __( 'Missing', 'photo-to-product' ),
                'detail' => '' === $backend ? __( 'Vendor Cloudflare Worker URL is unavailable.', 'photo-to-product' ) : ( 0 === strpos( $backend, 'https://' ) ? __( 'Vendor Cloudflare Worker URL is configured and uses HTTPS.', 'photo-to-product' ) : __( 'Vendor Cloudflare Worker URL is not using HTTPS. Use HTTPS for production.', 'photo-to-product' ) ),
            ],
            [
                'label'  => __( 'Installation registration', 'photo-to-product' ),
                'status' => ( ! empty( $settings['customer_id'] ) && ! empty( $settings['installation_id'] ) ) ? __( 'Configured', 'photo-to-product' ) : __( 'Incomplete', 'photo-to-product' ),
                'detail' => ( ! empty( $settings['customer_id'] ) && ! empty( $settings['installation_id'] ) ) ? __( 'Customer ID and installation ID are present.', 'photo-to-product' ) : __( 'Managed mode registration is incomplete.', 'photo-to-product' ),
            ],
            [
                'label'  => __( 'PayPal configuration', 'photo-to-product' ),
                'status' => __( 'Managed by backend', 'photo-to-product' ),
                'detail' => __( 'PayPal client ID and credit packs are fetched from the vendor Worker. Environment is fixed to live.', 'photo-to-product' ),
            ],
        ];
    }

    /**
     * Render recent diagnostics logs.
     */
    private function render_recent_logs(): void {
        $entries = Logger::recent( 15 );
        if ( empty( $entries ) ) {
            echo '<p>' . esc_html__( 'No recent plugin log entries.', 'photo-to-product' ) . '</p>';
            return;
        }
        echo '<table class="widefat striped" style="max-width: 1000px;"><thead><tr>';
        echo '<th>' . esc_html__( 'Time', 'photo-to-product' ) . '</th>';
        echo '<th>' . esc_html__( 'Level', 'photo-to-product' ) . '</th>';
        echo '<th>' . esc_html__( 'Message', 'photo-to-product' ) . '</th>';
        echo '<th>' . esc_html__( 'Context', 'photo-to-product' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $entries as $entry ) {
            $context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? wp_json_encode( $entry['context'] ) : '';
            echo '<tr>';
            echo '<td><code>' . esc_html( (string) ( $entry['time'] ?? '' ) ) . '</code></td>';
            echo '<td>' . esc_html( strtoupper( (string) ( $entry['level'] ?? '' ) ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( $entry['message'] ?? '' ) ) . '</td>';
            echo '<td><code style="white-space: pre-wrap;">' . esc_html( (string) $context ) . '</code></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    public function render_page(): void {
        // Restrict settings page to administrators only. Secrets and global
        // configuration should not be accessible to store managers. Users
        // must have the manage_options capability to view or edit settings.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-to-product' ) );
        }
        $settings = self::get_all();
        $has_customer_id        = ! empty( $settings['customer_id'] );
        $has_installation_id    = ! empty( $settings['installation_id'] );
        $has_installation_token = self::has_usable_secret_value( $settings['installation_token'] ?? '' );
        $is_registered          = $has_customer_id && $has_installation_id && $has_installation_token;
        $current_installation_token = self::get( 'installation_token', '' );
        ?>
        <div class="wrap photo-to-product-settings">
            <h1><?php esc_html_e( 'Photo to Product', 'photo-to-product' ); ?></h1>
            <?php settings_errors( self::OPTION_NAME ); ?>
            <form method="post" action="options.php">
                <?php settings_fields( self::OPTION_GROUP ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="aipi-account-mode"><?php esc_html_e( 'Account Mode', 'photo-to-product' ); ?></label></th>
                        <td>
                            <select id="aipi-account-mode" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[account_mode]">
                                <option value="byo_key" <?php selected( $settings['account_mode'], self::MODE_BYO ); ?>><?php esc_html_e( 'BYO OpenAI API Key Mode', 'photo-to-product' ); ?></option>
                                <option value="managed" <?php selected( $settings['account_mode'], self::MODE_MANAGED ); ?>><?php esc_html_e( 'Managed Mode', 'photo-to-product' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Managed mode checks and logs credits. BYO mode skips managed billing and uses the saved OpenAI key.', 'photo-to-product' ); ?></p>
                            <p class="description">
                                <?php esc_html_e( 'External services notice:', 'photo-to-product' ); ?>
                                <?php esc_html_e( 'In BYO mode, the plugin only contacts OpenAI when you generate a listing or test your key. In Managed mode, the plugin contacts the vendor Cloudflare Worker only after you connect this site, and then only to register your installation, retrieve balances, run managed generation, fetch public checkout configuration, or create PayPal orders. The PayPal SDK is loaded only on the Billing & Usage tab in connected Managed Mode to allow credit purchases.', 'photo-to-product' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aipi-openai-model"><?php esc_html_e( 'OpenAI Model', 'photo-to-product' ); ?></label></th>
                        <td>
                            <?php
                            $current_model        = $settings['openai_model'] ?? \AIPI\Generator::MODEL;
                            $recommended_models   = self::get_supported_openai_models();
                            $current_custom_model = self::is_recommended_openai_model( $current_model ) ? '' : $current_model;
                            $current_select_model = self::is_recommended_openai_model( $current_model ) ? $current_model : \AIPI\Generator::MODEL;
                            ?>
                            <select id="aipi-openai-model" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_model]">
                                <?php foreach ( $recommended_models as $model_option ) : ?>
                                    <option value="<?php echo esc_attr( $model_option ); ?>" <?php selected( $current_select_model, $model_option ); ?>><?php echo esc_html( $model_option ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Choose a recommended OpenAI model for BYO mode, or enter a custom model ID below if your account exposes a different one.', 'photo-to-product' ); ?>
                            </p>
                            <label for="aipi-openai-model-custom" class="screen-reader-text"><?php esc_html_e( 'Custom OpenAI model ID', 'photo-to-product' ); ?></label>
                            <input type="text" id="aipi-openai-model-custom" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_model_custom]" value="<?php echo esc_attr( $current_custom_model ); ?>" class="regular-text" placeholder="<?php echo esc_attr( \AIPI\Generator::MODEL ); ?>" />
                            <p class="description">
                                <?php esc_html_e( 'Optional custom model ID. When filled, this overrides the dropdown. Model availability depends on your OpenAI account and API access.', 'photo-to-product' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aipi-category-assignment-mode"><?php esc_html_e( 'AI Categories', 'photo-to-product' ); ?></label></th>
                        <td>
                            <select id="aipi-category-assignment-mode" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[category_assignment_mode]">
                                <?php foreach ( self::get_taxonomy_assignment_modes() as $value => $label ) : ?>
                                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['category_assignment_mode'] ?? 'existing_only', $value ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Controls how AI-suggested product categories are handled when new WooCommerce products are created.', 'photo-to-product' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aipi-tag-assignment-mode"><?php esc_html_e( 'AI Tags', 'photo-to-product' ); ?></label></th>
                        <td>
                            <select id="aipi-tag-assignment-mode" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[tag_assignment_mode]">
                                <?php foreach ( self::get_taxonomy_assignment_modes() as $value => $label ) : ?>
                                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['tag_assignment_mode'] ?? 'existing_only', $value ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Use “Create missing” only if you want the AI to create new product tags automatically.', 'photo-to-product' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Managed Service', 'photo-to-product' ); ?></th>
                        <td>
                            <code><?php echo esc_html( self::get_managed_backend_url() ); ?></code>
                            <p class="description">
                                <?php esc_html_e( 'Managed billing is routed through the vendor Cloudflare Worker. This infrastructure setting is not editable on customer sites.', 'photo-to-product' ); ?>
                            </p>
                        </td>
                    </tr>                    <tr>
                        <th scope="row"><?php esc_html_e( 'Connection Status', 'photo-to-product' ); ?></th>
                        <td>
                            <p>
                                <?php if ( $is_registered ) : ?>
                                    <strong><?php esc_html_e( 'Connected', 'photo-to-product' ); ?></strong>
                                <?php else : ?>
                                    <strong><?php esc_html_e( 'Not fully connected', 'photo-to-product' ); ?></strong>
                                <?php endif; ?>
                            </p>
                            <ul style="margin:0 0 8px 18px; list-style: disc;">
                                <li><?php echo esc_html( sprintf( __( 'Customer ID: %s', 'photo-to-product' ), $has_customer_id ? __( 'present', 'photo-to-product' ) : __( 'missing', 'photo-to-product' ) ) ); ?></li>
                                <li><?php echo esc_html( sprintf( __( 'Installation ID: %s', 'photo-to-product' ), $has_installation_id ? __( 'present', 'photo-to-product' ) : __( 'missing', 'photo-to-product' ) ) ); ?></li>
                                <li><?php echo esc_html( sprintf( __( 'Installation Token: %s', 'photo-to-product' ), $has_installation_token ? __( 'present', 'photo-to-product' ) : __( 'missing', 'photo-to-product' ) ) ); ?></li>
                            </ul>
                            <?php
                            $register_url = wp_nonce_url(
                                add_query_arg(
                                    [ 'action' => 'aipi_register_installation' ],
                                    admin_url( 'admin-post.php' )
                                ),
                                'aipi_register_installation_action'
                            );
                            $disconnect_url = wp_nonce_url(
                                add_query_arg(
                                    [ 'action' => 'aipi_disconnect_installation' ],
                                    admin_url( 'admin-post.php' )
                                ),
                                'aipi_disconnect_installation_action'
                            );
                            $reregister_url = wp_nonce_url(
                                add_query_arg(
                                    [ 'action' => 'aipi_reregister_installation' ],
                                    admin_url( 'admin-post.php' )
                                ),
                                'aipi_reregister_installation_action'
                            );
                            ?>
                            <p>
                                <?php if ( ! $is_registered ) : ?>
                                    <a class="button button-primary" href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Connect to Managed Service', 'photo-to-product' ); ?></a>
                                <?php else : ?>
                                    <a class="button button-primary" href="<?php echo esc_url( $reregister_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'This will disconnect the current installation on the backend and register a fresh installation for this site. Continue?', 'photo-to-product' ) ); ?>');"><?php esc_html_e( 'Refresh Managed Connection', 'photo-to-product' ); ?></a>
                                <?php endif; ?>
                            </p>
                            <p class="description" style="margin-top:8px;">
                                <?php esc_html_e( 'Connect to Managed Service sends this site’s URL and WordPress admin URL to our managed service to create an installation record and issue credentials. Refresh Managed Connection disconnects the current installation on the backend and creates a new installation with new credentials. Use the recovery credentials below to restore a previous installation if you saved its values.', 'photo-to-product' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Recovery Credentials', 'photo-to-product' ); ?></th>
                        <td>
                            <p class="description"><?php esc_html_e( 'Below are your current customer ID, installation ID and installation token. These credentials control your managed connection. Copy them and store them securely. Anyone with these values can reconnect to your managed installation.', 'photo-to-product' ); ?></p>
                            <p>
                                <label for="aipi-current-customer-id"><strong><?php esc_html_e( 'Customer ID', 'photo-to-product' ); ?></strong></label><br />
                                <input type="password" class="regular-text code" readonly id="aipi-current-customer-id" value="<?php echo esc_attr( (string) $settings['customer_id'] ); ?>" placeholder="<?php esc_attr_e( 'Not connected', 'photo-to-product' ); ?>" />
                            </p>
                            <p>
                                <label for="aipi-current-installation-id"><strong><?php esc_html_e( 'Installation ID', 'photo-to-product' ); ?></strong></label><br />
                                <input type="password" class="regular-text code" readonly id="aipi-current-installation-id" value="<?php echo esc_attr( (string) $settings['installation_id'] ); ?>" placeholder="<?php esc_attr_e( 'Not connected', 'photo-to-product' ); ?>" />
                            </p>
                            <p>
                                <label for="aipi-current-installation-token"><strong><?php esc_html_e( 'Installation Token', 'photo-to-product' ); ?></strong></label><br />
                                <input type="password" class="regular-text code" readonly id="aipi-current-installation-token" value="<?php echo esc_attr( (string) $current_installation_token ); ?>" placeholder="<?php esc_attr_e( 'Not connected', 'photo-to-product' ); ?>" />
                            </p>
                            <p>
                                <button type="button" class="button" id="aipi-reveal-credentials" data-show-label="<?php echo esc_attr__( 'Reveal', 'photo-to-product' ); ?>" data-hide-label="<?php echo esc_attr__( 'Hide', 'photo-to-product' ); ?>"><?php esc_html_e( 'Reveal', 'photo-to-product' ); ?></button>
                                <button type="button" class="button" id="aipi-copy-credentials"><?php esc_html_e( 'Copy All', 'photo-to-product' ); ?></button>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Restore Existing Connection', 'photo-to-product' ); ?></th>
                        <td>
                            <p class="description">
                                <?php esc_html_e( 'Use this only if you previously saved an already-issued customer ID, installation ID and installation token. Saving blank fields here will not erase the current connection.', 'photo-to-product' ); ?>
                            </p>
                            <p>
                                <label for="aipi-recover-customer-id"><strong><?php esc_html_e( 'Customer ID', 'photo-to-product' ); ?></strong></label><br />
                                <input type="text" class="regular-text" id="aipi-recover-customer-id" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[customer_id]" value="" placeholder="<?php esc_attr_e( 'Paste existing customer ID', 'photo-to-product' ); ?>" />
                            </p>
                            <p>
                                <label for="aipi-recover-installation-id"><strong><?php esc_html_e( 'Installation ID', 'photo-to-product' ); ?></strong></label><br />
                                <input type="text" class="regular-text" id="aipi-recover-installation-id" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[installation_id]" value="" placeholder="<?php esc_attr_e( 'Paste existing installation ID', 'photo-to-product' ); ?>" />
                            </p>
                            <p>
                                <label for="aipi-recover-installation-token"><strong><?php esc_html_e( 'Installation Token', 'photo-to-product' ); ?></strong></label><br />
                                <input type="text" class="regular-text code" autocomplete="off" id="aipi-recover-installation-token" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[installation_token]" value="" placeholder="<?php esc_attr_e( 'Paste existing installation token', 'photo-to-product' ); ?>" />
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aipi-byo-key"><?php esc_html_e( 'BYO OpenAI API Key', 'photo-to-product' ); ?></label></th>
                        <td>
                            <?php
                            // Determine whether a usable BYO key is present. Use the
                            // helper to account for encrypted values and constant
                            // overrides.
                            $has_key = self::has_usable_byo_key( $settings );
                            ?>
                            <input type="password" class="regular-text" autocomplete="new-password" id="aipi-byo-key" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[byo_openai_key]" value="" placeholder="<?php echo esc_attr__( 'Enter new key', 'photo-to-product' ); ?>" />
                            <?php if ( $has_key ) : ?>
                                <p class="description">
                                    <?php esc_html_e( 'A key is currently stored.', 'photo-to-product' ); ?>
                                </p>
                                <label style="display:block;margin-top:4px;">
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[clear_byo_openai_key]" value="1" />
                                    <?php esc_html_e( 'Remove the stored key', 'photo-to-product' ); ?>
                                </label>
                            <?php else : ?>
                                <p class="description">
                                    <?php esc_html_e( 'No key is currently stored.', 'photo-to-product' ); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">&nbsp;</th>
                        <td>
                            <button type="button" class="button" id="aipi-test-byo-key" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'aipi_pro_nonce' ) ); ?>"><?php esc_html_e( 'Test API Key', 'photo-to-product' ); ?></button>
                            <span id="aipi-test-byo-result"></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'PayPal', 'photo-to-product' ); ?></th>
                        <td>
                            <code><?php esc_html_e( 'Live', 'photo-to-product' ); ?></code>
                            <p class="description">
                                <?php esc_html_e( 'PayPal client ID and credit-pack amounts are supplied by the managed backend and are not editable on customer sites.', 'photo-to-product' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Connection Status', 'photo-to-product' ); ?></th>
                        <td><code><?php echo esc_html( $settings['connection_status'] ); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Legal &amp; Support', 'photo-to-product' ); ?></th>
                        <td>
                            <p class="description">
                                <?php
                                /* translators: %1$s: HTML link to privacy policy, %2$s: HTML link to terms of service, %3$s: HTML link to support */
                                echo wp_kses_post(
                                    sprintf(
                                        __( 'Please review our %1$s and %2$s for details about how we handle data and your rights. For assistance, visit our %3$s.', 'photo-to-product' ),
                                        '<a href="' . esc_url( self::PRIVACY_POLICY_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'photo-to-product' ) . '</a>',
                                        '<a href="' . esc_url( self::TERMS_OF_SERVICE_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms of Service', 'photo-to-product' ) . '</a>',
                                        '<a href="' . esc_url( self::SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support page', 'photo-to-product' ) . '</a>'
                                    )
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Logs', 'photo-to-product' ); ?></th>
                        <td>
                            <p class="description" style="margin-bottom:8px;">
                                <?php esc_html_e( 'The plugin stores recent logs to aid support and debugging. These logs are capped in number but may contain operational details. Check the box below to clear the stored logs when saving settings.', 'photo-to-product' ); ?>
                            </p>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[clear_logs]" value="1" />
                                <?php esc_html_e( 'Clear recent plugin logs on save', 'photo-to-product' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr />
            <h2><?php esc_html_e( 'Diagnostics', 'photo-to-product' ); ?></h2>
            <table class="widefat striped" style="max-width: 1000px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Check', 'photo-to-product' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'photo-to-product' ); ?></th>
                        <th><?php esc_html_e( 'Detail', 'photo-to-product' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( self::get_diagnostics() as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row['label'] ); ?></td>
                            <td><code><?php echo esc_html( $row['status'] ); ?></code></td>
                            <td><?php echo esc_html( $row['detail'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top: 1.5em;"><?php esc_html_e( 'Recent plugin logs', 'photo-to-product' ); ?></h2>
            <?php $this->render_recent_logs(); ?>
        </div>
        <?php
    }
}
