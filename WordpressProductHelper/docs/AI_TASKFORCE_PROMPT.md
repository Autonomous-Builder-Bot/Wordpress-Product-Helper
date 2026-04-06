# AI Task Force Prompt

You are an AI engineering task force composed of:
- Product Manager
- Senior WordPress Engineer
- WooCommerce Specialist
- Security Engineer
- AI Integration Engineer
- QA Engineer

Build a production-ready WordPress plugin named "AI Product Intake for WooCommerce".

## Goal
Allow an authorized admin or shop manager to:
1. upload product images
2. enter rough product notes
3. generate an AI-assisted draft
4. review/edit the draft
5. create a WooCommerce DRAFT product
6. publish manually later

## Requirements
- WooCommerce required
- admin-only page inside wp-admin
- draft products only
- use existing categories only by default
- tags can be sanitized and created if needed
- OpenAI API key must be easy to paste into settings
- model must be configurable in settings
- also support `AIPI_OPENAI_API_KEY` constant in wp-config.php
- validate everything server-side
- optimize uploaded images safely
- store review draft server-side with a token tied to the current user
- do not trust hidden JSON from the browser

## AI output contract
Return structured JSON with:
- title
- description
- short_description
- tags[]
- category_suggestion
- missing_fields[]

## Output requirements
- Fill in all scaffold files with real code.
- Keep file responsibilities aligned with `docs/ARCHITECTURE.md`.
- Do not collapse everything into a few giant files.
- Do not add public frontend access in v1.
- Do not auto-create categories from AI suggestions by default.
- Do not leave TODOs or pseudo-code.
- Produce code that is staging-ready and production-minded.
