// React and UI components are provided by WordPress. They are never bundled a second time.
export const {
    createElement: h,
    Fragment,
    createRoot,
    useState,
    useEffect,
    useMemo,
    useCallback,
    useRef,
    useReducer,
} = window.wp.element;
export const {
    Button,
    TextControl,
    TextareaControl,
    SelectControl,
    ToggleControl,
    CheckboxControl,
    PanelBody,
    Notice,
    TabPanel,
    Modal,
    Spinner,
} = window.wp.components;
export const apiFetch = window.wp.apiFetch;
export const { __ } = window.wp.i18n;
export const t = (text) => __(text, 'plain-forms');
export function Glyph({ name }) {
    return h('span', {
        className: `dashicons dashicons-${name}`,
        'aria-hidden': true,
    });
}
