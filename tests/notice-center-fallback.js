const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
    constructor(fallback = false, kind = 'notice') { this.fallback = fallback; this.kind = kind; this.children = []; }
    matches(selector) { return this.kind !== '' && new RegExp('(^|[^\\w-])\\.' + this.kind + '(?![\\w-])').test(selector); }
    closest(selector) {
        if (this.id && selector.split(',').map(value => value.trim()).includes('#' + this.id)) return this;
        return selector === 'form' ? this.form || null : selector.includes('.hide-if-js') && this.fallback ? this : null;
    }
    querySelector() { return this.hasControl ? {} : null; }
    querySelectorAll() { return this.children; }
}

const fallback = new Element(true);
const ordinary = new Element(false);
const commerce = new Element(false, 'woocommerce-error');
const settingsForm = {};
const pluginNotice = new Element(false, 'fs-notice');
pluginNotice.form = settingsForm;
pluginNotice.parentElement = settingsForm;
const wpcodeNotice = new Element(false, 'notice');
wpcodeNotice.form = settingsForm;
wpcodeNotice.parentElement = settingsForm;
const inlineNotice = new Element(false, 'notice');
inlineNotice.form = settingsForm;
inlineNotice.parentElement = {};
inlineNotice.classList = { add() {} };
const formNotice = new Element(false, 'notice');
formNotice.form = settingsForm;
formNotice.parentElement = settingsForm;
formNotice.hasControl = true;
formNotice.classList = { add() {} };
const content = new Element(false, '');
const connectionNotices = ['lost-connection-notice', 'wc-lost-connection-notice', 'local-storage-notice'].map(id => {
    const node = new Element(false);
    node.id = id;
    node.classList = { add(value) { node.kept = value; } };
    return node;
});
content.children = [fallback, ordinary, commerce, pluginNotice, wpcodeNotice, inlineNotice, formNotice, ...connectionNotices];
const items = new Element();
items.appendChild = node => items.children.push(node);
let textWrites = 0;
function counter() {
    let value = '';
    return { get textContent() { return value; }, set textContent(next) { textWrites++; value = next; } };
}
const count = counter();
const important = counter();
const panel = new Element(false, '');
content.insertBefore = node => { node.parentElement = content; };
panel.contains = () => false;
panel.querySelector = selector => selector === '.wutm-notice-items' ? items : selector === '.wutm-notice-count' ? count : important;
panel.classList = { toggle() {} };

const observers = [];
const context = {
    Element,
    document: {
        documentElement: { classList: { add() {} } },
        getElementById: id => id === 'wutm-notice-center' ? panel : id === 'wp-admin-bar-wutm-notice-center' ? null : content,
        readyState: 'complete',
    },
    window: { getComputedStyle: () => ({ display: 'block', visibility: 'visible' }), addEventListener() {} },
    MutationObserver: class { constructor(callback) { observers.push(callback); } observe() {} },
};
vm.runInNewContext(fs.readFileSync('assets/js/notice-center.js', 'utf8'), context);
assert.deepEqual(items.children, [ordinary, commerce, pluginNotice, wpcodeNotice], 'Top-level plugin notices should be collected while fallbacks and notices with form controls stay outside');
assert.equal(count.textContent, '4');
assert.equal(panel.parentElement, content, 'Panel must escape WooCommerce header/form containers');
assert.equal(important.textContent, '包含 1 則重要通知');
assert.ok(connectionNotices.every(node => node.kept === 'wutm-notice-keep' && !items.children.includes(node)), 'Live connection/autosave notices must stay outside, even when active');
const previousWrites = textWrites;
for (let i = 0; i < 20; i++) observers[0]([{ addedNodes: [] }]);
assert.equal(textWrites, previousWrites, 'Unchanged counters must not create observer feedback mutations');
let ready;
items.children = [];
context.document.readyState = 'loading';
context.document.addEventListener = (event, callback) => { if (event === 'DOMContentLoaded') ready = callback; };
vm.runInNewContext(fs.readFileSync('assets/js/notice-center.js', 'utf8'), context);
assert.equal(items.children.length, 0, 'Head script must wait for the panel markup');
ready();
assert.equal(count.textContent, '4', 'Head-loaded collector must initialize when markup is ready');
const events = {};
const toolbarCount = counter();
const details = { open: false };
const trigger = { setAttribute() {}, addEventListener(event, callback) { events[event] = callback; }, focus() {} };
const toolbar = {
    appendChild(node) { node.parentElement = this; },
    querySelector(selector) { return selector === '.ab-item' ? trigger : toolbarCount; },
    contains() { return false; },
};
const originalQuery = panel.querySelector;
panel.querySelector = selector => selector === 'details' ? details : originalQuery(selector);
panel.classList = { add() {}, remove() {}, toggle() {} };
context.document.getElementById = id => id === 'wutm-notice-center' ? panel : id === 'wp-admin-bar-wutm-notice-center' ? toolbar : content;
context.document.readyState = 'complete';
context.document.addEventListener = (event, callback) => { events['document-' + event] = callback; };
items.children = [];
vm.runInNewContext(fs.readFileSync('assets/js/notice-center.js', 'utf8'), context);
assert.equal(panel.parentElement, toolbar, 'Panel belongs in the admin toolbar, outside the observed content');
assert.equal(toolbarCount.textContent, '4');
assert.equal(details.open, false);
events.click({ preventDefault() {} });
assert.equal(details.open, true);
events['document-keydown']({ key: 'Escape' });
assert.equal(details.open, false);
const styles = fs.readFileSync('modules/notice-center/module.php', 'utf8');
assert.ok(!styles.includes('wutm-notice-precollect-fallback'), 'No timed reveal before collection');
assert.ok(styles.includes('details:not([open])>.wutm-notice-items{display:none!important;}'), 'Closed panel stays collapsed despite plugin styles');
assert.ok(styles.includes('.wutm-notice-items>.hidden,'), 'Hidden notices remain hidden inside the panel');
assert.ok(!styles.includes('wc-admin-notice{display:block;'), 'Panel must not force hidden or inline-style notices to display');
console.log('Notice-center fallback check passed.');
