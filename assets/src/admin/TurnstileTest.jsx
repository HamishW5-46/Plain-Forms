import { h, useEffect, useRef, useState, Button, Notice, apiFetch } from './wp';

let loader;
function loadTurnstile() {
    if (window.turnstile?.render) return Promise.resolve(window.turnstile);
    if (loader) return loader;
    loader = new Promise((resolve, reject) => {
        let script = document.querySelector('script[src^="https://challenges.cloudflare.com/turnstile/v0/api.js"]');
        const existing = !!script;
        if (!script) {
            script = document.createElement('script');
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            script.async = true;
        }
        let timer;
        const done = (error) => {
            clearTimeout(timer);
            script.removeEventListener('load', loaded);
            script.removeEventListener('error', failed);
            if (error) { if (!existing) script.remove(); reject(error); }
            else resolve(window.turnstile);
        };
        const loaded = () => window.turnstile?.render ? done() : failed();
        const failed = () => done(new Error('Cloudflare could not load. Check your connection or content blocker and try again.'));
        script.addEventListener('load', loaded);
        script.addEventListener('error', failed);
        timer = setTimeout(failed, 15000);
        if (!existing) document.head.appendChild(script);
    }).catch(error => { loader = null; throw error; });
    return loader;
}

export default function TurnstileTest({ values, hasSecret, publicHostname }) {
    const settings = Object.fromEntries(['turnstile_site_key', 'turnstile_secret_key', 'turnstile_hostname', 'turnstile_action', 'clear_turnstile_secret'].map(key => [key, values[key]]));
    const signature = JSON.stringify([settings, hasSecret]);
    const [run, setRun] = useState(null);
    const [phase, setPhase] = useState('idle');
    const [result, setResult] = useState(null);
    const target = useRef(null);
    const dashboardHostname = window.location.hostname;
    const expected = values.turnstile_hostname.trim().toLowerCase();
    const hostnameInvalid = !!values.turnstile_hostname && !/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i.test(values.turnstile_hostname);
    const actionInvalid = !!values.turnstile_action && !/^[a-zA-Z0-9_-]{1,32}$/.test(values.turnstile_action);
    const mismatch = !hostnameInvalid && !!expected && !!publicHostname && expected !== publicHostname.toLowerCase();
    const ready = !hostnameInvalid && !actionInvalid && !!values.turnstile_site_key.trim() && !values.clear_turnstile_secret && (!!values.turnstile_secret_key.trim() || hasSecret);

    useEffect(() => { setRun(null); setResult(null); setPhase('idle'); }, [signature]);
    useEffect(() => {
        if (!run || run.signature !== signature) return;
        let cancelled = false, widget = null, checking = false, finished = false;
        const controller = new AbortController();
        setPhase('loading');
        setResult(null);
        const fail = message => {
            if (cancelled || finished || checking) return;
            setPhase('error');
            setResult({ success: false, message });
        };
        loadTurnstile().then(turnstile => {
            if (cancelled) return;
            widget = turnstile.render(target.current, {
                sitekey: run.settings.turnstile_site_key.trim(),
                size: target.current.clientWidth < 300 ? 'compact' : 'normal',
                action: run.settings.turnstile_action || undefined,
                callback: async token => {
                    if (cancelled || checking || finished) return;
                    checking = true;
                    setPhase('checking');
                    try {
                        const reply = await apiFetch({ path: '/plain-forms/v1/admin/turnstile-test', method: 'POST', data: { settings: run.settings, token }, signal: controller.signal });
                        if (!cancelled) {
                            setResult(reply);
                            setPhase(reply.success ? 'success' : 'error');
                        }
                    } catch (error) {
                        if (!cancelled) {
                            setResult({ success: false, message: error.message || 'The server could not verify the challenge. Try again.' });
                            setPhase('error');
                        }
                    } finally { checking = false; finished = true; }
                },
                'expired-callback': () => fail('The test challenge expired. Run the test again.'),
                'error-callback': () => { fail('Cloudflare could not run this challenge. Check the site key and allowed hostnames in your Cloudflare widget.'); return true; },
            });
            if (!checking && !finished) setPhase('challenge');
        }).catch(() => fail('Cloudflare could not load the test. Check your connection, content blocker and site key, then try again.'));
        return () => {
            cancelled = true;
            controller.abort();
            if (widget !== null) {
                try { window.turnstile?.remove(widget); } catch (_) { /* Only this test widget is removed. */ }
            }
        };
    }, [run, signature]);

    return <div className="plainforms-turnstile-test">
        <h3>Check Turnstile configuration</h3>
        <p>Public website hostname: <strong>{publicHostname || dashboardHostname}</strong>. Include the <code>www</code> prefix when your public forms use it.</p>
        {hostnameInvalid && <Notice status="error" isDismissible={false}>Enter only a hostname, without https://, spaces or a path.</Notice>}
        {actionInvalid && <Notice status="error" isDismissible={false}>Expected action must use up to 32 letters, numbers, underscores or hyphens.</Notice>}
        {mismatch && <Notice status="warning" isDismissible={false}>Expected hostname “{values.turnstile_hostname}” differs from the public website hostname “{publicHostname}”. Check which hostname your forms actually use before saving.</Notice>}
        {expected && expected !== dashboardHostname.toLowerCase() && <p>This test runs on <strong>{dashboardHostname}</strong>. For a live hostname check, open the dashboard on the hostname used by your public forms.</p>}
        <p>Test the entered values with a real Cloudflare challenge. A blank secret uses the saved secret. The test does not save settings or submit an enquiry.</p>
        <Button variant="secondary" disabled={!ready || phase === 'loading' || phase === 'checking'} isBusy={phase === 'loading' || phase === 'checking'} onClick={() => setRun({ settings, signature })}>
            {phase === 'checking' ? 'Verifying configuration…' : 'Test Turnstile configuration'}
        </Button>
        {run && <div ref={target} className="plainforms-turnstile-test-widget" />}
        <div role="status" aria-live="polite" aria-atomic="true">
            {phase === 'loading' && <p>Loading Cloudflare verification…</p>}
            {phase === 'challenge' && <p>Complete the test challenge to verify these settings.</p>}
            {phase === 'checking' && <p>Checking the token with Cloudflare…</p>}
            {result && <Notice status={result.success ? 'success' : 'error'} isDismissible={false}>{result.message}</Notice>}
        </div>
    </div>;
}
