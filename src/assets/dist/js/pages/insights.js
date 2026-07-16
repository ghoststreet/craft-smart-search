(function () {
    'use strict';
    var ns = window.SmartSearch;

    function init() {
        if (typeof Chart !== 'undefined' && ns.components.Chart) {
            ns.core.ChartTheme.applyChartDefaults();
            ns.components.Chart.buildAll();
        }
    }

    ns.core.DOM.ready(init);
})();
