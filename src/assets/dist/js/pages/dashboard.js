(function () {
    'use strict';
    var ns = window.SmartSearch;
    var DOM = ns.core.DOM;

    function bindDismissGuide() {
        var btn = DOM.findControl('dismiss-guide');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var card = btn.closest('[data-craftsearch-guide]');
            if (card) card.remove();
            Craft.postActionRequest('smart-search/dashboard/dismiss-guide');
        });
    }

    function init() {
        bindDismissGuide();
        if (typeof Chart === 'undefined') return;
        ns.core.ChartTheme.applyChartDefaults();
        ns.components.Chart.buildAll();
    }

    DOM.ready(init);
})();
