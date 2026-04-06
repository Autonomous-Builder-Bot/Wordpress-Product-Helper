<?php
if ( empty( $template_errors ) || ! is_array( $template_errors ) ) {
    return;
}
?>
<div class="notice notice-error">
    <p><strong><?php echo esc_html__( 'Please fix the following issues:', 'ai-product-intake' ); ?></strong></p>
    <ul style="list-style: disc; margin-left: 18px;">
        <?php foreach ( $template_errors as $template_error ) : ?>
            <li><?php echo esc_html( $template_error ); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
