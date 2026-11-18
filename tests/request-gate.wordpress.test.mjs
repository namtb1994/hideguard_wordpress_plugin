import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import test, {after, before} from 'node:test';

const {chromium} = await import(process.env.HG_PLAYWRIGHT_MODULE || 'playwright');
const container = process.env.HG_TEST_CONTAINER;
if (!container || !/^ipediatri-hideguard-[a-z0-9-]+-test$/.test(container)) {
    throw new Error('HG_TEST_CONTAINER must name the disposable WordPress fixture, never the application container.');
}
const origin = 'http://127.0.0.1:18889';
let browser;
let payload;
before(async()=>{
    payload=JSON.parse(execFileSync('docker',['exec','-e','HG_WP_ROOT=/fixture',container,'php','-r',String.raw`
        require '/fixture/wp-content/plugins/hachitechs-hideguard/tests/bootstrap.php';
        global $wpdb;
        foreach (['session','block','rate','control','circuit'] as $suffix) {
            $wpdb->query('DELETE FROM '.$wpdb->prefix.'hachitech_'.$suffix);
        }
        $settings = new Hachitechs\HideguardWordPress\Settings();
        $values = $settings->values();
        $values['enabled'] = true;
        $values['paths'] = ['protected'];
        $values['points'] = [];
        $values['passDuration'] = 300;
        $values['keys'] = [
            'endpointUrl'=>'https://hideguard.io', 'publicKey'=>'fixture-site',
            'signaturePublicKey'=>SignedPayload::pem(), 'apiKey'=>'',
        ];
        update_option(Hachitechs\HideguardWordPress\Settings::OPTION, $values);
        update_option('hg_fixture_request_gate', true);
        echo json_encode(SignedPayload::payload());
    `],{encoding:'utf8'}));
    browser=await chromium.launch({headless:true,executablePath:process.env.HG_CHROME_PATH});
});
after(async()=>browser?.close());

async function pageFixture(t,{baseline=false}={}){
    const context=await browser.newContext();
    const page=await context.newPage();
    t.after(()=>context.close());
    const dataRequests=[];
    const dataResponses=[];
    page.on('request',r=>{if(r.url().includes('/hg-fixture/v1/opaque'))dataRequests.push(r.url());});
    page.on('response',r=>{if(r.url().includes('/hg-fixture/v1/opaque'))dataResponses.push(r.status());});
    let release;
    let confirmation;
    const permit=new Promise(resolve=>{release=resolve;});
    await page.route('https://hideguard.io/sdk/v1.js',route=>route.fulfill({contentType:'text/javascript',
        body:`setTimeout(()=>window.dispatchEvent(new CustomEvent('fpc:verdict',{detail:${JSON.stringify(payload)}})),150);`}));
    await page.route('**/hachitech-hideguard/v1/verdict',async route=>{
        confirmation=route;
        await permit;
        await route.continue();
    });
    if(baseline){
        await page.route('**/protected/',async route=>{
            const response=await route.fetch();
            const html=(await response.text()).replace(/<script\b[^>]*id="hachitechs-hideguard-request-gate"[^>]*>[\s\S]*?<\/script>/,'');
            await route.fulfill({response,body:html});
        });
    }
    await page.goto(origin+'/protected/',{waitUntil:'load'});
    for(let i=0;!confirmation&&i<100;i++)await new Promise(resolve=>setTimeout(resolve,10));
    assert.ok(confirmation,'The SDK report must reach the authoritative WordPress endpoint before Allow.');
    return {page,dataRequests,dataResponses,release};
}

test('baseline without early queue sends widgets too soon and receives native 403 responses',async t=>{
    const f=await pageFixture(t,{baseline:true});
    await f.page.waitForFunction(()=>hgFixture.errors.length>=3);
    assert.equal(f.dataRequests.length,3);
    assert.ok(f.dataResponses.length>=2);
    assert.ok(f.dataResponses.every(status=>status===403));
    f.release();
    await f.page.waitForFunction(()=>HideGuardAccess.getState().status==='allowed');
    assert.deepEqual(await f.page.evaluate(()=>hgFixture.finished),[]);
});

test('native WordPress widgets initialized in head/jQuery wait for signed backend Allow, then all load exactly once',async t=>{
    const f=await pageFixture(t);
    await f.page.waitForTimeout(1200);
    assert.equal(f.dataRequests.length,0);
    assert.deepEqual(await f.page.evaluate(()=>hgFixture.errors),[]);
    assert.equal(await f.page.locator('body').count(),1);
    assert.equal(await f.page.evaluate(()=>HideGuardAccess.getState().status),'pending');
    f.release();
    try { await f.page.waitForFunction(()=>hgFixture.finished.length===3,{},{timeout:10000}); }
    catch(error) {
        console.log('Fixture state',await f.page.evaluate(()=>({state:HideGuardAccess?.getState(),finished:hgFixture?.finished,errors:hgFixture?.errors})),f.dataResponses);
        throw error;
    }
    assert.deepEqual((await f.page.evaluate(()=>hgFixture.finished)).sort(),['fetch','jquery','xhr']);
    assert.deepEqual(await f.page.evaluate(()=>hgFixture.errors),[]);
    assert.equal(f.dataRequests.length,3);
    assert.deepEqual(f.dataResponses,[200,200,200]);
    await f.page.waitForTimeout(150);
    assert.equal(f.dataRequests.length,3,'No automatic replay after successful completion.');
});
