# Connectors and Config

## Required platform connectors/dependencies

### WordPress
- Plugin runs inside WordPress admin.
- Requires modern WordPress environment with standard admin, media, and options APIs.

### WooCommerce
- Required dependency.
- Products must be created as `WC_Product_Simple` drafts.

### OpenAI API
- Required for AI draft generation.
- Settings page must include:
  - API key input
  - model input or selector
  - optional test connection action
- Support config constant:
  - `AIPI_OPENAI_API_KEY`

## Suggested settings
- API key
- model
- max image upload size (MB)
- max image dimensions (px)
- JPEG/WebP compression quality
- logging enabled
- draft TTL (minutes)
- allow manual category creation (default false)

## Suggested default model handling
- editable text field in settings
- optional presets list
- do not hard-code a single model forever

## Suggested WordPress roles/capabilities
- capability: `manage_ai_product_intake`
- grant to administrators
- optionally grant to shop managers
