(() => {
    'use strict';
    const {createElement: h, useState, useEffect, Fragment} = wp.element;
    const {SelectControl, ToggleControl, PanelBody, Notice} = wp.components;
    wp.blocks.registerBlockType('plain-forms/form', {
        edit: ({attributes, setAttributes}) => {
            const [forms, setForms] = useState([]);
            const [error, setError] = useState('');
            useEffect(() => { let active = true; wp.apiFetch({path:'/plain-forms/v1/forms'}).then(data => { if(active) setForms(data); }).catch(() => { if(active) setError('Unable to load forms. Ask an administrator to check your access.'); }); return () => { active=false; }; }, []);
            const form = forms.find(f => f.id === attributes.formId);
            const selector = h(SelectControl, {label:'Form', value:attributes.formId, options:[{label:'Select a form',value:0}, ...forms.map(f=>({label:f.title + (f.enabled?'':' (disabled)'),value:f.id}))],onChange:v=>setAttributes({formId:Number(v)})});
            const preview = form ? h(Fragment, null,
                attributes.showTitle && h('h2', null, form.title),
                attributes.showDescription && h('p', null, form.description),
                h('ul', null, form.fields.map(f => h('li', {key:f.id}, f.label + (f.required ? ' (required)' : ''))))
            ) : null;
            return h(Fragment, null,
                h(wp.blockEditor.InspectorControls, null,
                    h(PanelBody, {title:'Form settings'}, selector,
                        h(ToggleControl, {label:'Show title', checked:attributes.showTitle, onChange:v=>setAttributes({showTitle:v})}),
                        h(ToggleControl, {label:'Show description', checked:attributes.showDescription, onChange:v=>setAttributes({showDescription:v})})
                    )
                ),
                h('div', wp.blockEditor.useBlockProps({className:'plainforms-editor-preview'}),
                    selector, error && h(Notice, {status:'error', isDismissible:false}, error), preview,
                    h('p', null, 'The live form is rendered on the website.')
                )
            );
        },
        save: () => null
    });
})();
