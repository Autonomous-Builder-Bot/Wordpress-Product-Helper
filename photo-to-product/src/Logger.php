<?php
namespace AIPI;

class Logger {
    private const OPTION_NAME = 'aipi_recent_logs';
    private const MAX_ENTRIES = 100;

    private static function string_length( string $value ): int {
        return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $value ) : strlen( $value );
    }

    private static function string_slice( string $value, int $start, ?int $length = null ): string {
        if ( function_exists( 'mb_substr' ) ) {
            return null === $length ? (string) mb_substr( $value, $start ) : (string) mb_substr( $value, $start, $length );
        }

        return null === $length ? (string) substr( $value, $start ) : (string) substr( $value, $start, $length );
    }

    public static function log( string $level, string $message, array $context = [] ): void {
        $entry = [
            'time'    => current_time( 'mysql' ),
            'level'   => sanitize_key( $level ),
            'message' => sanitize_text_field( $message ),
            'context' => self::sanitize_context( $context ),
        ];
        $existing = get_option( self::OPTION_NAME, [] );
        if ( ! is_array( $existing ) ) {
            $existing = [];
        }
        array_unshift( $existing, $entry );
        if ( count( $existing ) > self::MAX_ENTRIES ) {
            $existing = array_slice( $existing, 0, self::MAX_ENTRIES );
        }
        update_option( self::OPTION_NAME, $existing, false );
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( 'AIPI [' . strtoupper( $entry['level'] ) . '] ' . $entry['message'] . ' ' . wp_json_encode( $entry['context'] ) );
        }
    }

    public static function exception( string $level, string $message, \Throwable $e, array $context = [] ): void {
        // Record the class for debugging purposes.
        $context['exception_class'] = get_class( $e );
        // Extract the message and sanitize it aggressively. Strip any HTML tags,
        // collapse newlines and excessive whitespace, and truncate to 400 characters.
        $raw_msg = '';
        if ( method_exists( $e, 'getMessage' ) ) {
            $raw_msg = (string) $e->getMessage();
        }
        // Remove any HTML tags that might be included in exception messages.
        $sanitized = wp_strip_all_tags( $raw_msg );
        // Collapse CR/LF into single spaces.
        $sanitized = preg_replace( '/[\r\n]+/', ' ', $sanitized );
        $sanitized = trim( $sanitized );
        // Limit length to avoid overflowing logs.
        if ( self::string_length( $sanitized ) > 400 ) {
            $sanitized = self::string_slice( $sanitized, 0, 400 );
        }
        $context['exception_message'] = sanitize_text_field( $sanitized );
        self::log( $level, $message, $context );
    }

    public static function recent( int $limit = 20 ): array {
        $existing = get_option( self::OPTION_NAME, [] );
        if ( ! is_array( $existing ) ) {
            return [];
        }
        $limit = max( 1, min( 100, $limit ) );
        return array_slice( $existing, 0, $limit );
    }

    public static function clear(): void {
        delete_option( self::OPTION_NAME );
    }

    private static function sanitize_context( array $context ): array {
        $out = [];
        foreach ( $context as $key => $value ) {
            $k          = sanitize_key( (string) $key );
            $out[ $k ] = self::redact_sensitive_data( $value, $k );
        }
        return $out;
    }

    /**
     * Redact sensitive values and trim oversized payloads before they are stored.
     *
     * @param mixed       $value Context value.
     * @param string|null $key   Sanitized array key when available.
     * @return mixed
     */
    private static function redact_sensitive_data( $value, ?string $key = null ) {
        $sensitive_keys = [
            'api_key',
            'key',
            'token',
            'access_token',
            'refresh_token',
            'installation_token',
            'authorization',
            'secret',
            'password',
            'body',
            'raw',
            'request_body',
            'response_body',
            'headers',
            'customer',
            'billing',
            'payer',
            'email',
        ];

        if ( null !== $key && in_array( $key, $sensitive_keys, true ) ) {
            return '[redacted]';
        }

        if ( is_string( $value ) ) {
            $value = wp_strip_all_tags( preg_replace( '/[
]+/', ' ', $value ) );
            if ( preg_match( '/bearer\s+[a-z0-9\-\._~\+\/]+=*/i', $value ) ) {
                return '[redacted]';
            }
            if ( preg_match( '/(sk-[a-z0-9\-_]{8,}|token|secret|authorization)/i', $value ) ) {
                return '[redacted]';
            }
            if ( self::string_length( $value ) > 300 ) {
                $value = self::string_slice( $value, 0, 300 ) . '…';
            }
            return sanitize_text_field( $value );
        }

        if ( is_scalar( $value ) || null === $value ) {
            return $value;
        }

        if ( is_array( $value ) ) {
            $out = [];
            foreach ( $value as $child_key => $child_value ) {
                $safe_key            = sanitize_key( (string) $child_key );
                $out[ $safe_key ] = self::redact_sensitive_data( $child_value, $safe_key );
            }
            return $out;
        }

        return '[complex]';
    }
}
