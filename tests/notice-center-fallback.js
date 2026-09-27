const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
    constructor(fallback = false, isNotice = true) { this.fallback = fallback; this.isNotice = isNotice; this.children = []; }
    matches(selector) { return this.isNotice && selector.includes('.notice'); }
    closest(selector) { return selector.includes('.hide-if-js') && this.fallback ? this : null; }
    querySelectorAll() { return this.children; }
}

const fallback = new Element(true);
const ordinary = new Element(false);
const content = new Element(false, false);
content.children = [fallback, ordinary];
const items = new Element();
items.appendChild = node => items.children.push(node);
const count = { textContent: '' };
const important = { textContent: '' };
const panel = new Element(false, false);
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
assert.deepEqual(items.children, [ordinary], 'JavaScript fallback notice must stay outside the notification panel');
assert.equal(count.textContent, '1');
console.log('Notice-center fallback check passed.');
