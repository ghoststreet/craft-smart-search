(function () {
    'use strict';

    var ns = window.SmartSearch;
    var DOM = ns.core.DOM;
    var errors = ns.core.errors;

    var DB_FIELDS = ['db-host', 'db-port', 'db-database', 'db-user', 'db-password', 'db-ssl-mode'];
    var DB_REQUIRED = ['db-host', 'db-port', 'db-database', 'db-user', 'db-password'];

    function valueOf(targetName) {
        var el = DOM.find(targetName);
        return el ? String(el.value || '') : '';
    }

    function wireTest(controlName, resultName, action, successMessage, getData) {
        var btn = DOM.findControl(controlName);
        if (!btn) return;
        btn.addEventListener('click', function () {
            if (btn.disabled) return;
            var result = DOM.find(resultName);
            if (result) {
                result.textContent = 'Testing…';
                result.style.color = '';
            }
            var options = {};
            if (typeof getData === 'function') {
                options.data = getData();
            }
            Craft.sendActionRequest('POST', action, options)
                .then(function (response) {
                    var data = response.data || {};
                    if (!result) return;
                    if (data.success) {
                        result.textContent = successMessage;
                        result.style.color = 'green';
                    } else {
                        result.textContent = errors.messageFor(data);
                        result.style.color = 'red';
                    }
                })
                .catch(function (error) {
                    if (!result) return;
                    var body = (error && error.response && error.response.data) || {};
                    result.textContent = errors.messageFor(body);
                    result.style.color = 'red';
                });
        });
    }

    function readDbFields() {
        var out = {};
        out.host = valueOf('db-host');
        out.port = valueOf('db-port');
        out.database = valueOf('db-database');
        out.user = valueOf('db-user');
        out.password = valueOf('db-password');
        out.sslMode = valueOf('db-ssl-mode');
        return out;
    }

    function setupDbTest() {
        var btn = DOM.findControl('test-db');
        if (!btn) return;
        var result = DOM.find('test-db-result');

        wireTest('test-db', 'test-db-result', 'smart-search/settings/test-database-connection', 'Connected successfully.', readDbFields);

        function allFilled() {
            for (var i = 0; i < DB_REQUIRED.length; i++) {
                if (valueOf(DB_REQUIRED[i]).trim() === '') return false;
            }
            return true;
        }

        function sync() {
            var ready = allFilled();
            btn.disabled = !ready;
            btn.classList.toggle('disabled', !ready);
        }

        sync();

        DB_FIELDS.forEach(function (name) {
            var el = DOM.find(name);
            if (!el) return;
            el.addEventListener('input', function () {
                sync();
                if (result) {
                    result.textContent = '';
                    result.style.color = '';
                }
            });
            el.addEventListener('change', sync);
        });
    }

    function setupApiKeyTest() {
        var btn = DOM.findControl('test-api-key');
        if (!btn) return;
        var input = DOM.find('openai-api-key');
        var result = DOM.find('test-api-key-result');

        wireTest('test-api-key', 'test-api-key-result', 'smart-search/settings/test-api-key', 'API key is valid.', function () {
            return { apiKey: valueOf('openai-api-key') };
        });

        function sync() {
            var empty = valueOf('openai-api-key').trim() === '';
            btn.disabled = empty;
            btn.classList.toggle('disabled', empty);
        }

        sync();

        if (input) {
            input.addEventListener('input', function () {
                sync();
                if (result) {
                    result.textContent = '';
                    result.style.color = '';
                }
            });
        }
    }

    function setupSmartWarning() {
        var select = DOM.find('smart-embedding-model');
        var warning = DOM.find('smart-embedding-warning');
        if (!select || !warning) return;
        var original = select.value;
        select.addEventListener('change', function () {
            warning.classList.toggle('hidden', select.value === original);
        });
    }

    ns.pages.settings = {
        init: function () {
            setupDbTest();
            setupApiKeyTest();
            setupSmartWarning();
        }
    };

    DOM.ready(ns.pages.settings.init);
})();
