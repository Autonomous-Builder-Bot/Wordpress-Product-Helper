# Photo to Product Security Model

This document details the security measures implemented in the plugin and
explains how to harden or extend them. Security is a first‑class concern
because the plugin accepts file uploads and calls external APIs.

## Capabilities

The plugin uses the following capability model:

* **Base Capability** – Users must have the `manage_woocommerce` capability to access any plugin functionality. This capability is typically granted to the **Shop Manager** and **Administrator** roles in WooCommerce.
* **Administrator Override** – Users with the `manage_options` capability are considered administrators and may access any job, regardless of ownership. This override uses a capability rather than a role name. Directly checking roles (e.g. `'administrator'`) is discouraged.

These checks are centralised in `AIPI\Permission`.

## Nonces

All AJAX requests include a nonce token. This token is generated when admin
scripts are enqueued and verified on the server. Nonce verification is
performed in `Plugin::assert_ajax_permission()` before any business logic
executes. A valid nonce is **not sufficient** to grant access; it must be
paired with the capability checks above.

## File Uploads

* Only image files are accepted. File type is validated using
  `wp_check_filetype_and_ext()` which inspects both the filename and the
  actual file contents. This prevents files with fake
  extensions from being uploaded. If the function determines the file is not a
  supported image MIME type, the upload is rejected.
* Each file must be less than 5 MB and at most ten files may be uploaded per
  request. In addition, there is a **global cap** of ten attachments per job
  across all uploads. Attempting to exceed this limit results in an error.
* Files are processed through `wp_handle_upload()` which sanitises file names
  and moves them into the uploads directory. Attachment metadata is generated
  with `wp_generate_attachment_metadata()` to ensure thumbnails and other sizes
  are available.
* Each attachment created by the plugin includes a `_aipi_job` meta value
  referencing the job ID. This provenance metadata is used during cleanup and
  audit to associate attachments with their originating job.

## External API Calls

* The plugin calls OpenAI’s Chat Completions API. The API key must be
  provided via the `AIPI_OPENAI_KEY` constant or filtered via
  `aipi_openai_api_key`.
* The API request is made via `wp_remote_post()`. Responses are decoded
  from JSON; HTTP errors and invalid responses throw exceptions.
* The model is instructed to return a JSON object conforming to a strict
  schema. The PHP code validates the presence of required keys before
  trusting the data.

## SaaS Backend Communication

Managed Mode communicates with the vendor Cloudflare Worker. Customers do not configure the backend URL, PayPal environment, PayPal client ID, or any global service secret in WordPress. The plugin uses a safer trust boundary for managed-mode billing:

* **Vendor backend URL** – The backend URL is fixed by the plugin and points to the vendor Cloudflare Worker. Customers cannot accidentally route billing or credit requests to another service.
* **Per-installation token** – When a site is registered, the backend returns a `customer_id`, an `installation_id` and an `installation_token`. These identifiers are scoped to the site and are used in HTTP headers for managed requests. Without a valid token the plugin refuses to contact the backend.
* **Registration step** – The `aipi_register_installation` AJAX action allows administrators to register the site. The plugin sends only the site URL and any existing identifiers. The backend responds with the identifiers and token, which are stored in the database. You can repeat this step to rotate tokens or reconcile existing records.
* **Token usage** – The plugin sends the `installation_id` and `installation_token` in request headers whenever it checks credit balance or logs usage. If either is missing or blank, managed requests are skipped locally and the user is informed that registration is required.
* **BYO mode** – In BYO API Key Mode the plugin does not call the backend at all. All ledger functions are short-circuited, and no usage data is tracked.

This design eliminates the need for a global ledger secret in customer WordPress installations and confines the impact of a compromised site to that site's token. You can revoke a token in your backend without affecting other installations. It also means that no service secrets are ever exposed to browser JavaScript.

## Data Sanitisation

* All untrusted strings (e.g. AI generated content, filenames, form input)
  are passed through appropriate sanitisation functions (`sanitize_text_field`,
  `sanitize_file_name`, `wp_kses_post`, etc.) before being persisted or
  displayed.
* The admin JavaScript escapes HTML when rendering listing fields to
  mitigate XSS.

## Ownership Checks

Users can only view or mutate their own jobs. The only exception is
administrators (`manage_options`) who can manage all jobs. Ownership is
enforced on every AJAX handler via `Permission::user_can_access_job()`.

## Recommended Hardening

* **HTTPS** – Ensure your WordPress site uses HTTPS. API calls to OpenAI
  already enforce TLS, but the admin interface should also be served over
  HTTPS to protect cookies and nonces.
* **API Keys** – Store your OpenAI API key outside of version control and
  never expose it in client‑side code. Ideally define it in `wp-config.php`
  as `define('AIPI_OPENAI_KEY', 'sk-...');` or provide it via a secure
  environment variable and filter.
* **Rate Limiting** – The plugin does not impose its own rate limits on the
  OpenAI API. Consider adding rate limiting via middleware or additional
  hooks if you expect high usage.
* **Logging** – Enable logging of errors and unexpected states. WordPress’s
  built‑in error logging can be leveraged via `error_log()` or custom
  logging strategies.