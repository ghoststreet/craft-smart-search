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
            var parts = line.split(/(\[\d+\])/);
            parts.forEach(function (part) {
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

    function getRoot() {
        return DOM.find('preview-page');
    }

    function getSiteId() {
        var sel = DOM.findControl('site-select');
        return sel ? parseInt(sel.value, 10) : null;
    }

    function csrfParam() {
        var name = (window.Craft && Craft.csrfTokenName) || ns.config.csrfTokenName;
        var value = (window.Craft && Craft.csrfTokenValue) || ns.config.csrfTokenValue;
        if (!name || !value) return '';
        return encodeURIComponent(name) + '=' + encodeURIComponent(value);
    }

    function streamActionUrl(path, params) {
        var base = (window.Craft && Craft.actionUrl) ? Craft.actionUrl : '/actions/';
        var url = base.replace(/\/$/, '') + '/' + path;
        if (params) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + params;
        }
        return url;
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
        if (r.excerpt) {
            excerptEl.textContent = r.excerpt;
        } else {
            excerptEl.remove();
        }
        return card;
    }

    function postSearch(action, query, siteId) {
        var data = { q: query };
        if (siteId) data.siteId = siteId;

        return Craft.sendActionRequest('POST', action, { data: data })
            .then(function (r) { return r.data; })
            .catch(function (err) {
                var data = (err.response && err.response.data) || {};
                var message = data.message || data.code || err.message || 'Search failed.';
                throw new Error(message);
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

    function runSmart(query) {
        var root = getRoot();
        var resultsEl = DOM.find('smart-results', root);
        var errorEl = DOM.find('smart-error', root);

        if (!query) { reset(resultsEl, errorEl); return; }

        setLoading(resultsEl, errorEl);
        postSearch('smart-search/search/search', query, getSiteId())
            .then(function (data) { renderCards(resultsEl, data.semanticResults || []); })
            .catch(function (err) { showError(resultsEl, errorEl, err.message); });
    }

    function runCraft(query) {
        var root = getRoot();
        var resultsEl = DOM.find('craft-results', root);
        var errorEl = DOM.find('craft-error', root);

        if (!query) { reset(resultsEl, errorEl); return; }

        setLoading(resultsEl, errorEl);
        postSearch('smart-search/search/index', query, getSiteId())
            .then(function (data) { renderCards(resultsEl, data.results || []); })
            .catch(function (err) { showError(resultsEl, errorEl, err.message); });
    }

    function runAiAnswer(query) {
        var root = getRoot();
        var resultsEl = DOM.find('ai-answer-results', root);
        var summaryEl = DOM.find('ai-answer-summary', root);
        var errorEl = DOM.find('ai-answer-error', root);

        if (ragEventSource) { ragEventSource.close(); ragEventSource = null; }

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
        var params = 'q=' + encodeURIComponent(query);
        if (siteId) params += '&siteId=' + siteId;
        var csrf = csrfParam();
        if (csrf) params += '&' + csrf;

        var url = streamActionUrl('smart-search/search/ai-answer-stream', params);
        var summaryText = '';

        ragEventSource = new EventSource(url);

        ragEventSource.addEventListener('sources', function (e) {
            try {
                ragSources = JSON.parse(e.data).sources || [];
                renderCards(resultsEl, ragSources);
            } catch (_) {}
        });

        ragEventSource.addEventListener('token', function (e) {
            try {
                summaryText += JSON.parse(e.data).t || '';
                summaryEl.innerHTML = '';
                summaryEl.appendChild(formatSummary(summaryText, ragSources));
                summaryEl.hidden = false;
            } catch (_) {}
        });

        ragEventSource.addEventListener('done', function () {
            if (ragEventSource) { ragEventSource.close(); ragEventSource = null; }
        });

        ragEventSource.addEventListener('error', function (e) {
            if (ragEventSource) { ragEventSource.close(); ragEventSource = null; }
            var message = 'Streaming error.';
            try { if (e.data) message = JSON.parse(e.data).message || message; } catch (_) {}
            showError(resultsEl, errorEl, message);
        });

        ragEventSource.onerror = function () {
            if (ragEventSource && ragEventSource.readyState === EventSource.CLOSED) {
                ragEventSource = null;
            }
        };
    }

    function bindInputs() {
        var root = getRoot();
        if (!root) return;

        var handlers = { smart: runSmart, aiAnswer: runAiAnswer, craft: runCraft };

        Object.keys(handlers).forEach(function (type) {
            var input = DOM.findControl(type + '-input', root);
            var btn = DOM.findControl(type + '-submit', root);
            if (!input) return;

            function submit() {
                var q = input.value.trim();
                handlers[type](q);
            }

            if (btn) btn.addEventListener('click', submit);
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') submit();
            });
        });

        var siteSelect = DOM.findControl('site-select');
        if (siteSelect) {
            siteSelect.addEventListener('change', function () {
                Object.keys(handlers).forEach(function (type) {
                    var input = DOM.findControl(type + '-input', root);
                    if (input && input.value.trim()) handlers[type](input.value.trim());
                });
            });
        }
    }

    ns.pages.preview = { init: function () { bindInputs(); } };

    DOM.ready(ns.pages.preview.init);
})();
