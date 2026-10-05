(function () {
    'use strict';

    var ns = window.SmartSearch;
    var DOM = ns.core.DOM;
    var errors = ns.core.errors;

    var answerEventSource = null;
    var answerSources = [];

    function cloneTemplate(name) {
        return DOM.find(name).content.cloneNode(true);
    }

    function cloneCite(src) {
        var a = DOM.find('field-url', cloneTemplate('cite-tpl'));
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
                        p.appendChild(cloneCite(src));
                    }
                } else {
                    p.appendChild(document.createTextNode(part));
                }
            });
            fragment.appendChild(p);
        });
        return fragment;
    }

    function getSiteId(root) {
        var sel = DOM.findControl('site-select', root);
        return sel ? parseInt(sel.value, 10) : null;
    }

    function cloneCard(r) {
        var card = DOM.find('result-card', cloneTemplate('result-card-tpl'));
        var titleEl = DOM.find('field-url', card);
        var excerptEl = DOM.find('field-excerpt', card);
        titleEl.href = r.url || '#';
        titleEl.textContent = r.title || 'Untitled';
        if (r.excerpt) excerptEl.textContent = r.excerpt;
        else excerptEl.remove();
        return card;
    }

    function postSearch(action, query, siteId) {
        var data = { q: query };
        if (siteId) data.siteId = siteId;
        return Craft.sendActionRequest('POST', action, { data: data })
            .then(function (r) { return r.data; })
            .catch(function (err) {
                throw new Error(errors.messageFor((err.response && err.response.data) || err));
            });
    }

    function renderCards(resultsEl, results) {
        resultsEl.innerHTML = '';
        if (!results || results.length === 0) {
            resultsEl.hidden = true;
            return;
        }
        results.forEach(function (r) { resultsEl.appendChild(cloneCard(r)); });
        resultsEl.hidden = false;
    }

    function setLoading(resultsEl, errorEl) {
        resultsEl.innerHTML = '';
        resultsEl.appendChild(cloneTemplate('loading-tpl'));
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

    function closeAnswerStream() {
        if (answerEventSource) { answerEventSource.close(); answerEventSource = null; }
    }

    function runAiAnswer(query, root) {
        var resultsEl = DOM.find('ai-answer-results', root);
        var summaryEl = DOM.find('ai-answer-summary', root);
        var errorEl = DOM.find('ai-answer-error', root);

        closeAnswerStream();
        summaryEl.hidden = true;
        summaryEl.innerHTML = '';
        answerSources = [];

        if (!query) {
            reset(resultsEl, errorEl);
            return;
        }

        setLoading(resultsEl, errorEl);

        var siteId = getSiteId(root);
        var params = { q: query };
        if (siteId) params.siteId = siteId;
        if (Craft.csrfTokenName && Craft.csrfTokenValue) params[Craft.csrfTokenName] = Craft.csrfTokenValue;

        var summaryText = '';
        answerEventSource = new EventSource(Craft.getActionUrl('smart-search/search/ai-answer-stream', params));

        answerEventSource.addEventListener('sources', function (e) {
            var data = parseEventData(e);
            if (!data) return;
            answerSources = data.sources || [];
            renderCards(resultsEl, answerSources);
        });

        answerEventSource.addEventListener('token', function (e) {
            var data = parseEventData(e);
            if (!data) return;
            summaryText += data.t || '';
            summaryEl.innerHTML = '';
            summaryEl.appendChild(formatSummary(summaryText, answerSources));
            summaryEl.hidden = false;
        });

        answerEventSource.addEventListener('done', closeAnswerStream);

        answerEventSource.addEventListener('error', function (e) {
            closeAnswerStream();
            var data = e.data ? parseEventData(e) : null;
            showError(resultsEl, errorEl, (data && data.message) || 'Streaming error.');
        });
    }

    function renderTimings(root, key, timings) {
        var panel = DOM.find(key + '-timings', root);
        if (!panel) return;

        panel.innerHTML = '';
        if (!timings || timings.length === 0) {
            panel.hidden = true;
            return;
        }

        var slowest = timings.reduce(function (max, t) { return Math.max(max, t.ms); }, 0) || 1;

        timings.forEach(function (t) {
            var row = DOM.find('timing-row', cloneTemplate('timing-row-tpl'));
            DOM.find('field-label', row).textContent = t.name;
            DOM.find('field-value', row).textContent = t.ms.toFixed(1) + ' ms';
            DOM.find('field-bar', row).style.width = Math.max(1, (t.ms / slowest) * 100) + '%';
            panel.appendChild(row);
        });

        panel.hidden = false;
    }

    function runStandardSearch(query, root, key, action) {
        var resultsEl = DOM.find(key + '-results', root);
        var errorEl = DOM.find(key + '-error', root);

        renderTimings(root, key, null);
        if (!query) {
            reset(resultsEl, errorEl);
            return;
        }

        setLoading(resultsEl, errorEl);
        postSearch(action, query, getSiteId(root))
            .then(function (data) {
                renderTimings(root, key, data.timings);
                if (data.results.length === 0) {
                    resultsEl.innerHTML = '';
                    resultsEl.appendChild(cloneTemplate('no-results-tpl'));
                    resultsEl.hidden = false;
                } else {
                    renderCards(resultsEl, data.results);
                }
            })
            .catch(function (err) { showError(resultsEl, errorEl, err.message); });
    }

    var RUNNERS = {
        craft: function (q, root) { runStandardSearch(q, root, 'craft', 'smart-search/preview/craft-search'); },
        smart: function (q, root) { runStandardSearch(q, root, 'smart', 'smart-search/search'); },
        'ai-answer': runAiAnswer,
    };

    function bindForm() {
        var root = DOM.find('preview-page');
        var form = DOM.findControl('search-form', root);
        var input = DOM.findControl('query-input', form);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var q = input.value.trim();
            Object.keys(RUNNERS).forEach(function (type) {
                if (DOM.find('col-' + type, root)) RUNNERS[type](q, root);
            });
        });
    }

    DOM.ready(bindForm);
})();
