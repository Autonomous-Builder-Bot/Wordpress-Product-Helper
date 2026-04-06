<?php
?>
<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" enctype="multipart/form-data">
    <?php wp_nonce_field('aipi_generate_draft'); ?>
    <input type="hidden" name="action" value="aipi_generate_draft" />

    <table class="form-table">
        <tr>
            <th><label>Description</label></th>
            <td>
                <textarea name="description" rows="6" class="large-text" required></textarea>
            </td>
        </tr>

        <tr>
            <th><label>Images</label></th>
            <td>
                <input type="file" name="images[]" multiple accept="image/*" required />
            </td>
        </tr>

        <tr>
            <th><label>Price (optional)</label></th>
            <td>
                <input type="text" name="price" class="regular-text" />
            </td>
        </tr>

        <tr>
            <th><label>SKU (optional)</label></th>
            <td>
                <input type="text" name="sku" class="regular-text" />
            </td>
        </tr>
    </table>

    <p>
        <button type="submit" class="button button-primary">
            Generate Draft
        </button>
    </p>
</form>
