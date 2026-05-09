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

    /* ── utility ──────────────────────────────────────────── */

    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }

    function debounce(fn, ms) {
        let t;
        return function () {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, arguments), ms);
        };
    }

    /* ── static JS file loader ────────────────────────────── */

    // Cache already-loaded level data so we never fetch twice
    const levelCache = {};

    function loadLevelData(level, parentId, callback) {
        if (!useStatic) {
            loadLevelDataAjax(parentId, callback);
            return;
        }

        const maxLvl = parseInt(vars.max_levels) || 5;
        if (level < 1 || level > maxLvl) { callback(null); return; }

        const varName = 'mlcmLevel' + level;

        // Already in memory cache
        if (levelCache[level]) { callback(levelCache[level]); return; }

        // Already on window (e.g. inline script)
        if (typeof window[varName] !== 'undefined') {
            levelCache[level] = window[varName];
            delete window[varName];
            callback(levelCache[level]);
            return;
        }

        const ver = (vars.file_versions && vars.file_versions[level]) ? vars.file_versions[level] : '';
        const url = staticUrl + '/level-' + level + '.js' + (ver ? '?v=' + ver : '');

        const script = document.createElement('script');
        script.src   = url;
        script.async = true;

        const timer = setTimeout(function () {
            script.onload = script.onerror = null;
            if (script.parentNode) script.parentNode.removeChild(script);
            loadLevelDataAjax(parentId, callback);
        }, 10000);

        script.onload = function () {
            clearTimeout(timer);
            if (script.parentNode) script.parentNode.removeChild(script);
            if (typeof window[varName] !== 'undefined') {
                levelCache[level] = window[varName];
                delete window[varName];
                callback(levelCache[level]);
            } else {
                loadLevelDataAjax(parentId, callback);
            }
        };

        script.onerror = function () {
            clearTimeout(timer);
            if (script.parentNode) script.parentNode.removeChild(script);
            loadLevelDataAjax(parentId, callback);
        };

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
        return '<option value="' + id + '" data-slug="' + escAttr(slug) + '" data-url="' + escAttr(url) + '">' + escHtml(name) + '</option>';
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function escAttr(s) { return escHtml(s); }

    function getSubcatsForParent(data, parentId) {
        if (Array.isArray(data)) return data;          // level 1 flat array
        if (data && data[parentId]) return data[parentId]; // level 2+ keyed by parent
        return null;
    }

    /* ── core logic ───────────────────────────────────────── */

    function init(container) {
        const maxLevels = parseInt(container.dataset.levels) || 3;

        // Populate level 1 on load
        if (useStatic) {
            loadLevelData(1, 0, function (data) {
                if (data && Array.isArray(data)) populateSelect(container, 1, data);
            });
        }

        // Remove duplicate Go buttons
        var buttons = qsa('.mlcm-go-button', container);
        buttons.slice(1).forEach(function (b) { b.parentNode.removeChild(b); });

        // Change handler (delegated)
        var debouncedChange = debounce(function (e) {
            var select = e.target;
            if (!select.classList.contains('mlcm-select')) return;

            var level    = parseInt(select.dataset.level);
            var parentId = parseInt(select.value);

            if (parentId === -1) { resetFrom(container, level); return; }

            if (level >= maxLevels) { redirectToCategory(container); return; }

            loadSubcategories(container, level, parentId, maxLevels);
        }, 150);

        container.addEventListener('change', debouncedChange);

        // Go button
        container.addEventListener('click', function (e) {
            if (e.target.classList.contains('mlcm-go-button')) {
                redirectToCategory(container);
            }
        });

        // Mobile layout
        handleMobileLayout(container);
        window.addEventListener('resize', debounce(function () {
            handleMobileLayout(container);
        }, 100));
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
                sel.value    = '-1';
                sel.innerHTML = '<option value="-1">' + escHtml(labels[lvl - 1] || 'Level ' + lvl) + '</option>';
            }
        });
    }

    function loadSubcategories(container, level, parentId, maxLevels) {
        var nextLevel  = level + 1;
        var nextSelect = qs('.mlcm-select[data-level="' + nextLevel + '"]', container);

        if (nextSelect) {
            nextSelect.disabled  = true;
            nextSelect.classList.add('mlcm-loading');
            nextSelect.innerHTML = '<option value="-1">' + escHtml(labels[nextLevel - 1] || '') + '</option>';
        }

        loadLevelData(nextLevel, parentId, function (data) {
            if (nextSelect) nextSelect.classList.remove('mlcm-loading');

            if (!data) {
                if (nextSelect) nextSelect.disabled = false;
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
            if (sel.value !== '-1') last = sel;
        });

        if (!last) return;
        var selected = last.options[last.selectedIndex];
        var url      = selected ? selected.dataset.url : '';

        if (url && (url.indexOf('http://') === 0 || url.indexOf('https://') === 0)) {
            window.location.href = url;
        }
    }

    function handleMobileLayout(container) {
        var btn = qs('.mlcm-go-button', container);
        if (!btn) return;
        if (window.matchMedia('(max-width: 768px)').matches) {
            btn.style.width  = '100%';
            btn.style.margin = '10px 0 0 0';
        } else {
            btn.style.width  = '';
            btn.style.margin = '';
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
