(function () {
    'use strict';

    var ns = window.SmartSearch;
    var DOM = ns.core.DOM;

    var ragEventSource = null;
    var ragSources = [];

    function cloneCite(src) {
        var tpl = DOM.find('cite-tpl');
        if (!tpl) return null;
        var node = tpl.content.cloneNode(true);
        var a = DOM.find('field-url', node);
        if (!a) {
            console.error('cite-tpl is missing data-craftsearch-target="field-url"');
            return null;
        }
        a.href = src.url || '#';
        a.textContent = src.title || 'Source';
        return a;
    }

    function formatSummary(text, sources) {
        var sourcesById = {};
        sources.forEach(function (s) { sourcesById[s.id] = s; });

        var fragment = document.createDocumentFragment();
        var lines = text.split(/\n+/).map(function (l) { return l.trim(); }).filter(Boolean);
        lines.forEach(function (line) {
            var p = document.createElement('p');
            line.split(/(\[\d+\])/).forEach(function (part) {
                var m = part.match(/^\[(\d+)\]$/);
                if (m) {
                    var src = sourcesById[parseInt(m[1], 10)];
                    if (src) {
                        p.appendChild(document.createTextNode(' '));
                        var cite = cloneCite(src);
                        if (cite) p.appendChild(cite);
                    }
                } else {
                    p.appendChild(document.createTextNode(part));
                }
            });
            fragment.appendChild(p);
        });
        return fragment;
    }

    function getRoot() { return DOM.find('preview-page'); }

    function getSiteId() {
        var sel = DOM.findControl('site-select');
        return sel ? parseInt(sel.value, 10) : null;
    }

    function cloneCard(r) {
        var tpl = DOM.find('result-card-tpl');
        if (!tpl) return null;
        var node = tpl.content.cloneNode(true);
        var card = DOM.find('result-card', node);
        var titleEl = DOM.find('field-url', card);
        var excerptEl = DOM.find('field-excerpt', card);
        titleEl.href = r.url || '#';
        titleEl.textContent = r.title || 'Untitled';
        if (r.excerpt) excerptEl.textContent = r.excerpt;
        else excerptEl.remove();
        return card;
    }

    function postSearch(action, query, siteId, type) {
        var data = { q: query };
        if (type) data.type = type;
        if (siteId) data.siteId = siteId;
        return Craft.sendActionRequest('POST', action, { data: data })
            .then(function (r) { return r.data; })
            .catch(function (err) {
                var body = (err.response && err.response.data) || {};
                throw new Error(body.message || body.code || err.message || 'Search failed.');
            });
    }

    function renderCards(resultsEl, results) {
        resultsEl.innerHTML = '';
        if (!results || results.length === 0) {
            resultsEl.hidden = true;
            return;
        }
        results.forEach(function (r) {
            var card = cloneCard(r);
            if (card) resultsEl.appendChild(card);
        });
        resultsEl.hidden = false;
    }

    function setLoading(resultsEl, errorEl) {
        var tpl = DOM.find('loading-tpl');
        resultsEl.innerHTML = '';
        if (tpl) resultsEl.appendChild(tpl.content.cloneNode(true));
        resultsEl.hidden = false;
        errorEl.hidden = true;
        errorEl.textContent = '';
    }

    function showError(resultsEl, errorEl, message) {
        resultsEl.hidden = true;
        resultsEl.innerHTML = '';
        errorEl.textContent = message || 'An error occurred.';
        errorEl.hidden = false;
    }

    function reset(resultsEl, errorEl) {
        resultsEl.hidden = true;
        resultsEl.innerHTML = '';
        errorEl.hidden = true;
        errorEl.textContent = '';
    }

    function parseEventData(e) {
        try { return JSON.parse(e.data); }
        catch (err) { console.warn('Malformed stream payload', err); return null; }
    }

    function closeRagStream() {
        if (ragEventSource) { ragEventSource.close(); ragEventSource = null; }
    }

    function runRagAnswer(query, root) {
        var resultsEl = DOM.find('ai-answer-results', root);
        var summaryEl = DOM.find('ai-answer-summary', root);
        var errorEl = DOM.find('ai-answer-error', root);

        closeRagStream();

        if (!query) {
            reset(resultsEl, errorEl);
            summaryEl.hidden = true;
            summaryEl.innerHTML = '';
            ragSources = [];
            return;
        }

        setLoading(resultsEl, errorEl);
        summaryEl.hidden = true;
        summaryEl.innerHTML = '';
        ragSources = [];

        var siteId = getSiteId();
        var params = { q: query, type: 'ai-answer-stream' };
        if (siteId) params.siteId = siteId;
        if (Craft.csrfTokenName && Craft.csrfTokenValue) params[Craft.csrfTokenName] = Craft.csrfTokenValue;

        var summaryText = '';
        ragEventSource = new EventSource(Craft.getActionUrl('smart-search/search', params));

        ragEventSource.addEventListener('sources', function (e) {
            var data = parseEventData(e);
            if (!data) return;
            ragSources = data.sources || [];
            renderCards(resultsEl, ragSources);
        });

        ragEventSource.addEventListener('token', function (e) {
            var data = parseEventData(e);
            if (!data) return;
            summaryText += data.t || '';
            summaryEl.innerHTML = '';
            summaryEl.appendChild(formatSummary(summaryText, ragSources));
            summaryEl.hidden = false;
        });

        ragEventSource.addEventListener('done', closeRagStream);

        ragEventSource.addEventListener('error', function (e) {
            closeRagStream();
            var data = e.data ? parseEventData(e) : null;
            showError(resultsEl, errorEl, (data && data.message) || 'Streaming error.');
        });
    }

    // Only rendered when the API returned timings, which it does for an admin or in devMode.
    function renderTimings(root, key, timings) {
        var panel = DOM.find(key + '-timings', root);
        if (!panel) return;

        panel.innerHTML = '';
        if (!timings || timings.length === 0) {
            panel.hidden = true;
            return;
        }

        var tpl = DOM.find('timing-row-tpl');
        if (!tpl) { panel.hidden = true; return; }

        var slowest = timings.reduce(function (max, t) { return Math.max(max, t.ms); }, 0) || 1;

        timings.forEach(function (t) {
            var node = tpl.content.cloneNode(true);
            var row = DOM.find('timing-row', node);
            DOM.find('field-label', row).textContent = t.name;
            DOM.find('field-value', row).textContent = t.ms.toFixed(1) + ' ms';
            DOM.find('field-bar', row).style.width = Math.max(1, (t.ms / slowest) * 100) + '%';
            panel.appendChild(row);
        });

        panel.hidden = false;
    }

    function runStandardSearch(query, root, opts) {
        var resultsEl = DOM.find(opts.resultsTarget, root);
        var errorEl = DOM.find(opts.errorTarget, root);

        if (!query) {
            reset(resultsEl, errorEl);
            renderTimings(root, opts.key, null);
            return;
        }

        setLoading(resultsEl, errorEl);
        renderTimings(root, opts.key, null);
        postSearch(opts.action, query, getSiteId(), opts.type)
            .then(function (data) {
                renderTimings(root, opts.key, data.timings);
                var results = data[opts.dataField] || [];
                if (results.length === 0) {
                    resultsEl.innerHTML = '<div class="ss-preview-col__summary"><p>No relevant results found for your query.</p></div>';
                    resultsEl.hidden = false;
                } else {
                    renderCards(resultsEl, results);
                }
            })
            .catch(function (err) { showError(resultsEl, errorEl, err.message); });
    }

    var RUNNERS = {
        craft: function (q, root) {
            runStandardSearch(q, root, {
                key: 'craft',
                resultsTarget: 'craft-results', errorTarget: 'craft-error',
                action: 'smart-search/preview/craft-search', dataField: 'results',
            });
        },
        smart: function (q, root) {
            runStandardSearch(q, root, {
                key: 'smart',
                resultsTarget: 'smart-results', errorTarget: 'smart-error',
                action: 'smart-search/search', dataField: 'semanticResults', type: 'search',
            });
        },
        local: function (q, root) {
            runStandardSearch(q, root, {
                key: 'local',
                resultsTarget: 'local-results', errorTarget: 'local-error',
                action: 'smart-search/search', dataField: 'semanticResults', type: 'local',
            });
        },
        'ai-answer': function (q, root) { runRagAnswer(q, root); },
    };

    function bindInputs() {
        var root = getRoot();
        if (!root) return;

        var input = DOM.findControl('query-input', root);
        var btn = DOM.findControl('search-submit', root);
        if (!input) return;

        function submit() {
            var q = input.value.trim();
            Object.keys(RUNNERS).forEach(function (type) {
                // A column the settings have switched off is not rendered at all.
                if (DOM.find('col-' + type, root)) RUNNERS[type](q, root);
            });
        }

        if (btn) btn.addEventListener('click', submit);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') submit();
        });

        var siteSelect = DOM.findControl('site-select', root);
        if (siteSelect) {
            siteSelect.addEventListener('change', function () {
                if (input.value.trim()) submit();
            });
        }
    }

    DOM.ready(bindInputs);
})();
