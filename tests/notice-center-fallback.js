const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
    constructor(fallback = false, kind = 'notice') { this.fallback = fallback; this.kind = kind; this.children = []; }
    matches(selector) { return this.kind !== '' && new RegExp('(^|[^\\w-])\\.' + this.kind + '(?![\\w-])').test(selector); }
    closest(selector) { return selector === 'form' ? this.form || null : selector.includes('.hide-if-js') && this.fallback ? this : null; }
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
content.children = [fallback, ordinary, commerce, pluginNotice, wpcodeNotice, inlineNotice, formNotice];
const items = new Element();
items.appendChild = node => items.children.push(node);
const count = { textContent: '' };
const important = { textContent: '' };
const panel = new Element(false, '');
panel.contains = () => false;
panel.querySelector = selector => selector === '.wutm-notice-items' ? items : selector === '.wutm-notice-count' ? count : important;
panel.classList = { toggle() {} };

const context = {
    Element,
    document: {
        getElementById: id => id === 'wutm-notice-center' ? panel : content,
        readyState: 'complete',
    },
    window: { getComputedStyle: () => ({ display: 'block', visibility: 'visible' }), addEventListener() {} },
    MutationObserver: class { observe() {} },
};
vm.runInNewContext(fs.readFileSync('assets/js/notice-center.js', 'utf8'), context);
assert.deepEqual(items.children, [ordinary, commerce, pluginNotice, wpcodeNotice], 'Top-level plugin notices should be collected while fallbacks and notices with form controls stay outside');
assert.equal(count.textContent, '4');
assert.equal(important.textContent, '包含 1 則重要通知');
console.log('Notice-center fallback check passed.');
