<?php
namespace AIPI;

/**
 * Renders the Create Product admin tab.
 */
class AdminCreateProductTab {
    /**
     * @param bool   $is_ready
     * @param string $connection_url
     */
    public static function render( bool $is_ready, string $connection_url ): void {
        ?>
        <div class="aipi-hero-card">
            <div>
                <span class="aipi-eyebrow"><?php esc_html_e( 'Create Product', 'photo-to-product' ); ?></span>
                <h2><?php esc_html_e( 'Create a WooCommerce product draft', 'photo-to-product' ); ?></h2>
                <p><?php esc_html_e( 'Start with one product, or use Bulk Create for a larger listing session.', 'photo-to-product' ); ?></p>
            </div>
            <div class="aipi-inline-actions">
                <button class="button button-primary button-hero" id="aipi-pro-create-job"><?php esc_html_e( 'Create Product', 'photo-to-product' ); ?></button>
                <button type="button" class="button" id="aipi-open-bulk-create"><?php esc_html_e( 'Bulk Create', 'photo-to-product' ); ?></button>
            </div>
        </div>
        <?php if ( ! $is_ready ) : ?>
            <div class="notice notice-warning inline aipi-inline-notice">
                <p>
                    <?php esc_html_e( 'Finish your connection before you create products.', 'photo-to-product' ); ?>
                    <a href="<?php echo esc_url( $connection_url ); ?>"><?php esc_html_e( 'Open Connection', 'photo-to-product' ); ?></a>
                </p>
            </div>
        <?php endif; ?>
        <details class="aipi-card aipi-bulk-card" id="aipi-bulk-panel">
            <summary>
                <span class="aipi-eyebrow"><?php esc_html_e( 'Bulk Create', 'photo-to-product' ); ?></span>
                <strong><?php esc_html_e( 'Create several product drafts from one photo batch', 'photo-to-product' ); ?></strong>
            </summary>
            <div id="aipi-bulk-create">
                <div class="aipi-bulk-toolbar">
                    <input type="file" id="aipi-bulk-files" accept="image/*" multiple />
                    <button type="button" class="button" id="aipi-bulk-add-group"><?php esc_html_e( 'Add Product Group', 'photo-to-product' ); ?></button>
                    <button type="button" class="button button-primary" id="aipi-bulk-process"><?php esc_html_e( 'Create Draft Products', 'photo-to-product' ); ?></button>
                    <button type="button" class="button-link" id="aipi-bulk-reset"><?php esc_html_e( 'Reset', 'photo-to-product' ); ?></button>
                </div>
                <p class="description"><?php esc_html_e( 'Upload a batch of photos, assign every photo to a product group, add short notes, then create one WooCommerce draft per group.', 'photo-to-product' ); ?></p>
                <div id="aipi-bulk-summary" class="aipi-card aipi-subcard"></div>
                <div id="aipi-bulk-progress"></div>
                <div id="aipi-bulk-results"></div>
                <div id="aipi-bulk-groups" class="aipi-grid aipi-grid-2"></div>
            </div>
        </details>
        <div class="aipi-section-head">
            <h2><?php esc_html_e( 'Recent Drafts', 'photo-to-product' ); ?></h2>
            <p><?php esc_html_e( 'Open a draft, add notes, generate a product description, and create a WooCommerce draft when it looks right.', 'photo-to-product' ); ?></p>
        </div>
        <div id="aipi-pro-job-list"></div>
        <?php
    }
}
