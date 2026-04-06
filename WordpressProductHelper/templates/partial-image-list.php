<?php
if ( empty( $image_ids ) || ! is_array( $image_ids ) ) {
    return;
}
?>
<div class="aipi-image-grid" style="display:flex; flex-wrap:wrap; gap:16px; margin-top:12px;">
    <?php foreach ( $image_ids as $image_id ) : ?>
        <?php $thumb = wp_get_attachment_image_url( $image_id, 'thumbnail' ); ?>
        <div class="aipi-image-card" style="width:120px; text-align:center;">
            <?php if ( $thumb ) : ?>
                <img src="<?php echo esc_url( $thumb ); ?>" alt="" style="max-width:100%; height:auto; display:block; border:1px solid #ccd0d4; padding:4px; background:#fff;" />
            <?php endif; ?>
            <label style="display:block; margin-top:8px; font-size:12px;">
                <input type="checkbox" name="image_ids[]" value="<?php echo esc_attr( $image_id ); ?>" checked="checked" />
                <?php echo esc_html__( 'Keep', 'ai-product-intake' ); ?>
            </label>
        </div>
    <?php endforeach; ?>
</div>
