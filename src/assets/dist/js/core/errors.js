(function () {
    'use strict';
    var ns = window.SmartSearch;

    ns.core.errors = {
        messageFor: function (err) {
            return (err && err.message) ||
                Craft.t('smart-search', 'Something went wrong. The administrator can find details in the Smart Search log.');
        }
    };
})();
