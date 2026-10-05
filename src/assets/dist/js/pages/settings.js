(function () {
    'use strict';

    var ns = window.SmartSearch;
    var DOM = ns.core.DOM;
    var errors = ns.core.errors;

    /**
     * Selectize keeps the real <select> in the DOM but announces a change through jQuery,
     * which a native listener never sees, so every change listener here goes through
     * jQuery too. Plain fields behave the same either way.
     */
    function onChange(el, handler) {
        $(el).on('change', handler);
    }

    function valueOf(targetName) {
        return String(DOM.find(targetName).value || '');
    }

    function keyInput() {
        var block = document.querySelector('[data-smart-search-provider="' + valueOf('ai-provider') + '"]');
        return DOM.find('api-key', block);
    }

    function setResult(el, text, state) {
        el.textContent = text;
        DOM.setState(el, state);
    }

    function setupKeyTest() {
        var btn = DOM.findControl('test-api-key');
        var row = DOM.find('test-api-key-row');
        var result = DOM.find('test-api-key-result');

        function sync() {
            row.hidden = keyInput().value.trim() === '';
        }

        btn.addEventListener('click', function () {
            setResult(result, 'Testing…', '');
            Craft.sendActionRequest('POST', 'smart-search/settings/test-api-key', {
                data: { provider: valueOf('ai-provider'), apiKey: keyInput().value },
            })
                .then(function (response) {
                    if (response.data.success) setResult(result, 'API key is valid.', 'ok');
                    else setResult(result, errors.messageFor(response.data), 'error');
                })
                .catch(function (error) {
                    setResult(result, errors.messageFor((error.response && error.response.data) || {}), 'error');
                });
        });

        DOM.findAll('api-key').forEach(function (input) {
            input.addEventListener('input', function () { sync(); setResult(result, '', ''); });
            onChange(input, sync);
        });

        sync();
        return sync;
    }

    function repopulate(targetName, options) {
        var select = DOM.find(targetName);
        var previous = select.value;
        var pairs = Array.isArray(options)
            ? options.map(function (v) { return [String(v), String(v)]; })
            : Object.keys(options).map(function (k) { return [k, options[k]]; });

        var kept = pairs.some(function (pair) { return pair[0] === previous; });
        var next = kept ? previous : (pairs.length ? pairs[0][0] : '');

        var selectize = select.selectize;

        if (selectize) {
            selectize.clear(true);
            selectize.clearOptions(true);
            selectize.addOption(pairs.map(function (pair) {
                return { value: pair[0], text: pair[1] };
            }));
            selectize.refreshOptions(false);
            if (next !== '') selectize.addItem(next, true);
            return;
        }

        select.textContent = '';
        pairs.forEach(function (pair) {
            var opt = document.createElement('option');
            opt.value = pair[0];
            opt.textContent = pair[1];
            select.appendChild(opt);
        });
        select.value = next;
    }

    /**
     * Widths are a property of the embedding model, not of the site: Voyage returns 512 or
     * 1024 and refuses 1536, so the list has to follow whatever model is selected. A
     * provider without a width choice hides the field and the server picks the width.
     */
    function syncDimensions(models) {
        DOM.find('dimensions-field').hidden = !models.offersWidthChoice;

        var widths = (models.dimensions || {})[valueOf('smart-embedding-model')];
        if (widths && widths.length) repopulate('dimensions', widths);
    }

    /**
     * A provider owns its key field and its model lists, so switching provider swaps both.
     * The lists come from the page rather than a request: they are already rendered for
     * every provider.
     */
    function setupProviderToggle(select, onProviderChange) {
        var models = ns.core.Utils.parseJSON(DOM.find('provider-models').textContent, {});

        onChange(select, function () {
            var handle = select.value;
            document.querySelectorAll('[data-smart-search-provider]').forEach(function (block) {
                block.hidden = block.getAttribute('data-smart-search-provider') !== handle;
            });
            repopulate('smart-embedding-model', models[handle].embedding);
            repopulate('ai-answer-model', models[handle].answer);
            syncDimensions(models[handle]);
            onProviderChange();
        });
        onChange(DOM.find('smart-embedding-model'), function () {
            syncDimensions(models[select.value]);
        });
    }

    /**
     * Provider, model and width each decide what a stored vector means, so a change to
     * any sends the content back through indexing. Same trio the save action compares.
     */
    function setupSmartWarning() {
        var warning = DOM.find('smart-embedding-warning');
        if (!warning) return function () {};

        var fields = ['smart-embedding-model', 'ai-provider', 'dimensions']
            .map(function (name) { return DOM.find(name); })
            .map(function (field) { return { field: field, original: field.value }; });

        function sync() {
            warning.hidden = fields.every(function (watched) {
                return watched.field.value === watched.original;
            });
        }

        fields.forEach(function (watched) { onChange(watched.field, sync); });
        return sync;
    }

    function setupWeightBar() {
        var bar = DOM.find('weight-bar');
        if (!bar) return;

        var input = DOM.findControl('weight', bar);
        var keyword = DOM.find('weight-keyword', bar);
        var semantic = DOM.find('weight-semantic', bar);

        function render() {
            var semanticPct = Math.round(parseFloat(input.value) * 100);
            keyword.textContent = 'Keyword ' + (100 - semanticPct) + '%';
            semantic.textContent = 'Semantic ' + semanticPct + '%';
            bar.style.setProperty('--ss-weight-split', semanticPct + '%');
        }

        input.addEventListener('input', render);
        render();
    }

    function init() {
        setupWeightBar();

        var provider = DOM.find('ai-provider');
        if (!provider) return;

        var syncKeyTest = setupKeyTest();
        var syncWarning = setupSmartWarning();
        setupProviderToggle(provider, function () {
            syncKeyTest();
            syncWarning();
            setResult(DOM.find('test-api-key-result'), '', '');
        });
    }

    DOM.ready(init);
})();
