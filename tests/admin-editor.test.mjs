import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
import jquery from 'jquery';

for (const suffix of ['.js', '.min.js']) {
    test(`admin${suffix}: nonce, busy state, escaped messages and confirmed deletion`, async () => {
        const dom = new JSDOM(`<button id="mlcm-generate-menu"></button><span class="spinner"></span>
            <button id="mlcm-delete-cache"></button><span class="spinner"></span><div id="mlcm-generation-status"></div>`,
            { runScripts: 'outside-only' });
        const { window } = dom;
        const $ = jquery(window);
        window.jQuery = $;
        window.mlcmAdmin = { ajax_url: '/ajax', nonce: 'test-nonce', i18n: {
            generating: 'Generating', menu_generated: 'Done', error: 'Error',
            deleting: 'Deleting', cache_deleted: 'Deleted', delete_error: 'Delete failed',
            confirm_delete: 'Delete?'
        } };
        const requests = [];
        $.ajax = options => {
            const deferred = $.Deferred();
            requests.push({ options, deferred });
            return deferred.promise();
        };
        window.eval(readFileSync(new URL(`../assets/js/admin${suffix}`, import.meta.url), 'utf8'));
        window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
        await new Promise(resolve => $(resolve));
        $('#mlcm-generate-menu').trigger('click');
        assert.equal(requests[0].options.data.security, 'test-nonce');
        assert.equal($('#mlcm-delete-cache').prop('disabled'), true);
        requests[0].deferred.resolve({ success: true, data: { message: '<img src=x onerror=evil>' } });
        assert.equal($('#mlcm-generation-status img').length, 0);
        assert.equal($('#mlcm-generation-status p').text(), '<img src=x onerror=evil>');
        assert.equal($('#mlcm-delete-cache').prop('disabled'), false);
        window.confirm = () => false;
        $('#mlcm-delete-cache').trigger('click');
        assert.equal(requests.length, 1);
        window.confirm = () => true;
        $('#mlcm-delete-cache').trigger('click');
        assert.equal(requests[1].options.data.action, 'mlcm_delete_cache');
        requests[1].deferred.reject({ responseJSON: { data: { message: '<b>failure</b>' } } });
        assert.equal($('#mlcm-generation-status b').length, 0);
        assert.equal($('.spinner.is-active').length, 0);
        window.close();
    });
    test(`block-editor${suffix}: one registration, API version, max depth and wrapper props`, () => {
        const dom = new JSDOM('', { runScripts: 'outside-only' });
        const { window } = dom;
        const registrations = [];
        window.mlcmBlockVars = { api_version: 3, max_levels: 8 };
        window.wp = {
            blocks: { registerBlockType: (name, settings) => registrations.push({ name, settings }) },
            blockEditor: { InspectorControls: 'Inspector', useBlockProps: props => ({ ...props, 'data-wrapper': true }) },
            components: { PanelBody: 'Panel', SelectControl: 'Select', RangeControl: 'Range' },
            i18n: { __: text => text },
            element: { createElement: (type, props, ...children) => ({ type, props, children }) }
        };
        window.eval(readFileSync(new URL(`../assets/js/block-editor${suffix}`, import.meta.url), 'utf8'));
        assert.equal(registrations.length, 1);
        const { settings } = registrations[0];
        assert.equal(settings.apiVersion, 3);
        const nodes = settings.edit({ attributes: { layout: 'vertical', levels: 3 }, setAttributes() {} });
        assert.equal(nodes[0].children[0].children[1].props.max, 8);
        assert.equal(nodes[1].props['data-wrapper'], true);
        assert.equal(settings.save(), null);
        window.close();
    });
}
