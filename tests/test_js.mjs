import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const jsPath = path.resolve(__dirname, '../orderservice/public/js/orderservice.js');

const require = createRequire(import.meta.url);
const ui = require(jsPath);

// 1) Static security check: no innerHTML/outerHTML/insertAdjacentHTML/eval/Function sinks
const source = fs.readFileSync(jsPath, 'utf8');
assert.equal(/\b(innerHTML|outerHTML|insertAdjacentHTML|eval)\b/.test(source), false, 'JS must not contain unsafe DOM/eval sinks');

// 2) Math & formatting helpers
assert.equal(ui.parseDecimalToX100('1.234,56'), 123456);
assert.equal(ui.parseDecimalToX100('19.99'), 1999);
assert.equal(ui.parseDecimalToX100('2,5'), 250);
assert.equal(ui.parseDecimalToX100('1.005'), 101);
assert.equal(ui.parseDecimalToX100('-10,00'), 0);

assert.equal(ui.computeItemTotalCents(150, 12000), 18000);
assert.equal(ui.computeItemTotalCents(33, 1001), 330);
assert.equal(ui.computeItemTotalCents(50, 3), 2);

assert.equal(ui.formatCents(60550, 'R$'), 'R$ 605,50');
assert.equal(ui.formatCents(123456, 'R$'), 'R$ 1.234,56');
assert.equal(ui.formatCents(0, '$'), '$ 0,00');

// 3) PNG Data URI client-side validation
assert.equal(ui.isPngDataUri('data:image/png;base64,iVBORw0KGgo='), true);
assert.equal(ui.isPngDataUri('data:image/svg+xml;base64,PHN2Zz48L3N2Zz4='), false);
assert.equal(ui.isPngDataUri('data:text/html;base64,PGh0bWw+PC9odG1sPg=='), false);
assert.equal(ui.isPngDataUri('data:image/png;base64,'), false);

// 4) DOM interaction tests (signature pad, calculator, print trigger)
function makeElement(tag, attrs = {}) {
  const listeners = {};
  const attrMap = new Map(Object.entries(attrs));
  return {
    tagName: tag.toUpperCase(),
    value: attrs.value || '',
    textContent: '',
    children: [],
    ownerDocument: null,
    parentForm: null,
    getAttribute(name) {
      return attrMap.has(name) ? attrMap.get(name) : null;
    },
    setAttribute(name, val) {
      attrMap.set(name, String(val));
    },
    addEventListener(type, fn) {
      if (!listeners[type]) listeners[type] = [];
      listeners[type].push(fn);
    },
    dispatchEvent(evt) {
      const e = Object.assign({ preventDefault() { this.defaultPrevented = true; }, defaultPrevented: false }, evt);
      const list = listeners[e.type] || [];
      for (const fn of list) {
        fn.call(this, e);
      }
      return e;
    },
    closest(selector) {
      return selector === 'form' ? this.parentForm : null;
    },
    querySelector(selector) {
      return this._queryMap ? (this._queryMap[selector] || null) : null;
    },
    querySelectorAll(selector) {
      return this._queryAllMap ? (this._queryAllMap[selector] || []) : [];
    }
  };
}

const hiddenSig = makeElement('input', { id: 'sig-1', value: '' });
const statusEl = makeElement('span');
const clearBtn = makeElement('button');
const canvas = makeElement('canvas');
canvas.width = 460;
canvas.height = 150;
canvas.getBoundingClientRect = () => ({ left: 0, top: 0, width: 460, height: 150 });
canvas.getContext = () => ({
  beginPath() {},
  moveTo() {},
  lineTo() {},
  stroke() {},
  clearRect() {},
  lineWidth: 1,
  lineCap: 'round',
  lineJoin: 'round',
  strokeStyle: '#000'
});
canvas.toDataURL = (mime) => (mime === 'image/png' ? 'data:image/png;base64,iVBORw0KGgo=' : 'data:text/plain;base64,');

const pad = makeElement('div', {
  'data-input-id': 'sig-1',
  'data-required': '1',
  'data-status-ok': 'Assinatura capturada',
  'data-status-empty': 'Desenhe a assinatura acima',
  'data-msg-required': 'Assinatura obrigatória'
});
const form = makeElement('form');
const docListeners = {};
const doc = {
  getElementById(id) {
    return id === 'sig-1' ? hiddenSig : null;
  },
  addEventListener(type, fn) {
    if (!docListeners[type]) docListeners[type] = [];
    docListeners[type].push(fn);
  },
  dispatchEvent(evt) {
    const list = docListeners[evt.type] || [];
    for (const fn of list) {
      fn.call(this, evt);
    }
  }
};

hiddenSig.ownerDocument = doc;
form.ownerDocument = doc;
pad.ownerDocument = doc;
pad.parentForm = form;
pad._queryMap = {
  'canvas.os-signature-canvas': canvas,
  '.os-clear-sig': clearBtn,
  '.os-signature-status': statusEl
};
form._queryAllMap = {
  '.os-signature-pad': [pad]
};

const qtyInput = makeElement('input', { value: '1,50' });
const unitInput = makeElement('input', { value: '200,00' });
const previewEl = makeElement('div', { 'data-currency': 'R$' });
const calcForm = makeElement('form');
calcForm._queryMap = {
  '.os-calc-qty': qtyInput,
  '.os-calc-unit': unitInput,
  '.os-calc-preview': previewEl
};

let printed = false;
globalThis.print = () => { printed = true; };
const printBtn = makeElement('button');

const container = {
  querySelectorAll(selector) {
    if (selector === '.os-signature-pad') return [pad];
    if (selector === '.os-add-item-form') return [calcForm];
    if (selector === '.os-print-trigger') return [printBtn];
    return [];
  }
};

ui.initAll(container);

// Empty required signature blocks submit and shows localized required message
const blockedEvt = form.dispatchEvent({ type: 'submit' });
assert.equal(blockedEvt.defaultPrevented, true, 'empty required signature blocks submit');
assert.equal(statusEl.textContent, 'Assinatura obrigatória');

// Draw stroke populates localized status; releasing mouse outside canvas (on document) still syncs hidden input
canvas.dispatchEvent({ type: 'mousedown', clientX: 10, clientY: 10 });
assert.equal(statusEl.textContent, 'Assinatura capturada');
canvas.dispatchEvent({ type: 'mousemove', clientX: 50, clientY: 30 });
doc.dispatchEvent({ type: 'mouseup' });
assert.equal(hiddenSig.value, 'data:image/png;base64,iVBORw0KGgo=');
const allowedEvt = form.dispatchEvent({ type: 'submit' });
assert.equal(allowedEvt.defaultPrevented, false, 'drawn PNG signature allows submit');

// Clear resets hidden input with localized empty status
clearBtn.dispatchEvent({ type: 'click' });
assert.equal(hiddenSig.value, '');
assert.equal(statusEl.textContent, 'Desenhe a assinatura acima');

// Submit syncs drawn signature even before mouseup fires
canvas.dispatchEvent({ type: 'mousedown', clientX: 15, clientY: 15 });
const autoSyncedEvt = form.dispatchEvent({ type: 'submit' });
assert.equal(autoSyncedEvt.defaultPrevented, false, 'submit syncs active stroke');
assert.equal(hiddenSig.value, 'data:image/png;base64,iVBORw0KGgo=');
doc.dispatchEvent({ type: 'mouseup' });

// Live calculator preview
assert.equal(previewEl.textContent, 'R$ 300,00');
qtyInput.value = '2,50';
qtyInput.dispatchEvent({ type: 'input' });
assert.equal(previewEl.textContent, 'R$ 500,00');

// Print trigger
printBtn.dispatchEvent({ type: 'click' });
assert.equal(printed, true);

console.log('JS_OK all assertions passed');
