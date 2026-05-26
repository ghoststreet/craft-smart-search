(function () {
    'use strict';

    function init() {
        var DOM = window.SmartSearch.core.DOM;
        var filters = DOM.find('field-filters');
        var list = DOM.find('field-card-list');
        if (!filters || !list) return;

        filters.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-craftsearch-control="field-filter-button"]');
            if (!btn) return;
            filters.querySelectorAll('[data-craftsearch-control="field-filter-button"]').forEach(function (b) {
                b.classList.toggle('is-active', b === btn);
            });
            list.setAttribute('data-filter-mode', btn.getAttribute('data-filter-value'));
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
