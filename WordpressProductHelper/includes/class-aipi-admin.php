<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AIPI_Admin {
    const MENU_SLUG = 'aipi-intake';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_post_aipi_generate_draft', array( $this, 'handle_generate_draft' ) );
        add_action( 'admin_post_aipi_create_product', array( $this, 'handle_create_product' ) );
    }

    public function register_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'AI Product Intake', 'ai-product-intake' ),
            __( 'AI Product Intake', 'ai-product-intake' ),
            AIPI_Capabilities::CAPABILITY,
            self::MENU_SLUG,
            array( $this, 'render_page' )
        );
    }

    public function render_page() {
        $this->assert_permissions();

        $draft_token = isset( $_GET['draft_token'] ) ? AIPI_Utils::sanitize_text( wp_unslash( $_GET['draft_token'] ), 64 ) : '';
        $errors      = array();
        $draft       = null;
        $categories  = ( new AIPI_Taxonomy() )->get_category_options();

        if ( $draft_token ) {
            $store = new AIPI_Draft_Store();
            $draft = $store->get_draft( $draft_token );
            if ( is_wp_error( $draft ) ) {
                $errors[] = $draft->get_error_message();
                $draft    = null;
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'AI Product Intake', 'ai-product-intake' ) . '</h1>';

        if ( ! empty( $errors ) ) {
            $template_errors = $errors;
            include AIPI_PLUGIN_DIR . 'templates/partial-errors.php';
        }

        if ( $draft && ! empty( $draft['payload'] ) ) {
            $draft_payload = $draft['payload'];
            include AIPI_PLUGIN_DIR . 'templates/review-page.php';
        } else {
            include AIPI_PLUGIN_DIR . 'templates/intake-page.php';
        }

        echo '</div>';
    }

    public function handle_generate_draft() {
        $this->assert_permissions();
        check_admin_referer( 'aipi_generate_draft' );

        $description = isset( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : '';
        $price       = isset( $_POST['price'] ) ? wp_unslash( $_POST['price'] ) : '';
        $sku         = isset( $_POST['sku'] ) ? wp_unslash( $_POST['sku'] ) : '';

        $media = new AIPI_Media();
        $image_ids = $media->handle_uploads( $_FILES['images'] ?? array() );

        if ( is_wp_error( $image_ids ) ) {
            wp_die( esc_html( $image_ids->get_error_message() ) );
        }

        $validator = new AIPI_Validator();
        $validated_intake = $validator->validate_intake(
            array(
                'description' => $description,
                'image_ids'   => $image_ids,
            )
        );

        if ( is_wp_error( $validated_intake ) ) {
            wp_die( esc_html( $validated_intake->get_error_message() ) );
        }

        $openai = new AIPI_OpenAI();
        $ai_raw = $openai->generate_draft( $validated_intake );

        if ( is_wp_error( $ai_raw ) ) {
            wp_die( esc_html( $ai_raw->get_error_message() ) );
        }

        $normalizer = new AIPI_AI_Normalizer();
        $draft_data = $normalizer->normalize( $ai_raw );

        $draft_data['price']        = AIPI_Utils::sanitize_text( $price, 32 );
        $draft_data['sku']          = AIPI_Utils::sanitize_text( $sku, 100 );
        $draft_data['stock_status'] = 'instock';
        $draft_data['category_id']  = 0;
        $draft_data['image_ids']    = $image_ids;

        $store = new AIPI_Draft_Store();
        $token = $store->create_draft( $draft_data );

        if ( is_wp_error( $token ) ) {
            wp_die( esc_html( $token->get_error_message() ) );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&draft_token=' . rawurlencode( $token ) ) );
        exit;
    }

    public function handle_create_product() {
        $this->assert_permissions();
        check_admin_referer( 'aipi_create_product' );

        $draft_token = isset( $_POST['draft_token'] ) ? AIPI_Utils::sanitize_text( wp_unslash( $_POST['draft_token'] ), 64 ) : '';

        $store = new AIPI_Draft_Store();
        $draft = $store->get_draft( $draft_token );

        if ( is_wp_error( $draft ) ) {
            wp_die( esc_html( $draft->get_error_message() ) );
        }

        $image_ids = isset( $_POST['image_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['image_ids'] ) ) : array();
        $tags_raw  = isset( $_POST['tags'] ) ? explode( ',', wp_unslash( $_POST['tags'] ) ) : array();

        $validator = new AIPI_Validator();
        $validated = $validator->validate_review(
            array(
                'title'             => wp_unslash( $_POST['title'] ?? '' ),
                'description'       => wp_unslash( $_POST['description'] ?? '' ),
                'short_description' => wp_unslash( $_POST['short_description'] ?? '' ),
                'tags'              => $tags_raw,
                'price'             => wp_unslash( $_POST['price'] ?? '' ),
                'sku'               => wp_unslash( $_POST['sku'] ?? '' ),
                'stock_status'      => wp_unslash( $_POST['stock_status'] ?? 'instock' ),
                'category_id'       => wp_unslash( $_POST['category_id'] ?? 0 ),
                'image_ids'         => $image_ids,
            )
        );

        if ( is_wp_error( $validated ) ) {
            wp_die( esc_html( $validated->get_error_message() ) );
        }

        $factory    = new AIPI_Product_Factory();
        $product_id = $factory->create_product( $validated );

        if ( is_wp_error( $product_id ) ) {
            wp_die( esc_html( $product_id->get_error_message() ) );
        }

        $store->delete_draft( $draft_token );

        wp_safe_redirect( admin_url( 'post.php?post=' . absint( $product_id ) . '&action=edit' ) );
        exit;
    }

    private function assert_permissions() {
        if ( ! AIPI_Capabilities::current_user_can_manage() ) {
            wp_die( esc_html__( 'You do not have permission to access AI Product Intake.', 'ai-product-intake' ) );
        }
    }
}
