(function () {
    'use strict';

    function cssVar(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (v && v.trim()) || fallback;
    }

    function palette() {
        return {
            text: cssVar('--text-color', '#1f2933'),
            muted: cssVar('--medium-text-color', '#606d7b'),
            grid: 'rgba(127, 140, 153, 0.18)',
            primary: cssVar('--link-color', '#0c66c2'),
            primarySoft: 'rgba(12, 102, 194, 0.18)',
            success: '#29a847',
            warn: '#d48806',
            danger: '#cf1322',
            stale: '#d48806',
            unindexed: cssVar('--gray-300', '#9aa5b1')
        };
    }

    function applyChartDefaults() {
        if (typeof Chart === 'undefined') return;
        Chart.defaults.font.family = cssVar('--font-base', "system-ui, -apple-system, 'Segoe UI', sans-serif");
        Chart.defaults.font.size = 11;
        Chart.defaults.color = palette().muted;
    }

    window.SmartSearch.core.ChartTheme = {
        palette: palette,
        applyChartDefaults: applyChartDefaults
    };
})();
