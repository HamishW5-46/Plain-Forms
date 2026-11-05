import { h, Fragment, useState, Button, Glyph } from './wp';
export function FieldPreview({ field, compact = false }) {
    const f = field;
    if (f.type === 'hidden')
        return (
            <div className="plainforms-hidden-preview">
                <Glyph name="hidden" /> Hidden value{' '}
                <code>{f.default || 'No default value'}</code>
            </div>
        );
    const label = (
        <span>
            {f.label || 'Untitled field'}
            {f.required && <span className="plainforms-required-tag">Required</span>}
        </span>
    );
    const common = {
        disabled: true,
        tabIndex: -1,
        'aria-label': f.label || 'Untitled field',
    };
    let control;
    if (f.type === 'textarea')
        control = (
            <textarea
                {...common}
                rows={compact ? 2 : 4}
                placeholder={f.placeholder}
                value={f.default || ''}
                readOnly
            />
        );
    else if (f.type === 'select')
        control = (
            <select {...common} value={f.default || ''} onChange={() => {}}>
                <option value="">Please select</option>
                {f.options.map((o, i) => (
                    <option key={i} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        );
    else if (['checkbox', 'consent'].includes(f.type))
        control = (
            <label className="plainforms-preview-choice">
                <input
                    {...common}
                    type="checkbox"
                    checked={!!f.default}
                    readOnly
                />
                {label}
            </label>
        );
    else if (['radio', 'checkbox_group'].includes(f.type))
        control = (
            <div>
                {f.options.slice(0, compact ? 3 : 100).map((o, i) => (
                    <label key={i} className="plainforms-preview-choice">
                        <input
                            {...common}
                            type={f.type === 'radio' ? 'radio' : 'checkbox'}
                            checked={
                                f.type === 'radio'
                                    ? f.default === o.value
                                    : (f.default || []).includes(o.value)
                            }
                            readOnly
                        />
                        {o.label}
                    </label>
                ))}
            </div>
        );
    else
        control = (
            <input
                {...common}
                type={f.type}
                placeholder={f.placeholder}
                value={f.default || ''}
                readOnly
            />
        );
    return (
        <div className="plainforms-field-preview">
            {!['checkbox', 'consent'].includes(f.type) && (
                <div className="plainforms-preview-label">{label}</div>
            )}
            {control}
            {f.description && (
                <p className="plainforms-preview-help">{f.description}</p>
            )}
        </div>
    );
}
export function Preview({ form, defaults }) {
    const [mobile, setMobile] = useState(false);
    return (
        <div className="plainforms-preview-workspace">
            <div className="plainforms-preview-toolbar">
                <p>Preview only. Submissions are disabled here.</p>
                <div className="plainforms-button-group">
                    <Button
                        variant={!mobile ? 'primary' : 'secondary'}
                        onClick={() => setMobile(false)}
                    >
                        Desktop
                    </Button>
                    <Button
                        variant={mobile ? 'primary' : 'secondary'}
                        onClick={() => setMobile(true)}
                    >
                        Mobile
                    </Button>
                </div>
            </div>
            <div className={`plainforms-form-preview ${mobile ? 'is-mobile' : ''}`}>
                <h2>{form.title || 'Untitled form'}</h2>
                {form.config.description && (
                    <p className="plainforms-description-preview">
                        {form.config.description}
                    </p>
                )}
                {!form.config.fields.length && (
                    <p>Add a field to see your form here.</p>
                )}
                {form.config.fields.map((f) => (
                    <FieldPreview key={f._key} field={f} />
                ))}
                {(form.config.turnstile ?? defaults.turnstile) && (
                    <div className="plainforms-verification-preview">
                        <Glyph name="shield" /> Spam verification appears on
                        your website.
                    </div>
                )}
                <Button variant="primary" disabled>
                    Send enquiry
                </Button>
                <p className="plainforms-confirmation-preview">
                    <strong>After submission</strong>
                    <br />
                    {form.config.confirmation.message ||
                        defaults.successMessage}
                </p>
            </div>
        </div>
    );
}
