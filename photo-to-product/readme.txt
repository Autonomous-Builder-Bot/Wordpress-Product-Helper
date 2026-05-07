=== Photo to Product ===
Contributors: aipi-team
Tags: WooCommerce, AI, product listings, product description, OpenAI
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn product photos or descriptions into WooCommerce draft product listings with AI. Upload images, describe your product, or provide both and receive a structured draft listing ready for review. Bulk Create lets you upload many photos, sort them into product groups, add short notes for each group, and create draft products in a queue. At least one input is required. The workflow runs from your WordPress admin, and API secrets are stored server-side only.

== Description ==

Photo to Product streamlines the creation of WooCommerce product listings by harnessing OpenAI’s large language models. Store managers can upload photos of an item, provide a short description or do both and let the plugin generate a complete draft product listing including title, descriptions, categories, tags, brand, condition and confidence notes. For larger listing sessions, Bulk Create lets merchants upload many photos first, assign them into product groups, add notes for each group, and then create one queued draft per group. You must provide at least one input (either photos or a description) for generation. The listing can be reviewed and then converted into a draft WooCommerce product ready for final editing and publication. Uploaded images are automatically optimised before being analysed by the AI.

This plugin is **free** and defaults to **BYO (Bring Your Own) API Key Mode**. In this mode you supply your own OpenAI API key via the settings page before generation can run. The key is stored server-side only and is never exposed to browser JavaScript. Generation costs are billed directly to your OpenAI account, and the plugin does not track usage or credits.

Optionally, after changing the account mode and clicking the connection button, you may use **Managed Mode**, where the plugin connects to the vendor Cloudflare Worker for managed generation and credit-backed billing. Each site connects once and receives installation credentials scoped to that site. During managed generation, the Worker handles reservation/finalization and returns the generated listing to the plugin. The plugin never stores any vendor backend secret. To enable this mode:

1. Open **WooCommerce → Photo to Product → Connection**.
2. Choose **Managed Mode**, save, then click **Connect to Managed Service**.
3. After connection, the plugin displays your credit balance and enables managed generation and credit purchases.

You can choose whether to include uploaded images when generating the listing. When disabled, the AI uses only the seller notes. When enabled, compressed derivatives of your photos are sent to the model for direct analysis.

When including images, the plugin performs a lightweight reachability check from your WordPress server. If none of your images can be fetched (for example, due to a staging site blocking external requests or signed URLs), the plugin falls back to text‑only generation when seller notes are provided. If no notes exist, it surfaces an error suggesting that you either make the images accessible or add seller notes and disable image analysis. The plugin does **not** claim to know whether the AI service itself can reach your images.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the ‘Plugins’ screen in WordPress.
3. Navigate to **WooCommerce → Photo to Product** in the admin menu to create products, manage connection settings, and review usage.
4. Open the **Connection** tab to set up BYO API Key Mode or Managed Mode:
    * Choose **BYO API Key Mode** to use your own OpenAI API key. Enter your key in the BYO field and save.
    * Choose **Managed Mode** to use vendor-managed credits. Save the settings and then click **Connect to Managed Service** (administrator only). The plugin will save the managed connection credentials for this site. PayPal configuration is supplied by the vendor Cloudflare Worker.

== Frequently Asked Questions ==

=== Does the plugin send my images to OpenAI? ===

When you enable the *Use images for AI* option and you have uploaded photos, the plugin sends optimised derivatives of those photos to the AI model. Optimisation reduces file size and dimensions to improve performance. If the option is unchecked, or if no photos are uploaded, the AI uses only your description to generate the listing.

=== What data is stored? ===

The plugin stores your jobs as a custom post type along with attachments, generated listing data, and any errors. You can delete jobs at any time. The settings page stores only the data you enter.

Deleting a job removes the job record but **does not** delete the uploaded media. Your photos remain in the WordPress Media Library so they can be reused or manually removed later. This intentional behaviour prevents accidental loss of images that might be used in published products.

== Privacy and External Requests ==

The plugin does not send data to OpenAI, the vendor managed service, or PayPal merely by being installed or activated. BYO mode contacts OpenAI only when an administrator tests the saved API key or generates a listing. Managed Mode contacts the vendor Cloudflare Worker only after an administrator selects Managed Mode and connects the site, or later uses managed generation, balance lookup, public checkout configuration, or credit purchase features. PayPal code is loaded only on the plugin admin billing screen in connected Managed Mode.

== Managed Service ==

In Managed Mode, the plugin sends identifying information (site URL, WordPress admin URL, plugin version, WooCommerce version, and WordPress version) to the vendor Cloudflare Worker when an administrator connects the site. After connection, managed-service requests send this site’s saved installation credentials and the operation context needed to complete the request. Managed generation and credit-backed billing are handled by the Worker. The plugin does **not** store or transmit vendor service secrets.

== External Services ==

This plugin connects to external services only after an administrator configures the relevant mode and triggers an action that requires that service. It does not contact these services on plugin activation. It never exposes API secrets in the browser.

* **OpenAI (`api.openai.com`):** Used in BYO mode to validate your saved API key and to generate listing content. When you click *Generate Draft* or *Test Key*, the plugin sends your product notes and, if image analysis is enabled, prepared copies of the uploaded product photos to the OpenAI API. OpenAI terms: https://openai.com/policies/terms-of-use/ OpenAI privacy policy: https://openai.com/policies/privacy-policy/

* **Vendor Cloudflare Worker (`ai-product-importer-worker.colindmcintyre.workers.dev`):** Used only in Managed Mode after an administrator selects Managed Mode and connects the site. It registers the site, retrieves balances, runs managed generation, returns public PayPal configuration, and creates PayPal orders through the vendor backend. During connection the plugin sends the site URL, WordPress admin URL, plugin version, WooCommerce version, and WordPress version. Subsequent managed-service requests send the saved installation credentials and the operation context needed to complete the request. Vendor privacy policy: https://autonomous-builder-bot.github.io/photo-to-product-site/privacy.html Vendor terms: https://autonomous-builder-bot.github.io/photo-to-product-site/terms.html

* **PayPal (`www.paypal.com`):** The plugin loads the PayPal checkout SDK only on the plugin's admin Billing & Usage tab, only in Managed Mode, and only after the site is connected. When you buy credits, the plugin requests an order from the vendor backend and then opens the PayPal checkout. The plugin does not store payment secrets locally. PayPal user agreement: https://www.paypal.com/us/legalhub/paypal/useragreement-full PayPal privacy statement: https://www.paypal.com/us/legalhub/paypal/privacy-full

Support and service contact page: https://autonomous-builder-bot.github.io/photo-to-product-site/support.html

== Changelog ==

= 1.7.1 =
* **Performance and UX improvements.** The job listing endpoint now returns only IDs and minimal metadata, sorting by last updated time rather than post ID. Listing data is included only for generated jobs, significantly reducing response size. The admin UI renders listing details only when available.
* **Improved attachment titles.** Uploaded media titles are now derived from the filename without the extension and formatted for readability, instead of using the raw sanitised filename.
* **Ordering by last updated.** Jobs are now sorted by their last updated timestamp, so recently modified jobs appear first in the admin list.
* **More resilient JavaScript.** The admin script now checks for empty listing objects before rendering listing details, preventing undefined output when no listing data is returned.
* **Version bump to 1.7.1.**

= 1.7.0 =
* **Centralised OpenAI client.** All API calls to OpenAI now use a dedicated `OpenAIClient` class that enforces strict transport options and normalises errors. This prevents code drift and ensures consistent error handling. BYO key validation also uses the same path as generation without exposing raw provider messages.
* **External services documentation.** Added an *External Services* section to the readme detailing how and when the plugin communicates with OpenAI, the vendor Cloudflare Worker and PayPal. Added privacy and terms links to the settings page.
* **Privacy and terms links.** The settings page now lists links to the plugin’s Privacy Policy, Terms of Service and support site. The Managed Mode description clarifies what is sent during registration and usage.
* **Version bump to 1.7.0.**

= 1.6.0 =
* **Stale job handling and retention.** Added a daily cleanup cron that deletes completed or failed jobs older than 30 days (filterable via the `aipi_job_retention_days` filter) and marks jobs stuck in generation or product creation for more than two hours as failed, releasing locks.
* **Job timestamps and retry flag.** Jobs now record their creation and last updated times. These timestamps are exposed via the job listing API and shown in the admin UI. Failed jobs include a `canRetry` flag indicating whether they may be reset for generation.
* **BYO key testing.** Administrators can validate the saved OpenAI API key directly from the settings page using a new **Test API Key** button. The result of the test is displayed inline without reloading the page.
* **Diagnostics improvements.** The settings page now includes a diagnostics table summarising environment checks such as WooCommerce activation, account mode, key availability, backend URL configuration, registration completeness and PayPal configuration.
* **Enhanced HTTP request hardening.** Managed backend and OpenAI calls now enforce stricter request options, limiting redirects, rejecting unsafe URLs, verifying SSL and explicitly using HTTP/1.1.
* **Daily cleanup scheduling.** Activation schedules the daily cleanup event and deactivation unschedules it to prevent orphaned cron jobs.
* **Job metadata maintenance.** Jobs update their last modified timestamp whenever metadata or status changes. Creation timestamps are stored when jobs are first created.
* **Version bump to 1.6.0.**

= 1.5.5 =
* **Settings durability.** Saving the settings page with a blank BYO API Key field no longer clears the stored key. A new checkbox has been added to explicitly remove the saved key when desired. This prevents accidental key loss when adjusting other settings.
* **Log management.** Administrators can now clear recent plugin logs directly from the settings page. A checkbox on the settings screen wipes logs when the form is saved.
* **Uninstall hygiene.** The uninstall script now deletes the stored recent logs option (`aipi_recent_logs`) to avoid leaving orphaned data behind.
* **Backend call cleanup.** Removed double-encoding of the `action` query parameter in managed backend requests to prevent potential routing issues.
* **Activation hooks.** Added activation and deactivation hooks that record the plugin version and flush rewrite rules. This lays the groundwork for future migrations and ensures custom post type rules are registered on activation.

= 1.5.4 =
* **Taxonomy bug fix.** Resolved an issue where existing product tags could trigger PHP notices because `term_exists()` returns IDs rather than names. Tag names are now used consistently when building assignments.
* **Upload concurrency control.** Added a per-job upload lock to prevent concurrent upload requests from racing the attachment quota. The upload lock is refreshed during long operations and released reliably.
* **Uninstall pagination fix.** The uninstall routine now repeatedly queries page 1 to delete jobs and attachments, ensuring no records are skipped when new items are removed mid-loop.
* **Image host allowlist improvement.** Reachability checks now allow both the site domain and the upload domain by default, making the plugin compatible with offloaded media setups. The allowlist remains filterable.
* **Version bump and minor refactoring.** Bumped the plugin to version 1.5.4 and refactored the upload logic for clarity and reliability.

= 1.5.3 =
* **Security and reliability improvements.** Derivative images are now stored in a dedicated `aipi-ai` sub‑directory within uploads. File names include the attachment ID and a customisable suffix, and cleanup only touches files in this directory. This prevents accidental deletion of unrelated files.
* **Safer deletion on uninstall.** The uninstall script now removes derivative files and cleans up provenance metadata on attachments. Jobs and attachments are deleted in batches to avoid memory issues on large sites.
* **Translation readiness.** Added a call to `load_plugin_textdomain()` to load translations from a `languages` directory.
* **Administrative control on billing.** Only administrators (`manage_options`) may create PayPal orders via the AJAX API.
* **Reachability check improvements.** The fallback GET request used when verifying image reachability now limits the response size to a single kilobyte, avoiding large downloads.
* **Reset cleans all state.** Resetting a job now clears prepared images, generation context and description metadata to ensure fresh retries.
* **Documentation fixes.** FAQ headings use proper markdown and the changelog order has been corrected in this release.

= 1.5.1 =
* **Improved truthfulness and error handling.** Introduced a unified internal error taxonomy (`AipiException`) with machine‑readable codes. Controllers now map codes to HTTP status codes and user‑friendly messages without relying on substring matching.
* **Trustworthy retry UX.** Replaced the misleading “Retry” button with **Reset for generation**. When a job fails, clicking this button simply resets the job to `ready_for_generation`; it does not automatically restart AI generation. The admin notice now reads “Job reset. You can generate again.”
* **Accurate deletion wording.** Updated docs and UI to clarify that deleting a job removes only the job record. Uploaded images remain in the Media Library and must be removed manually if desired.
* **Lock release fix.** Fixed a bug where a thrown exception before the creating_product state transition could leave a lock unreleased, causing jobs to appear stuck. The lock is now released in all cases.
* **Image preflight honesty.** Modified the image reachability check to avoid overclaiming that the AI cannot access images. If no images are reachable from your server and you have not provided seller notes, the plugin now suggests adding notes or making images public. When notes exist, it falls back to text‑only generation.
* **Pagination added.** Job listing endpoint now accepts `page` and `per_page` parameters (default 1 and 20) and never fetches more than 100 jobs per request. This prevents performance issues on stores with many jobs.
* **Attachment retention explicit.** The deletion flow and documentation make it clear that attachments are not automatically removed when a job is deleted.
* **State machine exceptions.** Invalid status transitions now throw `AipiException` with code `invalid_state` for consistent error handling.

= 1.4.0 =
* Description and photos are now optional. A generation request may include only text, only photos or both. At least one input is required.
* Added early validation to return a clear error when no description or photos are provided.
* Introduced automatic optimisation of uploaded images before sending them to the AI. Compressed derivatives are used in multimodal requests.
* Added support for image‑only and text‑only generation modes. Prompts are built according to the selected mode.
* Hardened OpenAI response parsing with stricter shape checks and better error messages.
* Centralised listing validation with stronger business‑rule enforcement.
* Refactored controller logic into a dedicated workflow service for easier testing and maintenance.
* Added warnings when categories cannot be assigned instead of degrading unknown categories into tags.
* Improved failure handling so that cleanup does not mask original errors and recovery is idempotent.
* Updated state machine to allow moving from draft directly to ready for generation without uploading photos.
* Updated documentation and UI to reflect optional inputs and automatic image optimisation. Attachments are left untouched on uninstall.

= 1.5.0 =
* **Managed Mode update.** Managed Mode uses the vendor Cloudflare Worker and a per-site installation token. Use **Connect to Managed Service** to connect the site.
* **BYO by default.** The plugin now defaults to BYO API Key Mode for maximum privacy. Managed Mode is optional.
* **Registration flow update.** Added the `aipi_register_installation` AJAX action for the managed connection flow.
* **Documentation update.** Refreshed account mode and secret-handling guidance throughout the plugin and readme.

= 1.3.0 =
* Added option to include or exclude images in AI generation.
* Improved validation of AI responses and normalisation of categories and tags.
* Enhanced error handling with safer messages and server‑side logging.
* Added uninstall script to clean up jobs and settings on deletion. Attachments are intentionally left in place to avoid deleting images used by products.
* Updated plugin header and readme for clarity and compliance.

= 1.2.0 =
* Initial release of Photo to Product (managed SaaS with BYO mode).
