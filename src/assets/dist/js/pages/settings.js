(function () {
    'use strict';

    var ns = window.SmartSearch;
    var DOM = ns.core.DOM;
    var Utils = ns.core.Utils;
    var errors = ns.core.errors;

    var DB_FIELDS = ['db-host', 'db-port', 'db-database', 'db-user', 'db-password', 'db-ssl-mode'];
    var DB_REQUIRED = ['db-host', 'db-port', 'db-database', 'db-user', 'db-password'];

    function valueOf(targetName) {
        var el = DOM.find(targetName);
        return el ? String(el.value || '') : '';
    }

    function setResult(el, text, state) {
        if (!el) return;
        Utils.setText(el, text);
        DOM.setState(el, state || '');
    }

    function runTest(action, getData, result, successMessage) {
        setResult(result, 'Testing…', '');
        var options = getData ? { data: getData() } : {};
        Craft.sendActionRequest('POST', action, options)
            .then(function (response) {
                var data = response.data || {};
                if (data.success && data.warning) setResult(result, data.warning, 'warn');
                else if (data.success) setResult(result, successMessage, 'ok');
                else setResult(result, errors.messageFor(data), 'error');
            })
            .catch(function (error) {
                var body = (error && error.response && error.response.data) || {};
                setResult(result, errors.messageFor(body), 'error');
            });
    }

    function setupTest(opts) {
        var btn = DOM.findControl(opts.control);
        if (!btn) return;
        var result = DOM.find(opts.resultTarget);

        function isReady() {
            return opts.requiredFields.every(function (name) {
                return valueOf(name).trim() !== '';
            });
        }

        function sync() {
            var ready = isReady();
            btn.disabled = !ready;
            btn.classList.toggle('disabled', !ready);
        }

        btn.addEventListener('click', function () {
            if (btn.disabled) return;
            runTest(opts.action, opts.getData, result, opts.successMessage);
        });

        sync();

        opts.watchFields.forEach(function (name) {
            var el = DOM.find(name);
            if (!el) return;
            el.addEventListener('input', function () { sync(); setResult(result, '', ''); });
            el.addEventListener('change', sync);
        });
    }

    function setupSmartWarning() {
        var select = DOM.find('smart-embedding-model');
        var warning = DOM.find('smart-embedding-warning');
        if (!select || !warning) return;
        var original = select.value;
        select.addEventListener('change', function () {
            Utils.setHidden(warning, select.value === original);
        });
    }

    function readDbFields() {
        return {
            host: valueOf('db-host'),
            port: valueOf('db-port'),
            database: valueOf('db-database'),
            user: valueOf('db-user'),
            password: valueOf('db-password'),
            sslMode: valueOf('db-ssl-mode'),
            vectorsSchemaName: valueOf('db-vectors-schema'),
            vectorsTableName: valueOf('db-vectors-table'),
        };
    }

    ns.pages.settings = {
        init: function () {
            setupTest({
                control: 'test-db',
                resultTarget: 'test-db-result',
                action: 'smart-search/settings/test-database-connection',
                successMessage: 'Connected successfully.',
                requiredFields: DB_REQUIRED,
                watchFields: DB_FIELDS,
                getData: readDbFields,
            });
            setupTest({
                control: 'test-api-key',
                resultTarget: 'test-api-key-result',
                action: 'smart-search/settings/test-api-key',
                successMessage: 'API key is valid.',
                requiredFields: ['openai-api-key'],
                watchFields: ['openai-api-key'],
                getData: function () { return { apiKey: valueOf('openai-api-key') }; },
            });
            setupSmartWarning();
        }
    };

    DOM.ready(ns.pages.settings.init);
})();
