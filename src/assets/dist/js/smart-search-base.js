(function () {
    'use strict';

    var ns = window.SmartSearch = window.SmartSearch || {};
    ns.core = ns.core || {};
    ns.components = ns.components || {};

    ns.core.Utils = {
        parseJSON: function (str, fallback) {
            if (!str) return fallback;
            try { return JSON.parse(str); } catch (e) { return fallback; }
        }
    };

    ns.core.errors = {
        messageFor: function (err) {
            return (err && err.message) ||
                Craft.t('smart-search', 'Something went wrong. The administrator can find details in the Smart Search log.');
        }
    };
})();
