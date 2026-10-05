(function () {
    'use strict';
    var ns = window.SmartSearch;
    var Theme = ns.core.ChartTheme;

    function coverageParts(p) {
        return [
            { key: 'indexed', label: 'Indexed', color: p.indexed },
            { key: 'stale', label: 'Stale', color: p.stale },
            { key: 'notIndexed', label: 'Not indexed', color: p.unindexed }
        ];
    }

    function sparklineConfig(series) {
        var p = Theme.palette();
        return {
            type: 'line',
            data: {
                labels: series.map(function (r) { return r.date; }),
                datasets: [{
                    data: series.map(function (r) { return r.value; }),
                    borderColor: p.primary,
                    backgroundColor: p.primary,
                    borderWidth: 1.5,
                    pointRadius: 0,
                    tension: 0.3
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { intersect: false, mode: 'index' } },
                scales: { x: { display: false }, y: { display: false, beginAtZero: true } }
            }
        };
    }

    function areaConfig(series) {
        var cfg = sparklineConfig(series);
        cfg.data.datasets[0].fill = true;
        cfg.data.datasets[0].backgroundColor = Theme.palette().primarySoft;
        return cfg;
    }

    function horizontalStackedBarConfig(data) {
        var p = Theme.palette();
        return {
            type: 'bar',
            data: {
                labels: data.map(function (r) { return r.site; }),
                datasets: coverageParts(p).map(function (part) {
                    return {
                        label: part.label,
                        data: data.map(function (r) { return r[part.key]; }),
                        backgroundColor: part.color
                    };
                })
            },
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', align: 'end', labels: { boxWidth: 10, boxHeight: 10 } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var row = data[ctx.dataIndex];
                                var total = row.indexed + row.stale + row.notIndexed;
                                var v = ctx.parsed.x;
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
        'horizontal-stacked-bar': horizontalStackedBarConfig
    };

    function build(canvas) {
        var builder = BUILDERS[canvas.getAttribute('data-smart-search-chart')];
        var series = ns.core.Utils.parseJSON(canvas.getAttribute('data-smart-search-series'), null);
        if (!builder || series == null) return;
        new Chart(canvas.getContext('2d'), builder(series));
    }

    ns.core.DOM.ready(function () {
        Theme.applyChartDefaults();
        ns.core.DOM.findAll('chart-canvas').forEach(build);
    });
})();
