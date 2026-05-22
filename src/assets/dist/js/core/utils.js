(function () {
    'use strict';
    window.SmartSearch.core.Utils = {
        parseJSON: function (str, fallback) {
            if (!str) return fallback;
            try { return JSON.parse(str); } catch (e) { return fallback; }
        }
    };
})();
