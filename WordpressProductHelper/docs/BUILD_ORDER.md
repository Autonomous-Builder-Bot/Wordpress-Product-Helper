# Recommended Build Order

1. Bootstrap and dependency checks
   - `ai-product-intake.php`
   - `class-aipi-plugin.php`
   - `class-aipi-capabilities.php`

2. Settings and config access
   - `class-aipi-settings.php`
   - `templates/settings-page.php`

3. Utilities and validation foundations
   - `class-aipi-utils.php`
   - `class-aipi-validator.php`
   - `class-aipi-admin-notices.php`

4. Draft storage
   - `class-aipi-draft-store.php`

5. Media handling
   - `class-aipi-media.php`
   - `templates/partial-image-list.php`

6. OpenAI integration
   - `class-aipi-prompt-builder.php`
   - `class-aipi-openai.php`
   - `class-aipi-ai-normalizer.php`

7. Admin workflow and pages
   - `class-aipi-admin.php`
   - `templates/intake-page.php`
   - `templates/review-page.php`
   - `templates/partial-errors.php`
   - `templates/partial-missing-fields.php`

8. WooCommerce creation logic
   - `class-aipi-taxonomy.php`
   - `class-aipi-product-factory.php`

9. Logging and admin polish
   - `class-aipi-logger.php`
   - `assets/css/admin.css`
   - `assets/js/admin.js`

10. Cleanup and packaging
   - `uninstall.php`
   - `readme.txt`
