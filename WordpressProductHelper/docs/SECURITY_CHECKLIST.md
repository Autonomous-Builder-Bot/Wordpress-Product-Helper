# Security Checklist

- [ ] Plugin page exists only in wp-admin.
- [ ] Capability checks on every action.
- [ ] Nonces on every form action.
- [ ] Draft token tied to current user.
- [ ] Stored draft ownership checked before use.
- [ ] Uploaded files validated by actual image type, not extension only.
- [ ] Attachment IDs revalidated before product creation.
- [ ] Price validated as numeric and non-negative.
- [ ] SKU uniqueness checked before save.
- [ ] Category resolved to existing term only by default.
- [ ] AI output normalized and bounded.
- [ ] API errors handled cleanly.
- [ ] No hidden JSON trusted as source of truth.
- [ ] Logging does not dump secrets or huge payloads.
- [ ] Product created as draft only.
