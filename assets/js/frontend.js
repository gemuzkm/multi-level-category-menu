/**
 * MLCM Frontend — vanilla JS, no jQuery dependency
 */
(function () {
    'use strict';

    if (typeof mlcmVars === 'undefined') return;

    const vars       = mlcmVars;
    const useStatic  = vars.use_static === '1';
    const staticUrl  = vars.static_url || '';
    const labels     = vars.labels || [];
    const ajaxUrl    = vars.ajax_url || '';

    // New pages pin immutable generation URLs. Cached HTML intentionally keeps
    // its own snapshot until page-cache expiry/purge; a manifest cannot refresh
    // server-rendered options inside already-cached HTML.
    const fileVersions = (typeof window.mlcmVersions !== 'undefined')
        ? window.mlcmVersions
        : (vars.file_versions || {});

    /* ── utility ──────────────────────────────────────────── */

    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }

    /* ── static JS file loader ────────────────────────────── */

    const levelCache = {};
    const pending = {};
    const revisions = new WeakMap();

    function loadLevelData(level, parentId, callback) {
        if (!useStatic) {
            loadLevelDataAjax(parentId, callback);
            return;
        }

        const maxLvl = parseInt(vars.max_levels) || 5;
        if (level < 1 || level > maxLvl) { callback(null); return; }

        const split = vars.parent_files && level > 1;
        const key = split ? level + ':' + parentId : String(level);
        const varName = split ? 'mlcmL' + level + '_' + parentId : 'mlcmLevel' + level;
        const resolve = function (data) {
            callback(Array.isArray(data) ? data : (getSubcatsForParent(data, parentId) || []));
        };

        if (Object.prototype.hasOwnProperty.call(levelCache, key)) { resolve(levelCache[key]); return; }
        if (pending[key]) { pending[key].push({ parentId, resolve, callback }); return; }

        if (typeof window[varName] !== 'undefined') {
            levelCache[key] = window[varName];
            delete window[varName];
            resolve(levelCache[key]);
            return;
        }

        pending[key] = [{ parentId, resolve, callback }];
        const ver = fileVersions[level] || fileVersions[String(level)] || '';
        const filename = split ? 'l' + level + '-' + parentId + '.js' : 'level-' + level + '.js';
        const url = staticUrl + '/' + filename + (ver ? '?v=' + encodeURIComponent(ver) : '');

        const script = document.createElement('script');
        script.src   = url;
        script.async = true;

        function finish(ok) {
            clearTimeout(timer);
            script.onload = script.onerror = null;
            if (script.parentNode) script.parentNode.removeChild(script);
            const listeners = pending[key] || [];
            delete pending[key];
            const data = window[varName];
            if (ok && data && typeof data === 'object') {
                levelCache[key] = data;
                delete window[varName];
                listeners.forEach(function (item) { item.resolve(data); });
            } else {
                listeners.forEach(function (item) { loadLevelDataAjax(item.parentId, item.callback); });
            }
        }
        const timer = setTimeout(function () { finish(false); }, 10000);
        script.onload = function () { finish(true); };
        script.onerror = function () { finish(false); };

        document.head.appendChild(script);
    }

    /* ── AJAX fallback (no jQuery) ────────────────────────── */

    function loadLevelDataAjax(parentId, callback) {
        if (!ajaxUrl) { callback(null); return; }

        const body = new URLSearchParams({
            action   : 'mlcm_get_subcategories',
            parent_id: parentId || 0,
            _t       : Date.now()
        });

        fetch(ajaxUrl, {
            method : 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body   : body.toString()
        })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (resp) {
            callback((resp.success && resp.data) ? resp.data : null);
        })
        .catch(function () { callback(null); });
    }

    /* ── DOM helpers ──────────────────────────────────────── */

    function buildOptions(categories, label) {
        let html = '<option value="-1">' + escHtml(label) + '</option>';

        if (Array.isArray(categories)) {
            html += categories.map(function (cat) {
                return optionTag(cat.id || cat.term_id || '', cat.name || '', cat.slug || '', cat.url || '');
            }).join('');
        } else if (categories && typeof categories === 'object') {
            html += Object.values(categories).map(function (cat) {
                return optionTag(cat.id || cat.term_id || '', cat.name || '', cat.slug || '', cat.url || '');
            }).join('');
        }

        return html;
    }

    function optionTag(id, name, slug, url) {
        return '<option value="' + escAttr(String(id)) + '" data-slug="' + escAttr(slug) + '" data-url="' + escAttr(url) + '">' + escHtml(name) + '</option>';
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function escAttr(s) { return escHtml(s); }

    function getSubcatsForParent(data, parentId) {
        if (Array.isArray(data)) return data;
        if (data && data[parentId]) return data[parentId];
        return null;
    }

    // Returns true when the <select> for the given level already contains at
    // least one real category option (the placeholder option value="-1" is
    // always present, so "populated" means options.length > 1).
    function isSelectPopulated(container, level) {
        var select = qs('.mlcm-select[data-level="' + level + '"]', container);
        return !!select && select.options.length > 1;
    }

    /* ── core logic ───────────────────────────────────────── */

    function init(container) {
        if (revisions.has(container)) return;
        revisions.set(container, 0);
        const maxLevels = parseInt(container.dataset.levels) || 3;

        // Level 1 is rendered server-side by render_select() in PHP, so the
        // first <select> normally arrives with its options already in the HTML.
        // Only request level-1.js when that select is empty (e.g. PHP could not
        // read the cache file). This removes one redundant HTTP request on
        // every page that displays the menu.
        if (useStatic && !isSelectPopulated(container, 1)) {
            loadLevelData(1, parseInt(vars.custom_root_id, 10) || 0, function (data) {
                if (data && Array.isArray(data)) populateSelect(container, 1, data);
            });
        }

        var buttons = qsa('.mlcm-go-button', container);
        buttons.slice(1).forEach(function (b) { b.parentNode.removeChild(b); });

        container.addEventListener('change', function (e) {
            var select = e.target;
            if (!select.classList.contains('mlcm-select')) return;

            var level    = parseInt(select.dataset.level);
            var parentId = parseInt(select.value);
            revisions.set(container, revisions.get(container) + 1);
            resetFrom(container, level);

            if (parentId === -1) { resetFrom(container, level); return; }

            if (level >= maxLevels) { redirectToCategory(container); return; }

            loadSubcategories(container, level, parentId, maxLevels);
        });

        container.addEventListener('click', function (e) {
            if (e.target.classList.contains('mlcm-go-button')) {
                redirectToCategory(container);
            }
        });

    }

    function populateSelect(container, level, categories) {
        var select = qs('.mlcm-select[data-level="' + level + '"]', container);
        if (!select) return;
        var label = labels[level - 1] || 'Level ' + level;
        select.disabled = false;
        select.innerHTML = buildOptions(categories, label);
    }

    function resetFrom(container, fromLevel) {
        qsa('.mlcm-select', container).forEach(function (sel) {
            var lvl = parseInt(sel.dataset.level);
            if (lvl > fromLevel) {
                sel.disabled = true;
                sel.classList.remove('mlcm-loading');
                sel.value    = '-1';
                sel.innerHTML = '<option value="-1">' + escHtml(labels[lvl - 1] || 'Level ' + lvl) + '</option>';
            }
        });
    }

    function loadSubcategories(container, level, parentId, maxLevels) {
        var revision = revisions.get(container);
        var nextLevel  = level + 1;
        var nextSelect = qs('.mlcm-select[data-level="' + nextLevel + '"]', container);

        if (nextSelect) {
            nextSelect.disabled  = true;
            nextSelect.classList.add('mlcm-loading');
            nextSelect.innerHTML = '<option value="-1">' + escHtml(labels[nextLevel - 1] || '') + '</option>';
        }

        loadLevelData(nextLevel, parentId, function (data) {
            // An older asynchronous response must never overwrite a new choice
            // or redirect the visitor after the parent selection has changed.
            if (revision !== revisions.get(container)) return;
            if (nextSelect) nextSelect.classList.remove('mlcm-loading');

            if (!data) {
                return;
            }

            var subcats = getSubcatsForParent(data, parentId);
            var hasSub  = subcats && (Array.isArray(subcats) ? subcats.length > 0 : Object.keys(subcats).length > 0);

            if (hasSub) {
                populateSelect(container, nextLevel, subcats);
                resetFrom(container, nextLevel);
            } else {
                if (nextSelect) {
                    nextSelect.disabled  = true;
                    nextSelect.innerHTML = '<option value="-1">' + escHtml(labels[nextLevel - 1] || '') + '</option>';
                }
                redirectToCategory(container);
            }
        });
    }

    function redirectToCategory(container) {
        var selects = qsa('.mlcm-select', container);
        var last    = null;

        selects.forEach(function (sel) {
            if (!sel.disabled && sel.value !== '-1') last = sel;
        });

        if (!last) return;
        var selected = last.options[last.selectedIndex];
        var url      = selected ? selected.dataset.url : '';

        if (url && (url.indexOf('http://') === 0 || url.indexOf('https://') === 0)) {
            window.location.href = url;
        }
    }

    /* ── boot ─────────────────────────────────────────────── */

    function boot() {
        var containers = qsa('.mlcm-container');
        containers.forEach(function (c) { init(c); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

}());
