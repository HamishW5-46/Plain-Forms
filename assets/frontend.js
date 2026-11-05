(() => {
    'use strict';
    const states = [];
    function setup(form) {
        if (form.dataset.enhanced) return;
        form.dataset.enhanced = '1';
        const button = form.querySelector('.plainforms-submit');
        const summary = form.querySelector('.plainforms-error-summary');
        const status = form.querySelector('.plainforms-status');
        const state = {form, busy:false, token:'', widget:null, ready:false};
        const needsToken = form.dataset.turnstile === '1';
        states.push(state);
        function validity() {
            form.querySelectorAll('[data-pattern]').forEach(control => {
                let valid = true;
                try { valid = !control.value || new RegExp('^(?:' + control.dataset.pattern + ')$', 'u').test(control.value); } catch (_) { valid = false; }
                control.setCustomValidity(valid ? '' : 'Please use the requested format.');
            });
            form.querySelectorAll('[data-type="checkbox_group"]').forEach(group => {
                const controls = [...group.querySelectorAll('input[type="checkbox"]')];
                const missing = group.dataset.required === '1' && !controls.some(c=>c.checked);
                if (controls[0]) controls[0].setCustomValidity(missing ? 'Please choose at least one option.' : '');
            });
            return [...form.elements].every(control=>!control.willValidate || control.validity.valid);
        }
        state.update = () => { button.disabled = state.busy || !validity() || (needsToken && !state.token); };
        function clearErrors() {
            summary.hidden=true; summary.replaceChildren();
            form.querySelectorAll('[aria-invalid]').forEach(n=>n.removeAttribute('aria-invalid'));
            form.querySelectorAll('.plainforms-error').forEach(n=>{n.textContent='';});
        }
        function errors(result) {
            clearErrors(); const heading = document.createElement('p'); heading.textContent=result.message || 'Unable to submit. Please try again.'; summary.append(heading);
            Object.entries(result.fields || {}).forEach(([id,message]) => {
                const group = [...form.querySelectorAll('[data-field]')].find(n=>n.dataset.field===id);
                if (!group) return;
                const controls = group.querySelectorAll('input,select,textarea');
                controls.forEach(c=>c.setAttribute('aria-invalid','true'));
                group.querySelector('.plainforms-error').textContent=message;
                const p=document.createElement('p'), a=document.createElement('a'); a.href='#'+controls[0].id; a.textContent=(group.querySelector('legend,label')?.textContent || id)+': '+message; p.append(a); summary.append(p);
            });
            summary.hidden=false; summary.focus();
        }
        async function timing() {
            try {
                const response=await fetch(form.dataset.startUrl,{cache:'no-store',credentials:'same-origin'});
                if (!response.ok) return;
                const data=await response.json(); form.elements.started.value=data.started; form.elements.signature.value=data.signature;
            } catch (_) { /* Server-rendered signed timestamp remains available. */ }
        }
        form.addEventListener('input',()=>{state.update();});
        form.addEventListener('change',()=>{state.update();});
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (state.busy) return;
            if (!validity()) { form.reportValidity(); return; }
            if (needsToken && !state.token) { errors({message:'Please complete the verification.'}); return; }
            state.busy=true; state.update(); clearErrors(); status.textContent='Sending your enquiry…'; form.setAttribute('aria-busy','true');
            const data=new FormData(form); if(needsToken) data.set('cf-turnstile-response',state.token);
            try {
                const response=await fetch(form.dataset.endpoint,{method:'POST',body:data,credentials:'omit',headers:{Accept:'application/json'}});
                const result=await response.json();
                if (!response.ok || !result.success) { status.textContent=''; errors(result); }
                else { form.reset(); clearErrors(); status.textContent=result.message; await timing(); }
            } catch (_) { status.textContent=''; errors({message:'Unable to confirm delivery. Your answers are preserved. Check your connection before trying again.'}); }
            finally {
                state.busy=false; form.removeAttribute('aria-busy');
                if (needsToken) { state.token=''; if(window.turnstile && state.widget !== null) window.turnstile.reset(state.widget); }
                state.update();
            }
        });
        state.update(); timing();
        state.renderChallenge = () => {
            if(!needsToken || state.widget !== null || !window.turnstile) return;
            const target=form.querySelector('.plainforms-turnstile');
            if (!target.dataset.sitekey) return;
            try {
                state.widget=window.turnstile.render(target,{sitekey:target.dataset.sitekey,action:target.dataset.action || undefined,callback:token=>{state.token=token;state.update();},'expired-callback':()=>{state.token='';state.update();},'error-callback':()=>{state.token='';state.update();status.textContent='Verification unavailable. Please reload the page and try again.';}});
            } catch (_) { status.textContent='Verification unavailable. Please reload the page and try again.'; }
        };
        state.renderChallenge();
    }
    window.plainFormsTurnstileReady = () => states.forEach(state=>state.renderChallenge?.());
    function init() { document.querySelectorAll('form[data-plainforms-form]').forEach(setup); }
    if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
