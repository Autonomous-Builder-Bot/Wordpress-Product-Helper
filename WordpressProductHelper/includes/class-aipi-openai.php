<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AIPI_OpenAI {

    public function generate_draft( array $payload ) {
        $api_key = AIPI_Settings::get_api_key();
        $model   = AIPI_Settings::get_model();

        if ( empty( $api_key ) ) {
            return new WP_Error( 'aipi_missing_api_key', __( 'OpenAI API key is not configured.', 'ai-product-intake' ) );
        }

        $prompt_builder = new AIPI_Prompt_Builder();
        $request_data   = $prompt_builder->build_request( $payload );

        $body = array(
            'model' => $model,
            'messages' => array(
                array(
                    'role' => 'system',
                    'content' => $request_data['system_prompt'],
                ),
                array(
                    'role' => 'user',
                    'content' => $request_data['user_prompt'],
                ),
            ),
            'response_format' => array(
                'type' => 'json_schema',
                'json_schema' => $request_data['schema'],
            ),
        );

        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( $body ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'aipi_api_error', __( 'OpenAI API request failed.', 'ai-product-intake' ) );
        }

        $data = json_decode( $raw, true );

        if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'aipi_invalid_response', __( 'Invalid response from OpenAI.', 'ai-product-intake' ) );
        }

        $content = $data['choices'][0]['message']['content'];

        if ( is_string( $content ) ) {
            $decoded = json_decode( $content, true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }

        if ( is_array( $content ) ) {
            return $content;
        }

        return new WP_Error( 'aipi_parse_error', __( 'Could not parse AI response.', 'ai-product-intake' ) );
    }
}
