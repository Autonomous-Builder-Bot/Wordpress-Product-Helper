# Architecture and File Responsibilities

## Folder map

```text
ai-product-intake/
├── ai-product-intake.php
├── uninstall.php
├── readme.txt
├── assets/
│   ├── css/
│   │   └── admin.css
│   └── js/
│       └── admin.js
├── includes/
│   ├── class-aipi-plugin.php
│   ├── class-aipi-capabilities.php
│   ├── class-aipi-admin.php
│   ├── class-aipi-settings.php
│   ├── class-aipi-draft-store.php
│   ├── class-aipi-validator.php
│   ├── class-aipi-media.php
│   ├── class-aipi-openai.php
│   ├── class-aipi-prompt-builder.php
│   ├── class-aipi-ai-normalizer.php
│   ├── class-aipi-product-factory.php
│   ├── class-aipi-taxonomy.php
│   ├── class-aipi-logger.php
│   ├── class-aipi-utils.php
│   └── class-aipi-admin-notices.php
└── templates/
    ├── intake-page.php
    ├── review-page.php
    ├── settings-page.php
    ├── partial-errors.php
    ├── partial-image-list.php
    └── partial-missing-fields.php
```

## Main rules
- Keep admin flow logic in `class-aipi-admin.php`.
- Keep OpenAI transport in `class-aipi-openai.php`.
- Keep prompts separate in `class-aipi-prompt-builder.php`.
- Keep AI output normalization in `class-aipi-ai-normalizer.php`.
- Keep validation in `class-aipi-validator.php`.
- Keep WooCommerce product creation in `class-aipi-product-factory.php`.
- Keep taxonomy policy in `class-aipi-taxonomy.php`.
- Keep image processing in `class-aipi-media.php`.
- Keep transient draft storage in `class-aipi-draft-store.php`.

## Security boundaries
- Hidden fields are transport only.
- Draft token must be tied to current user.
- Final product creation must revalidate everything server-side.
- Categories must resolve to existing WooCommerce categories by default.
- Uploaded image attachments must be revalidated before product creation.
