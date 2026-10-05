(function () {
    'use strict';
    var DOM = window.SmartSearch.core.DOM;

    function bindDismissGuide() {
        var btn = DOM.findControl('dismiss-guide');
        if (!btn) return;
        btn.addEventListener('click', function () {
            btn.closest('[data-smart-search-guide]').remove();
            Craft.sendActionRequest('POST', 'smart-search/dashboard/dismiss-guide');
        });
    }

    DOM.ready(bindDismissGuide);
})();
