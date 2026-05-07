<?php
namespace AIPI;

use RuntimeException;
use AIPI\AipiException;
use AIPI\ImagePreparationService;
use AIPI\OpenAIClient;

/**
 * Responsible for invoking the OpenAI API to generate product listing details
 * from uploaded product photos and seller notes. The product images are sent
 * as multimodal image inputs; only finalized structured listing data is stored.
 */
class Generator {
    private static function string_length( string $value ): int {
        return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $value ) : strlen( $value );
    }

    public const MODEL = 'gpt-4o';

    protected $repo;

    /**
     * JSON Schema definition used for OpenAI structured output.
     *
     * @var array<string,mixed>
     */
    private static $listing_schema = [
        'type'                 => 'object',
        'additionalProperties' => false,
        'required'             => [
            'title',
            'short_description',
            'long_description',
            'bullet_features',
            'condition_notes',
            'seo_meta_description',
            'categories',
            'tags',
            'brand',
            'condition',
            'confidence_notes',
        ],
        'properties'           => [
            'title'                => [ 'type' => 'string' ],
            'short_description'    => [ 'type' => 'string' ],
            'long_description'     => [ 'type' => 'string' ],
            'bullet_features'      => [
                'type'  => 'array',
                'items' => [ 'type' => 'string' ],
            ],
            'condition_notes'      => [ 'type' => 'string' ],
            'seo_meta_description' => [ 'type' => 'string' ],
            'categories'           => [
                'type'  => 'array',
                'items' => [ 'type' => 'string' ],
            ],
            'tags'                 => [
                'type'  => 'array',
                'items' => [ 'type' => 'string' ],
            ],
            'brand'                => [ 'type' => 'string' ],
            'condition'            => [
                'type' => 'string',
                'enum' => [ 'new', 'like_new', 'good', 'fair', 'unknown' ],
            ],
            'confidence_notes'     => [
                'type'  => 'array',
                'items' => [ 'type' => 'string' ],
            ],
        ],
    ];

    public function __construct( JobRepository $repo ) {
        $this->repo = $repo;
    }

    /**
     * Estimate how many images are actually usable for generation. This is used by
     * managed-mode credit checks so billing preflight reflects reachable images
     * rather than merely attached images.
     *
     * @param int    $job_id
     * @param string $description
     * @param bool   $use_images
     * @return array<string,mixed>
     */
    public function estimate_generation_context( int $job_id, string $description, bool $use_images = true ): array {
        $attachments = $this->repo->get_attachments( $job_id );

        // When image analysis is disabled entirely, report no image usage. The
        // description may still be non-empty, but managed credit checks
        // should treat this as text-only. Attachment count is always
        // reported for completeness.
        if ( ! $use_images ) {
            return [
                'requested_use_images'  => false,
                'used_images'           => false,
                'reachable_image_count' => 0,
                'attachment_count'      => count( $attachments ),
            ];
        }

        // No attachments means the request cannot include images even though
        // images were requested. Mark as unused and report zero reachable.
        if ( empty( $attachments ) ) {
            return [
                'requested_use_images'  => true,
                'used_images'           => false,
                'reachable_image_count' => 0,
                'attachment_count'      => 0,
            ];
        }

        // Determine whether we can safely call WordPress image and HTTP APIs. In
        // standalone environments (e.g. unit tests) these functions may not
        // exist. When unavailable, skip expensive preflight and assume all
        // attachments are reachable. Credit checks will treat all images as
        // used, which errs on the side of caution. When functions exist,
        // perform full preparation and reachability checks.
        $can_prepare = function_exists( 'wp_get_attachment_url' ) &&
                       function_exists( 'wp_remote_head' ) &&
                       function_exists( 'wp_remote_get' ) &&
                       class_exists( \AIPI\ImagePreparationService::class );

        $attachment_count = count( $attachments );
        if ( ! $can_prepare ) {
            // When we cannot prepare or check images, return a context that
            // assumes every attachment is usable. Do not attempt to store
            // prepared metadata as it would be incomplete. Only mark
            // used_images as true when there is at least one attachment.
            return [
                'requested_use_images'  => true,
                'used_images'           => $attachment_count > 0,
                'reachable_image_count' => $attachment_count,
                'attachment_count'      => $attachment_count,
            ];
        }

        // Optimise images and perform a lightweight reachability check for
        // each derivative. Build a list of prepared items containing
        // the attachment ID, derivative URL and a reachable flag. If no
        // images are reachable, report zero usage but still reflect that
        // images were requested.
        $preparer = new ImagePreparationService();
        $prepared = $preparer->prepare_images_for_ai( $attachments );
        $reachable_count = 0;
        foreach ( $prepared as $index => $item ) {
            $reachable = false;
            if ( isset( $item['url'] ) && is_string( $item['url'] ) ) {
                // Avoid undefined function errors by verifying functions again.
                if ( function_exists( 'wp_remote_head' ) && function_exists( 'wp_remote_get' ) && function_exists( 'wp_parse_url' ) && function_exists( 'home_url' ) ) {
                    try {
                        $reachable = $this->is_image_url_reachable( (string) $item['url'] );
                    } catch ( \Throwable $e ) {
                        $reachable = false;
                    }
                }
            }
            $prepared[ $index ]['reachable'] = $reachable;
            if ( $reachable ) {
                $reachable_count++;
            }
        }
        // Persist the prepared list so generation can reuse the same image
        // set that preflight counted. Generation should only include URLs that
        // were marked reachable here so billing context, preflight messaging,
        // and the final multimodal payload stay in sync.
        $this->repo->set_meta( $job_id, '_aipi_prepared_images', $prepared );

        return [
            'requested_use_images'  => true,
            'used_images'           => $reachable_count > 0,
            'reachable_image_count' => $reachable_count,
            'attachment_count'      => $attachment_count,
        ];
    }

    /**
     * Generate listing data for a job using the provided description. Optionally include
     * uploaded images in the AI request. Jobs must be in the ready_for_generation state.
     *
     * @param int    $job_id
     * @param string $description
     * @param bool   $use_images Whether to include uploaded images in the AI request.
     * @return array Listing data
     */
    public function generate( int $job_id, string $description, bool $use_images = true, ?string $lock_token = null ): array {
        $status = $this->repo->get_status( $job_id );
        if ( StateMachine::STATUS_READY_FOR_GENERATION !== $status ) {
            // Expose invalid state as a code for controllers.
            throw new AipiException( 'invalid_state', __( 'Job is not ready for generation.', 'photo-to-product' ) );
        }
        $attachments = $this->repo->get_attachments( $job_id );
        // When images are requested, ensure there is at least one attachment. For text‑only
        // requests attachments may be empty.
        if ( $use_images && empty( $attachments ) ) {
            // When images are requested but none exist, treat as missing attachments.
            throw new AipiException( 'missing_attachments', __( 'No attachments available for this job.', 'photo-to-product' ) );
        }

        if ( null === $lock_token ) {
            throw new AipiException( 'in_progress', __( 'Generation lock is missing. Please retry the request.', 'photo-to-product' ) );
        }

        // Persist the description for audit and retries and record whether images were used.
        $this->repo->set_meta( $job_id, '_aipi_job_description', $description );
        try {
            // Transition to generating state only after the lock is acquired. Placing the
            // transition call inside this try ensures any exception from
            // StateMachine::transition() will still trigger lock release in
            // the finally block below.
            StateMachine::transition( $this->repo, $job_id, StateMachine::STATUS_GENERATING );
            // Build the appropriate message payload.
            if ( $lock_token ) {
                $this->repo->refresh_lock( $job_id, 'generate', $lock_token, 900 );
            }
            if ( $use_images ) {
                // Attempt to reuse prepared images from the preflight stage. This keeps
                // managed-mode billing context aligned with the exact image set that will
                // be sent for generation. If no prepared data is available, fall back to
                // dynamic preparation.
                $prepared = $this->repo->get_meta( $job_id, '_aipi_prepared_images', null );
                if ( is_array( $prepared ) && ! empty( $prepared ) ) {
                    $message_context = $this->build_multimodal_messages_from_prepared( $prepared, $description, count( $attachments ) );
                } else {
                    $message_context = $this->build_multimodal_messages( $attachments, $description );
                }
            } else {
                $message_context = [
                    'messages' => $this->build_text_only_messages( $attachments, $description ),
                    'context'  => [
                        'requested_use_images'  => false,
                        'used_images'           => false,
                        'reachable_image_count' => 0,
                        'attachment_count'      => count( $attachments ),
                    ],
                ];
            }
            $messages           = $message_context['messages'];
            $generation_context = $message_context['context'];
            $this->repo->set_meta( $job_id, '_aipi_generation_context', $generation_context );
            $this->repo->set_meta( $job_id, '_aipi_use_images', ! empty( $generation_context['used_images'] ) ? 1 : 0 );
            if ( $lock_token ) {
                $this->repo->refresh_lock( $job_id, 'generate', $lock_token, 900 );
            }
            $result  = $this->call_ai_service( $messages, $job_id, $generation_context );
            $listing = $result['listing'];

            if ( ! is_array( $listing ) ) {
                throw new AipiException( 'ai_response_invalid', __( 'The AI response could not be validated. Please try again.', 'photo-to-product' ) );
            }

            // Validate the listing structure and types before saving.
            $listing = $this->validate_listing( $listing );

            // Backward compatibility for ProductCreator/UI references.
            $listing['description'] = $listing['long_description'];

            if ( $lock_token && ! $this->repo->is_lock_owner( $job_id, 'generate', $lock_token ) ) {
                throw new AipiException( 'in_progress', __( 'Generation lock expired before the listing could be saved. Please try again.', 'photo-to-product' ) );
            }
            if ( StateMachine::STATUS_GENERATING !== $this->repo->get_status( $job_id ) ) {
                throw new AipiException( 'invalid_state', __( 'Job state changed before generation could be saved.', 'photo-to-product' ) );
            }
            $this->repo->set_meta( $job_id, '_aipi_generated_listing', $listing );
            $this->repo->set_meta( $job_id, '_aipi_generation_usage', $result['usage'] ?? [] );
            $this->repo->set_meta( $job_id, '_aipi_error_message', '' );
            StateMachine::transition( $this->repo, $job_id, StateMachine::STATUS_GENERATED );
            return $listing;
        } catch ( \Throwable $e ) {
            // On failure, set status to failed and store the error message.
            try {
                StateMachine::transition( $this->repo, $job_id, StateMachine::STATUS_FAILED );
            } catch ( \Throwable $transition_error ) {
                $this->log_internal_error( 'Failed to mark job ' . $job_id . ' as failed after generation error', $transition_error );
            }
            $this->repo->set_meta( $job_id, '_aipi_error_message', $this->safe_persisted_error_message( $e ) );
            // Re‑throw the original exception; controllers will determine response.
            throw $e;
        }
    }

    /**
     * Build a single text‑only prompt. Images are not included; the number of images
     * is referenced instead to give context. This is used when the user does not
     * want the AI to analyse images.
     *
     * @param int[]  $attachments
     * @param string $description
     * @return array<int,array<string,mixed>>
     */
    private function build_text_only_messages( array $attachments, string $description ): array {
        $count   = count( $attachments );
        $content = [];
        // Keep the instruction body small but make the required JSON structure impossible to miss.
        $intro = "Seller notes:\n" . trim( $description );
        if ( $count > 0 ) {
            $intro .= "\n\nIgnore the number of uploaded photos as a source of factual detail.";
        } else {
            $intro .= "\n\nUse only the seller notes.";
        }
        $intro .= "\n\nRequired output:\n{\n  \"title\": \"string\",\n  \"short_description\": \"string\",\n  \"long_description\": \"string\",\n  \"bullet_features\": [\"string\"],\n  \"condition_notes\": \"string\",\n  \"seo_meta_description\": \"string\",\n  \"categories\": [\"string\"],\n  \"tags\": [\"string\"],\n  \"brand\": \"string\",\n  \"condition\": \"new | like_new | good | fair | unknown\",\n  \"confidence_notes\": [\"string\"]\n}\n\nDo not leave title, short_description, or long_description empty.";
        $content[] = [
            'type' => 'text',
            'text' => $intro,
        ];
        return [
            [
                'role'    => 'system',
                'content' => 'You generate concise WooCommerce product listings from seller notes and, when available, product images. Return valid JSON only. No markdown. No code fences. No extra text. Use exactly this JSON structure: {"title":"string","short_description":"string","long_description":"string","bullet_features":["string"],"condition_notes":"string","seo_meta_description":"string","categories":["string"],"tags":["string"],"brand":"string","condition":"new | like_new | good | fair | unknown","confidence_notes":["string"]}. Rules: title must never be blank. short_description must be exactly 1 short paragraph, max 60 words. long_description must be exactly 1 short paragraph, max 90 words. bullet_features should contain 3 to 6 short strings when possible. seo_meta_description should be 1 short sentence, max 155 characters. brand should be empty if unknown. condition must be one of new, like_new, good, fair, unknown. confidence_notes should only contain real uncertainty. Do not invent unsupported facts. Keep the writing direct, factual, and compact.',
            ],
            [
                'role'    => 'user',
                'content' => $content,
            ],
        ];
    }

    /**
     * Validate the AI listing structure to ensure required keys and correct
     * data types are present. Throws RuntimeException on validation failure.
     *
     * @param array<string,mixed> $listing
     */
    private function validate_listing( array $listing ): array {
        /*
         * Normalise the AI output into a predictable shape. Build a complete listing
         * with sensible defaults, coerce values into the expected types and truncate
         * overly long values. Only extreme type mismatches that cannot be coerced
         * result in an error.
         */
        $defaults = [
            'title'                => __( 'Generated Product Listing', 'photo-to-product' ),
            'short_description'    => '',
            'long_description'     => '',
            'bullet_features'      => [],
            'condition_notes'      => '',
            'seo_meta_description' => '',
            'categories'           => [],
            'tags'                 => [],
            'brand'                => '',
            'condition'            => 'unknown',
            'confidence_notes'     => [],
        ];
        // Merge provided listing over defaults to ensure all keys exist.
        $normalized = array_merge( $defaults, is_array( $listing ) ? $listing : [] );

        // Normalise scalar string fields. Cast non-scalars to empty strings,
        // collapse whitespace and truncate to reasonable maximum lengths.
        $string_limits = [
            'title'                => 200,
            'short_description'    => 1000,
            'long_description'     => 8000,
            'condition_notes'      => 1000,
            'seo_meta_description' => 1000,
            'brand'                => 100,
        ];
        foreach ( [ 'title', 'short_description', 'long_description', 'condition_notes', 'seo_meta_description', 'brand', 'condition' ] as $key ) {
            $value = $normalized[ $key ];
            if ( ! is_scalar( $value ) && ! is_null( $value ) ) {
                // If the value cannot be coerced to a string, fall back to default.
                $value = $defaults[ $key ];
            }
            // Cast to string and normalise whitespace.
            $value = preg_replace( '/\s+/u', ' ', trim( (string) $value ) );
            // Truncate overly long values.
            if ( isset( $string_limits[ $key ] ) && self::string_length( $value ) > $string_limits[ $key ] ) {
                $value = function_exists( 'mb_substr' )
                    ? mb_substr( $value, 0, $string_limits[ $key ] )
                    : substr( $value, 0, $string_limits[ $key ] );
            }
            $normalized[ $key ] = $value;
        }

        // Coerce condition to allowed values. Unknown values default to 'unknown'.
        $allowed_conditions = self::$listing_schema['properties']['condition']['enum'];
        if ( ! in_array( $normalized['condition'], $allowed_conditions, true ) ) {
            $normalized['condition'] = 'unknown';
        }

        // Normalise array fields. Ensure each is an array of strings, trimmed,
        // deduplicated and truncated. Extra items beyond the maximum count are dropped.
        $array_limits = [
            'bullet_features'   => [ 'max_items' => 10, 'max_length' => 200 ],
            'categories'        => [ 'max_items' => 5,  'max_length' => 50 ],
            'tags'              => [ 'max_items' => 20, 'max_length' => 50 ],
            'confidence_notes'  => [ 'max_items' => 10, 'max_length' => 200 ],
        ];
        foreach ( [ 'bullet_features', 'categories', 'tags', 'confidence_notes' ] as $key ) {
            $items = $normalized[ $key ];
            if ( ! is_array( $items ) ) {
                $items = [];
            }
            $cleaned   = [];
            $max_items = $array_limits[ $key ]['max_items'];
            $max_len   = $array_limits[ $key ]['max_length'];
            foreach ( $items as $item ) {
                if ( ! is_scalar( $item ) ) {
                    continue;
                }
                $item = preg_replace( '/\s+/u', ' ', trim( (string) $item ) );
                if ( '' === $item ) {
                    continue;
                }
                if ( self::string_length( $item ) > $max_len ) {
                    $item = function_exists( 'mb_substr' )
                        ? mb_substr( $item, 0, $max_len )
                        : substr( $item, 0, $max_len );
                }
                $cleaned[] = $item;
                if ( count( $cleaned ) >= $max_items ) {
                    break;
                }
            }
            $normalized[ $key ] = $cleaned;
        }

        // Remove unknown top-level keys to avoid persisting unexpected data. Allow
        // the 'description' alias for backward compatibility.
        $allowed_keys = array_keys( self::$listing_schema['properties'] );
        foreach ( array_keys( $normalized ) as $key ) {
            if ( 'description' === $key ) {
                continue;
            }
            if ( ! in_array( $key, $allowed_keys, true ) ) {
                unset( $normalized[ $key ] );
            }
        }

        return $normalized;
    }

    /**
     * Build a single multimodal prompt with seller notes and all available images.
     *
     * @param int[] $attachments
     * @return array{messages:array<int,array<string,mixed>>,context:array<string,mixed>}
     */
    private function build_multimodal_messages( array $attachments, string $description ): array {
        $content = [];
        $content[] = [
            'type' => 'text',
            'text' => "Seller notes:\n" . trim( $description ) . "\n\nUse the seller notes and attached product images to create the JSON listing.\n\nRequired output:\n{\n  \"title\": \"string\",\n  \"short_description\": \"string\",\n  \"long_description\": \"string\",\n  \"bullet_features\": [\"string\"],\n  \"condition_notes\": \"string\",\n  \"seo_meta_description\": \"string\",\n  \"categories\": [\"string\"],\n  \"tags\": [\"string\"],\n  \"brand\": \"string\",\n  \"condition\": \"new | like_new | good | fair | unknown\",\n  \"confidence_notes\": [\"string\"]\n}\n\nDo not leave title, short_description, or long_description empty. Do not guess unsupported details.",
        ];
        // Optimise images before including them in the prompt. This produces
        // derivative files with constrained dimensions and quality. If
        // optimisation fails for an image, the original URL is used.
        $preparer = new ImagePreparationService();
        $prepared = $preparer->prepare_images_for_ai( $attachments );

        $reachable_count = 0;
        foreach ( $prepared as $item ) {
            if ( empty( $item['url'] ) ) {
                continue;
            }

            $url = (string) $item['url'];
            if ( ! $this->is_image_url_reachable( $url ) ) {
                continue;
            }

            $reachable_count++;
            $content[] = [
                'type'      => 'image_url',
                'image_url' => [
                    'url'    => esc_url_raw( $url ),
                    'detail' => 'auto',
                ],
            ];
        }

        if ( 0 === $reachable_count ) {
            if ( '' !== trim( $description ) ) {
                return [
                    'messages' => $this->build_text_only_messages( $attachments, $description ),
                    'context'  => [
                        'requested_use_images'  => true,
                        'used_images'           => false,
                        'reachable_image_count' => 0,
                        'attachment_count'      => count( $attachments ),
                    ],
                ];
            }

            throw new AipiException( 'missing_input', __( 'Please provide a description, at least one photo, or both.', 'photo-to-product' ) );
        }

        return [
            'messages' => [
                [
                    'role'    => 'system',
                    'content' => 'You generate concise WooCommerce product listings from seller notes and, when available, product images. Return valid JSON only. No markdown. No code fences. No extra text. Use exactly this JSON structure: {"title":"string","short_description":"string","long_description":"string","bullet_features":["string"],"condition_notes":"string","seo_meta_description":"string","categories":["string"],"tags":["string"],"brand":"string","condition":"new | like_new | good | fair | unknown","confidence_notes":["string"]}. Rules: title must never be blank. short_description must be exactly 1 short paragraph, max 60 words. long_description must be exactly 1 short paragraph, max 90 words. bullet_features should contain 3 to 6 short strings when possible. seo_meta_description should be 1 short sentence, max 155 characters. brand should be empty if unknown. condition must be one of new, like_new, good, fair, unknown. confidence_notes should only contain real uncertainty. Do not invent unsupported facts. Keep the writing direct, factual, and compact.',
                ],
                [
                    'role'    => 'user',
                    'content' => $content,
                ],
            ],
            'context'  => [
                'requested_use_images'  => true,
                'used_images'           => true,
                'reachable_image_count' => $reachable_count,
                'attachment_count'      => count( $attachments ),
            ],
        ];
    }

    /**
     * Build a multimodal prompt from a precomputed list of prepared images.
     * Each prepared item must contain a usable image URL. When no prepared
     * URLs remain, this falls back to text-only generation if seller notes are
     * provided.
     *
     * @param array<int,array<string,mixed>> $prepared
     * @param string $description
     * @param int    $attachment_count
     * @return array{messages:array<int,array<string,mixed>>,context:array<string,mixed>}
     * @throws AipiException When no usable images or description are available.
     */
    private function build_multimodal_messages_from_prepared( array $prepared, string $description, int $attachment_count ): array {
        $content   = [];
        $content[] = [
            'type' => 'text',
            'text' => "Seller notes:\n" . trim( $description ) . "\n\nUse the seller notes and attached product images to create the JSON listing.\n\nRequired output:\n{\n  \"title\": \"string\",\n  \"short_description\": \"string\",\n  \"long_description\": \"string\",\n  \"bullet_features\": [\"string\"],\n  \"condition_notes\": \"string\",\n  \"seo_meta_description\": \"string\",\n  \"categories\": [\"string\"],\n  \"tags\": [\"string\"],\n  \"brand\": \"string\",\n  \"condition\": \"new | like_new | good | fair | unknown\",\n  \"confidence_notes\": [\"string\"]\n}\n\nDo not leave title, short_description, or long_description empty. Do not guess unsupported details.",
        ];
        // Only include prepared image URLs that preflight marked reachable. This keeps
        // the generated multimodal payload aligned with the stored generation context and
        // avoids reporting one image count while sending another.
        $reachable_count = 0;
        foreach ( $prepared as $item ) {
            if ( empty( $item['url'] ) ) {
                continue;
            }
            if ( array_key_exists( 'reachable', $item ) && empty( $item['reachable'] ) ) {
                continue;
            }
            $reachable_count++;
            $content[] = [
                'type'      => 'image_url',
                'image_url' => [
                    'url'    => esc_url_raw( (string) $item['url'] ),
                    'detail' => 'auto',
                ],
            ];
        }
        // If there are no prepared image URLs, fall back to text-only generation when
        // seller notes are available. When no description is provided either, surface a
        // missing-input error so the user can correct the request.
        if ( 0 === $reachable_count ) {
            if ( '' !== trim( $description ) ) {
                // Build the attachment ID list so text-only messages can still reference how many
                // photos were uploaded. Filter out any non-integer IDs.
                $ids = array_map(
                    static function ( $item ) {
                        return ( is_array( $item ) && isset( $item['id'] ) && is_int( $item['id'] ) ) ? $item['id'] : null;
                    },
                    $prepared
                );
                $ids = array_filter( $ids, 'is_int' );
                return [
                    'messages' => $this->build_text_only_messages( $ids, $description ),
                    'context'  => [
                        'requested_use_images'  => true,
                        'used_images'           => false,
                        'reachable_image_count' => 0,
                        'attachment_count'      => $attachment_count,
                    ],
                ];
            }
                        throw new AipiException( 'missing_input', __( 'Please provide a description, at least one photo, or both.', 'photo-to-product' ) );
        }
        return [
            'messages' => [
                [
                    'role'    => 'system',
                    'content' => 'You generate concise WooCommerce product listings from seller notes and, when available, product images. Return valid JSON only. No markdown. No code fences. No extra text. Use exactly this JSON structure: {"title":"string","short_description":"string","long_description":"string","bullet_features":["string"],"condition_notes":"string","seo_meta_description":"string","categories":["string"],"tags":["string"],"brand":"string","condition":"new | like_new | good | fair | unknown","confidence_notes":["string"]}. Rules: title must never be blank. short_description must be exactly 1 short paragraph, max 60 words. long_description must be exactly 1 short paragraph, max 90 words. bullet_features should contain 3 to 6 short strings when possible. seo_meta_description should be 1 short sentence, max 155 characters. brand should be empty if unknown. condition must be one of new, like_new, good, fair, unknown. confidence_notes should only contain real uncertainty. Do not invent unsupported facts. Keep the writing direct, factual, and compact.',
                ],
                [
                    'role'    => 'user',
                    'content' => $content,
                ],
            ],
            'context'  => [
                'requested_use_images'  => true,
                'used_images'           => true,
                'reachable_image_count' => $reachable_count,
                'attachment_count'      => $attachment_count,
            ],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array{listing:array<string,mixed>,usage:array<string,mixed>}
     */
    private function extract_message_text( array $message ): string {
        if ( isset( $message['content'] ) && is_string( $message['content'] ) ) {
            return trim( $message['content'] );
        }

        if ( isset( $message['content'] ) && is_array( $message['content'] ) ) {
            $parts = [];
            foreach ( $message['content'] as $part ) {
                if ( is_string( $part ) ) {
                    $parts[] = $part;
                    continue;
                }
                if ( ! is_array( $part ) ) {
                    continue;
                }
                if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
                    $parts[] = $part['text'];
                } elseif ( isset( $part['text']['value'] ) && is_string( $part['text']['value'] ) ) {
                    $parts[] = $part['text']['value'];
                } elseif ( isset( $part['type'], $part['text'] ) && 'text' === $part['type'] && is_string( $part['text'] ) ) {
                    $parts[] = $part['text'];
                } elseif ( isset( $part['type'], $part['text']['value'] ) && in_array( $part['type'], [ 'text', 'output_text' ], true ) && is_string( $part['text']['value'] ) ) {
                    $parts[] = $part['text']['value'];
                }
            }
            return trim( implode( "\n", $parts ) );
        }

        return '';
    }

    /**
     * Dispatch generation to the correct AI backend.
     *
     * BYO mode calls OpenAI directly using the merchant's key. Managed mode
     * sends the prepared prompt to the vendor Cloudflare Worker, where the
     * vendor OpenAI key is stored as a secret and never exposed to WordPress.
     *
     *  array<int,array<string,mixed>> $messages
     *  int                           $job_id
     *  array<string,mixed>           $generation_context
     *  array{listing:array<string,mixed>,usage:array<string,mixed>}
     */
    private function call_ai_service( array $messages, int $job_id, array $generation_context ): array {
        if ( Settings::MODE_MANAGED === Settings::get_mode() ) {
            return $this->call_managed_generation_api( $messages, $job_id, $generation_context );
        }

        return $this->call_openai_api( $messages );
    }

    /**
     * Call the managed Cloudflare Worker generation endpoint.
     *
     *  array<int,array<string,mixed>> $messages
     *  int                           $job_id
     *  array<string,mixed>           $generation_context
     *  array{listing:array<string,mixed>,usage:array<string,mixed>}
     */
    private function call_managed_generation_api( array $messages, int $job_id, array $generation_context ): array {
        $attempt_id = (string) $this->repo->get_meta( $job_id, '_aipi_generation_attempt_id', '' );
        if ( '' === $attempt_id ) {
            $attempt_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'aipi_', true );
            $this->repo->set_meta( $job_id, '_aipi_generation_attempt_id', $attempt_id );
        }

        $ledger = new LedgerService();
        $result = $ledger->generateListing( [
            'job_id'             => $job_id,
            'attempt_id'         => $attempt_id,
            'mode'               => Settings::MODE_MANAGED,
            'model'              => apply_filters( 'aipi_openai_model', self::MODEL ),
            'messages'           => $messages,
            'response_format'    => [
                'type' => 'json_object',
            ],
            'max_tokens'         => 1400,
            'temperature'        => 0.4,
            'generation_context' => $generation_context,
        ] );

        if ( empty( $result['success'] ) ) {
            $code    = isset( $result['code'] ) ? (string) $result['code'] : 'ai_request_failed';
            $message = isset( $result['message'] ) ? (string) $result['message'] : __( 'Managed AI generation failed.', 'photo-to-product' );
            if ( 'insufficient_credits' === $code ) {
                throw new AipiException( 'insufficient_credits', $message );
            }
            throw new AipiException( 'ai_request_failed', $message );
        }

        $data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : [];
        $listing = [];
        if ( isset( $data['listing'] ) && is_array( $data['listing'] ) ) {
            $listing = $data['listing'];
        } elseif ( isset( $data['data']['listing'] ) && is_array( $data['data']['listing'] ) ) {
            $listing = $data['data']['listing'];
        }

        if ( empty( $listing ) ) {
            throw new AipiException( 'ai_response_invalid', __( 'Managed AI service returned no listing data.', 'photo-to-product' ) );
        }

        $usage = [];
        if ( isset( $data['usage'] ) && is_array( $data['usage'] ) ) {
            $usage = $data['usage'];
        } elseif ( isset( $data['data']['usage'] ) && is_array( $data['data']['usage'] ) ) {
            $usage = $data['data']['usage'];
        }

        return [
            'listing' => $listing,
            'usage'   => $this->normalize_usage_payload( $usage ),
        ];
    }

    /**
     *  array<string,mixed> $usage
     *  array<string,mixed>
     */
    private function normalize_usage_payload( array $usage ): array {
        $billable_amount = isset( $usage['billable_amount'] ) ? (float) $usage['billable_amount'] : ( isset( $usage['credits'] ) ? (float) $usage['credits'] : ( isset( $usage['total_credits'] ) ? (float) $usage['total_credits'] : 0 ) );

        return [
            'model'              => isset( $usage['model'] ) ? (string) $usage['model'] : apply_filters( 'aipi_openai_model', self::MODEL ),
            'input_tokens'       => isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : ( isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : 0 ),
            'output_tokens'      => isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : ( isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : 0 ),
            'total_tokens'       => isset( $usage['total_tokens'] ) ? (int) $usage['total_tokens'] : 0,
            'estimated_api_cost' => isset( $usage['estimated_api_cost'] ) ? (float) $usage['estimated_api_cost'] : ( isset( $usage['openai_cost'] ) ? (float) $usage['openai_cost'] : 0 ),
            'billable_amount'    => $billable_amount,
            'credits'            => $billable_amount,
            'total_credits'      => $billable_amount,
        ];
    }

    private function call_openai_api( array $messages ): array {
        $api_key = apply_filters( 'aipi_openai_api_key', defined( 'AIPI_OPENAI_KEY' ) ? AIPI_OPENAI_KEY : '' );
        if ( empty( $api_key ) ) {
            // Without an API key the request cannot be sent. Surface a stable code.
            throw new AipiException( 'missing_api_key', __( 'OpenAI API key is not configured.', 'photo-to-product' ) );
        }

        // Allow customization of the model via a filter. The default constant
        // remains as a fallback. Consumers may hook into 'aipi_openai_model'
        // to adjust this value.
        $model = apply_filters( 'aipi_openai_model', self::MODEL );
        /*
         * Build the request body for OpenAI chat completions. Both BYO and managed
         * mode now use the same JSON-object response contract so merchants do not see
         * divergent behavior based only on which account mode is active. The plugin
         * validates the resulting structure on the WordPress side.
         */
        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'response_format' => [
                // Request a JSON object.  The model is instructed via the system prompt to
                // return an object with the expected keys.  The plugin will parse and
                // validate the response rather than depending on the provider enforcing a
                // strict JSON schema.
                'type' => 'json_object',
            ],
            'max_tokens'  => 1400,
            'temperature' => 0.4,
        ];

        // Use the centralised OpenAI client for requests. This enforces a consistent
        // transport policy and normalises errors. Any AipiException thrown here
        // will bubble up to the caller and be handled by the controller.
        try {
            $decoded = OpenAIClient::send_request( $api_key, $body );
        } catch ( AipiException $e ) {
            // Re-throw to preserve the original error code and message.
            throw $e;
        }

        // Validate that the response contains a choices array with at least one item.
        if ( ! isset( $decoded['choices'] ) || ! is_array( $decoded['choices'] ) || empty( $decoded['choices'] ) ) {
            throw new AipiException( 'ai_response_invalid', __( 'AI service returned an unexpected response.', 'photo-to-product' ) );
        }
        $choice = $decoded['choices'][0] ?? null;
        if ( ! is_array( $choice ) || ! isset( $choice['message'] ) || ! is_array( $choice['message'] ) ) {
            throw new AipiException( 'ai_response_invalid', __( 'AI response missing message content.', 'photo-to-product' ) );
        }
        $message      = $choice['message'];
        $listing_json = $this->extract_message_text( $message );
        if ( '' === $listing_json && isset( $message['function_call']['arguments'] ) && is_string( $message['function_call']['arguments'] ) ) {
            $listing_json = $message['function_call']['arguments'];
        }
        $listing_json = trim( (string) $listing_json );
        // If content is empty, treat as refusal/empty output.
        if ( '' === $listing_json ) {
            throw new AipiException( 'ai_response_invalid', __( 'AI response was empty or refused.', 'photo-to-product' ) );
        }
        // Remove markdown code fences if present.
        if ( preg_match( '/^```(?:json)?\s*(.*?)```/s', $listing_json, $m ) ) {
            $listing_json = trim( $m[1] );
        }
        // Attempt JSON decode; fallback to extracting braces if decode fails.
        $listing = json_decode( $listing_json, true );
        if ( ! is_array( $listing ) ) {
            $start = strpos( $listing_json, '{' );
            $end   = strrpos( $listing_json, '}' );
            if ( false !== $start && false !== $end && $end > $start ) {
                $possible_json = substr( $listing_json, $start, $end - $start + 1 );
                $listing       = json_decode( $possible_json, true );
            }
        }
        if ( ! is_array( $listing ) ) {
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                Logger::log( 'error', 'AI response content was not valid JSON.' );
            }
            throw new AipiException( 'ai_response_invalid', __( 'AI service returned invalid JSON.', 'photo-to-product' ) );
        }
        // Usage may not always be present. Default to zero tokens.
        $usage = [
            'model'              => $model,
            'input_tokens'       => isset( $decoded['usage']['prompt_tokens'] ) ? (int) $decoded['usage']['prompt_tokens'] : 0,
            'output_tokens'      => isset( $decoded['usage']['completion_tokens'] ) ? (int) $decoded['usage']['completion_tokens'] : 0,
            'total_tokens'       => isset( $decoded['usage']['total_tokens'] ) ? (int) $decoded['usage']['total_tokens'] : 0,
            'estimated_api_cost' => 0,
            'billable_amount'    => 0,
        ];
        return [
            'listing' => $listing,
            'usage'   => $usage,
        ];
    }

    /**
     * Determine if an image URL is reachable by performing a HEAD request and,
     * if necessary, a minimal GET request. This helper falls back to a
     * GET request with a Range header to fetch the first byte when the
     * HEAD request fails or returns a non‑2xx status. Many hosts or CDNs
     * reject HEAD requests, so relying solely on HEAD can lead to false
     * negatives. A reachable URL must respond with a 2xx status to either
     * request. Any other response indicates the image may not be publicly
     * accessible and could cause the AI service to error when retrieving
     * the file.
     *
     * @param string $url
     * @return bool
     */
    private function is_image_url_reachable( string $url ): bool {
        // Restrict remote requests to an explicit allowlist. By default only
        // the current site's host is allowed. Additional hosts may be added
        // via the 'aipi_allowed_image_hosts' filter. If a URL's host is not
        // allowed, treat it as unreachable.
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return false;
        }
        $host      = strtolower( $parts['host'] );
        $site_host = strtolower( wp_parse_url( home_url(), PHP_URL_HOST ) ?: '' );
        // Determine upload host for sites where media is served from a different domain.
        $upload_host = '';
        $uploads     = wp_get_upload_dir();
        if ( ! empty( $uploads['baseurl'] ) && is_string( $uploads['baseurl'] ) ) {
            $upload_host = strtolower( wp_parse_url( $uploads['baseurl'], PHP_URL_HOST ) ?: '' );
        }
        $default_allowed_hosts = array_filter( array_unique( [ $site_host, $upload_host ] ) );
        $allowed_hosts = apply_filters( 'aipi_allowed_image_hosts', $default_allowed_hosts );
        $allowed_hosts = array_map(
            static function ( $allowed_host ) {
                return strtolower( (string) $allowed_host );
            },
            (array) $allowed_hosts
        );
        if ( ! in_array( $host, $allowed_hosts, true ) ) {
            return false;
        }
        // First attempt a HEAD request.
        $head = wp_remote_head( $url, [ 'timeout' => 10 ] );
        if ( ! is_wp_error( $head ) ) {
            $code = (int) wp_remote_retrieve_response_code( $head );
            if ( $code >= 200 && $code < 400 ) {
                return true;
            }
        }
        // Fall back to GET with a Range header to retrieve only the first byte.
        $get = wp_remote_get( $url, [
            'timeout'            => 10,
            // Attempt to fetch only the first byte via Range. Some hosts ignore Range
            // requests, so we also limit the total response size to avoid
            // downloading large files during reachability checks.
            'headers'            => [ 'Range' => 'bytes=0-0' ],
            'limit_response_size' => 1024,
        ] );
        if ( is_wp_error( $get ) ) {
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code( $get );
        return $code >= 200 && $code < 400;
    }

    /**
     * Convert an exception into a safe persisted message for later UI display.
     *
     * @param \Throwable $e
     * @return string
     */
    private function safe_persisted_error_message( \Throwable $e ): string {
        if ( $e instanceof AipiException ) {
            return $e->getMessage();
        }

        return __( 'Listing generation failed. Please try again later.', 'photo-to-product' );
    }

    /**
     * Log internal errors without exposing raw details to the UI.
     *
     * @param string $context
     * @param \Throwable $e
     */
    private function log_internal_error( string $context, \Throwable $e ): void {
        Logger::exception( 'error', $context, $e );
    }

}
