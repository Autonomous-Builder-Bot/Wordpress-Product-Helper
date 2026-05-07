<?php
namespace AIPI;

/**
 * Handles dismissible admin notices for setup and connection actions.
 */
class AdminNotices {
    /** @var callable */
    private $main_page_url_callback;

    /**
     * @param callable $main_page_url_callback Returns the main plugin page URL.
     */
    public function __construct( callable $main_page_url_callback ) {
        $this->main_page_url_callback = $main_page_url_callback;
    }

    /**
     * Render notices passed back from admin-post redirects.
     */
    public function render_settings_page_notices(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( 'photo-to-product' !== $page ) {
            return;
        }

        $notice = isset( $_GET['aipi_notice'] ) ? sanitize_key( wp_unslash( $_GET['aipi_notice'] ) ) : '';
        if ( '' === $notice ) {
            return;
        }

        $error_message = isset( $_GET['aipi_error'] ) ? sanitize_text_field( wp_unslash( $_GET['aipi_error'] ) ) : '';

        switch ( $notice ) {
            case 'register_success':
                $class   = 'notice notice-success is-dismissible';
                $message = __( 'Installation registered successfully.', 'photo-to-product' );
                break;
            case 'register_failed':
                $class   = 'notice notice-error';
                $message = '' !== $error_message ? sprintf( __( 'Registration failed: %s', 'photo-to-product' ), $error_message ) : __( 'Registration failed. Check the plugin logs for more details.', 'photo-to-product' );
                break;
            case 'disconnect_success':
                $class   = 'notice notice-success is-dismissible';
                $message = __( 'Installation disconnected successfully.', 'photo-to-product' );
                break;
            case 'disconnect_failed':
                $class   = 'notice notice-error';
                $message = '' !== $error_message ? sprintf( __( 'Disconnect failed: %s', 'photo-to-product' ), $error_message ) : __( 'Disconnect failed. Check the plugin logs for more details.', 'photo-to-product' );
                break;
            case 'reregister_success':
                $class   = 'notice notice-success is-dismissible';
                $message = __( 'This site was disconnected and registered again successfully.', 'photo-to-product' );
                break;
            case 'reregister_failed':
                $class   = 'notice notice-error';
                $message = '' !== $error_message ? sprintf( __( 'Re-registration failed: %s', 'photo-to-product' ), $error_message ) : __( 'Re-registration failed. Check the plugin logs for more details.', 'photo-to-product' );
                break;
            default:
                return;
        }

        printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
    }

    /**
     * Show a one-time setup notice after activation.
     */
    public function maybe_show_setup_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! get_option( 'aipi_pro_show_setup_notice' ) ) {
            return;
        }

        $dismiss_url = wp_nonce_url( add_query_arg( [ 'aipi_pro_dismiss_setup_notice' => 1 ], admin_url() ), 'aipi_pro_dismiss_setup_notice' );
        $setup_url   = call_user_func( $this->main_page_url_callback, [ 'tab' => 'connection' ] );

        echo '<div class="notice notice-info aipi-pro-setup-notice" data-dismissible="true">';
        echo '<p>' . esc_html__( 'Photo to Product is almost ready. Choose BYO mode or Managed Mode in Settings to start creating products.', 'photo-to-product' ) . ' ';
        echo '<a href="' . esc_url( $setup_url ) . '" class="button button-primary" style="margin-right:8px;">' . esc_html__( 'Set Up Now', 'photo-to-product' ) . '</a>';
        echo '<a href="' . esc_url( $dismiss_url ) . '" style="margin-left:8px;">' . esc_html__( 'Dismiss', 'photo-to-product' ) . '</a>';
        echo '</p></div>';
    }

    /**
     * Clear the setup notice flag after a verified dismissal request.
     */
    public function maybe_handle_setup_notice_dismissal(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! isset( $_GET['aipi_pro_dismiss_setup_notice'], $_GET['_wpnonce'] ) ) {
            return;
        }

        $nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
        if ( wp_verify_nonce( $nonce, 'aipi_pro_dismiss_setup_notice' ) ) {
            delete_option( 'aipi_pro_show_setup_notice' );
        }
    }
}
