import {
    h,
    Fragment,
    Button,
    Notice,
    Glyph,
    t,
    TextControl,
    useState,
    useEffect,
} from './wp';
import { listEmails } from './state';
export function EmailsControl({ value, onChange, ...props }) {
    const [text, setText] = useState(() => value.join(', '));
    useEffect(() => {
        setText((old) =>
            JSON.stringify(listEmails(old)) === JSON.stringify(value)
                ? old
                : value.join(', '),
        );
    }, [value]);
    return (
        <TextControl
            {...props}
            value={text}
            onChange={(next) => {
                setText(next);
                onChange(listEmails(next));
            }}
            __nextHasNoMarginBottom
            __next40pxDefaultSize
        />
    );
}
export function PageHeader({ eyebrow, title, description, actions }) {
    return (
        <header className="plainforms-app-header">
            <div>
                <div className="plainforms-eyebrow">
                    <Glyph name="feedback" /> {eyebrow || 'PLAIN FORMS'}
                </div>
                <h1>{title}</h1>
                {description && <p>{description}</p>}
            </div>
            <div className="plainforms-header-actions">{actions}</div>
        </header>
    );
}
export function Card({ title, description, children, className = '' }) {
    return (
        <section className={`plainforms-card ${className}`}>
            {title && (
                <div className="plainforms-card-heading">
                    <h2>{title}</h2>
                    {description && <p>{description}</p>}
                </div>
            )}
            <div className="plainforms-card-body">{children}</div>
        </section>
    );
}
export function Feedback({ feedback, onDismiss, focusRef }) {
    if (!feedback) return null;
    return (
        <div ref={focusRef} tabIndex="-1" className="plainforms-feedback">
            <Notice
                status={feedback.status}
                onRemove={onDismiss}
                isDismissible={true}
            >
                <span>{feedback.message}</span>
                {feedback.items?.length > 0 && (
                    <ul>
                        {feedback.items.map((item, index) => (
                            <li key={index}>{item}</li>
                        ))}
                    </ul>
                )}
            </Notice>
        </div>
    );
}
export function SaveButton({ busy, dirty, onSave, label = 'Save changes' }) {
    return (
        <Button
            variant="primary"
            onClick={onSave}
            isBusy={busy}
            disabled={busy || !dirty}
            className="plainforms-save-button"
        >
            {busy ? t('Saving…') : t(label)}
        </Button>
    );
}
