import {
    h,
    Fragment,
    useState,
    useRef,
    useEffect,
    Button,
    TextControl,
    ToggleControl,
    TabPanel,
    Modal,
    apiFetch,
    Glyph,
} from './wp';
import {
    hydrate,
    payload,
    useHistory,
    useSaveGuard,
    types,
    typeLabel,
    newField,
    uniqueId,
    formErrors,
    key,
} from './state';
import { PageHeader, Feedback, SaveButton } from './common';
import { FieldPreview, Preview } from './Preview';
import FieldInspector from './FieldInspector';
import FormSettings from './FormSettings';

export default function Builder({ data, links }) {
    const [initial] = useState(() => hydrate(data.form));
    const history = useHistory(initial);
    const form = history.value;
    const [baseline, setBaseline] = useState(() =>
        JSON.stringify(payload(initial)),
    );
    const [selected, setSelected] = useState(
        initial.config.fields[0]?._key || null,
    );
    const [emailTemplates, setEmailTemplates] = useState(data.emailTemplates);
    const [feedback, setFeedback] = useState(null);
    const [busy, setBusy] = useState(false);
    const [remove, setRemove] = useState(null);
    const [tab, setTab] = useState('build');
    const feedbackRef = useRef(null);
    const saveInFlight = useRef(false);
    const dirty = JSON.stringify(payload(form)) !== baseline;
    const fields = form.config.fields;
    const active = fields.find((field) => field._key === selected);
    const changeConfig = (config, group) =>
        history.update((value) => ({ ...value, config }), group);
    function changeField(field, group) {
        history.update(
            (value) => ({
                ...value,
                config: {
                    ...value.config,
                    fields: value.config.fields.map((item) =>
                        item._key === field._key ? field : item,
                    ),
                },
            }),
            `${field._key}-${group || 'change'}`,
        );
    }
    function add(type) {
        const field = newField(type, fields);
        history.update((value) => ({
            ...value,
            config: {
                ...value.config,
                fields: [...value.config.fields, field],
            },
        }));
        setSelected(field._key);
    }
    function move(index, delta) {
        history.update((value) => {
            const next = [...value.config.fields];
            [next[index], next[index + delta]] = [
                next[index + delta],
                next[index],
            ];
            return { ...value, config: { ...value.config, fields: next } };
        });
    }
    function duplicate(field, index) {
        const copy = {
            ...JSON.parse(JSON.stringify(field)),
            _key: key(),
            id: uniqueId(fields, field.id),
            label: field.label + ' (copy)',
        };
        history.update((value) => {
            const next = [...value.config.fields];
            next.splice(index + 1, 0, copy);
            return { ...value, config: { ...value.config, fields: next } };
        });
        setSelected(copy._key);
    }
    function removeField() {
        const index = fields.findIndex((f) => f._key === remove);
        const next = fields.filter((f) => f._key !== remove);
        history.update((value) => ({
            ...value,
            config: { ...value.config, fields: next },
        }));
        if (selected === remove)
            setSelected(next[Math.min(index, next.length - 1)]?._key || null);
        setRemove(null);
    }
    function starter() {
        const next = [
            newField('text', [], 'Name'),
            newField('email', [], 'Email address'),
            newField('text', [], 'Subject'),
            newField('textarea', [], 'Message'),
        ];
        next[0].id = 'name';
        next[0].autocomplete = 'name';
        next[2].id = 'subject';
        next[3].id = 'message';
        next.forEach((f) => {
            f.required = true;
        });
        history.update((value) => ({
            ...value,
            config: {
                ...value.config,
                fields: next,
                notification: {
                    ...value.config.notification,
                    reply_to_field: 'email',
                },
            },
        }));
        setSelected(next[0]._key);
    }
    async function save() {
        if (saveInFlight.current || (!dirty && form.id)) return;
        const errors = formErrors(form);
        if (errors.length) {
            setFeedback({
                status: 'error',
                message: 'A few details need your attention.',
                items: errors.map((e) => e.message),
            });
            if (errors[0].key) {
                setSelected(errors[0].key);
                setTab('build');
            }
            return;
        }
        saveInFlight.current = true;
        setBusy(true);
        setFeedback(null);
        try {
            const result = await apiFetch({
                path:
                    '/plain-forms/v1/admin/forms' +
                    (form.id ? '/' + form.id : ''),
                method: 'POST',
                data: payload(form),
            });
            const accepted = hydrate(result.form, fields);
            if (result.emailTemplates) setEmailTemplates(result.emailTemplates);
            history.reset(accepted);
            setBaseline(JSON.stringify(payload(accepted)));
            setFeedback({
                status: 'success',
                message:
                    'Form saved. Pages using this form will show the latest version.',
            });
            const url = new URL(window.location.href);
            url.searchParams.set('page', 'plain-forms-edit');
            url.searchParams.set('id', result.form.id);
            url.searchParams.delete('notice');
            window.history.replaceState(null, '', url);
        } catch (error) {
            setFeedback({
                status: 'error',
                message:
                    error.message ||
                    'The form could not be saved. Your changes are still here; please try again.',
            });
        } finally {
            saveInFlight.current = false;
            setBusy(false);
        }
    }
    useSaveGuard(dirty, save);
    useEffect(() => {
        if (feedback?.status === 'error') feedbackRef.current?.focus();
    }, [feedback]);
    useEffect(() => {
        if (selected && !fields.some((f) => f._key === selected))
            setSelected(fields[0]?._key || null);
    }, [fields, selected]);
    return (
        <>
            <PageHeader
                title={form.id ? 'Edit form' : 'Create a form'}
                description="Build a clear, welcoming way for visitors to get in touch."
                actions={
                    <>
                        <Button variant="tertiary" href={links.forms}>
                            All forms
                        </Button>
                        <SaveButton
                            busy={busy}
                            dirty={dirty || !form.id}
                            onSave={save}
                            label="Save form"
                        />
                    </>
                }
            />
            <Feedback
                feedback={feedback}
                onDismiss={() => setFeedback(null)}
                focusRef={feedbackRef}
            />
            <fieldset
                className="plainforms-app-fieldset"
                disabled={busy}
                aria-busy={busy}
            >
                <div className="plainforms-form-identity">
                    <div className="plainforms-title-input">
                        <TextControl
                            label="Form title"
                            value={form.title}
                            onChange={(title) =>
                                history.update(
                                    (value) => ({ ...value, title }),
                                    'title',
                                )
                            }
                            placeholder="For example, General enquiries"
                            __nextHasNoMarginBottom
                            __next40pxDefaultSize
                        />
                    </div>
                    <div className="plainforms-identity-status">
                        <span
                            className={`plainforms-badge ${form.config.enabled ? 'is-success' : ''}`}
                        >
                            {form.config.enabled ? 'Enabled' : 'Disabled'}
                        </span>
                        <ToggleControl
                            label="Enable this form"
                            checked={form.config.enabled}
                            onChange={(enabled) =>
                                changeConfig(
                                    { ...form.config, enabled },
                                    'enabled',
                                )
                            }
                            __nextHasNoMarginBottom
                        />
                    </div>
                </div>
                <div className="plainforms-builder-toolbar">
                    <div
                        className="plainforms-document-tabs"
                        role="group"
                        aria-label="Editor view"
                    >
                        {[
                            ['build', 'Build'],
                            ['settings', 'Form settings'],
                            ['preview', 'Preview'],
                        ].map(([id, label]) => (
                            <Button
                                key={id}
                                variant={tab === id ? 'primary' : 'tertiary'}
                                aria-pressed={tab === id}
                                onClick={() => setTab(id)}
                            >
                                {label}
                            </Button>
                        ))}
                    </div>
                    <div className="plainforms-toolbar-meta">
                        <span role="status" aria-live="polite">
                            {dirty
                                ? 'Unsaved changes'
                                : form.id
                                  ? 'All changes saved'
                                  : 'Not saved yet'}
                        </span>
                        <Button
                            variant="tertiary"
                            disabled={!history.canUndo}
                            onClick={history.undo}
                            aria-label="Undo change"
                            title="Undo"
                        >
                            <Glyph name="undo" />
                        </Button>
                        <Button
                            variant="tertiary"
                            disabled={!history.canRedo}
                            onClick={history.redo}
                            aria-label="Redo change"
                            title="Redo"
                        >
                            <Glyph name="redo" />
                        </Button>
                        <Button
                            variant="primary"
                            onClick={save}
                            disabled={busy || (!dirty && !!form.id)}
                            isBusy={busy}
                        >
                            Save
                        </Button>
                    </div>
                </div>
                {tab === 'build' && (
                    <div className="plainforms-builder-grid">
                        <aside className="plainforms-palette" aria-label="Add fields">
                            <div className="plainforms-eyebrow">YOUR TOOLBOX</div>
                            <h2>Add a field</h2>
                            <p>Choose a field, then edit its details.</p>
                            <div className="plainforms-palette-grid">
                                {types.map(([type, label, icon]) => (
                                    <Button
                                        key={type}
                                        className="plainforms-palette-button"
                                        variant="secondary"
                                        disabled={fields.length >= 50}
                                        onClick={() => add(type)}
                                    >
                                        <Glyph name={icon} />
                                        {label}
                                    </Button>
                                ))}
                            </div>
                            <p className="plainforms-muted">
                                {fields.length} of 50 fields
                            </p>
                        </aside>
                        <section className="plainforms-canvas" aria-label="Form fields">
                            <div className="plainforms-canvas-heading">
                                <h2>Your form</h2>
                                <span>
                                    {fields.length}{' '}
                                    {fields.length === 1 ? 'field' : 'fields'}
                                </span>
                            </div>
                            {!fields.length ? (
                                <div className="plainforms-empty-state">
                                    <div className="plainforms-empty-icon">
                                        <Glyph name="feedback" />
                                    </div>
                                    <h3>Every enquiry starts here</h3>
                                    <p>
                                        Add your first field from the toolbox,
                                        or start with a simple contact form.
                                    </p>
                                    <Button variant="primary" onClick={starter}>
                                        Start with a contact form
                                    </Button>
                                </div>
                            ) : (
                                <div className="plainforms-canvas-fields">
                                    {fields.map((field, index) => (
                                        <article
                                            className={`plainforms-canvas-field ${field._key === selected ? 'is-selected' : ''}`}
                                            key={field._key}
                                        >
                                            <div className="plainforms-field-card-toolbar">
                                                <Button
                                                    className="plainforms-select-field"
                                                    variant="tertiary"
                                                    aria-pressed={
                                                        selected === field._key
                                                    }
                                                    onClick={() =>
                                                        setSelected(field._key)
                                                    }
                                                    aria-label={`Edit ${field.label || 'untitled field'}`}
                                                >
                                                    <span className="plainforms-field-number">
                                                        {index + 1}
                                                    </span>
                                                    <span>
                                                        {typeLabel(field.type)}
                                                    </span>
                                                </Button>
                                                <div className="plainforms-field-actions">
                                                    <Button
                                                        variant="tertiary"
                                                        disabled={index === 0}
                                                        onClick={() =>
                                                            move(index, -1)
                                                        }
                                                        aria-label={`Move ${field.label} up`}
                                                        title="Move up"
                                                    >
                                                        <Glyph name="arrow-up-alt2" />
                                                    </Button>
                                                    <Button
                                                        variant="tertiary"
                                                        disabled={
                                                            index ===
                                                            fields.length - 1
                                                        }
                                                        onClick={() =>
                                                            move(index, 1)
                                                        }
                                                        aria-label={`Move ${field.label} down`}
                                                        title="Move down"
                                                    >
                                                        <Glyph name="arrow-down-alt2" />
                                                    </Button>
                                                    <Button
                                                        variant="tertiary"
                                                        disabled={
                                                            fields.length >= 50
                                                        }
                                                        onClick={() =>
                                                            duplicate(
                                                                field,
                                                                index,
                                                            )
                                                        }
                                                        aria-label={`Duplicate ${field.label}`}
                                                        title="Duplicate"
                                                    >
                                                        <Glyph name="admin-page" />
                                                    </Button>
                                                    <Button
                                                        variant="tertiary"
                                                        isDestructive
                                                        onClick={() =>
                                                            setRemove(
                                                                field._key,
                                                            )
                                                        }
                                                        aria-label={`Remove ${field.label}`}
                                                        title="Remove"
                                                    >
                                                        <Glyph name="trash" />
                                                    </Button>
                                                </div>
                                            </div>
                                            <div
                                                className="plainforms-selectable-preview"
                                                onClick={() =>
                                                    setSelected(field._key)
                                                }
                                            >
                                                <FieldPreview
                                                    field={field}
                                                    compact
                                                />
                                            </div>
                                        </article>
                                    ))}
                                </div>
                            )}
                            <div className="plainforms-canvas-footer">
                                <Glyph name="lock" /> Entries are validated
                                securely on your website.
                            </div>
                        </section>
                        {active ? (
                            <FieldInspector
                                key={active._key}
                                field={active}
                                onChange={changeField}
                            />
                        ) : (
                            <aside className="plainforms-inspector plainforms-inspector-empty">
                                <Glyph name="edit" />
                                <h2>Make it your own</h2>
                                <p>
                                    Select a field to edit its label, choices
                                    and settings.
                                </p>
                            </aside>
                        )}
                    </div>
                )}
                {tab === 'settings' && (
                    <FormSettings
                        form={form}
                        defaults={data.defaults}
                        templates={emailTemplates}
                        onChange={changeConfig}
                    />
                )}
                {tab === 'preview' && (
                    <Preview form={form} defaults={data.defaults} />
                )}
            </fieldset>
            <div className="plainforms-app-footer">
                <span>
                    {form.id ? `Form #${form.id}` : 'New form'}
                    {form.id && (
                        <>
                            {' '}
                            · <code>{`[plainforms_form id="${form.id}"]`}</code>
                        </>
                    )}
                </span>
                <span>Tip: use Ctrl / ⌘ + S to save.</span>
            </div>
            {remove && (
                <Modal
                    title="Remove this field?"
                    onRequestClose={() => setRemove(null)}
                >
                    <p>
                        The field will be removed from this form. Existing
                        enquiries keep their original values. You can undo this
                        change before saving.
                    </p>
                    <div className="plainforms-modal-actions">
                        <Button
                            variant="tertiary"
                            onClick={() => setRemove(null)}
                        >
                            Keep field
                        </Button>
                        <Button
                            variant="primary"
                            isDestructive
                            onClick={removeField}
                        >
                            Remove field
                        </Button>
                    </div>
                </Modal>
            )}
        </>
    );
}
