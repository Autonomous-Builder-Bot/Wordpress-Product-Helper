<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Build the system prompt, user prompt, and JSON schema contract.
 */
class AIPI_Prompt_Builder {
    public function build_request( array $intake ) {
        $description = AIPI_Utils::sanitize_textarea( $intake['description'] ?? '', 5000 );

        return array(
            'system_prompt' => $this->get_system_prompt(),
            'user_prompt'   => $this->get_user_prompt( $description ),
            'schema'        => $this->get_schema(),
        );
    }

    private function get_system_prompt() {
        return 'You are an expert WooCommerce product copy assistant. Convert rough product notes into a clean draft for a human editor. Return only JSON that matches the provided schema. Do not invent pricing or technical facts. Focus on clear product copy, useful short description text, sensible SEO-friendly tags, one optional category suggestion, and any missing information that would help a human finish the draft.';
    }

    private function get_user_prompt( $description ) {
        return "Create a WooCommerce-ready product draft from these rough notes:\n\n" . $description;
    }

    private function get_schema() {
        return array(
            'name'   => 'product_draft',
            'schema' => array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => array(
                    'title'               => array( 'type' => 'string' ),
                    'description'         => array( 'type' => 'string' ),
                    'short_description'   => array( 'type' => 'string' ),
                    'tags'                => array(
                        'type'  => 'array',
                        'items' => array( 'type' => 'string' ),
                    ),
                    'category_suggestion' => array( 'type' => 'string' ),
                    'missing_fields'      => array(
                        'type'  => 'array',
                        'items' => array( 'type' => 'string' ),
                    ),
                ),
                'required'             => array( 'title', 'description', 'short_description', 'tags', 'category_suggestion', 'missing_fields' ),
            ),
        );
    }
}
