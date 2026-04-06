# AI Product Intake for WooCommerce — AI Build Kit

This folder is a **scaffold + spec pack** for an AI coding system to turn into a real plugin.

## Goal
Build a WordPress admin-only plugin that lets an authorized user:
1. upload product images
2. enter rough product notes
3. generate an AI-assisted product draft
4. review and edit the draft
5. create a WooCommerce **draft** product
6. publish manually later in WooCommerce

## Non-negotiable rules
- WooCommerce is required.
- Admin-only workflow. No public frontend intake page in v1.
- Draft products only. Never auto-publish.
- Existing categories only by default. Do not auto-create categories from AI suggestions.
- Validate everything server-side.
- Never trust hidden form fields or AI output without revalidation.
- Optimize uploaded images safely.

## What this kit contains
- `docs/PROJECT_SUMMARY.md` — plain-English project summary
- `docs/ARCHITECTURE.md` — file map and responsibilities
- `docs/BUILD_ORDER.md` — recommended implementation order
- `docs/AI_TASKFORCE_PROMPT.md` — prompt to hand to a coding system
- `docs/CONNECTORS_AND_CONFIG.md` — external dependencies and settings
- `docs/DATA_CONTRACTS.md` — normalized data structures and expected shapes
- `docs/SECURITY_CHECKLIST.md` — security rules
- plugin scaffold files in `includes/`, `templates/`, and `assets/`

## Expected output from the coding system
Replace placeholder code with full production code while preserving the file structure and responsibilities.

## Suggested plugin slug
`ai-product-intake`

## Suggested capability
`manage_ai_product_intake`
