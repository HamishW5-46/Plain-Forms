const wordpressContainer = process.env.PLAINFORMS_WORDPRESS_CONTAINER || 'plainforms_wordpress';
/* Offline browser tests: renderer fixtures come from transactional WP CLI; all requests are mocked. */
const {createRequire} = require('node:module');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const dependencies = process.env.PLAINFORMS_NODE_MODULES;
const load = dependencies ? createRequire(path.join(dependencies, '../package.json')) : require;
const {chromium} = load('playwright');
const AxeBuilder = load('@axe-core/playwright').default;
const fixture = mode => {
    try { return execFileSync('docker',['exec','-e','PLAINFORMS_RUN_TESTS=1',wordpressContainer,'php','/var/www/html/wp-content/plugins/plain-forms/tests/browser-fixture.php',mode],{encoding:'utf8'}).replace(/(name="(?:_wpnonce|plainforms_nonce)" value=")[^"]+/g,'$1test-nonce'); }
    catch (_) { throw new Error(`WordPress fixture generation failed for ${mode}; inspect the CLI fixture separately.`); }
};
const assets = path.join(__dirname,'../assets');
let checks=0;
function check(condition,label) { assert.ok(condition,label); checks++; console.log(`PASS: ${label}`); }
(async () => {
    const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
    try {
        const context=await browser.newContext();
        const page=await context.newPage();
        const errors=[]; page.on('pageerror',e=>errors.push(e.message));
        let mode='frontend', delivery='success', submits=0, lastBody='';
        const html={frontend:fixture('frontend'),turnstile:fixture('turnstile')};
        check(!Object.values(html).some(h=>h.includes('test-secret-must-never-appear')),'Browser fixtures never expose Turnstile secret');
        await page.route('**/*',async route=>{
            const url=new URL(route.request().url());
            const headers={'access-control-allow-origin':'*','access-control-allow-headers':'*','access-control-allow-methods':'GET,POST,OPTIONS'};
            if(route.request().method()==='OPTIONS') return route.fulfill({status:204,headers});
            if(url.pathname.endsWith('/start')) return route.fulfill({json:{started:Math.floor(Date.now()/1000)-3,signature:'test-signature'},headers});
            if(url.pathname.endsWith('/submit')) {
                submits++; lastBody=route.request().postData() || '';
                await new Promise(resolve=>setTimeout(resolve,80));
                if(delivery==='verification') return route.fulfill({status:400,json:{success:false,code:'verification_failed',message:'Please complete the verification and try again.'},headers});
                if(delivery==='network') return route.abort('failed');
                if(delivery==='validation') return route.fulfill({status:422,json:{success:false,code:'validation_failed',message:'Please check the highlighted fields.',fields:{email:'Please enter a valid email address.'}},headers});
                return route.fulfill({json:{success:true,message:'Thanks, your message has been sent.',submissionId:123},headers});
            }
            if(url.pathname==='/') return route.fulfill({contentType:'text/html',body:html[mode]});
            if(['/frontend.js','admin.js','/admin.js','/frontend.css'].includes(url.pathname)) return route.fulfill({contentType:url.pathname.endsWith('css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(assets,path.basename(url.pathname)))});
            return route.abort();
        });
        await page.goto('http://plain-forms.test/');
        const submit=page.getByRole('button',{name:'Send enquiry'});
        check(await submit.isDisabled(),'Submit disabled while required fields empty');
        await page.getByLabel('Name (required)',{exact:true}).fill('Example Person');
        await page.getByLabel('Email (required)',{exact:true}).fill('invalid');
        check(await submit.isDisabled(),'Invalid browser email disables submit');
        await page.getByLabel('Email (required)',{exact:true}).fill('test@example.invalid');
        await page.getByLabel('Message (required)',{exact:true}).fill('A test enquiry');
        await page.getByLabel('I agree (required)',{exact:true}).check();
        await page.getByLabel('By email',{exact:true}).check();
        check(await submit.isDisabled(),'Required checkbox group must have a selected option');
        await page.getByLabel('First interest',{exact:true}).check();
        check(await submit.isEnabled(),'Submit enables once all required controls valid');
        await submit.focus(); await page.keyboard.press('Enter');
        await page.waitForFunction(()=>document.querySelector('.plainforms-status').textContent.includes('Thanks'));
        check(submits===1 && await page.getByLabel('Name (required)',{exact:true}).inputValue()==='','Keyboard submission sends once and resets controls');
        check(await submit.isDisabled(),'Required controls disable submit after successful reset');
        const fill = async()=>{await page.getByLabel('Name (required)',{exact:true}).fill('Example Person');await page.getByLabel('Email (required)',{exact:true}).fill('test@example.invalid');await page.getByLabel('Message (required)',{exact:true}).fill('Preserve this message');await page.getByLabel('I agree (required)',{exact:true}).check();await page.getByLabel('By email',{exact:true}).check();await page.getByLabel('First interest',{exact:true}).check();};
        await fill(); delivery='validation'; await submit.click();
        await page.waitForFunction(()=>document.activeElement.classList.contains('plainforms-error-summary'));
        check(await page.getByLabel('Email (required)',{exact:true}).getAttribute('aria-invalid')==='true','Server errors focus summary and mark associated control');
        check(await page.getByLabel('Message (required)',{exact:true}).inputValue()==='Preserve this message','Validation failure preserves answers');
        delivery='network'; await submit.click();
        await page.waitForFunction(()=>document.querySelector('.plainforms-error-summary').textContent.includes('Unable to confirm'));
        check(await page.getByLabel('Message (required)',{exact:true}).inputValue()==='Preserve this message','Network failure preserves answers');
        const axe=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze();
        check(axe.violations.length===0,'Frontend fixture passes automated WCAG accessibility checks: '+JSON.stringify(axe.violations.map(v=>v.id)));
        await page.setViewportSize({width:375,height:812});
        check(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'Form fits mobile viewport without horizontal overflow');
        mode='turnstile'; delivery='success';
        await page.addInitScript(()=>{window.turnstile={render:(_target,options)=>{window.challengeOptions=options;return 'test-widget';},reset:()=>{window.challengeOptions['expired-callback']();}};});
        await page.goto('http://plain-forms.test/'); await fill();
        check(await submit.isDisabled(),'Turnstile form disabled before verification');
        await page.evaluate(()=>window.challengeOptions.callback('verified-token'));
        check(await submit.isEnabled(),'Turnstile completion enables valid form');
        await page.evaluate(()=>window.challengeOptions['expired-callback']());
        check(await submit.isDisabled(),'Turnstile expiry disables form');
        await page.evaluate(()=>window.challengeOptions.callback('verified-token')); await submit.click();
        await page.waitForFunction(()=>document.querySelector('.plainforms-status').textContent.includes('Thanks'));
        check(await submit.isDisabled(),'Successful Turnstile submission resets challenge');
        await fill(); delivery='verification';
        await page.evaluate(()=>window.challengeOptions.callback('rejected-token'));
        await submit.click();
        await page.waitForFunction(()=>!document.querySelector('.plainforms-error-summary').hidden);
        check(await submit.isDisabled(), 'Rejected verification resets token and disables resubmission until a fresh challenge');
        check(await page.getByLabel('Message (required)',{exact:true}).inputValue()==='Preserve this message', 'Verification rejection preserves form answers');
        await page.evaluate(()=>window.challengeOptions.callback('fresh-token'));
        check(await page.locator('.plainforms-error-summary').isHidden() && await submit.isEnabled(), 'Fresh verification clears only the stale verification error and enables retry');
        check((await page.locator('.plainforms-status').textContent()).includes('send your enquiry again'), 'Refreshed verification asks the visitor to resubmit rather than claiming delivery');
        delivery='validation'; await submit.click();
        await page.waitForFunction(()=>document.querySelector('[aria-invalid="true"]'));
        check(lastBody.includes('fresh-token') && !lastBody.includes('rejected-token'), 'Retry sends the fresh callback token rather than reusing the rejected token');
        await page.evaluate(()=>window.challengeOptions.callback('third-token'));
        check(await page.locator('.plainforms-error-summary').isVisible() && await page.getByLabel('Email (required)',{exact:true}).getAttribute('aria-invalid')==='true', 'Fresh verification preserves unrelated field validation errors');
        await page.evaluate(()=>window.challengeOptions['error-callback']());
        check((await page.locator('.plainforms-status').textContent()).includes('Verification unavailable') && await submit.isDisabled(), 'Widget error announces unavailability and disables submission');
        await page.evaluate(()=>window.challengeOptions.callback('recovered-token'));
        check(await page.locator('.plainforms-status').textContent()==='' && await submit.isEnabled(), 'Recovered widget clears its stale unavailable message');
        delivery='success'; await submit.click();
        await page.waitForFunction(()=>document.querySelector('.plainforms-status').textContent.includes('Thanks'));
        check(lastBody.includes('recovered-token'), 'Recovered verification can submit successfully with the current token');
        check(errors.length===0,'No plugin browser exceptions: '+JSON.stringify(errors));
        await context.close();
        console.log(`${checks} browser checks passed.`);
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
