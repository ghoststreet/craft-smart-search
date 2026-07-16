(function () {
    'use strict';

    var HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

    window.SmartSearch.core.Utils = {
        parseJSON: function (str, fallback) {
            if (!str) return fallback;
            try { return JSON.parse(str); } catch (e) { return fallback; }
        },

        escapeHtml: function (s) {
            return String(s).replace(/[&<>"']/g, function (c) { return HTML_ESCAPES[c]; });
        },

        setText: function (el, text) {
            if (el && el.textContent !== text) el.textContent = text;
        },

        setHidden: function (el, hidden) {
            if (el) el.hidden = !!hidden;
        }
    };
})();
