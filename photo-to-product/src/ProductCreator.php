<?php
namespace AIPI;

use RuntimeException;
use AIPI\AipiException;

/**
 * Creates WooCommerce products from generated listings. It assigns
 * categories, tags, brand and condition, and attaches uploaded images as
 * featured and gallery media. Products are created as drafts to allow
 * manual review before publishing.
 */
class ProductCreator {
    private $repo;

    public function __construct( JobRepository $repo ) {
        $this->repo = $repo;
    }

    /**
     * Create a WooCommerce product from the generated listing on the job. The
     * job must be in generated state. On success transitions the job to
     * completed and stores the product ID. On failure stores the error and
     * transitions the job back to generated so product creation can be retried
     * without regenerating the listing.
     *
     * @param int $job_id
     * @return int Product ID
     */
    public function create( int $job_id, ?string $lock_token = null ): int {
        $status = $this->repo->get_status( $job_id );
        if ( StateMachine::STATUS_GENERATED !== $status ) {
            // Job must be in generated state. Surface invalid_state code.
            throw new AipiException( 'invalid_state', __( 'Job is not ready for product creation.', 'photo-to-product' ) );
        }
        $listing = $this->repo->get_meta( $job_id, '_aipi_generated_listing', [] );
        if ( empty( $listing ) || ! is_array( $listing ) ) {
            // There is no listing data to create a product from.
            throw new AipiException( 'missing_listing', __( 'Missing generated listing.', 'photo-to-product' ) );
        }
        $this->validate_listing_for_product_creation( $listing );

        $attachments = $this->repo->get_attachments( $job_id );
        // Do not require attachments for product creation. If no images are present
        // (e.g. text‑only generation) the product will be created without a
        // featured image or gallery.

        if ( null === $lock_token ) {
            throw new AipiException( 'in_progress', __( 'Product creation lock is missing. Please retry the request.', 'photo-to-product' ) );
        }

        try {
            // Transition to creating_product state only after acquiring the lock. Placing
            // the transition inside this try ensures any exception will still
            // trigger lock release in the finally block below.
            StateMachine::transition( $this->repo, $job_id, StateMachine::STATUS_CREATING_PRODUCT );

            // Build category and tag assignments. Normalise and deduplicate to limit taxonomy pollution.
            $categories_to_assign  = [];
            $tags_to_assign        = [];
            // Normalise categories and tags from the listing. Limit counts to avoid
            // taxonomy pollution. Categories that do not already exist are not
            // automatically converted into tags; instead they are skipped and a
            // taxonomy warning is recorded. Tags are taken directly from the
            // listing and normalised.
            $normalized_categories = [];
            if ( isset( $listing['categories'] ) && is_array( $listing['categories'] ) ) {
                $normalized_categories = $this->normalize_terms( $listing['categories'] );
                $normalized_categories = array_slice( $normalized_categories, 0, 3 );
            }
            $normalized_tags = [];
            if ( isset( $listing['tags'] ) && is_array( $listing['tags'] ) ) {
                $normalized_tags = $this->normalize_terms( $listing['tags'] );
                $normalized_tags = array_slice( $normalized_tags, 0, 12 );
            }
            $unknown_categories = [];
            $unknown_tags       = [];
            // Determine taxonomy assignment modes from saved settings first,
            // then allow site-specific filters to override if needed.
            $cat_mode = Settings::sanitize_taxonomy_assignment_mode(
                (string) apply_filters(
                    'aipi_category_assignment_mode',
                    (string) Settings::get( 'category_assignment_mode', 'existing_only' )
                )
            );
            $tag_mode = Settings::sanitize_taxonomy_assignment_mode(
                (string) apply_filters(
                    'aipi_tag_assignment_mode',
                    (string) Settings::get( 'tag_assignment_mode', 'existing_only' )
                )
            );
            if ( 'disabled' !== $cat_mode && ! empty( $normalized_categories ) ) {
                foreach ( $normalized_categories as $cat_name ) {
                    $term = term_exists( $cat_name, 'product_cat' );
                    if ( $term && ! is_wp_error( $term ) ) {
                        // Existing term, always assign.
                        $categories_to_assign[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
                    } else {
                        if ( 'create_if_missing' === $cat_mode ) {
                            $created = wp_insert_term( $cat_name, 'product_cat' );
                            if ( ! is_wp_error( $created ) ) {
                                $categories_to_assign[] = (int) ( is_array( $created ) ? $created['term_id'] : $created );
                            } else {
                                $unknown_categories[] = $cat_name;
                            }
                        } else {
                            // Record unknown category for warning and skip assignment.
                            $unknown_categories[] = $cat_name;
                        }
                    }
                }
            }
            // Build tags assignment based on mode. Keep normalized tag names here;
            // resolve to IDs later in one pass. Unknown categories are not
            // converted into tags. For existing terms term_exists() returns
            // either an array with term_id or an integer; we do not rely on
            // the returned array keys because names are not included.
            if ( 'disabled' !== $tag_mode && ! empty( $normalized_tags ) ) {
                foreach ( $normalized_tags as $tag_name ) {
                    $term = term_exists( $tag_name, 'product_tag' );
                    if ( $term && ! is_wp_error( $term ) ) {
                        // Existing tag: use the provided name to maintain
                        // consistent casing. Do not rely on term_exists() array keys.
                        $tags_to_assign[] = $tag_name;
                    } else {
                        if ( 'create_if_missing' === $tag_mode ) {
                            $created = wp_insert_term( $tag_name, 'product_tag' );
                            if ( ! is_wp_error( $created ) ) {
                                // On success record the name; the term ID will be
                                // resolved later when assigning IDs.
                                $tags_to_assign[] = $tag_name;
                            } else {
                                $unknown_tags[] = $tag_name;
                            }
                        } else {
                            $unknown_tags[] = $tag_name;
                        }
                    }
                }
                // Deduplicate tag names preserving order.
                $tags_to_assign = $this->normalize_terms( $tags_to_assign );
            }
            // Create a new WooCommerce simple product via CRUD API. This ensures hooks
            // and default metadata are applied consistently. We set basic fields
            // before assigning categories, tags, images and custom meta. If
            // anything fails, the exception handler below will delete the product.
            $product = new \WC_Product_Simple();
            $product->set_name( wp_strip_all_tags( $listing['title'] ) );
            $product->set_description( wp_kses_post( $listing['long_description'] ?? $listing['description'] ?? '' ) );
            $product->set_short_description( wp_kses_post( $listing['short_description'] ?? '' ) );
            $product->set_status( 'draft' );

            // Assign categories and tags. Only assign if non-empty to avoid overwriting.
            if ( ! empty( $categories_to_assign ) ) {
                $product->set_category_ids( $categories_to_assign );
            }
            // Prepare tag IDs. Only create missing tags when settings explicitly allow it.
            $tag_ids = [];
            if ( ! empty( $tags_to_assign ) ) {
                foreach ( $tags_to_assign as $tag_name ) {
                    $term = term_exists( $tag_name, 'product_tag' );
                    if ( ( 0 === $term || null === $term ) && 'create_if_missing' === $tag_mode ) {
                        $term = wp_insert_term( $tag_name, 'product_tag' );
                    }
                    if ( ! is_wp_error( $term ) && 0 !== $term && null !== $term ) {
                        $tag_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
                    } elseif ( 'create_if_missing' !== $tag_mode ) {
                        $unknown_tags[] = $tag_name;
                    }
                }
                if ( ! empty( $tag_ids ) ) {
                    $product->set_tag_ids( $tag_ids );
                }
            }

            // Record taxonomy warnings for any categories or tags that could not be assigned.
            if ( ! empty( $unknown_categories ) || ! empty( $unknown_tags ) ) {
                $messages = [];
                if ( ! empty( $unknown_categories ) ) {
                    $messages[] = sprintf( __( 'Some categories could not be assigned: %s', 'photo-to-product' ), implode( ', ', array_values( array_unique( $unknown_categories ) ) ) );
                }
                if ( ! empty( $unknown_tags ) ) {
                    $messages[] = sprintf( __( 'Some tags could not be assigned: %s', 'photo-to-product' ), implode( ', ', array_values( array_unique( $unknown_tags ) ) ) );
                }
                $this->repo->set_meta( $job_id, '_aipi_taxonomy_warning', implode( ' ', $messages ) );
            } else {
                $this->repo->set_meta( $job_id, '_aipi_taxonomy_warning', '' );
            }

            // Set featured image and gallery. The first attachment is featured.
            $featured_id = array_shift( $attachments );
            if ( $featured_id ) {
                $product->set_image_id( $featured_id );
            }
            if ( ! empty( $attachments ) ) {
                $product->set_gallery_image_ids( $attachments );
            }

            // Store brand and condition as custom meta fields. Use CRUD meta API.
            if ( isset( $listing['brand'] ) && is_string( $listing['brand'] ) ) {
                $product->update_meta_data( '_aipi_brand', sanitize_text_field( $listing['brand'] ) );
            }
            if ( isset( $listing['condition'] ) && is_string( $listing['condition'] ) ) {
                $product->update_meta_data( '_aipi_condition', sanitize_text_field( $listing['condition'] ) );
            }
            if ( isset( $listing['bullet_features'] ) && is_array( $listing['bullet_features'] ) ) {
                $product->update_meta_data( '_aipi_bullet_features', array_map( 'sanitize_text_field', $listing['bullet_features'] ) );
            }
            if ( isset( $listing['condition_notes'] ) && is_string( $listing['condition_notes'] ) ) {
                $product->update_meta_data( '_aipi_condition_notes', sanitize_textarea_field( $listing['condition_notes'] ) );
            }
            if ( isset( $listing['seo_meta_description'] ) && is_string( $listing['seo_meta_description'] ) ) {
                $product->update_meta_data( '_aipi_seo_meta_description', sanitize_text_field( $listing['seo_meta_description'] ) );
            }
            if ( isset( $listing['confidence_notes'] ) && is_array( $listing['confidence_notes'] ) ) {
                $product->update_meta_data( '_aipi_confidence_notes', array_map( 'sanitize_text_field', $listing['confidence_notes'] ) );
            }
            // Leave price blank for admin to set later.
            $product->set_regular_price( '' );
            $product->set_price( '' );

            // Persist the product to the database only if this request still owns the lock
            // and the job is still in the expected state.
            if ( $lock_token ) {
                $this->repo->refresh_lock( $job_id, 'create_product', $lock_token, 900 );
                if ( ! $this->repo->is_lock_owner( $job_id, 'create_product', $lock_token ) ) {
                    throw new AipiException( 'in_progress', __( 'Product creation lock expired before the product could be saved. Please try again.', 'photo-to-product' ) );
                }
            }
            if ( StateMachine::STATUS_CREATING_PRODUCT !== $this->repo->get_status( $job_id ) ) {
                throw new AipiException( 'invalid_state', __( 'Job state changed before product creation could be saved.', 'photo-to-product' ) );
            }
            $product_id = $product->save();
            if ( ! $product_id || is_wp_error( $product_id ) ) {
                throw new AipiException( 'product_creation_failed', __( 'Failed to save product.', 'photo-to-product' ) );
            }

            // Record the created product and update job status. Clear any prior errors.
            $this->repo->set_meta( $job_id, '_aipi_created_product', (int) $product_id );
            $this->repo->set_meta( $job_id, '_aipi_error_message', '' );
            StateMachine::transition( $this->repo, $job_id, StateMachine::STATUS_COMPLETED );
            return (int) $product_id;
        } catch ( \Throwable $e ) {
            // If a product was created prior to the failure, delete it to avoid orphaned drafts.
            if ( isset( $product_id ) && $product_id && ! is_wp_error( $product_id ) ) {
                wp_delete_post( (int) $product_id, true );
            }
            // Persist the error on the job. We remain in generated state to allow retrying product creation
            // without needing to call the AI again.
            $this->repo->set_meta( $job_id, '_aipi_error_message', $this->safe_persisted_error_message( $e ) );
            try {
                StateMachine::transition( $this->repo, $job_id, StateMachine::STATUS_GENERATED );
            } catch ( \Throwable $transition_error ) {
                $this->log_internal_error( 'Failed to restore job ' . $job_id . ' to generated after product creation error', $transition_error );
            }
            throw $e;
        }
    }

    /**
     * Ensure the generated listing contains enough meaningful content to justify
     * creating a WooCommerce draft product.
     *
     * @param array $listing Generated listing payload.
     * @throws AipiException When essential fields are empty or placeholders.
     */
    private function validate_listing_for_product_creation( array $listing ): void {
        $normalize = static function ( $value ): string {
            $text = is_scalar( $value ) || null === $value ? (string) $value : '';
            $text = wp_strip_all_tags( $text );
            $text = preg_replace( '/\s+/u', ' ', $text );
            return trim( (string) $text );
        };

        $title = $normalize( $listing['title'] ?? '' );
        $short = $normalize( $listing['short_description'] ?? '' );
        $long  = $normalize( $listing['long_description'] ?? ( $listing['description'] ?? '' ) );

        if ( '' === $title || __( 'Generated Product Listing', 'photo-to-product' ) === $title ) {
            throw new AipiException( 'missing_title', __( 'The generated listing is missing a usable title. Please regenerate the listing.', 'photo-to-product' ) );
        }

        if ( '' === $short ) {
            throw new AipiException( 'missing_short_description', __( 'The generated listing is missing a short description. Please regenerate the listing.', 'photo-to-product' ) );
        }

        if ( '' === $long ) {
            throw new AipiException( 'missing_long_description', __( 'The generated listing is missing a full description. Please regenerate the listing.', 'photo-to-product' ) );
        }
    }


    /**
     * Normalise and deduplicate taxonomy terms. Removes empty strings, trims whitespace,
     * sanitises for safe usage, and returns unique values preserving order.
     *
     * @param array<int,string> $terms
     * @return array<int,string>
     */
    private function normalize_terms( array $terms ): array {
        $seen = [];
        $out  = [];
        foreach ( $terms as $term ) {
            if ( ! is_string( $term ) ) {
                continue;
            }
            $clean = sanitize_text_field( trim( $term ) );
            if ( '' === $clean ) {
                continue;
            }
            // Avoid duplicates (case‑insensitive).
            $lc = strtolower( $clean );
            if ( isset( $seen[ $lc ] ) ) {
                continue;
            }
            $seen[ $lc ] = true;
            $out[]       = $clean;
        }
        return $out;
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

        return __( 'Product creation failed. Please try again later.', 'photo-to-product' );
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
