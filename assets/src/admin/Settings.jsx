import {
    h,
    Fragment,
    useState,
    useEffect,
    useRef,
    TextControl,
    TextareaControl,
    SelectControl,
    ToggleControl,
    Button,
    Notice,
    TabPanel,
    apiFetch,
    Glyph,
} from './wp';
import {
    PageHeader,
    Card,
    Feedback,
    SaveButton,
    EmailsControl,
} from './common';
import { useSaveGuard } from './state';
import TurnstileTest from './TurnstileTest';
const sections = [
    {
        name: 'general',
        title: 'General',
        icon: 'admin-generic',
        description: 'Thoughtful defaults for every enquiry.',
    },
    {
        name: 'email',
        title: 'Email',
        icon: 'email',
        description: 'Make sure enquiries reach the right people.',
    },
    {
        name: 'spam',
        title: 'Spam protection',
        icon: 'shield',
        description: 'Keep automated messages out of your inbox.',
    },
    {
        name: 'privacy',
        title: 'Privacy',
        icon: 'privacy',
        description: 'Keep only the information you need.',
    },
    {
        name: 'advanced',
        title: 'Advanced',
        icon: 'admin-tools',
        description: 'Operational settings and data ownership.',
    },
];
const forKey = (key) =>
    key.startsWith('turnstile') ||
    [
        'rate_limit_count',
        'rate_limit_window',
        'honeypot_enabled',
        'timing_enabled',
        'clear_turnstile_secret',
    ].includes(key)
        ? 'spam'
        : ['recipients', 'sender_name', 'sender_email', 'subject'].includes(key)
          ? 'email'
          : [
                  'retention_days',
                  'auto_delete_expired',
                  'store_user_agent',
                  'store_referer',
                  'hash_ip',
              ].includes(key)
            ? 'privacy'
            : ['delete_on_uninstall', 'debug_logging'].includes(key)
              ? 'advanced'
              : 'general';
const initialValues = (values) => ({
    ...values,
    turnstile_secret_key: '',
    clear_turnstile_secret: false,
});
export default function Settings({ data, links }) {
    const [values, setValues] = useState(() => initialValues(data.values));
    const [baseline, setBaseline] = useState(() =>
        JSON.stringify(initialValues(data.values)),
    );
    const [hasSecret, setHasSecret] = useState(data.hasSecret);
    const [section, setSection] = useState('general');
    const [feedback, setFeedback] = useState(null);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const [customRetention, setCustomRetention] = useState(
        ![0, 30, 90, 180, 365, 730].includes(data.values.retention_days),
    );
    const feedbackRef = useRef(null),
        inFlight = useRef(false);
    const dirty = JSON.stringify(values) !== baseline;
    const set = (key, value) => {
        setValues((old) => ({ ...old, [key]: value }));
        setErrors((old) => {
            if (!old[key]) return old;
            const next = { ...old };
            delete next[key];
            return next;
        });
    };
    const help = (key, text) =>
        errors[key] ? (
            <span className="plainforms-control-error">{errors[key]}</span>
        ) : (
            text
        );
    const text = (key, label, description = '', type = 'text') => (
        <TextControl
            key={key}
            label={label}
            type={type}
            value={values[key]}
            onChange={(v) => set(key, v)}
            help={help(key, description)}
            aria-invalid={!!errors[key]}
            __nextHasNoMarginBottom
            __next40pxDefaultSize
        />
    );
    const toggle = (key, label, description) => (
        <ToggleControl
            label={label}
            help={description}
            checked={values[key]}
            onChange={(v) => set(key, v)}
            __nextHasNoMarginBottom
        />
    );
    const number = (key, label, description, min, max) => (
        <TextControl
            label={label}
            type="number"
            min={min}
            max={max}
            value={values[key]}
            onChange={(v) => set(key, v === '' ? '' : Number(v))}
            help={help(key, description)}
            aria-invalid={!!errors[key]}
            __nextHasNoMarginBottom
            __next40pxDefaultSize
        />
    );
    async function save() {
        if (inFlight.current || !dirty) return;
        inFlight.current = true;
        setBusy(true);
        setFeedback(null);
        setErrors({});
        try {
            const result = await apiFetch({
                path: '/plain-forms/v1/admin/settings',
                method: 'POST',
                data: values,
            });
            const next = initialValues(result.values);
            setValues(next);
            setBaseline(JSON.stringify(next));
            setHasSecret(result.hasSecret);
            setFeedback({
                status: 'success',
                message:
                    'Settings saved. Your forms will use these defaults unless they have their own overrides.',
            });
        } catch (error) {
            const fields = error.data?.fields || {};
            setErrors(fields);
            if (Object.keys(fields).length)
                setSection(forKey(Object.keys(fields)[0]));
            setFeedback({
                status: 'error',
                message:
                    error.message ||
                    'Settings could not be saved. Your changes are still here; please try again.',
                items: Object.values(fields),
            });
        } finally {
            inFlight.current = false;
            setBusy(false);
        }
    }
    useSaveGuard(dirty, save);
    useEffect(() => {
        if (feedback?.status === 'error') feedbackRef.current?.focus();
    }, [feedback]);
    const current = sections.find((item) => item.name === section);
    const ready =
        !!values.turnstile_site_key &&
        (!!values.turnstile_secret_key ||
            (hasSecret && !values.clear_turnstile_secret));
    return (
        <>
            <PageHeader
                title="Settings"
                description="One place to manage email, protection and privacy across your forms."
                actions={
                    <SaveButton
                        busy={busy}
                        dirty={dirty}
                        onSave={save}
                        label="Save settings"
                    />
                }
            />
            <Feedback
                feedback={feedback}
                onDismiss={() => setFeedback(null)}
                focusRef={feedbackRef}
            />
            <div className="plainforms-settings-shell">
                <nav
                    className="plainforms-settings-navigation"
                    aria-label="Settings sections"
                >
                    {sections.map((item) => (
                        <Button
                            key={item.name}
                            className={
                                section === item.name ? 'is-current' : ''
                            }
                            aria-current={
                                section === item.name ? 'page' : undefined
                            }
                            onClick={() => setSection(item.name)}
                        >
                            <Glyph name={item.icon} />
                            {item.title}
                            <Glyph name="arrow-right-alt2" />
                        </Button>
                    ))}
                    <div className="plainforms-settings-side-note">
                        <Glyph name="admin-generic" />
                        <p>Per-form settings can override these defaults.</p>
                        {links.forms && (
                            <a href={links.forms}>Manage your forms</a>
                        )}
                    </div>
                </nav>
                <fieldset
                    className="plainforms-app-fieldset plainforms-settings-content"
                    disabled={busy}
                    aria-busy={busy}
                >
                    <div className="plainforms-section-intro">
                        <div className="plainforms-eyebrow">FORM DEFAULTS</div>
                        <h2>{current.title}</h2>
                        <p>{current.description}</p>
                    </div>
                    {section === 'general' && (
                        <>
                            <Card
                                title="Receiving enquiries"
                                description="Keep a reliable copy of messages sent through your website."
                            >
                                {toggle(
                                    'storage_enabled',
                                    'Store submissions by default',
                                    'Recommended. Stored enquiries remain available even if an email notification fails.',
                                )}
                                {!values.storage_enabled && (
                                    <Notice
                                        status="warning"
                                        isDismissible={false}
                                    >
                                        Forms without storage rely on email
                                        delivery. There is no stored copy to
                                        recover or resend.
                                    </Notice>
                                )}
                                {toggle(
                                    'mark_read_on_view',
                                    'Mark enquiries as read when opened',
                                    'New submissions become read when you open them from the submissions list.',
                                )}
                            </Card>
                            <Card
                                title="Visitor confirmation"
                                description="A friendly response after a successful submission."
                            >
                                <TextareaControl
                                    label="Default success message"
                                    value={values.success_message}
                                    rows={4}
                                    onChange={(v) => set('success_message', v)}
                                    help={help(
                                        'success_message',
                                        'Individual forms can use a different message.',
                                    )}
                                    __nextHasNoMarginBottom
                                />
                            </Card>
                        </>
                    )}
                    {section === 'email' && (
                        <>
                            <Card
                                title="Notification recipients"
                                description="Choose the people who should receive new enquiries."
                            >
                                <EmailsControl
                                    label="Default recipients"
                                    value={values.recipients}
                                    onChange={(v) => set('recipients', v)}
                                    help={help(
                                        'recipients',
                                        'Separate multiple addresses with commas. Forms can override this list.',
                                    )}
                                    aria-invalid={!!errors.recipients}
                                />
                                {text(
                                    'subject',
                                    'Default email subject',
                                    'Use {form_title}, {submission_id}, or a field ID such as {subject}. Unknown placeholders are omitted.',
                                )}
                            </Card>
                            <Card
                                title="Sender identity"
                                description="Use an address supported by your website’s email service."
                            >
                                <div className="plainforms-two-columns">
                                    {text(
                                        'sender_name',
                                        'Sender name',
                                        'Leave blank to use the WordPress default.',
                                    )}
                                    {text(
                                        'sender_email',
                                        'Sender email',
                                        'Leave blank to let WordPress or your mail service choose it.',
                                        'email',
                                    )}
                                </div>
                                <div className="plainforms-info-note">
                                    <Glyph name="info" />
                                    <p>
                                        Visitors’ email addresses belong in a
                                        form’s Reply-To setting. Keeping your
                                        own address as the sender helps mail
                                        delivery.
                                    </p>
                                </div>
                            </Card>
                        </>
                    )}
                    {section === 'spam' && (
                        <>
                            <Card
                                title="Cloudflare Turnstile"
                                description="Verify real visitors without asking them to solve puzzles."
                            >
                                {toggle(
                                    'turnstile_enabled',
                                    'Enable Turnstile by default',
                                    'Forms can inherit this setting or choose their own protection.',
                                )}
                                <div className="plainforms-two-columns">
                                    {text(
                                        'turnstile_site_key',
                                        'Site key',
                                        'The public key from your Cloudflare widget.',
                                    )}
                                    <div>
                                        <TextControl
                                            label="Secret key"
                                            type="password"
                                            autoComplete="new-password"
                                            value={values.turnstile_secret_key}
                                            onChange={(v) =>
                                                setValues((old) => ({
                                                    ...old,
                                                    turnstile_secret_key: v,
                                                    clear_turnstile_secret: false,
                                                }))
                                            }
                                            help={
                                                hasSecret
                                                    ? 'A secret is saved. Leave this blank to keep it.'
                                                    : 'Enter the secret key from Cloudflare. It will not be shown again.'
                                            }
                                            __nextHasNoMarginBottom
                                            __next40pxDefaultSize
                                        />
                                        {hasSecret &&
                                            !values.clear_turnstile_secret && (
                                                <Button
                                                    variant="link"
                                                    isDestructive
                                                    onClick={() =>
                                                        setValues((old) => ({
                                                            ...old,
                                                            clear_turnstile_secret: true,
                                                            turnstile_secret_key:
                                                                '',
                                                        }))
                                                    }
                                                >
                                                    Remove saved secret
                                                </Button>
                                            )}
                                        {values.clear_turnstile_secret && (
                                            <p className="plainforms-control-error">
                                                The saved secret will be removed
                                                when you save.{' '}
                                                <Button
                                                    variant="link"
                                                    onClick={() =>
                                                        set(
                                                            'clear_turnstile_secret',
                                                            false,
                                                        )
                                                    }
                                                >
                                                    Keep it
                                                </Button>
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <div className="plainforms-two-columns">
                                    {text(
                                        'turnstile_hostname',
                                        'Expected hostname',
                                        'For example, example.org. Do not include https:// or a path.',
                                    )}
                                    {text(
                                        'turnstile_action',
                                        'Expected action',
                                        'Optional. Use letters, numbers, underscores or hyphens.',
                                    )}
                                </div>
                                <TurnstileTest values={values} hasSecret={hasSecret} publicHostname={data.publicHostname} />
                                {values.turnstile_enabled && !ready && (
                                    <Notice
                                        status="warning"
                                        isDismissible={false}
                                    >
                                        Add both Turnstile keys before
                                        publishing a protected form. Unverified
                                        submissions will be refused.
                                    </Notice>
                                )}
                            </Card>
                            <Card
                                title="Everyday spam protection"
                                description="Simple safeguards that run on your server."
                            >
                                {toggle(
                                    'honeypot_enabled',
                                    'Enable honeypot',
                                    'Silently discard submissions that fill the hidden bot field.',
                                )}
                                {toggle(
                                    'timing_enabled',
                                    'Enable timing protection',
                                    'Reject submissions made in under two seconds. Keep this off if it interferes with your visitors.',
                                )}
                                <div className="plainforms-two-columns">
                                    {number(
                                        'rate_limit_count',
                                        'Maximum attempts',
                                        `Per source, per form, during the window below.`,
                                        1,
                                        1000,
                                    )}
                                    {number(
                                        'rate_limit_window',
                                        'Rate limit window (seconds)',
                                        '600 seconds is 10 minutes.',
                                        60,
                                        86400,
                                    )}
                                </div>
                                {!values.hash_ip && (
                                    <Notice
                                        status="warning"
                                        isDismissible={false}
                                    >
                                        Source-based rate limiting is off
                                        because IP hashing is disabled under
                                        Privacy.
                                    </Notice>
                                )}
                            </Card>
                        </>
                    )}
                    {section === 'privacy' && (
                        <>
                            <Card
                                title="Retention"
                                description="Automatically remove stored enquiries when you no longer need them."
                            >
                                <SelectControl
                                    label="Retention period"
                                    value={
                                        customRetention
                                            ? 'custom'
                                            : String(values.retention_days)
                                    }
                                    options={[
                                        { value: '30', label: '30 days' },
                                        { value: '90', label: '90 days' },
                                        { value: '180', label: '180 days' },
                                        {
                                            value: '365',
                                            label: '1 year (365 days)',
                                        },
                                        {
                                            value: '730',
                                            label: '2 years (730 days)',
                                        },
                                        { value: '0', label: 'Keep forever' },
                                        {
                                            value: 'custom',
                                            label: 'Custom period…',
                                        },
                                    ]}
                                    onChange={(v) => {
                                        setCustomRetention(v === 'custom');
                                        if (v !== 'custom')
                                            set('retention_days', Number(v));
                                    }}
                                    __nextHasNoMarginBottom
                                    __next40pxDefaultSize
                                />
                                {customRetention &&
                                    number(
                                        'retention_days',
                                        'Custom retention (days)',
                                        'Use 0 to keep enquiries forever.',
                                        0,
                                        36500,
                                    )}
                                {toggle(
                                    'auto_delete_expired',
                                    'Automatically delete expired submissions',
                                    'Cleanup follows your retention period and uses WordPress scheduled tasks.',
                                )}
                                <p className="plainforms-muted">
                                    Email copies, CSV downloads and backups
                                    follow their own retention policies.
                                </p>
                            </Card>
                            <Card
                                title="Connection information"
                                description="Keep optional metadata to a minimum."
                            >
                                {toggle(
                                    'hash_ip',
                                    'Hash IP for rate limiting',
                                    'Uses a site-specific keyed hash. Raw IP addresses are never stored. Turning this off also disables source-based rate limits.',
                                )}
                                {toggle(
                                    'store_user_agent',
                                    'Store user agent',
                                    'Retain browser information with each enquiry.',
                                )}
                                {toggle(
                                    'store_referer',
                                    'Store referring page',
                                    'Retain only the referring origin and path, without query strings or fragments.',
                                )}
                                <div className="plainforms-info-note">
                                    <Glyph name="privacy" />
                                    <p>
                                        WordPress’s verified personal-data
                                        export and erasure tools can find stored
                                        enquiries by email address. Update your
                                        privacy policy to reflect these
                                        settings.
                                    </p>
                                </div>
                            </Card>
                        </>
                    )}
                    {section === 'advanced' && (
                        <>
                            <Card
                                title="Diagnostics"
                                description="Keep troubleshooting information limited and intentional."
                            >
                                {toggle(
                                    'debug_logging',
                                    'Enable operational debug logging',
                                    'Log fixed failure codes only. Submitted values, tokens and secrets are not logged.',
                                )}
                            </Card>
                            <Card
                                title="Uninstall behaviour"
                                description="You own your form definitions and enquiry history."
                                className="plainforms-danger-card"
                            >
                                {toggle(
                                    'delete_on_uninstall',
                                    'Delete all plugin data on uninstall',
                                    'Applies only when you delete this plugin. Deactivation always preserves data.',
                                )}
                                {values.delete_on_uninstall ? (
                                    <Notice
                                        status="warning"
                                        isDismissible={false}
                                    >
                                        Deleting the plugin will permanently
                                        remove its forms, submissions and
                                        settings. Export anything you need to
                                        keep first.
                                    </Notice>
                                ) : (
                                    <p className="plainforms-muted">
                                        Your data will be preserved if the
                                        plugin is uninstalled.
                                    </p>
                                )}
                            </Card>
                        </>
                    )}
                </fieldset>
            </div>
            <div className="plainforms-app-footer">
                <span role="status" aria-live="polite">
                    {dirty
                        ? 'You have unsaved changes.'
                        : 'Your settings are up to date.'}
                </span>
                <SaveButton
                    busy={busy}
                    dirty={dirty}
                    onSave={save}
                    label="Save settings"
                />
            </div>
        </>
    );
}
