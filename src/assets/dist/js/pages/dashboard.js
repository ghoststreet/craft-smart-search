(function () {
    'use strict';
    var ns = window.SmartSearch;

    function bindDismissGuide() {
        var btn = document.querySelector('[data-craftsearch-control="dismiss-guide"]');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var card = btn.closest('[data-craftsearch-guide]');
            if (card) card.parentNode.removeChild(card);
            if (!window.Craft || typeof Craft.postActionRequest !== 'function') return;
            Craft.postActionRequest('smart-search/dashboard/dismiss-guide');
        });
    }

    ns.pages.dashboard = {
        init: function () {
            bindDismissGuide();
            if (typeof Chart === 'undefined') return;
            ns.core.ChartTheme.applyChartDefaults();
            ns.components.Chart.buildAll();
        }
    };

    ns.core.DOM.ready(ns.pages.dashboard.init);
})();
