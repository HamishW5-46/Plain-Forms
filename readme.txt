=== Plain Forms ===
Contributors: hamishwright
Tags: contact, forms, enquiries, turnstile, gutenberg
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.13.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Forms, plain and simple. A free, no-subscription contact and enquiry form builder with the features you need.

== Description ==

Forms, plain and simple. A free, no-subscription WordPress form builder for contact and enquiry forms, with the features you actually need, without the premium price tag.

Plain Forms is a lightweight form builder for WordPress focused on ordinary contact and enquiry forms.

It provides a polished React-powered form builder in wp-admin while keeping frontend forms server-rendered, accessible and independent of JavaScript frameworks.

Plain Forms includes:

* React-powered visual form builder using native WordPress components.
* Dynamic Gutenberg block with server-side rendering.
* Shortcode support.
* Asynchronous form submission without page reload.
* Non-JavaScript POST fallback when Cloudflare Turnstile is not required.
* Stored submissions with search, filtering and status management.
* CSV export with spreadsheet formula-injection protection.
* Email notifications and optional submitter receipts.
* Resend support for failed or previously stored notifications.
* Cloudflare Turnstile integration.
* Honeypot and optional timing-based spam protection.
* Configurable rate limiting.
* Submission retention controls.
* WordPress personal data export and erasure integration.
* Privacy-conscious IP hashing with no raw IP storage.
* No jQuery dependency.
* No runtime Composer or Node.js dependencies.

Supported fields include:

* Text
* Email
* Telephone
* Textarea
* Select
* Radio
* Checkbox
* Checkbox group
* Hidden
* Consent

Notification failures do not discard enquiries. When submission storage is enabled, the enquiry is stored before email delivery is attempted so administrators can review and resend failed notifications.

Team notifications and optional submitter receipts can use independently configured HTML templates. Theme and child-theme PHP template overrides are also supported for developers who need complete control over email markup.

Plain Forms deliberately focuses on contact and enquiry forms rather than attempting to reproduce every advanced feature of larger commercial form platforms.

== Installation ==

1. Install and activate Plain Forms.
2. Open Plain Forms > Settings.
3. Review the default recipient, sender and submission storage settings.
4. If using Cloudflare Turnstile, configure the site key, secret key, expected hostname and optional action before enabling it.
5. Open Plain Forms > Forms and create a form.
6. Add fields and configure notifications, Reply-To behaviour, confirmation messages, storage and spam protection.
7. Insert the Plain Forms block into a page or post and select the form.

A shortcode is also available:

`[plainforms_form id="123"]`

Forms reference their stored form definition, so changes to a form automatically appear wherever that form is used.

Before publishing a new form, test validation, spam protection, stored submissions and email delivery.

== Cloudflare Turnstile ==

Cloudflare Turnstile support is optional.

When enabled, configure:

* Site key
* Secret key
* Expected hostname
* Optional expected action

The stored Turnstile secret is never returned to the browser or exposed through REST responses.

A blank secret field preserves the existing stored secret. Removing the secret requires an explicit action.

Turnstile verification occurs server-side using Cloudflare's Siteverify service.

When Turnstile is enabled, the plugin loads Cloudflare's Turnstile JavaScript from `challenges.cloudflare.com`. Visitors' browsers communicate with Cloudflare to complete security checks, which may involve processing IP addresses, browser information, and other security-related signals.

Turnstile response tokens are submitted to Cloudflare's Siteverify API for server-side validation.

Cloudflare Privacy Policy: https://www.cloudflare.com/privacypolicy/

Cloudflare Terms: https://www.cloudflare.com/website-terms/

== Email Notifications ==

Plain Forms supports:

* Team notification emails.
* Optional submitter receipts.
* Multiple notification recipients.
* Configurable subjects.
* Reply-To using a validated email field.
* HTML and plaintext alternatives.
* Field and system placeholders.
* Per-form notification configuration.
* Theme and child-theme PHP template overrides.
* Resending stored notifications without creating duplicate submissions.

Submissions are stored before notification delivery is attempted when storage is enabled.

If notification delivery fails, the enquiry remains available in wp-admin and can be resent later.

== Submissions ==

Stored submissions can be:

* Viewed
* Searched
* Filtered
* Marked read or unread
* Archived
* Marked as spam
* Restored
* Permanently deleted
* Exported as CSV

Notification delivery status is tracked separately from submission status.

CSV exports protect values that could otherwise be interpreted as formulas by spreadsheet software.

== Privacy ==

Form definitions are stored as private WordPress posts.

Stored enquiries may contain:

* Accepted field values.
* A snapshot of field labels, field types and form title.
* UTC submission timestamps.
* Submission status.
* Notification delivery status.
* Generic internal delivery-failure information.

Optional connection metadata may include:

* A site-keyed hash derived from the source IP address.
* User agent.
* Referring origin and path.

Plain Forms does not store raw IP addresses in its submissions table.

IP hashing and source-based rate limiting are enabled by default. User agent and referrer storage are disabled by default.

Only the direct connection IP is trusted. Forwarded proxy headers are not used to determine the visitor address.

Disabling IP hashing also disables source-based rate limiting.

Rate-limit records are temporary.

Default submission retention is 365 days. A retention period of zero means submissions are retained indefinitely.

Automatic cleanup runs in bounded batches through WP-Cron. Sites using low-traffic or externally managed cron environments should ensure WordPress scheduled events are executed reliably.

Plain Forms integrates with WordPress personal data export and erasure tools. Stored enquiries can be located using an exact email address, including addresses stored in custom email fields.

Email copies, downloaded CSV exports and backups have separate retention policies and must be managed independently.

When Cloudflare Turnstile is enabled, the visitor's verification token is sent to Cloudflare's Siteverify service over HTTPS. Plain Forms does not send the raw IP address using Turnstile's `remoteip` parameter.

Site owners should describe relevant form and Turnstile processing in their privacy policy.

The Turnstile secret is stored in WordPress options and is not encrypted at rest. Database and backup access should therefore be protected using normal WordPress and hosting security controls.

== Accessibility ==

Plain Forms aims for WCAG 2.2 AA-friendly behaviour.

Frontend forms use semantic labels, required-state information, accessible status messages and field-associated validation errors.

The form builder and settings interface are designed for keyboard navigation and use native WordPress interface components where practical.

== Lifecycle and Data ==

Activation:

* Creates or verifies the site-prefixed submissions table.
* Creates non-autoloaded default settings.
* Grants Plain Forms capabilities to administrators.
* Records plugin and database schema versions.

Plain Forms does not support network activation. On multisite installations, activate it independently for each site.

Deactivation preserves forms, settings, submissions and capabilities while clearing scheduled cleanup events.

Uninstall preserves data by default.

If "Delete all plugin data on uninstall" has explicitly been enabled for a site, uninstall removes that site's:

* Forms and related metadata.
* Form revisions.
* Submission table.
* Plugin settings.
* Version information.
* Plugin capabilities.
* Stored rate-limit records.

Multisite uninstall respects each site's own setting.

== Frequently Asked Questions ==

= Is Plain Forms intended to replace premium form plugins? =

No. Plain Forms focuses on ordinary contact and enquiry forms.

It does not currently attempt to provide advanced features such as payments, surveys, quizzes, user registration, file uploads, multipage forms, complex conditional logic or CRM integrations.

= Does Plain Forms require JavaScript? =

The wp-admin form builder requires JavaScript.

Frontend forms use JavaScript for asynchronous submission and enhanced behaviour, but a normal POST fallback is available when Cloudflare Turnstile is not required.

= Does Plain Forms store submissions? =

Yes, when submission storage is enabled.

Storage is enabled by default and is recommended because it provides a fallback if email delivery fails.

= Does Plain Forms store visitor IP addresses? =

Not as raw IP addresses.

When enabled, Plain Forms derives a site-keyed hash for source-based rate limiting and abuse detection.

= Does Plain Forms require jQuery? =

No.

= Can I customise notification emails? =

Yes.

Plain Forms supports independently configured HTML templates for team notifications and submitter receipts, plus PHP template overrides from the active theme or child theme.

== Changelog ==

= 0.13.0 =

* Added a live Turnstile configuration test in Settings > Spam protection using entered values or the saved secret.
* Added immediate expected-hostname warnings, including www differences, and specific authenticated verification feedback.
* Configuration tests never save settings or submit enquiries; editing a verification field invalidates the result.


= 0.12.2 =

* Clear stale verification errors when a fresh Turnstile challenge succeeds, preserving answers and unrelated field errors.
* Add optional fixed diagnostic codes for server-side Turnstile failures without logging secrets, tokens or provider details.


= 0.12.1 =

* Accept valid boolean settings without falling through into text validation.
* Cover full settings saves with the uninstall toggle both enabled and disabled.
* Improve mobile Forms list layout and empty-state display.

= 0.12.0 =

* Development identity refactor to Plain Forms with fresh staging settings and storage.
* Updated block, shortcode, template override paths, styling and development tooling.
* Database schema structure remains version 2; previous staging data is not migrated.

= 0.11.1 =

* Clarified output escaping and request input boundaries.
* Prepared SQL identifiers and documented intentional custom-table queries.
* Isolated theme-template diagnostics without changing PHP error reporting.
* Added clean reproducible release packaging and Plugin Check review documentation.

= 0.11.1 =

* Added independent HTML templates for team notifications and optional submitter receipts.
* Added built-in sanitized HTML editing and PHP theme/child-theme template overrides.
* Added escaped field and system placeholders, private sample previews, and HTML/plaintext alternatives.
* Added receipt field, subject and Reply-To settings with separate delivery status and explicit resend support.
* Added additive database schema version 2 while preserving existing data.
* Existing forms retain plaintext notifications and receipts remain disabled until configured.

= 0.9.0 =

* Added React form builder with field toolbox, canvas, property inspector and structured choice editor.
* Added field duplication, reordering and undo/redo.
* Added starter contact form template and desktop/mobile preview.
* Added asynchronous form saving with failure recovery.
* Added React settings and dashboard interfaces using WordPress-provided components.
* Added private capability-protected REST save routes.
* Ensured stored Turnstile secrets never enter React bootstrap or REST response data.
* Added additional WordPress endpoint, browser and accessibility regression checks.
* Included the prebuilt admin bundle. React is supplied by WordPress and esbuild is development-only.

= 0.8.1 =

* Fixed hidden Add/Edit Form and submission detail admin routing while retaining capability checks.
* Added regression tests for WordPress page authorisation and callback resolution.

= 0.8.0 =

* Added form editor, schema, dynamic Gutenberg block and server-side renderer.
* Added REST and normal POST submission pipelines.
* Added frontend JavaScript enhancement.
* Added Turnstile, honeypot, timing signatures and keyed rate limiting.
* Added notification failure handling and resend support.
* Added submissions administration, filtered CSV export, dashboard and settings.
* Added retention, privacy integration and opt-in destructive uninstall.
* Added unit, integration, browser, accessibility and static checks.

= 0.1.0 =

* Initial storage, settings, capability and lifecycle foundations.

== Turnstile configuration test ==

In Settings > Spam protection, enter the site key, secret, optional expected hostname
and action, then select Test Turnstile configuration. Complete the test challenge; the
server will verify its token with the same checks used by public forms and report any
credential, hostname, action or connection problem. Save settings separately after testing.
Leaving the secret blank tests the existing saved secret without revealing it in the browser.
Changing a verification setting clears the previous test result.

The test runs on the dashboard hostname shown in the panel. If your administration and
public forms use different hostnames, open the dashboard on the public hostname for an
exact hostname check. The public website hostname is shown as a guide, with immediate
warnings when the configured expected hostname differs. Alternative form domains may be
intentional, so the warning does not silently change settings or disable validation.

Running the test explicitly loads Cloudflare's hosted script even if default Turnstile
protection is disabled. Only authorised settings administrators can use the endpoint.
The test does not save configuration, create a submission or send notification emails.

== Turnstile troubleshooting ==

A green Turnstile widget confirms the browser challenge. The server must still validate
its token using Cloudflare Siteverify. Tokens expire after five minutes and can be used
only once. A failed submission refreshes the challenge; submit again after it succeeds.

If every attempt fails, confirm that the site key and secret key belong to the same
Cloudflare widget and that the widget permits the public site's hostname. Configured
expected hostname/action values must match the rendered form and Siteverify response.
After changing these settings, purge cached form pages so they use the current site key
and action. Keep server-side hostname/action validation enabled when configured.

To diagnose a server rejection, temporarily enable operational debug logging under
Plain Forms > Settings > Advanced, reproduce once and inspect the site's PHP error log
(or WordPress debug log when configured). Disable logging again after troubleshooting.
Only fixed event codes are logged, never keys, tokens, form values or provider messages:

* turnstile_secret_invalid: Cloudflare rejected the configured secret.
* turnstile_not_configured: required keys are missing.
* turnstile_token_expired: token expired or was already consumed.
* turnstile_token_rejected / turnstile_invalid_token: token rejected, missing or malformed.
* turnstile_hostname_mismatch / turnstile_action_mismatch: expected configuration did not match.
* turnstile_timestamp_invalid: challenge timestamp invalid, too old or ahead of the server clock.
* turnstile_network_failed / turnstile_http_failed / turnstile_provider_failed / turnstile_invalid_response: server/API connection or response problem.

== External services ==

Cloudflare Turnstile is optional and disabled by default. When enabled for a form,
viewing that form loads Cloudflare's official script from challenges.cloudflare.com.
Cloudflare processes browser/device and connection information to detect automated
traffic. On submission, this plugin sends the verification token and the site's
secret key from the server to Cloudflare's Siteverify API. The plugin does not send
form field values or an additional remoteip parameter to Siteverify. Cloudflare
controls the script's version and caching; the script is not self-hosted.

Service: https://www.cloudflare.com/products/turnstile/
Terms: https://www.cloudflare.com/website-terms/
Privacy: https://www.cloudflare.com/privacypolicy/

== Building from source ==

The plugin includes the compiled React administration bundle and its JSX source in
assets/src/admin/. No Composer or npm installation is required on a production site.
To rebuild, run npm ci followed by npm run build. The source build uses esbuild with
entry point assets/src/admin/index.jsx, IIFE output, ES2020 target, JSX factory h and
fragment Fragment, bundled and minified to assets/admin-app.js. React and WordPress
components are supplied by WordPress rather than bundled. The development repository
and release packaging instructions are at https://github.com/HamishW5-46/plain-forms.
