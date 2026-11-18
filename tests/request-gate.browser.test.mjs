import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createServer} from 'node:http';
import {once} from 'node:events';
import test, {after, before} from 'node:test';

const {chromium} = await import(process.env.HG_PLAYWRIGHT_MODULE || 'playwright');
const source = await readFile(new URL('../assets/request-gate.js', import.meta.url), 'utf8');
const runtime = await readFile(new URL('../assets/runtime.js', import.meta.url), 'utf8');
const publicSdk = process.env.HG_PUBLIC_SDK_FILE ? await readFile(process.env.HG_PUBLIC_SDK_FILE, 'utf8') : null;
const jquery = await readFile(new URL('../../../../wp-includes/js/jquery/jquery.js', import.meta.url), 'utf8');
let browser;
before(async () => {
    browser = await chromium.launch({headless: true, executablePath: process.env.HG_CHROME_PATH});
});
after(async () => browser?.close());

async function fixture(t, {manual = false, maxWaitMs = 45000, realSdk = false} = {}) {
    const requests = [];
    let verdictResponse;
    const sdk = createServer((request, response) => {
        requests.push({url: 'sdk' + request.url, method: request.method});
        response.setHeader('Access-Control-Allow-Origin', '*');
        if (request.url === '/sdk/v1.js') {
            response.setHeader('Content-Type', 'text/javascript');
            response.end(realSdk ? publicSdk : `window.sdkLoaded = true;
                fetch(${JSON.stringify(sdkOrigin + '/collector')}, {method:'POST',body:'sdk'}).then(() => {
                    window.dispatchEvent(new CustomEvent('fpc:verdict', {detail:{visitorId:'fixture',verdict:'allow'}}));
                });`);
        } else if (request.url === '/api/v1/collect') {
            response.setHeader('Access-Control-Allow-Headers', 'content-type');
            response.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify({verdict:'allow',visitorId:'fixture',score:0,reasons:[]}));
        } else {
            response.end('{}');
        }
    });
    sdk.listen(0, '127.0.0.1');
    await once(sdk, 'listening');
    const sdkHttp = 'http://127.0.0.1:' + sdk.address().port;
    const sdkOrigin = 'https://sdk.hideguard.test';
    const server = createServer(async (request, response) => {
        const chunks = [];
        for await (const chunk of request) chunks.push(chunk);
        requests.push({url: request.url, method: request.method, body: Buffer.concat(chunks).toString(), headers: request.headers});
        if (request.url === '/') {
            response.setHeader('Content-Type', 'text/html');
            const configuration = {enabled: true, pageProtected: true, publicKey: 'fixture', endpointUrl: sdkOrigin,
                accessUrl: origin + '/verify', verdictUrl: origin + '/verdict', failUrl: origin + '/fail',
                gateUrl: origin + '/gate', deniedUrl: origin + '/gate?failed=1', returnUrl: origin + '/', page: ''};
            const bootstrap = {enabled: true, sdkOrigin, sdkOrigins:['https://syn.hideguard.io'], maxWaitMs,
                verificationUrls: [configuration.accessUrl, configuration.verdictUrl, configuration.failUrl,
                    origin+'/?rest_route=/hachitech-hideguard/v1/check']};
            response.end(`<!doctype html><html><head><script>${source}
                HachitechsHideguardNetwork.install(${JSON.stringify(bootstrap)});</script>
                <link rel="stylesheet" href="/style.css"><script src="/static.js"></script>
                <script src="/jquery.js"></script><script>
                    HachitechsHideguardNetwork.getGate().attachJQuery(jQuery);
                    window.finished=[]; window.events=[];
                    window.setState=(status,expiresAt=Date.now()+60000)=>{
                        window.HideGuardAccess={getState:()=>({status,expiresAt})};
                        window.dispatchEvent(new CustomEvent('hachitech:access-state',{detail:{status}}));
                    };
                </script></head><body inert data-hachitech-ab-inert>
                <div id="component" style="width:123px">Initialized HTML</div><img src="/image.svg">
                ${manual ? '' : `<script type="module">import {initialize} from '/runtime.js';
                    initialize(${JSON.stringify(configuration)});</script>`}
                </body></html>`);
        } else if (request.url === '/jquery.js') {
            response.setHeader('Content-Type', 'text/javascript'); response.end(jquery);
        } else if (request.url === '/runtime.js') {
            response.setHeader('Content-Type', 'text/javascript'); response.end(runtime);
        } else if (request.url === '/static.js') {
            response.setHeader('Content-Type', 'text/javascript'); response.end('window.staticLoaded=true;');
        } else if (request.url === '/style.css') {
            response.setHeader('Content-Type', 'text/css'); response.end('#component{height:42px}');
        } else if (request.url === '/image.svg') {
            response.setHeader('Content-Type', 'image/svg+xml'); response.end('<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"/>');
        } else if (request.url === '/verify') {
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify({status:'verification_required',verificationId:'fixture',verificationUntil:Date.now()+35000}));
        } else if (request.url === '/verdict') {
            verdictResponse = response;
        } else if (request.url === '/fail') {
            response.setHeader('Content-Type', 'application/json'); response.end('{"status":"verification_failed"}');
        } else if (request.url.startsWith('/gate')) {
            response.end('Existing Block/error gate');
        } else {
            if (request.url.includes('slow')) await new Promise(resolve => setTimeout(resolve, 160));
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify({ok:true,body:Buffer.concat(chunks).toString()}));
        }
    });
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    const origin = 'http://127.0.0.1:' + server.address().port;
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.route(sdkOrigin + '/**', async route => {
        const response = await route.fetch({url: sdkHttp + new URL(route.request().url()).pathname});
        await route.fulfill({response});
    });
    t.after(async () => {
        await context.close();
        server.closeAllConnections(); sdk.closeAllConnections();
        await Promise.all([new Promise(resolve => server.close(resolve)), new Promise(resolve => sdk.close(resolve))]);
    });
    await page.route('https://syn.hideguard.io/**', async route => {
        requests.push({url:'syn'+new URL(route.request().url()).pathname,method:route.request().method()});
        await route.fulfill({status:200,headers:{'access-control-allow-origin':'*','content-type':'application/json'},
            body:JSON.stringify({ip:'127.0.0.1',syn:null})});
    });
    await page.goto(origin, {waitUntil: 'load'});
    async function settle(status) {
        if (manual) {
            await page.evaluate(status => setState(status), status);
            return;
        }
        await page.waitForFunction(() => window.sdkLoaded === true || window.FingerprintCollector?.version);
        for (let attempt = 0; !verdictResponse && attempt < 2000; attempt++) await new Promise(resolve => setTimeout(resolve, 10));
        assert.ok(verdictResponse, 'SDK must reach backend confirmation independently of the business queue.');
        verdictResponse.setHeader('Content-Type', 'application/json');
        if (status === 'error') verdictResponse.statusCode=503;
        verdictResponse.end(JSON.stringify(status === 'allowed'
            ? {status,allowed:true,expiresAt:Date.now()+60000,passDuration:300}
            : {status,blocked:true,blockedUntil:Date.now()+60000}));
    }
    return {page, requests, origin, sdkOrigin, settle};
}

function starts(requests, path) { return requests.filter(request => request.url === path).length; }

test('UI/static/SDK initialize while fetch, native XHR and jQuery wait for backend Allow; each sends once', async t => {
    const f = await fixture(t);
    await f.page.evaluate(() => {
        document.getElementById('component').dataset.initialized='yes';
        fetch('/opaque-fetch', {method:'POST',headers:{'X-Fixture':'preserved'},body:'payload=1'})
            .then(r=>r.json()).then(()=>finished.push('fetch'));
        window.xhr=new XMLHttpRequest(); xhr.open('GET','/opaque-xhr');
        xhr.onload=()=>finished.push('xhr'); xhr.send();
        window.jq=jQuery.ajax({url:'/opaque-jquery',timeout:40}).done(()=>finished.push('jquery'))
            .fail((_xhr,status)=>finished.push('jquery-'+status));
        fetch('/static-template.json').then(()=>finished.push('template'));
    });
    await f.page.waitForTimeout(180);
    assert.equal(await f.page.evaluate(()=>document.getElementById('component').dataset.initialized),'yes');
    assert.equal(await f.page.locator('#component').evaluate(e=>getComputedStyle(e).height),'42px');
    assert.equal(await f.page.evaluate(()=>window.staticLoaded),true);
    assert.equal(starts(f.requests,'/image.svg'),1);
    assert.equal(starts(f.requests,'sdk/collector'),1);
    for (const path of ['/opaque-fetch','/opaque-xhr','/opaque-jquery','/static-template.json']) assert.equal(starts(f.requests,path),0);
    assert.deepEqual(await f.page.evaluate(()=>finished),[]);
    await f.settle('allowed');
    await f.page.waitForFunction(()=>finished.length===4);
    assert.deepEqual((await f.page.evaluate(()=>finished)).sort(),['fetch','jquery','template','xhr']);
    for (const path of ['/opaque-fetch','/opaque-xhr','/opaque-jquery','/static-template.json']) assert.equal(starts(f.requests,path),1);
    const post=f.requests.find(request=>request.url==='/opaque-fetch');
    assert.equal(post.body,'payload=1'); assert.equal(post.headers['x-fixture'],'preserved');
    assert.equal(await f.page.evaluate(()=>xhr instanceof XMLHttpRequest && jq.state()==='resolved'),true);
});

test('abort cancels queued fetch/XHR/jqXHR and preserves native callback contracts without later replay', async t => {
    const f=await fixture(t,{manual:true});
    const values=await f.page.evaluate(async()=>{
        const controller=new AbortController();
        const pending=fetch('/cancel-fetch',{signal:controller.signal}).catch(error=>error.name);
        controller.abort();
        const x=new XMLHttpRequest(); x.open('POST','/cancel-xhr');
        for (const name of ['readystatechange','abort','loadend']) x.addEventListener(name,()=>events.push([name,x.readyState]));
        x.send('queued'); x.abort();
        const jq=jQuery.ajax({url:'/cancel-jquery',timeout:20}).fail((_xhr,status)=>events.push(['jquery',status])); jq.abort();
        return {fetch:await pending,events,readyState:x.readyState,jqState:jq.state()};
    });
    assert.equal(values.fetch,'AbortError');
    assert.deepEqual(values.events,[['readystatechange',4],['abort',4],['loadend',4],['jquery','abort']]);
    assert.equal(values.readyState,0); assert.equal(values.jqState,'rejected');
    await f.settle('allowed'); await f.page.waitForTimeout(40);
    for(const path of ['/cancel-fetch','/cancel-xhr','/cancel-jquery']) assert.equal(starts(f.requests,path),0);
});

test('Block cancels queued calls before sending and navigates through the existing core gate', async t=>{
    const f=await fixture(t);
    await f.page.evaluate(()=>{
        fetch('/blocked-fetch').catch(()=>{});
        const xhr=new XMLHttpRequest(); xhr.open('POST','/blocked-xhr'); xhr.send('never-send');
        jQuery.ajax({url:'/blocked-jquery',timeout:20});
    });
    await f.settle('blocked'); await f.page.waitForURL('**/gate');
    for(const path of ['/blocked-fetch','/blocked-xhr','/blocked-jquery']) assert.equal(starts(f.requests,path),0);
});

test('A backend Block sends both tabs to the gate', async t => {
    const f = await fixture(t);
    const peer = await f.page.context().newPage();
    await peer.route(f.origin + '/verify', route => route.fulfill({json: {
        status: 'allowed', allowed: true, expiresAt: Date.now() + 60000, passDuration: 300,
    }}));
    await peer.goto(f.origin);
    await peer.waitForFunction(() => window.HideGuardAccess?.getState().status === 'allowed');

    await f.settle('blocked');
    await Promise.all([f.page.waitForURL('**/gate'), peer.waitForURL('**/gate')]);
    assert.equal(starts(f.requests, '/verdict'), 1);
});

test('XHR and jQuery timeouts begin after release and still fire once for a slow server', async t=>{
    const f=await fixture(t,{manual:true});
    await f.page.evaluate(()=>{
        const xhr=new XMLHttpRequest(); xhr.open('GET','/slow-xhr'); xhr.timeout=40;
        xhr.ontimeout=()=>finished.push('xhr-timeout'); xhr.send();
        jQuery.ajax({url:'/slow-jquery',timeout:40}).fail((_xhr,status)=>finished.push('jquery-'+status));
    });
    await f.page.waitForTimeout(100);
    assert.deepEqual(await f.page.evaluate(()=>finished),[]);
    await f.settle('allowed'); await f.page.waitForFunction(()=>finished.length===2);
    assert.deepEqual((await f.page.evaluate(()=>finished)).sort(),['jquery-timeout','xhr-timeout']);
    assert.equal(starts(f.requests,'/slow-xhr'),1); assert.equal(starts(f.requests,'/slow-jquery'),1);
});

test('watchdog fails closed; a new verification round never replays canceled work', async t=>{
    const f=await fixture(t,{manual:true,maxWaitMs:70});
    await f.page.evaluate(()=>fetch('/expired-queue').catch(error=>finished.push(error.name)));
    await f.page.waitForFunction(()=>finished.length===1);
    assert.deepEqual(await f.page.evaluate(()=>finished),['NetworkError']);
    assert.equal(starts(f.requests,'/expired-queue'),0);
    await f.settle('pending'); await f.page.evaluate(()=>{fetch('/new-round').then(()=>finished.push('new'));});
    await f.settle('allowed'); await f.page.waitForFunction(()=>finished.includes('new'));
    assert.equal(starts(f.requests,'/expired-queue'),0); assert.equal(starts(f.requests,'/new-round'),1);
});

test('sync XHR fails closed during pending; opened handlers cannot escape the queue', async t=>{
    const f=await fixture(t,{manual:true});
    const error=await f.page.evaluate(()=>{
        const sync=new XMLHttpRequest(); sync.open('GET','/sync',false);
        try { sync.send(); return 'sent'; } catch(error) { return error.name; }
    });
    assert.equal(error,'NotSupportedError'); assert.equal(starts(f.requests,'/sync'),0);
    await f.page.evaluate(()=>{
        const xhr=new XMLHttpRequest();
        xhr.onreadystatechange=()=>{if(xhr.readyState===1)xhr.send();};
        xhr.onload=()=>finished.push('opened'); xhr.open('GET','/from-opened');
    });
    await f.page.waitForTimeout(30); assert.equal(starts(f.requests,'/from-opened'),0);
    await f.settle('allowed'); await f.page.waitForFunction(()=>finished.includes('opened'));
    assert.equal(starts(f.requests,'/from-opened'),1);
});


test('current public SDK completes collect/SYN through its own transport before page requests are released', {skip:!publicSdk}, async t=>{
    const f=await fixture(t,{realSdk:true});
    await f.page.evaluate(()=>{ fetch('/real-sdk-widget').then(()=>finished.push('widget')); });
    await f.settle('allowed');
    await f.page.waitForFunction(()=>finished.includes('widget'));
    assert.equal(starts(f.requests,'syn/api/p0f'),1);
    assert.equal(starts(f.requests,'sdk/api/v1/collect')>=1,true);
    assert.equal(starts(f.requests,'/real-sdk-widget'),1);
});


test('queued bodies are snapshots, including cross-realm FormData; repeated abort and reopen retain state',async t=>{
    const f=await fixture(t,{manual:true});
    await f.page.evaluate(()=>{
        const frame=document.createElement('iframe');document.body.append(frame);
        const form=new frame.contentWindow.FormData();form.append('value','original');
        const xhr=new XMLHttpRequest();xhr.open('POST','/form-snapshot');
        xhr.onload=()=>finished.push('form');xhr.send(form);form.set('value','changed');
        const array=new Uint8Array([65,66]);
        const binary=new XMLHttpRequest();binary.open('POST','/binary-snapshot');
        binary.onload=()=>finished.push('binary');binary.send(array);array[0]=90;
        const headers=new Headers({'X-Snapshot':'original'});
        fetch('/fetch-snapshot',{method:'POST',headers,body:'original'}).then(()=>finished.push('fetch'));
        headers.set('X-Snapshot','changed');
        const canceled=new XMLHttpRequest();canceled.open('GET','/never-snapshot');canceled.send();
        canceled.abort();canceled.abort();
        if(canceled.readyState!==0)throw new Error('Aborted XHR must remain UNSENT.');
        canceled.open('GET','/reopened');canceled.onload=()=>finished.push('reopened');canceled.send();
    });
    await f.settle('allowed');await f.page.waitForFunction(()=>finished.length===4);
    const form=f.requests.find(r=>r.url==='/form-snapshot');
    assert.match(form.body,/original/);assert.doesNotMatch(form.body,/changed/);
    assert.equal(f.requests.find(r=>r.url==='/binary-snapshot').body,'AB');
    assert.equal(f.requests.find(r=>r.url==='/fetch-snapshot').headers['x-snapshot'],'original');
    assert.equal(starts(f.requests,'/never-snapshot'),0);assert.equal(starts(f.requests,'/reopened'),1);
});


test('backend verification failure cancels queued work and uses the existing error gate',async t=>{
    const f=await fixture(t);
    await f.page.evaluate(()=>{
        fetch('/failed-fetch').catch(()=>{});
        const xhr=new XMLHttpRequest();xhr.open('GET','/failed-xhr');xhr.send();
        jQuery.ajax({url:'/failed-jquery',timeout:30});
    });
    await f.settle('error');
    await f.page.waitForURL(url=>url.pathname==='/gate'&&url.searchParams.get('failed')==='1');
    for(const path of ['/failed-fetch','/failed-xhr','/failed-jquery'])assert.equal(starts(f.requests,path),0);
});

test('later pending state holds new requests without aborting/replaying requests already sent',async t=>{
    const f=await fixture(t,{manual:true});
    await f.settle('allowed');
    await f.page.evaluate(()=>{fetch('/slow-already-sent').then(()=>finished.push('old'));});
    await f.settle('pending');
    await f.page.evaluate(()=>{fetch('/wait-new').then(()=>finished.push('new'));});
    await f.page.waitForFunction(()=>finished.includes('old'));
    assert.equal(starts(f.requests,'/slow-already-sent'),1);assert.equal(starts(f.requests,'/wait-new'),0);
    await f.settle('allowed');await f.page.waitForFunction(()=>finished.includes('new'));
    assert.equal(starts(f.requests,'/slow-already-sent'),1);assert.equal(starts(f.requests,'/wait-new'),1);
});


test('fetch preserves explicit falsy AbortSignal reasons and never sends aborted work',async t=>{
    const f=await fixture(t,{manual:true});
    const values=await f.page.evaluate(async()=>{
        const results=[];
        for(const reason of [null,0,false,'custom']){
            const controller=new AbortController();
            const request=fetch('/falsy-abort',{signal:controller.signal}).catch(error=>error);
            controller.abort(reason);results.push(await request);
        }
        return results;
    });
    assert.deepEqual(values,[null,0,false,'custom']);
    await f.settle('allowed');await f.page.waitForTimeout(20);
    assert.equal(starts(f.requests,'/falsy-abort'),0);
});


test('verification URLs bypass with nonce/cache query args, while unrelated plain REST routes still wait',async t=>{
    const f=await fixture(t,{manual:true});
    await f.page.evaluate(async()=>{
        await fetch('/verify?cache=1');
        await fetch('/?rest_route=/hachitech-hideguard/v1/check&_nonce=fixture');
        fetch('/?rest_route=/another/widget').then(()=>finished.push('widget'));
    });
    assert.equal(starts(f.requests,'/verify?cache=1'),1);
    assert.equal(starts(f.requests,'/?rest_route=/hachitech-hideguard/v1/check&_nonce=fixture'),1);
    assert.equal(starts(f.requests,'/?rest_route=/another/widget'),0);
    await f.settle('allowed');await f.page.waitForFunction(()=>finished.includes('widget'));
    assert.equal(starts(f.requests,'/?rest_route=/another/widget'),1);
});


test('invalid XHR open does not silently cancel a previously queued valid request',async t=>{
    const f=await fixture(t,{manual:true});
    const error=await f.page.evaluate(()=>{
        const xhr=new XMLHttpRequest();xhr.open('GET','/keep-valid-open');
        xhr.onload=()=>finished.push('valid');xhr.send();
        try{xhr.open('BAD METHOD','/invalid-open');return 'none';}catch(error){return error.name;}
    });
    assert.equal(error,'SyntaxError');
    await f.settle('allowed');await f.page.waitForFunction(()=>finished.includes('valid'));
    assert.equal(starts(f.requests,'/keep-valid-open'),1);assert.equal(starts(f.requests,'/invalid-open'),0);
});
