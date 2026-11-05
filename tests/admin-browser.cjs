const wordpressContainer = process.env.PLAINFORMS_WORDPRESS_CONTAINER || 'plainforms_wordpress';
/* Real WordPress React/components assets; all requests mocked, fixtures generated in transactions. */
const { createRequire } = require('node:module');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const load = process.env.PLAINFORMS_NODE_MODULES
    ? createRequire(
          path.join(process.env.PLAINFORMS_NODE_MODULES, '../package.json'),
      )
    : require;
const { chromium } = load('playwright');
const AxeBuilder = load('@axe-core/playwright').default;
const wpRoot = path.resolve(__dirname, '../../../..');
let checks = 0;
const check = (condition, label) => {
    assert.ok(condition, label);
    checks++;
    console.log('PASS: ' + label);
};
function fixture(mode) {
    try {
        return execFileSync(
            'docker',
            [
                'exec',
                '-e',
                'PLAINFORMS_RUN_TESTS=1',
                wordpressContainer,
                'php',
                '/var/www/html/wp-content/plugins/plain-forms/tests/browser-fixture.php',
                mode,
            ],
            { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 },
        )
            .replace(/"nonce":"[^"]+"/g, '"nonce":"test-nonce"')
            .replace(
                /createNonceMiddleware\(\s*["'][^"']+["']\s*\)/g,
                'createNonceMiddleware("test-nonce")',
            );
    } catch (_) {
        throw new Error('Unable to generate ' + mode + ' fixture.');
    }
}
(async () => {
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox'],
    });
    try {
        const context = await browser.newContext({
            viewport: { width: 1440, height: 1050 },
        });
        const page = await context.newPage();
        const exceptions = [],
            badConsole = [];
        let expectedHttpErrors = 0;
        page.on('pageerror', (e) => exceptions.push(e.message));
        page.on('console', (message) => {
            if (message.type() === 'error') {
                if (
                    /^Failed to load resource:.*status of (400|503) /.test(
                        message.text(),
                    ) &&
                    message.location().url.includes('/plain-forms/v1/admin/')
                ) {
                    expectedHttpErrors++;
                    return;
                }
                badConsole.push(message.text());
            }
        });
        const fixtures = {
            builder: fixture('admin'),
            new: fixture('new'),
            settings: fixture('settings'),
            dashboard: fixture('dashboard'),
            forms: fixture('forms-list'),
            emptyForms: fixture('forms-empty'),
        };
        check(
            !Object.values(fixtures).some((html) =>
                html.includes('test-secret-must-never-appear'),
            ),
            'Stored Turnstile secret absent from all React bootstrap data',
        );
        let mode = 'builder',
            failSave = false,
            requests = [],
            settingsError = false;
        const settingsSeed = JSON.parse(
            fixtures.settings.match(
                /id="plainforms-admin-bootstrap">([\s\S]*?)<\/script>/,
            )[1],
        ).data;
        await page.route('**/*', async (route) => {
            const request = route.request(),
                url = new URL(request.url());
            const headers = {
                'access-control-allow-origin': 'http://plain-forms.test',
                'access-control-allow-credentials': 'true',
                'access-control-allow-headers': '*',
                'access-control-allow-methods': 'GET,POST,OPTIONS',
            };
            if (request.method() === 'OPTIONS')
                return route.fulfill({ status: 204, headers });
            if (url.pathname.includes('/plain-forms/v1/admin/')) {
                const data = request.postDataJSON();
                requests.push({
                    path: url.pathname,
                    data,
                    nonce: request.headers()['x-wp-nonce'],
                });
                await new Promise((resolve) => setTimeout(resolve, 120));
                if (failSave)
                    return route.fulfill({
                        status: 503,
                        headers,
                        json: {
                            code: 'save_failed',
                            message:
                                'Saving is temporarily unavailable. Please try again.',
                            data: { status: 503 },
                        },
                    });
                if (url.pathname.endsWith('/email-preview')) {
                    return route.fulfill({
                        headers,
                        json: {
                            html: `<html><body><h1>${data.kind} preview sample</h1></body></html>`,
                            themeTemplate: '',
                            fallback: false,
                        },
                    });
                }
                if (url.pathname.endsWith('/settings')) {
                    if (settingsError)
                        return route.fulfill({
                            status: 400,
                            headers,
                            json: {
                                code: 'invalid_settings',
                                message:
                                    'Please check the highlighted settings.',
                                data: {
                                    status: 400,
                                    fields: {
                                        sender_email:
                                            'Enter a valid sender email address.',
                                    },
                                },
                            },
                        });
                    const values = { ...data };
                    delete values.turnstile_secret_key;
                    delete values.clear_turnstile_secret;
                    return route.fulfill({
                        headers,
                        json: {
                            values,
                            hasSecret: data.clear_turnstile_secret
                                ? false
                                : !!data.turnstile_secret_key ||
                                  settingsSeed.hasSecret,
                            message: 'Settings saved.',
                        },
                    });
                }
                return route.fulfill({
                    headers,
                    json: {
                        form: {
                            id: 735,
                            title: data.title.trim(),
                            config: data.config,
                        },
                        message: 'Form saved.',
                    },
                });
            }
            if (url.pathname === '/')
                return route.fulfill({
                    contentType: 'text/html',
                    body: fixtures[mode],
                });
            if (
                url.pathname.startsWith('/wp-includes/') ||
                url.pathname.startsWith('/wp-admin/') ||
                url.pathname.startsWith(
                    '/wp-content/plugins/plain-forms/assets/',
                )
            ) {
                const file = path.resolve(
                    wpRoot,
                    '.' + decodeURIComponent(url.pathname),
                );
                if (
                    file.startsWith(wpRoot + path.sep) &&
                    fs.existsSync(file) &&
                    fs.statSync(file).isFile()
                ) {
                    const contentType = file.endsWith('.css')
                        ? 'text/css'
                        : file.endsWith('.js')
                          ? 'application/javascript'
                          : file.endsWith('.woff2')
                            ? 'font/woff2'
                            : 'application/octet-stream';
                    return route.fulfill({
                        body: fs.readFileSync(file),
                        contentType,
                    });
                }
            }
            return route.abort();
        });
        mode = 'new';
        await page.goto('http://plain-forms.test/');
        await page
            .getByRole('heading', { name: 'Create a form', exact: true })
            .waitFor();
        await page
            .getByRole('button', { name: 'Save form', exact: true })
            .click();
        await page
            .locator('.plainforms-feedback')
            .getByText('Give your form a title.', { exact: true })
            .waitFor();
        check(
            requests.length === 0,
            'New forms explain missing title before sending a save request',
        );
        await page
            .getByRole('button', {
                name: 'Start with a contact form',
                exact: true,
            })
            .click();
        check(
            (await page.locator('.plainforms-canvas-field').count()) === 4,
            'A new form can start from the contact template',
        );
        await page
            .getByLabel('Form title', { exact: true })
            .fill('General enquiries');
        await page
            .getByRole('button', { name: 'Save form', exact: true })
            .click();
        await page
            .locator('.plainforms-feedback')
            .getByText(
                'Form saved. Pages using this form will show the latest version.',
                { exact: true },
            )
            .waitFor();
        check(
            requests.at(-1).path.endsWith('/admin/forms') &&
                requests.at(-1).data.config.notification.reply_to_field ===
                    'email',
            'First save creates a form with the starter Reply-To field',
        );
        check(
            new URL(page.url()).searchParams.get('id') === '735',
            'First save updates the edit URL with its returned form ID',
        );
        mode = 'builder';
        await page.goto('http://plain-forms.test/');
        await page
            .getByRole('heading', { name: 'Edit form', exact: true })
            .waitFor();
        check(
            (await page.locator('.plainforms-canvas-field').count()) === 8,
            'React builder loads stored fields using real WordPress components',
        );
        check(
            await page
                .getByRole('button', { name: 'Save form', exact: true })
                .isDisabled(),
            'Save stays disabled until the document changes',
        );
        await page
            .getByRole('button', { name: 'Short answer', exact: true })
            .click();
        check(
            (await page.locator('.plainforms-canvas-field').count()) === 9,
            'Toolbox adds a field and selects its inspector',
        );
        await page.getByLabel('Label', { exact: true }).fill('Your question');
        check(
            await page
                .locator('.plainforms-canvas-field')
                .last()
                .textContent()
                .then((t) => t.includes('Your question')),
            'Editing a field updates the canvas immediately',
        );
        await page
            .getByRole('button', { name: 'Move Your question up', exact: true })
            .click();
        check(
            (
                await page.locator('.plainforms-canvas-field').nth(7).textContent()
            ).includes('Your question'),
            'Move controls reorder fields while preserving selection',
        );
        await page
            .getByRole('button', {
                name: 'Duplicate Your question',
                exact: true,
            })
            .click();
        check(
            (await page.locator('.plainforms-canvas-field').count()) === 10,
            'Field duplication creates a separate editable field',
        );
        await page
            .getByRole('button', {
                name: 'Remove Your question (copy)',
                exact: true,
            })
            .click();
        await page
            .getByRole('dialog')
            .getByRole('button', { name: 'Remove field', exact: true })
            .click();
        check(
            (await page.locator('.plainforms-canvas-field').count()) === 9,
            'Removal confirmation protects against accidental deletion',
        );
        await page
            .getByRole('button', { name: 'Undo change', exact: true })
            .click();
        check(
            (await page.locator('.plainforms-canvas-field').count()) === 10,
            'Undo restores a removed field',
        );
        await page
            .getByRole('button', { name: 'Redo change', exact: true })
            .click();
        check(
            (await page.locator('.plainforms-canvas-field').count()) === 9,
            'Redo reapplies the change',
        );
        await page
            .getByRole('button', { name: 'Dropdown', exact: true })
            .click();
        await page
            .getByLabel('Option 1 label', { exact: true })
            .fill('General enquiry');
        await page
            .getByRole('button', { name: 'Add option', exact: true })
            .click();
        check(
            (await page
                .getByLabel('Option 3 label', { exact: true })
                .count()) === 1,
            'Choice editor adds and edits structured options',
        );
        await page
            .getByRole('button', { name: 'Form settings', exact: true })
            .click();
        const recipients = page.getByLabel('Recipients', { exact: true });
        await recipients.pressSequentially(
            'one@example.invalid, two@example.invalid',
            { delay: 5 },
        );
        check(
            (await recipients.inputValue()).includes(', two@example.invalid'),
            'Multiple-recipient typing preserves commas and caret',
        );

        await page
            .getByLabel('Notification email format', { exact: true })
            .selectOption('html');
        await page
            .getByLabel('Notification template source', { exact: true })
            .selectOption('editor');
        const notificationHtml = page.getByLabel('Notification HTML template', {
            exact: true,
        });
        await notificationHtml.fill(
            '<h1 id="custom-notification">Hello {name}</h1>{system.all_fields}{system.admin_link}',
        );
        check(
            (await notificationHtml.inputValue()).includes('{name}'),
            'Notification HTML can be edited with field placeholders',
        );
        await page
            .getByRole('button', {
                name: 'Preview notification email',
                exact: true,
            })
            .click();
        await page
            .frameLocator('iframe[title="Notification email preview content"]')
            .getByRole('heading', {
                name: 'notification preview sample',
                exact: true,
            })
            .waitFor();
        check(
            (await page
                .locator('iframe[title="Notification email preview content"]')
                .getAttribute('sandbox')) === '',
            'Email preview renders server HTML in an isolated sandbox',
        );
        check(
            requests.at(-1).path.endsWith('/email-preview') &&
                requests.at(-1).data.kind === 'notification',
            'Notification preview uses the private preview endpoint, not a send action',
        );
        await page
            .getByRole('button', { name: 'Close email preview', exact: true })
            .click();
        await page
            .getByLabel('Send a receipt to the submitter', { exact: true })
            .check();
        check(
            (await page
                .getByLabel('Receipt recipient field', { exact: true })
                .inputValue()) === 'email',
            'Enabling receipts selects an existing email field',
        );
        await page
            .getByLabel('Receipt Reply-To address', { exact: true })
            .fill('help@example.invalid');
        await page
            .getByLabel('Receipt template source', { exact: true })
            .selectOption('editor');
        await page
            .getByLabel('Receipt HTML template', { exact: true })
            .fill('<h1>Thank you {name}</h1><p>{form_title}</p>');
        await page
            .getByRole('button', { name: 'Preview receipt email', exact: true })
            .click();
        await page
            .frameLocator('iframe[title="Receipt email preview content"]')
            .getByRole('heading', {
                name: 'receipt preview sample',
                exact: true,
            })
            .waitFor();
        check(
            requests.at(-1).data.kind === 'receipt',
            'Receipt preview uses its own template',
        );
        await page
            .getByRole('button', { name: 'Close email preview', exact: true })
            .click();
        await page
            .getByLabel('Receipt template source', { exact: true })
            .selectOption('theme');
        check(
            (await page
                .getByText('plain-forms/emails/receipt.php', { exact: true })
                .count()) === 1,
            'Theme source explains the receipt filename and shared fallback',
        );
        await page
            .getByLabel('Receipt template source', { exact: true })
            .selectOption('editor');
        const mailAxe = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
            .analyze();
        check(
            !mailAxe.violations.length,
            'Notification/receipt settings have no automatic WCAG violations: ' +
                JSON.stringify(mailAxe.violations.map((v) => v.id)),
        );
        if (process.env.PLAINFORMS_SCREENSHOTS) {
            fs.mkdirSync(process.env.PLAINFORMS_SCREENSHOTS, {
                recursive: true,
            });
            await page.screenshot({
                path: path.join(
                    process.env.PLAINFORMS_SCREENSHOTS,
                    'email-settings.png',
                ),
                fullPage: true,
            });
        }
        await page
            .getByRole('button', { name: 'Preview', exact: true })
            .click();
        await page.getByRole('button', { name: 'Mobile', exact: true }).click();
        check(
            (await page.locator('.plainforms-form-preview.is-mobile').count()) === 1,
            'Preview supports a mobile-width layout',
        );
        await page.getByRole('button', { name: 'Build', exact: true }).click();
        const screenshotDir = process.env.PLAINFORMS_SCREENSHOTS;
        if (screenshotDir) {
            fs.mkdirSync(screenshotDir, { recursive: true });
            await page.screenshot({
                path: path.join(screenshotDir, 'builder.png'),
            });
        }
        const firstCount = requests.length;
        await page.keyboard.press('Control+s');
        await page.keyboard.press('Control+s');
        await page
            .locator('.plainforms-feedback')
            .getByText(
                'Form saved. Pages using this form will show the latest version.',
                { exact: true },
            )
            .waitFor();
        check(
            requests.length === firstCount + 1,
            'Keyboard save prevents duplicate concurrent requests',
        );
        const sent = requests.at(-1);
        check(
            sent.data.config.receipt.enabled &&
                sent.data.config.receipt.email_field === 'email' &&
                sent.data.config.receipt.template.includes('{name}') &&
                sent.data.config.notification.format === 'html',
            'Saving persists distinct notification and receipt configurations',
        );

        check(
            sent.nonce === 'test-nonce' &&
                sent.data.config.notification.recipients.length === 2,
            'REST save includes nonce and structured recipients',
        );
        check(
            sent.data.config.fields.every((f) => !('_key' in f)),
            'Editor-only keys are removed from persisted schema',
        );
        check(
            await page
                .getByRole('button', { name: 'Save form', exact: true })
                .isDisabled(),
            'Successful save resets the dirty state without reloading',
        );
        await page
            .getByLabel('Form title', { exact: true })
            .fill('A preserved draft');
        failSave = true;
        await page
            .getByRole('button', { name: 'Save form', exact: true })
            .click();
        await page
            .locator('.plainforms-feedback')
            .getByText('Saving is temporarily unavailable. Please try again.', {
                exact: true,
            })
            .waitFor();
        check(
            (await page
                .getByLabel('Form title', { exact: true })
                .inputValue()) === 'A preserved draft',
            'Failed save preserves all document edits',
        );
        check(
            await page
                .locator('.plainforms-feedback')
                .evaluate((el) => el === document.activeElement),
            'Failed save focuses accessible feedback',
        );
        failSave = false;
        await page
            .getByRole('button', { name: 'Save form', exact: true })
            .click();
        await page
            .locator('.plainforms-feedback')
            .getByText(
                'Form saved. Pages using this form will show the latest version.',
                { exact: true },
            )
            .waitFor();
        let axe = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
            .analyze();
        check(
            !axe.violations.length,
            'Builder has no automatic WCAG violations: ' +
                JSON.stringify(
                    axe.violations.map((v) => ({
                        id: v.id,
                        targets: v.nodes.map((n) => n.target),
                    })),
                ),
        );
        await page.setViewportSize({ width: 390, height: 844 });
        check(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth,
            ),
            'Builder fits a mobile viewport without overflow',
        );
        await page.setViewportSize({ width: 1440, height: 1050 });
        mode = 'settings';
        await page.goto('http://plain-forms.test/');
        await page
            .getByRole('heading', { name: 'Settings', exact: true })
            .waitFor();
        await page
            .getByRole('button', { name: 'Spam protection', exact: true })
            .click();
        check(
            (await page
                .getByLabel('Secret key', { exact: true })
                .inputValue()) === '',
            'Saved secret is represented by an empty password input',
        );
        await page
            .getByLabel('Secret key', { exact: true })
            .fill('new-secret-only-for-test');
        await page
            .getByRole('button', { name: 'Save settings', exact: true })
            .first()
            .click();
        await page
            .locator('.plainforms-feedback')
            .getByText(
                'Settings saved. Your forms will use these defaults unless they have their own overrides.',
                { exact: true },
            )
            .waitFor();
        check(
            (await page
                .getByLabel('Secret key', { exact: true })
                .inputValue()) === '',
            'Secret input clears after saving and is never returned',
        );
        await page
            .getByRole('button', { name: 'Remove saved secret', exact: true })
            .click();
        await page
            .getByRole('button', { name: 'Save settings', exact: true })
            .first()
            .click();
        await page
            .getByText(
                'Enter the secret key from Cloudflare. It will not be shown again.',
                { exact: true },
            )
            .waitFor();
        check(
            requests.at(-1).data.clear_turnstile_secret === true,
            'Removing a secret requires an explicit save flag',
        );
        await page.getByRole('button', { name: 'Email', exact: true }).click();
        await page.getByLabel('Sender email', { exact: true }).fill('broken');
        settingsError = true;
        await page
            .getByRole('button', { name: 'Save settings', exact: true })
            .first()
            .click();
        await page
            .locator('.plainforms-feedback')
            .getByText('Please check the highlighted settings.', {
                exact: true,
            })
            .waitFor();
        check(
            (await page
                .getByLabel('Sender email', { exact: true })
                .getAttribute('aria-invalid')) === 'true',
            'Server validation marks the relevant settings control',
        );
        check(
            (await page
                .getByLabel('Sender email', { exact: true })
                .inputValue()) === 'broken',
            'Settings validation preserves edited values',
        );
        settingsError = false;
        await page
            .getByLabel('Sender email', { exact: true })
            .fill('sender@example.invalid');
        await page
            .getByRole('button', { name: 'Save settings', exact: true })
            .first()
            .click();
        await page
            .locator('.plainforms-feedback')
            .getByText(
                'Settings saved. Your forms will use these defaults unless they have their own overrides.',
                { exact: true },
            )
            .waitFor();
        axe = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
            .analyze();
        check(
            !axe.violations.length,
            'Settings have no automatic WCAG violations: ' +
                JSON.stringify(
                    axe.violations.map((v) => ({
                        id: v.id,
                        targets: v.nodes.map((n) => n.target),
                    })),
                ),
        );
        if (screenshotDir)
            await page.screenshot({
                path: path.join(screenshotDir, 'settings.png'),
            });
        await page
            .getByRole('button', { name: 'Privacy', exact: true })
            .click();
        await page
            .getByLabel('Retention period', { exact: true })
            .selectOption('90');
        await page
            .getByRole('button', { name: 'Save settings', exact: true })
            .first()
            .click();
        await page
            .locator('.plainforms-feedback')
            .getByText(
                'Settings saved. Your forms will use these defaults unless they have their own overrides.',
                { exact: true },
            )
            .waitFor();
        check(
            requests.at(-1).data.retention_days === 90,
            'Convenient retention presets persist the existing numeric schema',
        );
        await page.setViewportSize({ width: 390, height: 844 });
        check(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth,
            ),
            'Settings remain usable on mobile',
        );
        await page.setViewportSize({ width: 1440, height: 1050 });
        mode = 'dashboard';
        await page.goto('http://plain-forms.test/');
        await page
            .getByRole('heading', {
                name: 'A place for every enquiry',
                exact: true,
            })
            .waitFor();
        check(
            (await page.locator('.plainforms-metric').count()) === 6,
            'React dashboard shows all six real aggregate metrics',
        );
        if (screenshotDir)
            await page.screenshot({
                path: path.join(screenshotDir, 'dashboard.png'),
            });
        mode = 'forms';
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('http://plain-forms.test/');
        check(await page.locator('.plainforms-forms-table').evaluate(el => getComputedStyle(el).display === 'table'), 'Forms list keeps the desktop table layout');
        for (const width of [320, 390, 768]) {
            await page.setViewportSize({ width, height: 844 });
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Populated forms list fits ${width}px without page overflow`);
            check(await page.getByRole('button', { name: 'Delete form', exact: true }).isVisible(), `Form actions remain available at ${width}px`);
        }
        check(await page.getByText('Reference', { exact: true }).last().isVisible() && await page.locator('.plainforms-cell-value code').isVisible(), 'Mobile cards keep labelled shortcode references');
        check((await page.getByRole('table', { name: 'Forms', exact: true }).getByRole('columnheader').count()) === 7, 'Mobile table retains accessible column headings');
        await page.setViewportSize({ width: 390, height: 844 });
        const listAxe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        check(!listAxe.violations.length, 'Forms list has no automatic WCAG violations: ' + JSON.stringify(listAxe.violations));
        mode = 'emptyForms';
        await page.goto('http://plain-forms.test/');
        check(await page.getByText('No forms yet. Add a form to get started.', { exact: true }).isVisible(), 'Empty forms list explains how to get started');
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Empty forms list fits mobile without overflow');
        check(await page.getByRole('link', { name: 'Add form', exact: true }).isVisible(), 'Add form stays available on an empty mobile list');
        const emptyAxe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        check(!emptyAxe.violations.length, 'Empty forms list has no automatic WCAG violations: ' + JSON.stringify(emptyAxe.violations));
        check(
            !exceptions.length,
            'No React/runtime exceptions: ' + JSON.stringify(exceptions),
        );
        check(
            expectedHttpErrors === 2,
            'Only the two deliberately simulated HTTP failures were reported by Chromium',
        );
        check(
            !badConsole.length,
            'No plugin-generated browser console errors: ' +
                JSON.stringify(badConsole),
        );
        await context.close();
        console.log(`${checks} React admin browser checks passed.`);
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
