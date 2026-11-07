# Plain Forms

Forms, plain and simple. A free, no-subscription WordPress form builder for contact and enquiry forms, with the features you actually need, without the premium price tag.

It combines a polished React-powered wp-admin experience with server-rendered frontend forms, stored submissions, email notifications, Cloudflare Turnstile support and privacy-conscious defaults.

Plain Forms is deliberately smaller in scope than large commercial form platforms. It focuses on doing ordinary contact and enquiry forms well without requiring a subscription, jQuery, or runtime Composer/Node dependencies.

## Features

- React-powered visual form builder using native WordPress components
- Dynamic Gutenberg block
- Shortcode support
- Server-rendered frontend forms
- Asynchronous submission without page reload
- Non-JavaScript POST fallback when Turnstile is not required
- Strict server-side validation
- Stored submissions
- Search and filtering
- Submission statuses
- CSV export with formula-injection protection
- Team notification emails
- Optional submitter receipts
- Notification resend support
- Cloudflare Turnstile
- Honeypot protection
- Optional timing protection
- Configurable rate limiting
- Configurable submission retention
- WordPress personal data export and erasure integration
- Privacy-conscious IP hashing
- Accessible frontend validation and status messages
- No jQuery dependency
- No runtime Composer dependency

## Supported Fields

Plain Forms currently supports:

- Text
- Email
- Telephone
- Textarea
- Select
- Radio
- Checkbox
- Checkbox group
- Hidden
- Consent

The project intentionally does not currently provide payments, surveys, quizzes, user registration, file uploads, multipage forms, advanced conditional logic, CRM integrations or similar large-form-suite features.

## Requirements

- WordPress 6.6 or later
- PHP 8.1 or later

Plain Forms is developed for PHP 8.1 through modern supported PHP releases.

## Installation

Install Plain Forms into:

```text
wp-content/plugins/plain-forms/
```

Activate it through WordPress, then open:

```text
Plain Forms → Settings
```

Review the default notification recipient, sender details, storage settings and spam-protection configuration.

Create a form under:

```text
Plain Forms → Forms → Add form
```

Forms can be inserted using the Plain Forms Gutenberg block.

A shortcode is also available:

```text
[plainforms_form id="123"]
```

Form definitions remain centralised. Updating a form automatically updates every block or shortcode referencing that form.

## Form Builder

The administration interface uses React supplied by WordPress itself.

The builder includes:

- Field toolbox
- Visual form canvas
- Property inspector
- Structured option editing
- Field duplication
- Reordering controls
- Undo and redo
- Starter contact form
- Desktop and mobile preview
- Form-level settings
- Asynchronous saving
- Keyboard save support
- Unsaved-change protection
- Draft preservation after failed saves

React source lives under:

```text
assets/src/admin/
```

It is compiled using:

```text
scripts/build.cjs
```

into the prebuilt administration bundle included with the plugin.

Production WordPress installations do not need Node.js or npm.

## Frontend Forms

Frontend forms are rendered server-side by PHP.

JavaScript progressively enhances them with:

- Asynchronous submission
- Loading states
- Accessible success and failure messages
- Submit-state management
- Duplicate-submission prevention
- Turnstile integration
- Preservation of entered values after failures

When Turnstile is not required, a normal POST fallback remains available.

## Spam Protection

Plain Forms supports several complementary controls:

- Cloudflare Turnstile
- Honeypot field
- Conservative optional timing checks
- Per-source and per-form rate limiting

Turnstile verification occurs server-side.

Under **Settings → Spam protection**, use **Test Turnstile configuration** to verify
entered keys, the expected hostname and action with a real challenge before saving.
A blank secret uses the saved secret without returning it to the browser. The panel
also flags hostname differences immediately, including `www` prefixes. Tests run on
the dashboard hostname and neither save settings nor create enquiries. Changes to
verification settings clear the previous result.

The stored Turnstile secret is never exposed to frontend JavaScript, React bootstrap data or REST responses.

## Stored Submissions

When storage is enabled, enquiries are saved before notification delivery is attempted.

This means an email failure does not cause the enquiry itself to be lost.

Stored submissions can be:

- Viewed
- Searched
- Filtered
- Marked read or unread
- Archived
- Marked as spam
- Restored
- Permanently deleted
- Exported as CSV

Notification delivery status is stored separately from submission status.

CSV exports include protection against spreadsheet formula injection.

## Email Notifications

Plain Forms supports separate configurations for:

- Team notifications
- Optional submitter receipts

Features include:

- Multiple recipients
- Configurable subjects
- Reply-To using a validated form email field
- Field and system placeholders
- HTML and plaintext alternatives
- Separate delivery status
- Notification resend without duplicate submissions
- Theme and child-theme PHP template overrides

Submitted values are escaped before being inserted into templates.

## Privacy

Plain Forms does not store raw visitor IP addresses in its submissions table.

When IP-based abuse protection is enabled, a site-keyed hash is derived from the connection address.

Optional stored metadata includes:

- Hashed source identifier
- User agent
- Referring origin and path

User-agent and referrer retention are disabled by default.

Only the direct connection IP is trusted. Forwarded proxy headers are not used to identify visitors.

Default submission retention is 365 days. A value of zero means submissions are retained indefinitely.

Plain Forms integrates with WordPress personal data export and erasure APIs so stored enquiries can be located using an exact email address.

When Cloudflare Turnstile is enabled, verification tokens are sent to Cloudflare's Siteverify service over HTTPS. Plain Forms does not send the raw source address using Turnstile's `remoteip` parameter.

Site owners remain responsible for describing their form processing, notification delivery, exports and backups in their own privacy policies.

## Accessibility

Plain Forms targets WCAG 2.2 AA-friendly behaviour.

The frontend uses semantic controls, associated labels, programmatic required states, field-associated validation errors and accessible status messaging.

The React administration interface uses native WordPress components where practical and is tested for keyboard and accessibility behaviour.

## Data Lifecycle

Activation creates the required site-prefixed submissions table, default settings and Plain Forms capabilities.

Deactivation preserves forms, settings and submissions.

Uninstall also preserves data by default.

An administrator may explicitly enable:

```text
Delete all plugin data on uninstall
```

When enabled, uninstall removes Plain Forms forms, metadata, revisions, submissions, settings, capabilities and stored rate-limit data for that site.

Network activation is not supported. On multisite, activate Plain Forms separately for each site.

## Development

Install PHP development dependencies:

```bash
composer install
```

Run PHP tests:

```bash
composer test
```

Run static analysis:

```bash
composer analyse
```

PHPStan runs at level 5 with PHP 8.1 as the analysis target.

Install JavaScript development dependencies:

```bash
npm ci
```

Build the React administration application:

```bash
npm run build
```

Run JavaScript checks:

```bash
npm run check
```

Run React administration browser tests:

```bash
npm run test:admin
```

The compiled administration bundle is committed to the repository so production installations do not require a JavaScript build step.

Do not run transactional integration tests against a live WordPress installation.

## Project Structure

```text
Admin/              WordPress administration screens
Blocks/             Gutenberg block registration and rendering
Rest/               Public and private REST controllers
assets/             Frontend/admin assets and compiled React bundle
assets/src/admin/   React administration source
includes/           Core PHP services and domain logic
scripts/            Build tooling
tests/              Automated and browser/integration tests
plain-forms.php      Plugin bootstrap
uninstall.php       Controlled uninstall handling
```

## Security

Plain Forms follows normal WordPress security boundaries including:

- Capability checks for administration operations
- WordPress REST authentication for private endpoints
- Strict public submission validation
- Parameterised database queries
- Contextual output escaping
- Sanitisation by field type
- Server-side Turnstile verification
- Nonce-protected administrative actions
- Safe email header handling
- CSV formula-injection protection
- No public submission-data endpoint
- No raw IP storage
- Stored secret isolation

Security issues should not be disclosed publicly until they can be assessed and addressed.

## Scope

Plain Forms is intended to remain focused.

It is not currently intended to become a complete clone of Gravity Forms or other large commercial form platforms.

The goal is a reliable, polished and maintainable solution for ordinary WordPress contact and enquiry forms without requiring a paid licence.

## License

Plain Forms is licensed under the GNU General Public License v2.0 or later.

See `LICENSE` for details.

## Current Version

Current development release: `0.11.0`

Notable recent work includes:

- React form builder and administration dashboard
- Server-rendered Gutenberg integration
- Stored submissions and CSV export
- Turnstile, honeypot and rate limiting
- Privacy and retention controls
- HTML notification templates
- Optional submitter receipts
- Theme and child-theme email template overrides
- Browser, integration, accessibility and static-analysis coverage