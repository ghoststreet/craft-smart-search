(function () {
    'use strict';
    window.SmartSearch.core.craft = {
        runQueue: function () { Craft.cp.runQueue(); },
        notice: function (msg) { Craft.cp.displayNotice(Craft.t('smart-search', msg)); },
        error: function (msg) { Craft.cp.displayError(msg); },
        t: function (category, str, params) { return Craft.t(category, str, params); }
    };
})();
