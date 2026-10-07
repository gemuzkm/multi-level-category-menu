import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const menu = (filled = true) => `<div class="mlcm-container" data-levels="3">
  <select class="mlcm-select" data-level="1"><option value="-1">Level 1</option>
  ${filled ? '<option value="10">A</option><option value="20">B</option>' : ''}</select>
  <select class="mlcm-select" data-level="2" disabled><option value="-1">Level 2</option></select>
  <select class="mlcm-select" data-level="3" disabled><option value="-1">Level 3</option></select>
  </div>`;

function setup(file, html = menu(), overrides = {}) {
    const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://example.test/' });
    const { window } = dom;
    window.mlcmVars = {
        use_static: '1', static_url: 'https://example.test/cache/gen-test',
        parent_files: true, max_levels: 3, ajax_url: '/ajax', labels: [],
        file_versions: { 1: 'test', 2: 'test', 3: 'test' }, ...overrides
    };
    const scripts = [];
    const append = window.document.head.appendChild.bind(window.document.head);
    window.document.head.appendChild = function (script) { scripts.push(script); return append(script); };
    window.fetch = () => Promise.reject(new Error('Unexpected AJAX request'));
    window.eval(readFileSync(new URL(`../assets/js/${file}`, import.meta.url), 'utf8'));
    window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
    const select = (level, index = 0) => window.document.querySelectorAll(`[data-level="${level}"]`)[index];
    const change = (id, level = 1, index = 0) => {
        const element = select(level, index);
        element.value = String(id);
        element.dispatchEvent(new window.Event('change', { bubbles: true }));
    };
    const deliver = (script, name, data) => { window[name] = data; script.onload(); };
    return { dom, window, scripts, select, change, deliver };
}

for (const file of ['frontend.js', 'frontend.min.js']) {
    test(`${file}: SSR options avoid level-1 request; change is immediate`, () => {
        const x = setup(file);
        assert.equal(x.scripts.length, 0);
        x.change(10);
        assert.equal(x.scripts.length, 1);
        assert.match(x.scripts[0].src, /\/l2-10\.js\?v=test$/);
        x.deliver(x.scripts[0], 'mlcmL2_10', [{ id: 11, name: 'Child', url: '/child' }]);
        assert.equal(x.select(2).options[1].value, '11');
        x.dom.window.close();
    });
    test(`${file}: empty SSR loads level 1 and AJAX uses custom root`, async () => {
        const x = setup(file, menu(false), { custom_root_id: 99 });
        assert.match(x.scripts[0].src, /level-1\.js/);
        let body;
        x.window.fetch = async (url, options) => {
            body = options.body;
            return { ok: true, json: async () => ({ success: true, data: [{ id: 10, name: 'Root child' }] }) };
        };
        x.scripts[0].onerror();
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(new URLSearchParams(body).get('parent_id'), '99');
        assert.equal(x.select(1).options[1].value, '10');
        x.dom.window.close();
    });
    test(`${file}: out-of-order responses and reset cannot overwrite latest choice`, () => {
        const x = setup(file);
        x.change(10);
        x.change(20);
        x.deliver(x.scripts[1], 'mlcmL2_20', [{ id: 21, name: 'B child' }]);
        x.deliver(x.scripts[0], 'mlcmL2_10', [{ id: 11, name: 'A child' }]);
        assert.equal(x.select(2).options[1].value, '21');
        x.change(21, 2);
        x.change(-1);
        x.deliver(x.scripts[2], 'mlcmL3_21', [{ id: 22, name: 'Old response' }]);
        assert.equal(x.select(3).disabled, true);
        assert.equal(x.select(3).options.length, 1);
        x.dom.window.close();
    });
    test(`${file}: concurrent menus share a static request and retain independent state`, () => {
        const x = setup(file, menu() + menu());
        x.change(10, 1, 0);
        x.change(10, 1, 1);
        assert.equal(x.scripts.length, 1);
        x.deliver(x.scripts[0], 'mlcmL2_10', [{ id: 11, name: 'Child' }]);
        assert.equal(x.select(2, 0).options[1].value, '11');
        assert.equal(x.select(2, 1).options[1].value, '11');
        x.dom.window.close();
    });
    test(`${file}: legacy aggregated format and script failure fallback`, async () => {
        const x = setup(file, menu(), { parent_files: false });
        x.change(10);
        assert.match(x.scripts[0].src, /level-2\.js/);
        x.deliver(x.scripts[0], 'mlcmLevel2', { 10: [{ id: 11, name: 'Old format' }] });
        assert.equal(x.select(2).options[1].value, '11');
        x.window.fetch = async () => ({ ok: true, json: async () => ({ success: true, data: [{ id: 12, name: 'AJAX' }] }) });
        x.change(11, 2);
        x.scripts[1].onerror();
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(x.select(3).options[1].value, '12');
        x.dom.window.close();
    });
    test(`${file}: AJAX error keeps children disabled; dynamic options are escaped`, async () => {
        const x = setup(file, menu(), { use_static: '0' });
        x.change(10);
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(x.select(2).disabled, true);
        x.window.fetch = async () => ({ ok: true, json: async () => ({
            success: true, data: [{ id: 'x"><img src=x>', name: '<script>alert(1)</script>', url: '" onload="evil' }]
        }) });
        x.change(20);
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(x.window.document.querySelector('img,script'), null);
        assert.equal(x.select(2).options[1].textContent, '<script>alert(1)</script>');
        x.dom.window.close();
    });
}
