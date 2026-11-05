import {
    h,
    Fragment,
    TextControl,
    TextareaControl,
    SelectControl,
    ToggleControl,
    CheckboxControl,
    PanelBody,
    Button,
    Notice,
    Glyph,
} from './wp';
import { types, isChoice, isBoolean, uniqueId } from './state';
export default function FieldInspector({ field, onChange }) {
    const f = field;
    const set = (name, value) => onChange({ ...f, [name]: value }, name);
    const option = (index, part, value) => {
        const options = f.options.map((item, i) =>
            i === index ? { ...item, [part]: value } : item,
        );
        let defaultValue = f.default;
        if (part === 'value')
            defaultValue = Array.isArray(f.default)
                ? f.default.map((v) =>
                      v === f.options[index].value ? value : v,
                  )
                : f.default === f.options[index].value
                  ? value
                  : f.default;
        onChange(
            { ...f, options, default: defaultValue },
            `option-${index}-${part}`,
        );
    };
    return (
        <aside className="plainforms-inspector" aria-label="Field settings">
            <div className="plainforms-inspector-heading">
                <div className="plainforms-eyebrow">FIELD SETTINGS</div>
                <h2>{f.label || 'Untitled field'}</h2>
            </div>
            <div className="plainforms-inspector-content">
                <TextControl
                    id="plainforms-field-label"
                    label="Label"
                    value={f.label}
                    onChange={(v) => set('label', v)}
                    __nextHasNoMarginBottom
                    __next40pxDefaultSize
                />
                <SelectControl
                    label="Field type"
                    value={f.type}
                    options={types.map(([value, label]) => ({ value, label }))}
                    onChange={(type) =>
                        onChange({
                            ...f,
                            type,
                            default: isBoolean(type)
                                ? false
                                : type === 'checkbox_group'
                                  ? []
                                  : '',
                            options: isChoice(type)
                                ? f.options.length
                                    ? f.options
                                    : [
                                          {
                                              value: 'option_1',
                                              label: 'First option',
                                          },
                                      ]
                                : [],
                            pattern: ['text', 'tel'].includes(type)
                                ? f.pattern
                                : '',
                        })
                    }
                    __nextHasNoMarginBottom
                    __next40pxDefaultSize
                />
                <TextareaControl
                    label="Help text"
                    value={f.description}
                    onChange={(v) => set('description', v)}
                    rows={2}
                    help="Optional guidance shown below this field."
                    __nextHasNoMarginBottom
                />
                {f.type !== 'hidden' && (
                    <ToggleControl
                        label="Required field"
                        checked={f.required}
                        onChange={(v) => set('required', v)}
                        __nextHasNoMarginBottom
                    />
                )}
                {isChoice(f.type) && (
                    <div className="plainforms-option-editor">
                        <h3>Choices</h3>
                        <p>
                            Labels are shown to visitors. Values are saved with
                            each enquiry.
                        </p>
                        {f.options.map((item, index) => (
                            <div className="plainforms-option-row" key={index}>
                                <TextControl
                                    label={`Option ${index + 1} label`}
                                    value={item.label}
                                    onChange={(v) => option(index, 'label', v)}
                                    __nextHasNoMarginBottom
                                    __next40pxDefaultSize
                                />
                                <TextControl
                                    label={`Option ${index + 1} value`}
                                    value={item.value}
                                    onChange={(v) => option(index, 'value', v)}
                                    __nextHasNoMarginBottom
                                    __next40pxDefaultSize
                                />
                                <Button
                                    variant="tertiary"
                                    isDestructive
                                    onClick={() => {
                                        const options = f.options.filter(
                                            (_, i) => i !== index,
                                        );
                                        const values = options.map(
                                            (o) => o.value,
                                        );
                                        onChange({
                                            ...f,
                                            options,
                                            default: Array.isArray(f.default)
                                                ? f.default.filter((v) =>
                                                      values.includes(v),
                                                  )
                                                : values.includes(f.default)
                                                  ? f.default
                                                  : '',
                                        });
                                    }}
                                    aria-label={`Remove option ${index + 1}`}
                                >
                                    <Glyph name="no-alt" />
                                </Button>
                            </div>
                        ))}
                        <Button
                            variant="secondary"
                            disabled={f.options.length >= 100}
                            onClick={() =>
                                set('options', [
                                    ...f.options,
                                    {
                                        value: uniqueId(
                                            f.options.map((o) => ({
                                                id: o.value,
                                            })),
                                            'option',
                                        ),
                                        label: 'New option',
                                    },
                                ])
                            }
                        >
                            Add option
                        </Button>
                    </div>
                )}
                {['text', 'email', 'tel', 'textarea'].includes(f.type) && (
                    <TextControl
                        label="Placeholder"
                        value={f.placeholder}
                        onChange={(v) => set('placeholder', v)}
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                    />
                )}
                <PanelBody title="Advanced settings" initialOpen={false}>
                    <TextControl
                        label="Field ID"
                        value={f.id}
                        onChange={(v) => set('id', v)}
                        help="A stable reference for saved values and email placeholders. Use lowercase letters, numbers and underscores."
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                    />
                    {['text', 'email', 'tel', 'textarea'].includes(f.type) && (
                        <>
                            <TextControl
                                label="Autocomplete"
                                value={f.autocomplete}
                                onChange={(v) => set('autocomplete', v)}
                                help="For example: name, email or tel."
                                __nextHasNoMarginBottom
                                __next40pxDefaultSize
                            />
                            <div className="plainforms-two-columns">
                                <TextControl
                                    label="Minimum length"
                                    type="number"
                                    min="0"
                                    max="20000"
                                    value={f.min_length}
                                    onChange={(v) =>
                                        set(
                                            'min_length',
                                            v === '' ? '' : Number(v),
                                        )
                                    }
                                    __nextHasNoMarginBottom
                                    __next40pxDefaultSize
                                />
                                <TextControl
                                    label="Maximum length"
                                    type="number"
                                    min="1"
                                    max="20000"
                                    value={f.max_length}
                                    onChange={(v) =>
                                        set(
                                            'max_length',
                                            v === '' ? '' : Number(v),
                                        )
                                    }
                                    __nextHasNoMarginBottom
                                    __next40pxDefaultSize
                                />
                            </div>
                        </>
                    )}
                    {['text', 'tel'].includes(f.type) && (
                        <TextControl
                            label="Validation pattern"
                            value={f.pattern}
                            onChange={(v) => set('pattern', v)}
                            help="Optional simple pattern, such as [A-Za-z ]{2,50}."
                            __nextHasNoMarginBottom
                            __next40pxDefaultSize
                        />
                    )}
                    {f.type === 'consent' ? (
                        <Notice status="info" isDismissible={false}>
                            Consent is never selected by default.
                        </Notice>
                    ) : f.type === 'checkbox' ? (
                        <ToggleControl
                            label="Selected by default"
                            checked={f.default}
                            onChange={(v) => set('default', v)}
                            __nextHasNoMarginBottom
                        />
                    ) : f.type === 'checkbox_group' ? (
                        <fieldset className="plainforms-default-options">
                            <legend>Default selections</legend>
                            {f.options.map((o, i) => (
                                <CheckboxControl
                                    key={i}
                                    label={o.label}
                                    checked={f.default.includes(o.value)}
                                    onChange={(checked) =>
                                        set(
                                            'default',
                                            checked
                                                ? [...f.default, o.value]
                                                : f.default.filter(
                                                      (v) => v !== o.value,
                                                  ),
                                        )
                                    }
                                    __nextHasNoMarginBottom
                                />
                            ))}
                        </fieldset>
                    ) : isChoice(f.type) ? (
                        <SelectControl
                            label="Default selection"
                            value={f.default}
                            options={[
                                { value: '', label: 'No default' },
                                ...f.options.map((o) => ({
                                    value: o.value,
                                    label: o.label,
                                })),
                            ]}
                            onChange={(v) => set('default', v)}
                            __nextHasNoMarginBottom
                            __next40pxDefaultSize
                        />
                    ) : (
                        <TextControl
                            label="Default value"
                            value={f.default}
                            onChange={(v) => set('default', v)}
                            help={
                                f.type === 'hidden'
                                    ? 'Visible in page source. Never put secrets here.'
                                    : undefined
                            }
                            __nextHasNoMarginBottom
                            __next40pxDefaultSize
                        />
                    )}
                </PanelBody>
            </div>
        </aside>
    );
}
