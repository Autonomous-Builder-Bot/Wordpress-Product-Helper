<?php
namespace AIPI;

use AIPI\AipiException;
use AIPI\OpenAIClient;

/**
 * Bootstraps Photo to Product and wires WordPress hooks to the plugin services.
 */
class Plugin {
    /**
     * Absolute path to the main plugin file. Used for resolving asset URLs.
     *
     * @var string
     */
    private $plugin_file;

    /** @var JobRepository */
    private $repo;
    /** @var Uploader */
    private $uploader;
    /** @var Generator */
    private $generator;
    /** @var ProductCreator */
    private $product_creator;
    /** @var Cleanup */
    private $cleanup;
    /** @var Settings */
    private $settings;
    /** @var LedgerService */
    private $ledger;
    /** @var GenerationWorkflow */
    private $workflow;
    /** @var ProductCreationWorkflow */
    private $product_creation_workflow;
    /** @var AdminNotices */
    private $admin_notices;

    public function __construct( string $plugin_file ) {
        $this->plugin_file = $plugin_file;
    }

    /**
     * Initialise the plugin after WooCommerce and other plugins have loaded.
     */
    public function init(): void {
        load_plugin_textdomain( 'photo-to-product', false, dirname( plugin_basename( $this->plugin_file ) ) . '/languages' );

        // Register privacy policy content when the helper is available.
        if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
            wp_add_privacy_policy_content(
                __( 'Photo to Product', 'photo-to-product' ),
                wp_kses_post(
                    sprintf(
                        /* translators: 1: plugin name */
                        __(
                            'When you generate listings, %1$s sends your product notes and, if enabled, optimised copies of your uploaded photos to the OpenAI API. In Managed Mode, it sends your site URL and WordPress admin URL to the managed service to register your installation and issues an installation ID and token. Subsequent managed-service requests send only the installation ID and token together with the request context. When you purchase credits, payment is processed via the vendor\'s backend using PayPal. No payment data or API keys are stored in the browser.',
                            'photo-to-product'
                        ),
                        'Photo to Product'
                    )
                )
            );
        }

        $this->repo           = new JobRepository();
        $this->uploader       = new Uploader( $this->repo );
        $this->generator      = new Generator( $this->repo );
        $this->product_creator = new ProductCreator( $this->repo );
        $this->cleanup        = new Cleanup( $this->repo );
        $this->settings       = new Settings();
        $this->ledger         = new LedgerService();
        $this->workflow       = new GenerationWorkflow( $this->repo, $this->generator, $this->ledger );
        $this->product_creation_workflow = new ProductCreationWorkflow( $this->repo, $this->product_creator );
        $this->admin_notices = new AdminNotices( function ( array $args = [] ): string {
            return $this->get_main_page_url( $args );
        } );
        $this->settings->init();

        add_filter( 'aipi_openai_api_key', [ $this, 'filter_openai_api_key' ] );

        add_filter( 'aipi_openai_model', [ $this, 'filter_openai_model' ] );

        add_action( 'init', [ $this, 'register_post_type' ] );
        add_action( 'admin_menu', [ $this, 'register_menu_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_aipi_create_job', [ $this, 'handle_create_job' ] );
        add_action( 'wp_ajax_aipi_upload_photos', [ $this, 'handle_upload_photos' ] );
        add_action( 'wp_ajax_aipi_ready_for_generation', [ $this, 'handle_ready_for_generation' ] );
        add_action( 'wp_ajax_aipi_generate_listing', [ $this, 'handle_generate_listing' ] );
        add_action( 'wp_ajax_aipi_create_product', [ $this, 'handle_create_product' ] );
        add_action( 'wp_ajax_aipi_delete_job', [ $this, 'handle_delete_job' ] );
        add_action( 'wp_ajax_aipi_list_jobs', [ $this, 'handle_list_jobs' ] );
        add_action( 'wp_ajax_aipi_register_installation', [ $this, 'handle_register_installation' ] );
        add_action( 'wp_ajax_aipi_register_customer',    [ $this, 'handle_register_installation' ] );
        add_action( 'wp_ajax_aipi_reset_installation', [ $this, 'handle_reset_installation' ] );
        add_action( 'admin_post_aipi_register_installation', [ $this, 'handle_register_installation_post' ] );
        add_action( 'admin_post_aipi_disconnect_installation', [ $this, 'handle_disconnect_installation_post' ] );
        add_action( 'admin_post_aipi_reregister_installation', [ $this, 'handle_reregister_installation_post' ] );
        add_action( 'admin_notices', [ $this->admin_notices, 'render_settings_page_notices' ] );
        add_action( 'wp_ajax_aipi_get_balance', [ $this, 'handle_get_balance' ] );
        add_action( 'wp_ajax_aipi_create_paypal_order', [ $this, 'handle_create_paypal_order' ] );
        add_action( 'delete_attachment', [ ImagePreparationService::class, 'cleanup_derivative_for_attachment' ] );

        add_action( 'admin_notices', [ $this->admin_notices, 'maybe_show_setup_notice' ] );
        add_action( 'admin_init', [ $this->admin_notices, 'maybe_handle_setup_notice_dismissal' ] );

        add_action( 'wp_ajax_aipi_test_byo_key', [ $this, 'handle_test_byo_key' ] );
    }

    /**
     * Register the aipi_job custom post type. Jobs are not publicly
     * queryable; they exist solely for internal management.
     */
    public function register_post_type(): void {
        aipi_pro_register_job_post_type();
    }

    /**
     * Register the admin menu page for the plugin under WooCommerce. Only
     * users with Permission::BASE_CAPABILITY can access this page.
     */
    public function register_menu_page(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Photo to Product', 'photo-to-product' ),
            __( 'Photo to Product', 'photo-to-product' ),
            Permission::BASE_CAPABILITY,
            'photo-to-product',
            [ $this, 'render_admin_page' ]
        );
    }

    /**
     * Enqueue admin CSS/JS on our plugin page and localise variables.
     *
     * @param string $hook
     */
    public function enqueue_assets( string $hook ): void {
        $url = plugin_dir_url( $this->plugin_file );
        $job_hook      = 'woocommerce_page_photo-to-product';
        $settings_hook = 'woocommerce_page_photo-to-product-settings';
        $current_page  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $is_plugin_page = ( $hook === $job_hook ) || ( $hook === $settings_hook ) || ( 'photo-to-product' === $current_page ) || ( 'photo-to-product-settings' === $current_page );
        if ( ! $is_plugin_page ) {
            return;
        }

        $active_tab      = $this->get_active_tab();
        $settings        = Settings::get_all();
        $installation_id = isset( $settings['installation_id'] ) ? trim( (string) $settings['installation_id'] ) : '';

        wp_enqueue_style( 'aipi-pro-admin', $url . 'admin/css/admin.css', [], AIPI_PRO_VERSION );

        if ( 'create-product' === $active_tab ) {
            wp_enqueue_script( 'aipi-pro-admin', $url . 'admin/js/admin.js', [ 'jquery' ], AIPI_PRO_VERSION, true );
            wp_enqueue_script( 'aipi-pro-bulk', $url . 'admin/js/bulk.js', [ 'jquery', 'aipi-pro-admin' ], AIPI_PRO_VERSION, true );
            wp_localize_script( 'aipi-pro-admin', 'AIPI_PRO', [
                'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
                'nonce'            => wp_create_nonce( 'aipi_pro_nonce' ),
                'settingsUrl'      => $this->get_main_page_url( [ 'tab' => 'connection' ] ),
                'accountMode'      => Settings::get_mode(),
                'connectionStatus' => (string) $settings['connection_status'],
                'customerId'       => (string) $settings['customer_id'],
                'byoKeyConfigured' => '' !== Settings::get_byo_openai_key(),
            ] );
        }

        if ( in_array( $active_tab, [ 'connection', 'settings' ], true ) ) {
            wp_enqueue_script( 'aipi-pro-settings', $url . 'admin/js/settings.js', [ 'jquery' ], AIPI_PRO_VERSION, true );
            wp_localize_script( 'aipi-pro-settings', 'AIPI_PRO_SETTINGS', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'aipi_pro_nonce' ),
            ] );
        }

        if ( 'billing-usage' === $active_tab ) {
            wp_enqueue_script( 'aipi-pro-settings', $url . 'admin/js/settings.js', [ 'jquery' ], AIPI_PRO_VERSION, true );
            wp_localize_script( 'aipi-pro-settings', 'AIPI_PRO_SETTINGS', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'aipi_pro_nonce' ),
            ] );
        }

        if ( Settings::is_managed_mode() && '' !== $installation_id ) {
            $public_config_result = $this->ledger->getPublicConfig();
            $public_config        = ! empty( $public_config_result['success'] ) && ! empty( $public_config_result['data'] ) && is_array( $public_config_result['data'] )
                ? $public_config_result['data']
                : [];
            $client_id = '';
            if ( ! empty( $public_config['paypal_client_id'] ) ) {
                $client_id = (string) $public_config['paypal_client_id'];
            } elseif ( ! empty( $public_config['paypalClientId'] ) ) {
                $client_id = (string) $public_config['paypalClientId'];
            }
            $client_id = trim( $client_id );
            $currency = 'USD';
            if ( ! empty( $public_config['currency'] ) && is_string( $public_config['currency'] ) ) {
                $currency = strtoupper( sanitize_text_field( $public_config['currency'] ) );
            } elseif ( ! empty( $public_config['paypal_currency'] ) && is_string( $public_config['paypal_currency'] ) ) {
                $currency = strtoupper( sanitize_text_field( $public_config['paypal_currency'] ) );
            }
            if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
                $currency = 'USD';
            }
            $raw_packs = [];
            if ( isset( $public_config['packs'] ) && is_array( $public_config['packs'] ) ) {
                $raw_packs = $public_config['packs'];
            } elseif ( isset( $public_config['pack_amounts'] ) && is_array( $public_config['pack_amounts'] ) ) {
                $raw_packs = $public_config['pack_amounts'];
            }
            $packs = [];
            foreach ( $raw_packs as $idx => $pack ) {
                if ( is_array( $pack ) ) {
                    $pid = $pack['id'] ?? ( $pack['key'] ?? 'pack_' . $idx );
                    $amt = $pack['amount'] ?? ( $pack['amount_usd'] ?? ( $pack['value'] ?? 0 ) );
                    $lbl = isset( $pack['label'] ) ? sanitize_text_field( (string) $pack['label'] ) : '';
                } else {
                    $pid = 'pack_' . $idx;
                    $amt = $pack;
                    $lbl = '';
                }
                $amt = is_numeric( $amt ) ? (float) $amt : 0.0;
                if ( $amt > 0 ) {
                    $packs[] = [ 'id' => sanitize_key( (string) $pid ), 'amount' => round( $amt, 2 ), 'label' => $lbl ];
                }
            }
            if ( 'billing-usage' === $active_tab && Settings::MODE_MANAGED === Settings::get_mode() && Settings::get( 'connection_status', '' ) === 'configured' && '' !== $client_id && ! empty( $packs ) ) {
                wp_enqueue_script( 'aipi-pro-admin-runtime', $url . 'admin/js/admin.js', [ 'jquery' ], AIPI_PRO_VERSION, true );
                wp_localize_script( 'aipi-pro-admin-runtime', 'AIPI_PRO', [
                    'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
                    'nonce'            => wp_create_nonce( 'aipi_pro_nonce' ),
                    'settingsUrl'      => $this->get_main_page_url( [ 'tab' => 'connection' ] ),
                    'accountMode'      => Settings::get_mode(),
                    'connectionStatus' => (string) $settings['connection_status'],
                    'customerId'       => (string) $settings['customer_id'],
                    'byoKeyConfigured' => '' !== Settings::get_byo_openai_key(),
                ] );
                $paypal_sdk_src = 'https://www.paypal.com/sdk/js?client-id=' . rawurlencode( $client_id ) . '&currency=' . rawurlencode( $currency );
                wp_enqueue_script( 'aipi-pro-paypal-sdk', $paypal_sdk_src, [], null, true );
                wp_enqueue_script( 'aipi-pro-paypal', $url . 'admin/js/paypal.js', [ 'jquery', 'aipi-pro-paypal-sdk' ], AIPI_PRO_VERSION, true );
                wp_localize_script( 'aipi-pro-paypal', 'AIPI_PRO_PAYPAL', [
                    'packs'    => $packs,
                    'currency' => $currency,
                ] );
            }
        }
    }

    /**
     * Render the main admin page with internal tabs.
     */
    public function render_admin_page(): void {
        if ( ! Permission::user_can_manage() ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-to-product' ) );
        }

        $active_tab = $this->get_active_tab();
        echo '<div class="wrap photo-to-product">';
        echo '<h1>' . esc_html__( 'Photo to Product', 'photo-to-product' ) . '</h1>';
        echo '<p class="aipi-page-intro">' . esc_html__( 'Turn product photos or descriptions into WooCommerce drafts, then review them before you publish.', 'photo-to-product' ) . '</p>';
        $this->render_tab_navigation( $active_tab );
        echo '<div class="aipi-tab-panel">';
        echo '<div id="aipi-pro-notice" style="margin-top:1em;"></div>';
        switch ( $active_tab ) {
            case 'connection':
                $this->render_connection_tab();
                break;
            case 'billing-usage':
                $this->render_billing_usage_tab();
                break;
            case 'settings':
                $this->render_settings_tab();
                break;
            case 'create-product':
            default:
                $this->render_create_product_tab();
                break;
        }
        echo '</div></div>';
    }

    public function render_settings_page(): void {
        wp_safe_redirect( $this->get_main_page_url( [ 'tab' => 'connection' ] ) );
        exit;
    }

    private function get_active_tab(): string {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'create-product';
        $allowed = [ 'create-product', 'connection', 'billing-usage', 'settings' ];
        return in_array( $tab, $allowed, true ) ? $tab : 'create-product';
    }

    private function get_main_page_url( array $args = [] ): string {
        $base = admin_url( 'admin.php?page=photo-to-product' );
        return add_query_arg( $args, $base );
    }

    private function render_tab_navigation( string $active_tab ): void {
        $tabs = [
            'create-product' => __( 'Create Product', 'photo-to-product' ),
            'connection'     => __( 'Connection', 'photo-to-product' ),
            'billing-usage'  => __( 'Billing & Usage', 'photo-to-product' ),
            'settings'       => __( 'Settings', 'photo-to-product' ),
        ];
        echo '<nav class="nav-tab-wrapper aipi-nav-tabs">';
        foreach ( $tabs as $slug => $label ) {
            if ( 'create-product' !== $slug && ! current_user_can( 'manage_options' ) ) {
                continue;
            }
            $class = 'nav-tab' . ( $active_tab === $slug ? ' nav-tab-active' : '' );
            echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $this->get_main_page_url( [ 'tab' => $slug ] ) ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
    }

    private function render_create_product_tab(): void {
        $mode          = Settings::get_mode();
        $managed_ready = Settings::MODE_MANAGED === $mode && 'configured' === Settings::get( 'connection_status', '' );
        $byo_ready     = Settings::MODE_BYO === $mode && '' !== Settings::get_byo_openai_key();
        $is_ready      = $managed_ready || $byo_ready;

        AdminCreateProductTab::render( $is_ready, $this->get_main_page_url( [ 'tab' => 'connection' ] ) );
    }

    private function render_connection_tab(): void {
        if ( ! Permission::user_can_manage_settings() ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-to-product' ) );
        }
        $settings      = Settings::get_all();
        $mode          = Settings::get_mode();
        $has_key       = '' !== Settings::get_byo_openai_key();
        $is_registered = ! empty( $settings['customer_id'] ) && ! empty( $settings['installation_id'] ) && ! empty( Settings::get( 'installation_token', '' ) );
        $register_url   = wp_nonce_url( add_query_arg( [ 'action' => 'aipi_register_installation' ], admin_url( 'admin-post.php' ) ), 'aipi_register_installation_action' );
        $reregister_url = wp_nonce_url( add_query_arg( [ 'action' => 'aipi_reregister_installation' ], admin_url( 'admin-post.php' ) ), 'aipi_reregister_installation_action' );

        echo '<div class="aipi-grid aipi-grid-2">';
        echo '<div class="aipi-card aipi-status-card">';
        echo '<span class="aipi-eyebrow">' . esc_html__( 'Connection', 'photo-to-product' ) . '</span>';
        if ( Settings::MODE_MANAGED === $mode ) {
            echo '<h2>' . esc_html__( 'Managed service', 'photo-to-product' ) . '</h2>';
            echo '<p class="aipi-status-pill ' . esc_attr( $is_registered ? 'is-good' : 'is-muted' ) . '">' . esc_html( $is_registered ? __( 'Connected', 'photo-to-product' ) : __( 'Not connected', 'photo-to-product' ) ) . '</p>';
            echo '<p class="aipi-muted-copy">' . esc_html__( 'Use credits without adding your own OpenAI key.', 'photo-to-product' ) . '</p>';
            echo '<p class="aipi-inline-actions">';
            if ( $is_registered ) {
                echo "<a class=\"button button-primary\" href=\"" . esc_url( $reregister_url ) . "\" onclick=\"return confirm('" . esc_js( __( 'Refresh this managed connection?', 'photo-to-product' ) ) . "');\">" . esc_html__( 'Refresh Managed Connection', 'photo-to-product' ) . '</a>';
            } else {
                echo '<a class="button button-primary" href="' . esc_url( $register_url ) . '">' . esc_html__( 'Connect to Managed Service', 'photo-to-product' ) . '</a>';
            }
            echo '</p>';
        } else {
            echo '<h2>' . esc_html__( 'Bring your own key', 'photo-to-product' ) . '</h2>';
            echo '<p class="aipi-status-pill ' . esc_attr( $has_key ? 'is-good' : 'is-muted' ) . '">' . esc_html( $has_key ? __( 'API key saved', 'photo-to-product' ) : __( 'API key needed', 'photo-to-product' ) ) . '</p>';
            echo '<p class="aipi-muted-copy">' . esc_html__( 'Use your own OpenAI account.', 'photo-to-product' ) . '</p>';
        }
        echo '</div>';

        echo '<details class="aipi-card aipi-advanced-card">';
        echo '<summary><span class="aipi-eyebrow">' . esc_html__( 'Advanced', 'photo-to-product' ) . '</span><strong>' . esc_html__( 'Recovery Credentials', 'photo-to-product' ) . '</strong></summary>';
        echo '<p class="aipi-muted-copy">' . esc_html__( 'Keep these somewhere safe if you may need to restore this connection later.', 'photo-to-product' ) . '</p>';
        echo '<div class="aipi-secret-stack">';
        echo '<input type="password" class="regular-text code" readonly id="aipi-current-customer-id" value="' . esc_attr( (string) $settings['customer_id'] ) . '" placeholder="' . esc_attr__( 'Customer ID', 'photo-to-product' ) . '" />';
        echo '<input type="password" class="regular-text code" readonly id="aipi-current-installation-id" value="' . esc_attr( (string) $settings['installation_id'] ) . '" placeholder="' . esc_attr__( 'Installation ID', 'photo-to-product' ) . '" />';
        echo '<input type="password" class="regular-text code" readonly id="aipi-current-installation-token" value="' . esc_attr( (string) Settings::get( 'installation_token', '' ) ) . '" placeholder="' . esc_attr__( 'Installation Token', 'photo-to-product' ) . '" />';
        echo '</div>';
        echo '<p class="aipi-inline-actions"><button type="button" class="button" id="aipi-reveal-credentials" data-show-label="' . esc_attr__( 'Reveal', 'photo-to-product' ) . '" data-hide-label="' . esc_attr__( 'Hide', 'photo-to-product' ) . '">' . esc_html__( 'Reveal', 'photo-to-product' ) . '</button> <button type="button" class="button" id="aipi-copy-credentials">' . esc_html__( 'Copy All', 'photo-to-product' ) . '</button> <span id="aipi-copy-result"></span></p>';
        echo '</details>';
        echo '</div>';

        echo '<form method="post" action="options.php" class="aipi-card aipi-settings-form">';
        settings_fields( Settings::OPTION_GROUP );
        echo '<input type="hidden" name="tab" value="connection" />';
        echo '<span class="aipi-eyebrow">' . esc_html__( 'Connection Settings', 'photo-to-product' ) . '</span>';
        echo '<h2>' . esc_html__( 'Choose your connection', 'photo-to-product' ) . '</h2>';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="aipi-account-mode">' . esc_html__( 'Mode', 'photo-to-product' ) . '</label></th><td>';
        echo '<select id="aipi-account-mode" name="' . esc_attr( Settings::OPTION_NAME ) . '[account_mode]">';
        echo '<option value="managed" ' . selected( $settings['account_mode'], Settings::MODE_MANAGED, false ) . '>' . esc_html__( 'Managed Mode', 'photo-to-product' ) . '</option>';
        echo '<option value="byo_key" ' . selected( $settings['account_mode'], Settings::MODE_BYO, false ) . '>' . esc_html__( 'BYO API Key', 'photo-to-product' ) . '</option>';
        echo '</select><p class="description">' . esc_html__( 'Managed Mode uses credits. BYO uses your OpenAI account.', 'photo-to-product' ) . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="aipi-byo-key">' . esc_html__( 'OpenAI API Key', 'photo-to-product' ) . '</label></th><td><input type="password" class="regular-text" autocomplete="new-password" id="aipi-byo-key" name="' . esc_attr( Settings::OPTION_NAME ) . '[byo_openai_key]" value="" placeholder="' . esc_attr__( 'Enter new key', 'photo-to-product' ) . '" /> <button type="button" class="button" id="aipi-test-byo-key" data-ajax-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'aipi_pro_nonce' ) ) . '">' . esc_html__( 'Test Key', 'photo-to-product' ) . '</button> <span id="aipi-test-byo-result"></span>';
        echo '<label style="display:block;margin-top:6px;"><input type="checkbox" name="' . esc_attr( Settings::OPTION_NAME ) . '[clear_byo_openai_key]" value="1" /> ' . esc_html__( 'Remove saved key', 'photo-to-product' ) . '</label></td></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Restore Existing Connection', 'photo-to-product' ) . '</th><td><details class="aipi-inline-details"><summary>' . esc_html__( 'Restore from saved values', 'photo-to-product' ) . '</summary>';
        echo '<input type="text" class="regular-text" name="' . esc_attr( Settings::OPTION_NAME ) . '[customer_id]" value="" placeholder="' . esc_attr__( 'Customer ID', 'photo-to-product' ) . '" /><br />';
        echo '<input type="text" class="regular-text" name="' . esc_attr( Settings::OPTION_NAME ) . '[installation_id]" value="" placeholder="' . esc_attr__( 'Installation ID', 'photo-to-product' ) . '" style="margin-top:8px;" /><br />';
        echo '<input type="text" class="regular-text code" name="' . esc_attr( Settings::OPTION_NAME ) . '[installation_token]" value="" placeholder="' . esc_attr__( 'Installation Token', 'photo-to-product' ) . '" style="margin-top:8px;" />';
        echo '<p class="description">' . esc_html__( 'Use this only when restoring a saved managed connection.', 'photo-to-product' ) . '</p></details></td></tr>';
        echo '</table>';
        submit_button( __( 'Save Connection', 'photo-to-product' ) );
        echo '<p class="description"><a href="' . esc_url( Settings::PRIVACY_POLICY_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'photo-to-product' ) . '</a> · <a href="' . esc_url( Settings::TERMS_OF_SERVICE_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms', 'photo-to-product' ) . '</a> · <a href="' . esc_url( Settings::SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support', 'photo-to-product' ) . '</a></p>';
        echo '</form>';
    }

    private function render_billing_usage_tab(): void {
        if ( ! Permission::user_can_manage_settings() ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-to-product' ) );
        }
        $balance_text = __( 'Unavailable', 'photo-to-product' );
        if ( Settings::MODE_MANAGED === Settings::get_mode() ) {
            $balance = $this->ledger->getBalance();
            if ( ! empty( $balance['success'] ) && ! empty( $balance['data'] ) && is_array( $balance['data'] ) ) {
                $value = $balance['data']['balance'] ?? $balance['data']['credits'] ?? $balance['data']['available_credits'] ?? null;
                if ( null !== $value && '' !== $value ) {
                    $balance_text = (string) $value;
                }
            }
        } else {
            $balance_text = __( 'BYO Mode', 'photo-to-product' );
        }
        $summary = $this->get_usage_summary();
        if ( Settings::MODE_MANAGED !== Settings::get_mode() ) { echo '<div class="notice notice-info inline aipi-inline-notice"><p>' . esc_html__( 'Billing and credits are available in Managed Mode.', 'photo-to-product' ) . '</p></div>'; } elseif ( 'configured' !== Settings::get( 'connection_status', '' ) ) { echo '<div class="notice notice-warning inline aipi-inline-notice"><p>' . esc_html__( 'Connect to Managed Service to see your credits and recent activity.', 'photo-to-product' ) . '</p></div>'; }
        echo '<div class="aipi-grid aipi-grid-4">';
        echo '<div class="aipi-card aipi-metric-card aipi-balance-card"><span class="aipi-metric-label">' . esc_html__( 'Available Credits', 'photo-to-product' ) . '</span><strong class="aipi-metric-value">' . esc_html( $balance_text ) . '</strong><span class="aipi-metric-subtext">' . esc_html__( 'Ready to create drafts', 'photo-to-product' ) . '</span></div>';
        echo '<div class="aipi-card aipi-metric-card"><span class="aipi-metric-label">' . esc_html__( 'Products This Month', 'photo-to-product' ) . '</span><strong class="aipi-metric-value">' . esc_html( (string) $summary['completed'] ) . '</strong></div>';
        echo '<div class="aipi-card aipi-metric-card"><span class="aipi-metric-label">' . esc_html__( 'Credits Used', 'photo-to-product' ) . '</span><strong class="aipi-metric-value">' . esc_html( (string) $summary['credits'] ) . '</strong></div>';
        echo '<div class="aipi-card aipi-metric-card"><span class="aipi-metric-label">' . esc_html__( 'Success Rate', 'photo-to-product' ) . '</span><strong class="aipi-metric-value">' . esc_html( (string) $summary['success_rate'] ) . '%</strong></div>';
        echo '</div>';
        echo '<div class="aipi-grid aipi-grid-2" style="margin-top:16px;">';
        echo '<div id="aipi-pro-buy-credits" class="aipi-card aipi-buy-card"></div>';
        echo '<div class="aipi-card"><span class="aipi-eyebrow">' . esc_html__( 'This Month', 'photo-to-product' ) . '</span><h2>' . esc_html__( 'Value Summary', 'photo-to-product' ) . '</h2><ul class="aipi-summary-list"><li><strong>' . esc_html__( 'Products created', 'photo-to-product' ) . ':</strong> ' . esc_html( (string) $summary['completed'] ) . '</li><li><strong>' . esc_html__( 'Credits used', 'photo-to-product' ) . ':</strong> ' . esc_html( (string) $summary['credits'] ) . '</li><li><strong>' . esc_html__( 'Average credits per product', 'photo-to-product' ) . ':</strong> ' . esc_html( (string) $summary['avg'] ) . '</li><li><strong>' . esc_html__( 'Successful jobs', 'photo-to-product' ) . ':</strong> ' . esc_html( (string) $summary['completed'] ) . '</li></ul></div>';
        echo '</div>';
        echo '<div class="aipi-grid aipi-grid-2" style="margin-top:16px;">';
        echo '<div class="aipi-card"><span class="aipi-eyebrow">' . esc_html__( 'Recent Activity', 'photo-to-product' ) . '</span><h2>' . esc_html__( 'Recent credit and draft activity', 'photo-to-product' ) . '</h2>';
        $this->render_recent_activity_list();
        echo '</div>';
        echo '<div class="aipi-card"><span class="aipi-eyebrow">' . esc_html__( 'Recent Jobs', 'photo-to-product' ) . '</span><h2>' . esc_html__( 'Recent product drafts', 'photo-to-product' ) . '</h2>';
        $this->render_recent_jobs_table();
        echo '</div>';
        echo '</div>';
    }

    private function render_settings_tab(): void {
        if ( ! Permission::user_can_manage_settings() ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-to-product' ) );
        }
        $settings = Settings::get_all();
        $current_model = $settings['openai_model'] ?? \AIPI\Generator::MODEL;
        $recommended_models = Settings::get_supported_openai_models();
        $current_custom_model = Settings::is_recommended_openai_model( $current_model ) ? '' : $current_model;
        $current_select_model = Settings::is_recommended_openai_model( $current_model ) ? $current_model : \AIPI\Generator::MODEL;
        echo '<form method="post" action="options.php" class="aipi-card aipi-settings-form">';
        settings_fields( Settings::OPTION_GROUP );
        echo '<input type="hidden" name="tab" value="settings" />';
        echo '<span class="aipi-eyebrow">' . esc_html__( 'Settings', 'photo-to-product' ) . '</span>';
        echo '<h2>' . esc_html__( 'Draft Settings', 'photo-to-product' ) . '</h2>';
        echo '<p class="aipi-muted-copy">' . esc_html__( 'Control how product drafts are prepared and assigned in WooCommerce.', 'photo-to-product' ) . '</p>';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="aipi-openai-model">' . esc_html__( 'OpenAI Model', 'photo-to-product' ) . '</label></th><td>';
        echo '<select id="aipi-openai-model" name="' . esc_attr( Settings::OPTION_NAME ) . '[openai_model]">';
        foreach ( $recommended_models as $model_option ) {
            echo '<option value="' . esc_attr( $model_option ) . '" ' . selected( $current_select_model, $model_option, false ) . '>' . esc_html( $model_option ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Choose a recommended OpenAI model, or enter a custom model ID below if your OpenAI account exposes a different one.', 'photo-to-product' ) . '</p>';
        echo '<input type="text" id="aipi-openai-model-custom" name="' . esc_attr( Settings::OPTION_NAME ) . '[openai_model_custom]" value="' . esc_attr( $current_custom_model ) . '" class="regular-text" placeholder="' . esc_attr( \AIPI\Generator::MODEL ) . '" />';
        echo '<p class="description">' . esc_html__( 'Optional custom model ID. When filled, it overrides the dropdown. Availability depends on your OpenAI account and API access.', 'photo-to-product' ) . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="aipi-category-assignment-mode">' . esc_html__( 'Categories', 'photo-to-product' ) . '</label></th><td><select id="aipi-category-assignment-mode" name="' . esc_attr( Settings::OPTION_NAME ) . '[category_assignment_mode]">';
        foreach ( Settings::get_taxonomy_assignment_modes() as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $settings['category_assignment_mode'] ?? 'existing_only', $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="aipi-tag-assignment-mode">' . esc_html__( 'Tags', 'photo-to-product' ) . '</label></th><td><select id="aipi-tag-assignment-mode" name="' . esc_attr( Settings::OPTION_NAME ) . '[tag_assignment_mode]">';
        foreach ( Settings::get_taxonomy_assignment_modes() as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $settings['tag_assignment_mode'] ?? 'existing_only', $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Logs', 'photo-to-product' ) . '</th><td><label><input type="checkbox" name="' . esc_attr( Settings::OPTION_NAME ) . '[clear_logs]" value="1" /> ' . esc_html__( 'Clear recent plugin logs on save', 'photo-to-product' ) . '</label></td></tr>';
        echo '</table>';
        submit_button( __( 'Save Settings', 'photo-to-product' ) );
        echo '</form>';
        echo '<div class="aipi-grid aipi-grid-2" style="margin-top:16px;">';
        echo '<details class="aipi-card aipi-advanced-card"><summary><span class="aipi-eyebrow">' . esc_html__( 'Advanced', 'photo-to-product' ) . '</span><strong>' . esc_html__( 'Diagnostics', 'photo-to-product' ) . '</strong></summary><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Check', 'photo-to-product' ) . '</th><th>' . esc_html__( 'Status', 'photo-to-product' ) . '</th></tr></thead><tbody>';
        foreach ( Settings::get_diagnostics() as $row ) {
            echo '<tr><td>' . esc_html( $row['label'] ) . '</td><td><code>' . esc_html( $row['status'] ) . '</code></td></tr>';
        }
        echo '</tbody></table></details>';
        echo '<details class="aipi-card aipi-advanced-card"><summary><span class="aipi-eyebrow">' . esc_html__( 'Advanced', 'photo-to-product' ) . '</span><strong>' . esc_html__( 'Recent Logs', 'photo-to-product' ) . '</strong></summary>';
        $this->render_recent_logs_panel();
        echo '</details></div>';
    }

    private function get_usage_summary(): array {
        $month_start = strtotime( gmdate( 'Y-m-01 00:00:00' ) );
        $query = new \WP_Query([
            'post_type'      => JobRepository::POST_TYPE,
            'post_status'    => 'private',
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'date_query'     => [[ 'after' => gmdate( 'Y-m-01 00:00:00' ), 'inclusive' => true ]],
        ]);
        $completed = 0; $credits = 0;
        foreach ( $query->posts as $job_id ) {
            $status = $this->repo->get_status( (int) $job_id );
            if ( 'completed' === $status ) { $completed++; }
            $usage = $this->repo->get_meta( (int) $job_id, '_aipi_generation_usage', [] );
            if ( is_array( $usage ) ) {
                $credits += (float) ( $usage['credits'] ?? $usage['total_credits'] ?? $usage['billable_amount'] ?? 0 );
            }
        }
        $total = count( $query->posts );
        return [
            'completed'    => $completed,
            'credits'      => $credits,
            'avg'          => $completed > 0 ? round( $credits / $completed, 1 ) : 0,
            'success_rate' => $total > 0 ? round( ( $completed / $total ) * 100 ) : 0,
        ];
    }

    private function render_recent_activity_list(): void {
        $query = new \WP_Query([
            'post_type'      => JobRepository::POST_TYPE,
            'post_status'    => 'private',
            'posts_per_page' => 8,
            'fields'         => 'ids',
            'meta_key'       => '_aipi_last_updated',
            'orderby'        => 'meta_value_num',
            'order'          => 'DESC',
        ]);
        if ( empty( $query->posts ) ) {
            echo '<p>' . esc_html__( 'No activity yet. Create a product draft or buy credits to see activity here.', 'photo-to-product' ) . '</p>';
            return;
        }
        echo '<ul class="aipi-activity-list">';
        foreach ( $query->posts as $job_id ) {
            $job_id   = (int) $job_id;
            $status   = $this->repo->get_status( $job_id );
            $updated  = (int) $this->repo->get_meta( $job_id, '_aipi_last_updated', 0 );
            $usage    = $this->repo->get_meta( $job_id, '_aipi_generation_usage', [] );
            $credits  = is_array( $usage ) ? (string) ( $usage['credits'] ?? $usage['total_credits'] ?? $usage['billable_amount'] ?? '' ) : '';
            $label = __( 'Updated draft', 'photo-to-product' );
            if ( 'completed' === $status ) {
                $label = __( 'Created WooCommerce draft', 'photo-to-product' );
            } elseif ( 'generated' === $status ) {
                $label = __( 'Draft ready for review', 'photo-to-product' );
            } elseif ( 'failed' === $status ) {
                $label = __( 'Draft needs attention', 'photo-to-product' );
            }
            $title = get_the_title( $job_id );
            echo '<li><div><strong>' . esc_html( $label ) . '</strong><span class="aipi-activity-meta">' . esc_html( $title ? $title : '#' . (string) $job_id ) . ( $credits !== '' ? ' · ' . esc_html( $credits ) . ' ' . esc_html__( 'credits', 'photo-to-product' ) : '' ) . '</span></div><span class="aipi-activity-time">' . esc_html( $updated ? gmdate( 'M j', $updated ) : '—' ) . '</span></li>';
        }
        echo '</ul>';
    }

    private function render_recent_jobs_table(): void {
        $query = new \WP_Query([
            'post_type'      => JobRepository::POST_TYPE,
            'post_status'    => 'private',
            'posts_per_page' => 10,
            'fields'         => 'ids',
            'meta_key'       => '_aipi_last_updated',
            'orderby'        => 'meta_value_num',
            'order'          => 'DESC',
        ]);
        if ( empty( $query->posts ) ) {
            echo '<p>' . esc_html__( 'No product drafts yet.', 'photo-to-product' ) . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'photo-to-product' ) . '</th><th>' . esc_html__( 'Draft', 'photo-to-product' ) . '</th><th>' . esc_html__( 'Status', 'photo-to-product' ) . '</th><th>' . esc_html__( 'Credits', 'photo-to-product' ) . '</th><th>' . esc_html__( 'Result', 'photo-to-product' ) . '</th></tr></thead><tbody>';
        foreach ( $query->posts as $job_id ) {
            $job_id = (int) $job_id;
            $updated = (int) $this->repo->get_meta( $job_id, '_aipi_last_updated', 0 );
            $status  = $this->repo->get_status( $job_id );
            $usage   = $this->repo->get_meta( $job_id, '_aipi_generation_usage', [] );
            $credits = is_array( $usage ) ? (string) ( $usage['credits'] ?? $usage['total_credits'] ?? $usage['billable_amount'] ?? '—' ) : '—';
            $product_id = (int) $this->repo->get_meta( $job_id, '_aipi_created_product', 0 );
            $title = get_the_title( $job_id );
            $result = $product_id > 0 ? sprintf( __( 'Product #%d', 'photo-to-product' ), $product_id ) : __( 'Draft prepared', 'photo-to-product' );
            echo '<tr><td>' . esc_html( $updated ? gmdate( 'Y-m-d', $updated ) : '—' ) . '</td><td>' . esc_html( $title ? $title : '#' . (string) $job_id ) . '</td><td><span class="aipi-status-pill is-muted">' . esc_html( ucwords( str_replace( '_', ' ', $status ) ) ) . '</span></td><td>' . esc_html( $credits ) . '</td><td>' . esc_html( $result ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function render_recent_logs_panel(): void {
        $entries = Logger::recent( 10 );
        if ( empty( $entries ) ) {
            echo '<p>' . esc_html__( 'No recent logs.', 'photo-to-product' ) . '</p>';
            return;
        }
        echo '<ul class="aipi-simple-list">';
        foreach ( $entries as $entry ) {
            echo '<li><strong>' . esc_html( strtoupper( (string) ( $entry['level'] ?? '' ) ) ) . ':</strong> ' . esc_html( (string) ( $entry['message'] ?? '' ) ) . '</li>';
        }
        echo '</ul>';
    }

    /**
     * In BYO mode, use the saved customer OpenAI key. Otherwise leave the
     * existing constant/filter-based key path intact for managed mode.
     */
    public function filter_openai_api_key( string $key ): string {
        if ( Settings::MODE_BYO === Settings::get_mode() ) {
            return Settings::get_byo_openai_key();
        }
        return $key;
    }

    /**
     * Filter the OpenAI model used by Generator. Returns the model stored in settings
     * when available and valid. Falls back to the provided model otherwise.
     *
     * @param string $model Default model constant from Generator
     * @return string Selected model
     */
    public function filter_openai_model( string $model ): string {
        // Prefer the saved model when one is configured.
        try {
            $settings = Settings::get_all();
            $selected = isset( $settings['openai_model'] ) ? Settings::sanitize_openai_model( (string) $settings['openai_model'] ) : '';
            if ( '' !== $selected ) {
                return $selected;
            }
        } catch ( \Throwable $e ) {
            // Swallow exceptions from settings retrieval. Fall back to provided model.
        }
        return $model;
    }

    /**
     * Perform nonce and capability checks for AJAX requests. If either fails
     * send a JSON error and exit. Nonce alone is insufficient to secure
     * AJAX; capability checks ensure the user may perform the requested action.
     */
    private function assert_ajax_permission(): void {
        $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'aipi_pro_nonce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'photo-to-product' ) ], 403 );
        }
        if ( ! Permission::user_can_manage() ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'photo-to-product' ) ], 403 );
        }
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


    /**
     * Clear stale workflow metadata so a retry starts from a clean state.
     *
     * @param int $job_id
     */
    private function reset_generation_metadata( int $job_id ): void {
        $this->repo->set_meta( $job_id, '_aipi_error_message', '' );
        $this->repo->set_meta( $job_id, '_aipi_ledger_warning', '' );
        $this->repo->set_meta( $job_id, '_aipi_taxonomy_warning', '' );
        $this->repo->set_meta( $job_id, '_aipi_generation_attempt_id', '' );
        $this->repo->set_meta( $job_id, '_aipi_generation_usage', [] );
        $this->repo->set_meta( $job_id, '_aipi_generated_listing', [] );
        $this->repo->set_meta( $job_id, '_aipi_created_product', 0 );
        $this->repo->set_meta( $job_id, '_aipi_use_images', '' );
        // Clear prepared images and generation context so retries rebuild fresh
        // request metadata instead of reusing stale generation details.
        $this->repo->set_meta( $job_id, '_aipi_prepared_images', [] );
        $this->repo->set_meta( $job_id, '_aipi_generation_context', [] );
        $this->repo->set_meta( $job_id, '_aipi_job_description', '' );
    }

    /**
     * Convert known exception messages into safer user-facing messages.
     *
     * @param \Throwable $e
     * @param string     $fallback
     * @return string
     */
    private function user_safe_error_message( \Throwable $e, string $fallback ): string {
        // Known AipiException messages are safe to show directly.
        if ( $e instanceof AipiException ) {
            return $e->getMessage();
        }
        $message   = trim( (string) $e->getMessage() );
        $lc_message = strtolower( $message );
        // Maintain an allowlist of safe generic error phrases to show to users.
        $allowlist = [
            'invalid nonce',
            'insufficient permissions',
            'access denied',
            'no photos were uploaded',
            'generation is already in progress',
            'product creation is already in progress',
            'too many attachments',
            'too many files',
            'file exceeds maximum size',
            'only image uploads are allowed',
            'no attachments available for this job',
            'uploaded images could not be prepared for ai analysis',
            'insufficient credits',
            'credit check failed',
            'openai api key is not configured',
            'no images were reachable from this server',
        ];
        foreach ( $allowlist as $needle ) {
            if ( false !== strpos( $lc_message, $needle ) ) {
                return $message;
            }
        }
        return $fallback;
    }


    /**
     * Determine the appropriate HTTP status code for a thrown exception.
     *
     * Known AipiException codes are mapped explicitly; unknown exceptions
     * default to 500. See AipiException::getAipiCode() for available codes.
     *
     * @param \Throwable $e
     * @return int
     */
    private function get_error_http_status( \Throwable $e ): int {
        if ( $e instanceof AipiException ) {
            switch ( $e->getAipiCode() ) {
                case 'insufficient_credits':
                    return 402; // Payment Required
                case 'missing_input':
                case 'missing_attachments':
                case 'missing_listing':
                case 'invalid_state':
                case 'image_fetch_failed':
                case 'too_many_files':
                case 'too_many_attachments':
                case 'file_too_large':
                case 'invalid_file_type':
                case 'upload_error':
                    return 400; // Bad Request
                case 'upload_insert_failed':
                case 'product_creation_failed':
                case 'ai_response_invalid':
                    return 500; // Internal Server Error
                case 'in_progress':
                    return 409; // Conflict
                case 'ledger_unavailable':
                    return 503; // Service Unavailable
                case 'missing_api_key':
                    return 400; // Bad Request
                case 'ai_request_failed':
                    return 502; // Bad Gateway
                default:
                    return 500;
            }
        }
        // Fallback: treat "already in progress" as a conflict.
        $msg = strtolower( (string) $e->getMessage() );
        if ( false !== strpos( $msg, 'already in progress' ) ) {
            return 409;
        }
        return 500;
    }
    /**
     * AJAX: Create a new job and return its ID.
     */
    public function handle_create_job(): void {
        $this->assert_ajax_permission();
        $owner_id = get_current_user_id();
        try {
            $job_id = $this->repo->create_job( $owner_id );
            wp_send_json_success( [ 'jobId' => $job_id ] );
        } catch ( \Throwable $e ) {
            $this->log_internal_error( 'create job', $e );
            $message = $this->user_safe_error_message( $e, __( 'Could not create a new job. Please try again.', 'photo-to-product' ) );
            wp_send_json_error( [ 'message' => $message ], $this->get_error_http_status( $e ) );
        }
    }

    /**
     * AJAX: Upload photos to a job. Expects job_id and photos[] in $_FILES.
     */
    public function handle_upload_photos(): void {
        $this->assert_ajax_permission();
        if ( ! Permission::user_can_upload() ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission to upload files.', 'photo-to-product' ) ], 403 );
        }
        $job_id = isset( $_POST['job_id'] ) ? intval( $_POST['job_id'] ) : 0;
        if ( ! Permission::user_can_access_job( $job_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Access denied.', 'photo-to-product' ) ], 403 );
        }
        // Ensure the photos input exists to avoid undefined index notices.
        if ( empty( $_FILES['photos'] ) || ! is_array( $_FILES['photos'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No photos were uploaded.', 'photo-to-product' ) ], 400 );
        }
        try {
            $attachments = $this->uploader->upload( $job_id, $_FILES['photos'] );
            wp_send_json_success( [ 'attachments' => $attachments ] );
        } catch ( \Throwable $e ) {
            $this->log_internal_error( 'upload photos', $e );
            $message      = $this->user_safe_error_message( $e, __( 'Could not upload photos. Please try again.', 'photo-to-product' ) );
            $status_code = $this->get_error_http_status( $e );
            wp_send_json_error( [ 'message' => $message ], $status_code );
        }
    }

    /**
     * AJAX: Mark a job as ready for generation. Photos are optional; actual
     * input validation happens when generation is triggered.
     */
    public function handle_ready_for_generation(): void {
        $this->assert_ajax_permission();
        $job_id = isset( $_POST['job_id'] ) ? intval( $_POST['job_id'] ) : 0;
        if ( ! Permission::user_can_access_job( $job_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Access denied.', 'photo-to-product' ) ], 403 );
        }
        // In the new input model, photos are optional. Marking a job ready
        // for generation no longer requires attachments; it simply
        // transitions the state to ready_for_generation. Any subsequent
        // generation call will validate that at least a description or
        // photo is provided.
        try {
            StateMachine::transition( $this->repo, $job_id, StateMachine::STATUS_READY_FOR_GENERATION );
            $this->reset_generation_metadata( $job_id );
            wp_send_json_success( [ 'status' => StateMachine::STATUS_READY_FOR_GENERATION ] );
        } catch ( \Throwable $e ) {
            $this->log_internal_error( 'mark ready for generation', $e );
            $message = $this->user_safe_error_message( $e, __( 'Could not mark this job ready for generation. Please try again.', 'photo-to-product' ) );
            wp_send_json_error( [ 'message' => $message ], $this->get_error_http_status( $e ) );
        }
    }

    /**
     * AJAX: Trigger AI generation for a job. Expects job_id and description.
     */
    public function handle_generate_listing(): void {
        $this->assert_ajax_permission();
        $job_id      = isset( $_POST['job_id'] ) ? intval( $_POST['job_id'] ) : 0;
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
        if ( function_exists( 'mb_substr' ) ) {
            $description = mb_substr( $description, 0, 4000 );
        } else {
            $description = substr( $description, 0, 4000 );
        }
        // Whether to include images in the AI request. Default to true when unspecified.
        $use_images = true;
        if ( isset( $_POST['use_images'] ) ) {
            $val = wp_unslash( $_POST['use_images'] );
            // Accept various truthy values; anything other than '0' or empty string is treated as true.
            $use_images = ! ( '' === $val || '0' === $val || 'false' === strtolower( (string) $val ) );
        }
        if ( ! Permission::user_can_access_job( $job_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Access denied.', 'photo-to-product' ) ], 403 );
        }
        try {
            // Allow failed jobs to be retried by returning them to the ready state.
            $current_status = $this->repo->get_status( $job_id );
            if ( StateMachine::STATUS_FAILED === $current_status ) {
                try {
                    StateMachine::transition( $this->repo, $job_id, StateMachine::STATUS_READY_FOR_GENERATION );
                    $this->repo->set_meta( $job_id, '_aipi_error_message', '' );
                } catch ( \Throwable $reset_error ) {
                    // Log the reset failure for debugging but continue so generation may still proceed.
                    $this->log_internal_error( 'reset failed job before generation', $reset_error );
                }
            }
            $listing = $this->workflow->execute( $job_id, $description, $use_images );
            wp_send_json_success( [ 'listing' => $listing ] );
        } catch ( \Throwable $e ) {
            // Log the detailed error server‑side when debug logging is enabled.
            $this->log_internal_error( 'generate listing', $e );
            $message      = $this->user_safe_error_message( $e, __( 'Listing generation failed. Please try again later.', 'photo-to-product' ) );
            $status_code = $this->get_error_http_status( $e );
            wp_send_json_error( [ 'message' => $message ], $status_code );
        }
    }

    /**
     * AJAX: Create a product from a generated listing. Expects job_id.
     */
    public function handle_create_product(): void {
        $this->assert_ajax_permission();
        if ( ! Permission::user_can_create_products() ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission to create products.', 'photo-to-product' ) ], 403 );
        }
        $job_id = isset( $_POST['job_id'] ) ? intval( $_POST['job_id'] ) : 0;
        if ( ! Permission::user_can_access_job( $job_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Access denied.', 'photo-to-product' ) ], 403 );
        }
        try {
            $product_id = $this->product_creation_workflow->execute( $job_id );
            wp_send_json_success( [ 'productId' => $product_id ] );
        } catch ( \Throwable $e ) {
            // Log the actual error and return a safe message to the UI.
            $this->log_internal_error( 'product creation', $e );
            $message      = $this->user_safe_error_message( $e, __( 'Product creation failed. Please try again later.', 'photo-to-product' ) );
            $status_code = $this->get_error_http_status( $e );
            wp_send_json_error( [ 'message' => $message ], $status_code );
        }
    }

    /**
     * AJAX: Delete a job. This removes the job record but leaves any
     * uploaded media in the WordPress Media Library. Images are not
     * automatically deleted so they can be reused or manually removed
     * via the Media Library. Expects job_id.
     */
    public function handle_delete_job(): void {
        $this->assert_ajax_permission();
        $job_id = isset( $_POST['job_id'] ) ? intval( $_POST['job_id'] ) : 0;
        if ( ! Permission::user_can_access_job( $job_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Access denied.', 'photo-to-product' ) ], 403 );
        }
        try {
            $status = $this->repo->get_status( $job_id );
            if ( in_array( $status, [ StateMachine::STATUS_GENERATING, StateMachine::STATUS_CREATING_PRODUCT ], true ) ) {
                throw new AipiException( 'in_progress', __( 'This job cannot be deleted while work is in progress.', 'photo-to-product' ) );
            }
            if ( $this->repo->has_active_lock( $job_id, 'generate' ) || $this->repo->has_active_lock( $job_id, 'create_product' ) ) {
                throw new AipiException( 'in_progress', __( 'This job is currently locked by another request. Please try again in a moment.', 'photo-to-product' ) );
            }
            $this->cleanup->delete_job( $job_id );
            wp_send_json_success( [ 'deleted' => true ] );
        } catch ( \Throwable $e ) {
            $this->log_internal_error( 'delete job', $e );
            $message     = $this->user_safe_error_message( $e, __( 'Could not delete this job. Please try again.', 'photo-to-product' ) );
            $status_code = $this->get_error_http_status( $e );
            wp_send_json_error( [ 'message' => $message ], $status_code );
        }
    }

    /**
     * AJAX: List jobs for the current user. Administrators see all jobs; other
     * users see only their own jobs. Returns essential metadata and the
     * generated listing if available. Does not include attachments to avoid
     * unnecessary data transfer.
     */
    public function handle_list_jobs(): void {
        $this->assert_ajax_permission();
        try {
            $current_user = get_current_user_id();
            // Apply basic pagination to avoid unbounded queries on large stores. Accept
            // optional page and per_page parameters from the request. Defaults
            // are page 1 and 20 items per page. Enforce sane bounds on per_page.
            $page     = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
            $per_page = isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : 20;
            if ( $per_page < 1 || $per_page > 100 ) {
                $per_page = 20;
            }
            $args = [
                'post_type'      => JobRepository::POST_TYPE,
                'posts_per_page' => $per_page,
                'paged'          => $page,
                // Jobs are stored privately, so include private status instead of publish.
                'post_status'    => 'private',
                // Sort jobs by the last updated timestamp when available. This uses
                // the numeric meta value stored in `_aipi_last_updated`. Falling
                // back to ID order is handled in the loop when timestamps are
                // missing. Sorting by updated time surfaces the most recently
                // active jobs first, which is more useful operationally than
                // ordering by post ID.
                'meta_key'       => '_aipi_last_updated',
                'orderby'        => 'meta_value_num',
                'order'          => 'DESC',
                // Only return IDs to reduce memory overhead. We call
                // get_post() explicitly inside the loop when needed.
                'fields'         => 'ids',
                'meta_query'     => [],
            ];
            // Non-admins see only their jobs.
            if ( ! Permission::user_can_manage_settings() ) {
                $args['meta_query'][] = [
                    'key'   => '_aipi_job_owner',
                    'value' => $current_user,
                ];
            }
            $query = new \WP_Query( $args );
            $job_ids = $query->posts;
            // Warm the meta cache for the returned job IDs. This avoids
            // repeated queries in the loop below. Because we requested
            // 'fields' => 'ids', $job_ids is already an array of integers.
            if ( ! empty( $job_ids ) ) {
                update_meta_cache( 'post', $job_ids );
            }
            $out   = [];
            foreach ( $job_ids as $job_id ) {
                $job_id           = (int) $job_id;
                // Fetch the post object explicitly. get_post() returns WP_Post
                // or null when the post is missing. Using ID-only queries
                // reduces memory usage for large datasets.
                $job              = get_post( $job_id );
                $status           = $this->repo->get_status( $job_id );
                $attachments      = $this->repo->get_attachments( $job_id );
                $listing          = $this->repo->get_meta( $job_id, '_aipi_generated_listing', [] );
                $product_id       = (int) $this->repo->get_meta( $job_id, '_aipi_created_product', 0 );
                $error            = $this->repo->get_meta( $job_id, '_aipi_error_message', '' );
                $ledger_warning   = $this->repo->get_meta( $job_id, '_aipi_ledger_warning', '' );
                $taxonomy_warning = $this->repo->get_meta( $job_id, '_aipi_taxonomy_warning', '' );
                $edit_url         = $product_id ? get_edit_post_link( $product_id, '' ) : '';
                // Timestamps: use post_date and post_modified as fallbacks. The
                // repository also records created/last_updated in meta which
                // takes precedence if present. Convert to ISO8601 strings for
                // easier consumption on the client. WordPress stores dates in
                // MySQL datetime (local timezone) format in $job->post_date.
                $created_ts  = (int) $this->repo->get_meta( $job_id, '_aipi_created_at', 0 );
                if ( $created_ts <= 0 ) {
                    $created_ts = strtotime( (string) $job->post_date_gmt ?: (string) $job->post_date );
                }
                $updated_ts  = (int) $this->repo->get_meta( $job_id, '_aipi_last_updated', 0 );
                if ( $updated_ts <= 0 ) {
                    $updated_ts = strtotime( (string) $job->post_modified_gmt ?: (string) $job->post_modified );
                }
                $created_iso = $created_ts ? gmdate( 'c', $created_ts ) : '';
                $updated_iso = $updated_ts ? gmdate( 'c', $updated_ts ) : '';
                // Determine whether the job can be retried. Allow retry for
                // failed jobs and jobs that have generated listings but not
                // created products. Other statuses are not retryable in UI.
                $can_retry = in_array( $status, [ 'failed', 'generated' ], true );
                // Do not include the full listing payload for jobs that are not
                // in the generated state. This reduces the size of the job
                // listing response. The client can request details later via
                // a dedicated endpoint if needed. When the job has not yet
                // generated a listing, return an empty object for listing.
                $listing_payload = new \stdClass();
                if ( 'generated' === $status && is_array( $listing ) ) {
                    $listing_payload = $listing;
                }
                $out[]    = [
                    'id'              => $job_id,
                    'status'          => $status,
                    'attachments'     => count( $attachments ),
                    'listing'         => $listing_payload,
                    'productId'       => $product_id,
                    'editUrl'         => $edit_url,
                    'error'           => $error,
                    'ledgerWarning'   => $ledger_warning,
                    'taxonomyWarning' => $taxonomy_warning,
                    'createdAt'       => $created_iso,
                    'updatedAt'       => $updated_iso,
                    'canRetry'        => $can_retry,
                ];
            }
            wp_send_json_success( [
                'jobs'        => $out,
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => (int) $query->found_posts,
                'total_pages' => (int) $query->max_num_pages,
                'has_more'    => $page < (int) $query->max_num_pages,
            ] );
        } catch ( \Throwable $e ) {
            $this->log_internal_error( 'list jobs', $e );
            wp_send_json_error( [ 'message' => __( 'Could not load jobs. Please try again.', 'photo-to-product' ) ], $this->get_error_http_status( $e ) );
        }
    }


    /**
     * Handle nonce/capability checks for a settings-page form submission.
     */
    private function assert_settings_form_permission( string $action ): void {
        check_admin_referer( $action );
        if ( ! Permission::user_can_manage_settings() ) {
            wp_die( esc_html__( 'You are not allowed to perform this action.', 'photo-to-product' ), 403 );
        }
    }

    /**
     * Build the settings page URL with an optional status message payload.
     */
    private function get_settings_page_url( array $args = [] ): string {
        return $this->get_main_page_url( array_merge( [ 'tab' => 'connection' ], $args ) );
    }


    /**
     * Non-JavaScript fallback for installation registration from the settings page.
     */
    public function handle_register_installation_post(): void {
        $this->assert_settings_form_permission( 'aipi_register_installation_action' );

        $result = $this->ledger->registerInstallation();
        if ( empty( $result['success'] ) ) {
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                Logger::log( 'error', 'Installation registration failed (admin-post).', [ 'result' => $result ] );
            }
            $error_message = '';
            if ( ! empty( $result['message'] ) && is_string( $result['message'] ) ) {
                $error_message = $result['message'];
            } elseif ( ! empty( $result['code'] ) && is_string( $result['code'] ) ) {
                $error_message = $result['code'];
            }
            wp_safe_redirect( $this->get_settings_page_url( [
                'aipi_notice' => 'register_failed',
                'aipi_error'  => rawurlencode( $error_message ),
            ] ) );
            exit;
        }

        wp_safe_redirect( $this->get_settings_page_url( [
            'aipi_notice' => 'register_success',
        ] ) );
        exit;
    }


    /**
     * Disconnect the current installation from the managed backend and clear local credentials.
     */
    public function handle_disconnect_installation_post(): void {
        $this->assert_settings_form_permission( 'aipi_disconnect_installation_action' );

        $result = $this->ledger->disconnectInstallation();
        if ( empty( $result['success'] ) ) {
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                Logger::log( 'error', 'Installation disconnect failed (admin-post).', [ 'result' => $result ] );
            }
            $error_message = '';
            if ( ! empty( $result['message'] ) && is_string( $result['message'] ) ) {
                $error_message = $result['message'];
            } elseif ( ! empty( $result['code'] ) && is_string( $result['code'] ) ) {
                $error_message = $result['code'];
            }
            wp_safe_redirect( $this->get_settings_page_url( [
                'aipi_notice' => 'disconnect_failed',
                'aipi_error'  => rawurlencode( $error_message ),
            ] ) );
            exit;
        }

        Settings::update_many(
            [
                'customer_id'        => '',
                'installation_id'    => '',
                'installation_token' => '',
                'connection_status'  => 'not_connected',
                'paypal_client_id'   => '',
            ]
        );

        wp_safe_redirect( $this->get_settings_page_url( [
            'aipi_notice' => 'disconnect_success',
        ] ) );
        exit;
    }

    /**
     * Disconnect the current installation and immediately register a fresh installation.
     */
    public function handle_reregister_installation_post(): void {
        $this->assert_settings_form_permission( 'aipi_reregister_installation_action' );

        $existing_installation_id = (string) Settings::get( 'installation_id', '' );
        $existing_token           = (string) Settings::get( 'installation_token', '' );
        if ( '' !== trim( $existing_installation_id ) && '' !== trim( $existing_token ) ) {
            $disconnect = $this->ledger->disconnectInstallation();
            if ( empty( $disconnect['success'] ) ) {
                if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                    Logger::log( 'error', 'Installation disconnect failed during re-registration.', [ 'result' => $disconnect ] );
                }
                $error_message = '';
                if ( ! empty( $disconnect['message'] ) && is_string( $disconnect['message'] ) ) {
                    $error_message = $disconnect['message'];
                } elseif ( ! empty( $disconnect['code'] ) && is_string( $disconnect['code'] ) ) {
                    $error_message = $disconnect['code'];
                }
                wp_safe_redirect( $this->get_settings_page_url( [
                    'aipi_notice' => 'reregister_failed',
                    'aipi_error'  => rawurlencode( $error_message ),
                ] ) );
                exit;
            }
        }

        Settings::update_many(
            [
                'customer_id'        => '',
                'installation_id'    => '',
                'installation_token' => '',
                'connection_status'  => 'not_connected',
                'paypal_client_id'   => '',
            ]
        );

        $register = $this->ledger->registerInstallation();
        if ( empty( $register['success'] ) ) {
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                Logger::log( 'error', 'Installation re-registration failed (admin-post).', [ 'result' => $register ] );
            }
            $error_message = '';
            if ( ! empty( $register['message'] ) && is_string( $register['message'] ) ) {
                $error_message = $register['message'];
            } elseif ( ! empty( $register['code'] ) && is_string( $register['code'] ) ) {
                $error_message = $register['code'];
            }
            wp_safe_redirect( $this->get_settings_page_url( [
                'aipi_notice' => 'reregister_failed',
                'aipi_error'  => rawurlencode( $error_message ),
            ] ) );
            exit;
        }

        wp_safe_redirect( $this->get_settings_page_url( [
            'aipi_notice' => 'reregister_success',
        ] ) );
        exit;
    }


    /**
     * AJAX: Register this site installation with the configured backend.
     *
     * The backend should return a customer ID, installation ID and
     * installation token scoped to this site. These values are saved in
     * Settings. Only administrators (manage_options) can perform this
     * operation.
     */
    public function handle_register_installation(): void {
        $this->assert_ajax_permission();
        // Only administrators (manage_options) may register the site/customer. This operation
        // writes global site identifiers and should not be accessible to store managers.
        if ( ! Permission::user_can_manage_settings() ) {
            wp_send_json_error( [ 'message' => __( 'Only administrators can register this site.', 'photo-to-product' ) ], 403 );
        }
        $result = $this->ledger->registerInstallation();
        if ( empty( $result['success'] ) ) {
            // Do not surface backend error details directly. Log the result for debugging
            // and return a generic user‑facing message.
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                Logger::log( 'error', 'Installation registration failed.', [ 'result' => $result ] );
            }
            wp_send_json_error( [ 'message' => __( 'Could not register this installation. Please try again.', 'photo-to-product' ) ], 400 );
        }

        // Normalise the registration payload so the client receives the same
        // structure regardless of whether the backend returned a top-level
        // response shape or a nested data wrapper.
        $registration = [];
        if ( ! empty( $result['data'] ) && is_array( $result['data'] ) ) {
            $registration = $result['data'];
        } elseif ( is_array( $result ) ) {
            $registration = $result;
        }


        wp_send_json_success(
            [
                'result' => [
                    'customer_id'     => isset( $registration['customer_id'] ) ? (string) $registration['customer_id'] : '',
                    'installation_id' => isset( $registration['installation_id'] ) ? (string) $registration['installation_id'] : '',
                    'has_installation_token' => ! empty( $registration['installation_token'] ),
                ],
                'message' => __( 'Installation registered successfully.', 'photo-to-product' ),
            ]
        );
    }

    /**
     * AJAX: Reset the managed-mode installation connection for this site.
     *
     * Clears locally stored customer/installation identifiers and token so the
     * site can be safely re-registered from the settings page. This does not
     * delete or modify the remote vendor account; it only removes the saved
     * local association on this WordPress site.
     */
    public function handle_reset_installation(): void {
        $this->assert_ajax_permission();
        if ( ! Permission::user_can_manage_settings() ) {
            wp_send_json_error( [ 'message' => __( 'Only administrators can reset this site connection.', 'photo-to-product' ) ], 403 );
        }

        $confirm = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';
        if ( 'yes' !== $confirm ) {
            wp_send_json_error( [ 'message' => __( 'Reset confirmation was not provided.', 'photo-to-product' ) ], 400 );
        }

        Settings::update_many(
            [
                'customer_id'        => '',
                'installation_id'    => '',
                'installation_token' => '',
                'connection_status'  => 'not_connected',
                'paypal_client_id'   => '',
            ]
        );

        wp_send_json_success(
            [
                'message' => __( 'Installation connection reset successfully. You can register this site again at any time.', 'photo-to-product' ),
            ]
        );
    }

    /**
     * AJAX: Retrieve current balance from ledger. Does not expose secrets.
     */
    public function handle_get_balance(): void {
        $this->assert_ajax_permission();
        if ( Settings::MODE_MANAGED !== Settings::get_mode() ) {
            wp_send_json_success( [ 'balance' => null, 'mode' => Settings::get_mode() ] );
        }
        $result = $this->ledger->getBalance();
        if ( empty( $result['success'] ) ) {
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                Logger::log( 'error', 'Balance lookup failed.', [ 'result' => $result ] );
            }
            wp_send_json_error( [ 'message' => __( 'Could not retrieve balance.', 'photo-to-product' ) ], 400 );
        }
        wp_send_json_success( $result['data'] ?? [] );
    }

    /**
     * AJAX: Create a PayPal order for a managed credit pack.
     * This action is only available to administrators in managed mode.
     */
    public function handle_create_paypal_order(): void {
        $this->assert_ajax_permission();
        if ( ! Permission::user_can_manage_settings() ) {
            wp_send_json_error( [ 'message' => __( 'Only administrators can create PayPal orders.', 'photo-to-product' ) ], 403 );
        }

        $pack_id  = '';
        $raw_pack = $_POST['pack_id'] ?? $_POST['packId'] ?? null;
        if ( is_string( $raw_pack ) || is_numeric( $raw_pack ) ) {
            $pack_id = sanitize_key( wp_unslash( $raw_pack ) );
        }

        if ( '' === $pack_id ) {
            wp_send_json_error( [ 'message' => __( 'A credit pack selection is required.', 'photo-to-product' ) ], 400 );
        }

        try {
            $result = $this->ledger->createPayPalOrderFromPack( $pack_id );
        } catch ( \Throwable $e ) {
            $this->log_internal_error( 'create PayPal order', $e );
            $message     = $this->user_safe_error_message( $e, __( 'Could not create PayPal order. Please try again.', 'photo-to-product' ) );
            $status_code = $this->get_error_http_status( $e );
            wp_send_json_error( [ 'message' => $message ], $status_code );
        }
        if ( empty( $result['success'] ) ) {
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                Logger::log( 'error', 'PayPal order creation failed.', [ 'result' => $result ] );
            }
            wp_send_json_error( [ 'message' => __( 'Could not create PayPal order.', 'photo-to-product' ) ], 400 );
        }
        wp_send_json_success( $result['data'] ?? [] );
    }

    /**
     * AJAX: Validate the BYO OpenAI API key. Only administrators can test keys.
     *
     * This handler attempts to perform a minimal chat completion request
     * against the OpenAI API using the saved or constant-provided API key. If
     * the request succeeds (HTTP 200 and a JSON body with choices), the
     * response is considered valid. Otherwise an error is returned.
     */
    public function handle_test_byo_key(): void {
        $this->assert_ajax_permission();
        // Restrict this operation to administrators as it may expose whether
        // a key is valid. Store managers should not test keys.
        if ( ! Permission::user_can_manage_settings() ) {
            wp_send_json_error( [ 'message' => __( 'Only administrators can test API keys.', 'photo-to-product' ) ], 403 );
        }
        $key = Settings::get_byo_openai_key();
        if ( '' === $key ) {
            wp_send_json_error( [ 'message' => __( 'No BYO API key is configured.', 'photo-to-product' ) ], 400 );
        }
        // Use the OpenAIClient to validate the BYO key. Do not leak upstream
        // error details; return a generic success/failure message instead.
        $result = OpenAIClient::validate_api_key( $key );
        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success( [ 'message' => $result['message'] ] );
        }
        wp_send_json_error( [ 'message' => $result['message'] ], 400 );
    }

}
