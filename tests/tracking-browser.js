const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../modules/advanced-tracking-manager/assets/events.js'), 'utf8');
const storage = new Map();
const calls = [];
const google = [];
const purchase = {id: 'atm-fixture-order', name: 'Purchase', data: {value: 19.5, currency: 'EUR'}};
function load(config, fetch) {
    const document = new EventTarget();
    document.body = new EventTarget();
    const window = {wutmAtmConfig: config, fbq: (...args) => calls.push(args), gtag: (...args) => google.push(args)};
    let classicCart;
    window.jQuery = () => ({on: (name, callback) => {
        assert.equal(name, 'added_to_cart');
        classicCart = callback;
    }});
    vm.runInNewContext(source, {document, window, fetch, URLSearchParams, localStorage: {
        getItem: key => storage.get(key),
        setItem: (key, value) => storage.set(key, value)
    }});
    return {document, window, classicCart};
}
const config = {pixel: '123', events: [purchase, purchase], google: true, ga4: 'G-FIXTURE'};
load(null);
assert.equal(calls.length, 0);
const {document, window} = load(config);
load(config);
const conversions = calls.filter(call => call[0] === 'trackSingle');
assert.equal(conversions.length, 1);
assert.equal(conversions[0][4].eventID, purchase.id);
document.dispatchEvent(new Event('wutm-atm-consent'));
assert.deepEqual(calls.at(-1), ['consent', 'revoke']);
assert.equal(window['ga-disable-G-FIXTURE'], true);
assert.equal(google.at(-1)[2].ad_storage, 'denied');
const googleOnly = load({pixel: '', events: [], google: true, ga4: 'G-GOOGLE'});
googleOnly.document.dispatchEvent(new Event('wutm-atm-consent'));
assert.equal(googleOnly.window['ga-disable-G-GOOGLE'], true);
async function testCart() {
    let requests = 0;
    const data = [{id: 'atm-cart-fixture', name: 'AddToCart', data: {num_items: 2}}];
    const cart = load({...config, events: [], cart: '/wp-admin/admin-ajax.php', nonce: 'fixture'}, async (url, args) => {
        requests++;
        assert.equal(url, '/wp-admin/admin-ajax.php');
        assert.equal(args.credentials, 'same-origin');
        assert.equal(args.body.get('action'), 'wutm_atm_cart');
        return {json: async () => ({success: true, data})};
    });
    await cart.classicCart();
    cart.document.body.dispatchEvent(new Event('wc-blocks_added_to_cart'));
    await new Promise(resolve => setImmediate(resolve));
    const addToCart = calls.filter(call => call[0] === 'trackSingle' && call[2] === 'AddToCart');
    assert.equal(addToCart.length, 1);
    assert.equal(requests, 2);
    cart.document.dispatchEvent(new Event('wutm-atm-consent'));
    await cart.classicCart();
    assert.equal(requests, 2);
    console.log('Tracking browser consent, cart delivery and purchase deduplication passed.');
}
testCart().catch(error => { console.error(error); process.exitCode = 1; });
