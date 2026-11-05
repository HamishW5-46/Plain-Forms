import {
    h,
    Fragment,
    useState,
    useEffect,
    useRef,
    Button,
    SelectControl,
    TextareaControl,
    Notice,
    PanelBody,
    Modal,
    Spinner,
    apiFetch,
} from './wp';
import { payload } from './state';

export default function EmailTemplateEditor({
    kind,
    settings,
    onChange,
    form,
    templates,
}) {
    const label = kind === 'receipt' ? 'Receipt' : 'Notification';
    const [open, setOpen] = useState(false),
        [loading, setLoading] = useState(false),
        [preview, setPreview] = useState(null),
        [error, setError] = useState('');
    const pending = useRef(null);
    useEffect(() => () => pending.current?.abort(), []);
    function close() {
        pending.current?.abort();
        setLoading(false);
        setOpen(false);
    }
    async function showPreview() {
        pending.current?.abort();
        const controller = new AbortController();
        pending.current = controller;
        setOpen(true);
        setLoading(true);
        setPreview(null);
        setError('');
        try {
            const result = await apiFetch({
                path: '/plain-forms/v1/admin/email-preview',
                method: 'POST',
                data: { ...payload(form), id: form.id || 0, kind },
                signal: controller.signal,
            });
            if (!controller.signal.aborted) setPreview(result);
        } catch (e) {
            if (!controller.signal.aborted)
                setError(
                    e.message ||
                        'The email could not be previewed. Check the template and try again.',
                );
        } finally {
            if (!controller.signal.aborted) setLoading(false);
        }
    }
    const placeholders = [
        '{form_title}',
        '{submission_id}',
        '{system.site_name}',
        '{system.submitted_at}',
        '{system.site_url}',
        '{system.all_fields}',
        ...(kind === 'notification'
            ? ['{system.admin_url}', '{system.admin_link}']
            : []),
        ...form.config.fields.map((f) => '{' + f.id + '}'),
    ];
    const file = templates?.theme?.[kind];
    const markup = preview
        ? "<!doctype html><html><head><meta http-equiv=\"Content-Security-Policy\" content=\"default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-src 'none'\"></head><body>" +
          preview.html +
          '</body></html>'
        : '';
    return (
        <div className="plainforms-email-template-editor">
            <div className="plainforms-two-columns">
                <SelectControl
                    label={`${label} email format`}
                    value={settings.format}
                    options={[
                        { value: 'plain', label: 'Plain text' },
                        { value: 'html', label: 'HTML email' },
                    ]}
                    onChange={(format) => onChange({ format })}
                    __nextHasNoMarginBottom
                    __next40pxDefaultSize
                />
                {settings.format === 'html' && (
                    <SelectControl
                        label={`${label} template source`}
                        value={settings.template_source}
                        options={[
                            { value: 'default', label: 'Built-in template' },
                            {
                                value: 'editor',
                                label: 'Custom HTML in this editor',
                            },
                            {
                                value: 'theme',
                                label: 'Theme / child theme PHP file',
                            },
                        ]}
                        onChange={(source) =>
                            onChange({
                                template_source: source,
                                ...(source === 'editor' && !settings.template
                                    ? {
                                          template:
                                              templates?.defaults?.[kind] ||
                                              '<p>{form_title}</p>',
                                      }
                                    : {}),
                            })
                        }
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                    />
                )}
            </div>
            {settings.format === 'html' && (
                <>
                    {settings.template_source === 'editor' && (
                        <div className="plainforms-html-source">
                            <TextareaControl
                                label={`${label} HTML template`}
                                value={settings.template}
                                onChange={(template) => onChange({ template })}
                                rows={14}
                                spellCheck={false}
                                help="Use email-safe HTML with inline styles. Field placeholders are escaped; PHP, scripts and embedded styles are not supported here."
                                __nextHasNoMarginBottom
                            />
                            <Button
                                variant="secondary"
                                onClick={() =>
                                    onChange({
                                        template:
                                            templates?.defaults?.[kind] ||
                                            '<p>{form_title}</p>',
                                    })
                                }
                            >
                                Insert{' '}
                                {kind === 'receipt'
                                    ? 'receipt'
                                    : 'notification'}{' '}
                                starter HTML
                            </Button>
                        </div>
                    )}
                    {settings.template_source === 'theme' && (
                        <div className="plainforms-theme-template-help">
                            <p>Place the file in your child theme or theme:</p>
                            <ul>
                                {form.id > 0 && (
                                    <li>
                                        <code>{`plain-forms/emails/${kind}-${form.id}.php`}</code>{' '}
                                        <span>(this form)</span>
                                    </li>
                                )}
                                <li>
                                    <code>{`plain-forms/emails/${kind}.php`}</code>{' '}
                                    <span>(shared fallback)</span>
                                </li>
                            </ul>
                            {!form.id && (
                                <p>
                                    Save this form to see its form-specific
                                    filename.
                                </p>
                            )}
                            {file ? (
                                <Notice status="info" isDismissible={false}>
                                    Currently using <code>{file}</code>.
                                </Notice>
                            ) : (
                                <Notice status="warning" isDismissible={false}>
                                    No matching theme template was found. The
                                    built-in HTML template is used until you add
                                    a file.
                                </Notice>
                            )}
                            <p>
                                Form-specific files take priority. For each
                                filename, the child theme is checked before the
                                parent theme.
                            </p>
                        </div>
                    )}
                    <div className="plainforms-email-template-actions">
                        <Button
                            variant="secondary"
                            onClick={showPreview}
                            disabled={loading}
                        >
                            Preview{' '}
                            {kind === 'receipt' ? 'receipt' : 'notification'}{' '}
                            email
                        </Button>
                        <span>Uses sample values. No email is sent.</span>
                    </div>
                    <PanelBody
                        title={`${label} placeholders`}
                        initialOpen={false}
                    >
                        <p>
                            Insert these directly into the HTML.{' '}
                            <code>{'{system.all_fields}'}</code> expands to a
                            labelled table of the submitted values.
                        </p>
                        <div className="plainforms-placeholder-list">
                            {placeholders.map((token) => (
                                <code key={token}>{token}</code>
                            ))}
                        </div>
                        {kind === 'receipt' && (
                            <p>
                                Receipts do not expose internal submission
                                links.
                            </p>
                        )}
                    </PanelBody>
                </>
            )}
            {open && (
                <Modal
                    title={`${label} email preview`}
                    onRequestClose={close}
                    className="plainforms-email-preview-modal"
                >
                    {loading && (
                        <div className="plainforms-preview-loading">
                            <Spinner />
                            <p>Rendering sample email…</p>
                        </div>
                    )}
                    {error && (
                        <Notice status="error" isDismissible={false}>
                            {error}
                        </Notice>
                    )}
                    {preview && (
                        <>
                            {preview.fallback && (
                                <Notice status="warning" isDismissible={false}>
                                    A theme template was not found, so this
                                    preview uses the built-in template.
                                </Notice>
                            )}
                            {preview.themeTemplate && (
                                <p>
                                    Theme template:{' '}
                                    <code>{preview.themeTemplate}</code>
                                </p>
                            )}
                            <iframe
                                title={`${label} email preview content`}
                                sandbox=""
                                referrerPolicy="no-referrer"
                                srcDoc={markup}
                            />
                            <p className="plainforms-muted">
                                A safe preview using sample information.
                                Appearance may vary between email clients.
                            </p>
                        </>
                    )}
                    <div className="plainforms-modal-actions">
                        <Button variant="secondary" onClick={close}>
                            Close email preview
                        </Button>
                    </div>
                </Modal>
            )}
        </div>
    );
}
