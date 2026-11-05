import { useReducer, useEffect, useRef } from './wp';
let sequence = 0;
export const key = () => `field-${++sequence}`;
export const types = [
    ['text', 'Short answer', 'editor-textcolor'],
    ['email', 'Email address', 'email'],
    ['tel', 'Phone number', 'phone'],
    ['textarea', 'Long answer', 'editor-alignleft'],
    ['select', 'Dropdown', 'arrow-down-alt2'],
    ['radio', 'Multiple choice', 'marker'],
    ['checkbox', 'Checkbox', 'yes-alt'],
    ['checkbox_group', 'Checkbox group', 'forms'],
    ['consent', 'Consent', 'shield'],
    ['hidden', 'Hidden value', 'hidden'],
];
export const typeLabel = (type) =>
    types.find((item) => item[0] === type)?.[1] || type;
export const isChoice = (type) =>
    ['select', 'radio', 'checkbox_group'].includes(type);
export const isBoolean = (type) => ['checkbox', 'consent'].includes(type);
export const listEmails = (text) => text.split(/[,;\s]+/).filter(Boolean);
export const inheritOptions = [
    { label: 'Use global default', value: 'inherit' },
    { label: 'Enabled', value: 'yes' },
    { label: 'Disabled', value: 'no' },
];
export const inherited = (value) =>
    value === null ? 'inherit' : value ? 'yes' : 'no';
export const fromInherited = (value) =>
    value === 'inherit' ? null : value === 'yes';
export function uniqueId(fields, base) {
    let candidate = base,
        n = 2;
    while (fields.some((field) => field.id === candidate))
        candidate = `${base}_${n++}`;
    return candidate;
}
export function newField(type, fields, label) {
    return {
        _key: key(),
        id: uniqueId(fields, type),
        type,
        label: label || typeLabel(type),
        description: '',
        required: false,
        placeholder: '',
        autocomplete: type === 'email' ? 'email' : type === 'tel' ? 'tel' : '',
        default: isBoolean(type) ? false : type === 'checkbox_group' ? [] : '',
        options: isChoice(type)
            ? [
                  { value: 'option_1', label: 'First option' },
                  { value: 'option_2', label: 'Second option' },
              ]
            : [],
        min_length: 0,
        max_length: type === 'textarea' ? 10000 : 2000,
        pattern: '',
    };
}
export function hydrate(form, previous = []) {
    return {
        ...form,
        config: {
            ...form.config,
            fields: form.config.fields.map((field) => ({
                ...field,
                _key:
                    previous.find((item) => item.id === field.id)?._key ||
                    key(),
            })),
        },
    };
}
export function payload(form) {
    return {
        title: form.title,
        config: {
            ...form.config,
            fields: form.config.fields.map(({ _key, ...field }) => field),
        },
    };
}
export function useHistory(initial) {
    const [state, dispatch] = useReducer(
        (s, a) => {
            if (a.type === 'reset')
                return { past: [], present: a.value, future: [], group: null };
            if (a.type === 'undo')
                return !s.past.length
                    ? s
                    : {
                          past: s.past.slice(0, -1),
                          present: s.past[s.past.length - 1],
                          future: [s.present, ...s.future],
                          group: null,
                      };
            if (a.type === 'redo')
                return !s.future.length
                    ? s
                    : {
                          past: [...s.past, s.present],
                          present: s.future[0],
                          future: s.future.slice(1),
                          group: null,
                      };
            const value = a.change(s.present);
            if (JSON.stringify(value) === JSON.stringify(s.present)) return s;
            const grouped =
                a.group &&
                s.group === a.group &&
                a.time - s.time < 700 &&
                !s.future.length;
            return {
                past: grouped ? s.past : [...s.past, s.present].slice(-50),
                present: value,
                future: [],
                group: a.group,
                time: a.time,
            };
        },
        { past: [], present: initial, future: [], group: null },
    );
    return {
        value: state.present,
        update: (change, group = null) =>
            dispatch({ type: 'update', change, group, time: Date.now() }),
        undo: () => dispatch({ type: 'undo' }),
        redo: () => dispatch({ type: 'redo' }),
        reset: (value) => dispatch({ type: 'reset', value }),
        canUndo: !!state.past.length,
        canRedo: !!state.future.length,
    };
}
export function useSaveGuard(dirty, save) {
    const saveRef = useRef(save);
    saveRef.current = save;
    useEffect(() => {
        const unload = (event) => {
            if (dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        const keyboard = (event) => {
            if (
                (event.ctrlKey || event.metaKey) &&
                event.key.toLowerCase() === 's'
            ) {
                event.preventDefault();
                saveRef.current();
            }
        };
        window.addEventListener('beforeunload', unload);
        window.addEventListener('keydown', keyboard);
        return () => {
            window.removeEventListener('beforeunload', unload);
            window.removeEventListener('keydown', keyboard);
        };
    }, [dirty]);
}
export function formErrors(form) {
    const errors = [];
    const ids = new Set();
    if (!form.title.trim()) errors.push({ message: 'Give your form a title.' });
    for (const f of form.config.fields) {
        if (!f.label.trim())
            errors.push({ key: f._key, message: 'Every field needs a label.' });
        if (
            !/^[a-z][a-z0-9_]{0,49}$/.test(f.id) ||
            ids.has(f.id) ||
            ['form_title', 'submission_id'].includes(f.id)
        )
            errors.push({
                key: f._key,
                message: `“${f.label || 'Field'}” needs a unique field ID using lowercase letters, numbers and underscores.`,
            });
        ids.add(f.id);
        if (
            isChoice(f.type) &&
            (!f.options.length ||
                f.options.some((o) => !o.value.trim() || !o.label.trim()) ||
                new Set(f.options.map((o) => o.value.trim())).size !==
                    f.options.length)
        )
            errors.push({
                key: f._key,
                message: `Check the option labels and unique values for “${f.label}”.`,
            });
        if (
            !Number.isInteger(f.min_length) ||
            !Number.isInteger(f.max_length) ||
            f.min_length < 0 ||
            f.max_length < Math.max(1, f.min_length) ||
            f.max_length > 20000
        )
            errors.push({
                key: f._key,
                message: `Check the length limits for “${f.label}”.`,
            });
    }
    return errors;
}
