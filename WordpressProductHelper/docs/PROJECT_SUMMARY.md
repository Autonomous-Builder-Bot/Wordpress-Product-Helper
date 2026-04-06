# Project Summary

## One-line summary
Turn rough product notes and uploaded images into an editable AI-generated WooCommerce draft product.

## Core user flow
1. Admin opens plugin page inside WordPress admin.
2. Admin enters rough product description, optional title hint, optional price/SKU, and uploads images.
3. Plugin validates uploads and optimizes images.
4. Plugin sends normalized text input to OpenAI.
5. OpenAI returns structured JSON with:
   - title
   - description
   - short_description
   - tags[]
   - category_suggestion
   - missing_fields[]
6. Plugin normalizes AI output and stores a temporary draft server-side.
7. Admin reviews and edits the draft.
8. Plugin validates reviewed values and creates a WooCommerce **draft** product.
9. Admin publishes manually in WooCommerce later.

## Business value
- saves time on repetitive product entry
- improves consistency of product descriptions
- generates helpful tags automatically
- keeps a human in control before anything reaches the store

## In scope
- simple WooCommerce products
- admin-only workflow
- image upload and optimization
- OpenAI-assisted product copy generation
- review screen before creation
- WooCommerce draft product creation
- optional logging

## Out of scope for v1
- variable products
- shipping/dimensions logic
- frontend customer submission forms
- auto-publishing
- advanced SEO plugin integrations
- automatic category creation by default
- multi-store support
