(function () {
    'use strict';
    var ns = window.SmartSearch;
    var Theme = ns.core.ChartTheme;

    function sparklineConfig(series, color) {
        var p = Theme.palette();
        var c = color || p.primary;
        return {
            type: 'line',
            data: {
                labels: series.map(function (r) { return r.date; }),
                datasets: [{
                    data: series.map(function (r) { return r.value; }),
                    borderColor: c,
                    backgroundColor: c,
                    borderWidth: 1.5,
                    pointRadius: 0,
                    tension: 0.3,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { enabled: true, intersect: false, mode: 'index' } },
                scales: { x: { display: false }, y: { display: false, beginAtZero: true } }
            }
        };
    }

    function areaConfig(series) {
        var p = Theme.palette();
        var cfg = sparklineConfig(series, p.primary);
        cfg.data.datasets[0].fill = true;
        cfg.data.datasets[0].backgroundColor = p.primarySoft;
        return cfg;
    }

    function donutConfig(parts) {
        var p = Theme.palette();
        return {
            type: 'doughnut',
            data: {
                labels: ['Indexed', 'Stale', 'Not indexed'],
                datasets: [{
                    data: [parts.indexed, parts.stale, parts.notIndexed],
                    backgroundColor: [p.success, p.stale, p.unindexed],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: { legend: { display: false }, tooltip: { enabled: true } }
            }
        };
    }

    function horizontalStackedBarConfig(data) {
        var p = Theme.palette();
        return {
            type: 'bar',
            data: {
                labels: data.map(function (r) { return r.site; }),
                datasets: [
                    { label: 'Indexed', data: data.map(function (r) { return r.indexed; }), backgroundColor: p.success, borderWidth: 0 },
                    { label: 'Stale', data: data.map(function (r) { return r.stale; }), backgroundColor: p.stale, borderWidth: 0 },
                    { label: 'Not indexed', data: data.map(function (r) { return r.notIndexed; }), backgroundColor: p.unindexed, borderWidth: 0 }
                ]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', align: 'end', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: false } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var row = data[ctx.dataIndex] || {};
                                var total = (row.indexed || 0) + (row.stale || 0) + (row.notIndexed || 0);
                                var v = ctx.parsed.x || 0;
                                var pct = total > 0 ? Math.round((v / total) * 100) : 0;
                                return ctx.dataset.label + ': ' + v + ' (' + pct + '%)';
                            }
                        }
                    }
                },
                scales: {
                    x: { stacked: true, grid: { color: p.grid }, beginAtZero: true, ticks: { precision: 0 } },
                    y: { stacked: true, grid: { display: false } }
                }
            }
        };
    }

    var BUILDERS = {
        'sparkline': sparklineConfig,
        'area': areaConfig,
        'donut': donutConfig,
        'horizontal-stacked-bar': horizontalStackedBarConfig
    };

    ns.components.Chart = {
        build: function (canvas) {
            if (typeof Chart === 'undefined') return null;
            var kind = canvas.getAttribute('data-craftsearch-chart');
            var builder = BUILDERS[kind];
            if (!builder) return null;
            var series = ns.core.Utils.parseJSON(canvas.getAttribute('data-craftsearch-series'), null);
            if (series == null) return null;
            return new Chart(canvas.getContext('2d'), builder(series));
        },
        buildAll: function (root) {
            return ns.core.DOM.findAll('chart-canvas', root).map(this.build, this);
        }
    };

    ns.core.DOM.ready(function () {
        Theme.applyChartDefaults();
        ns.components.Chart.buildAll();
    });
})();
