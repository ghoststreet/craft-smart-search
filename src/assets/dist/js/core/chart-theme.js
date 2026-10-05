(function () {
    'use strict';

    function cssVar(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    function palette() {
        return {
            muted: cssVar('--medium-text-color'),
            grid: cssVar('--gray-100'),
            primary: cssVar('--link-color'),
            primarySoft: cssVar('--blue-100'),
            indexed: cssVar('--green-600'),
            stale: cssVar('--bg-pending'),
            unindexed: cssVar('--gray-300')
        };
    }

    function applyChartDefaults() {
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.font.size = 11;
        Chart.defaults.color = palette().muted;
    }

    window.SmartSearch.core.ChartTheme = {
        palette: palette,
        applyChartDefaults: applyChartDefaults
    };
})();
