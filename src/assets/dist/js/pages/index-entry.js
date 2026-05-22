(function () {
    'use strict';

    function init() {
        var filters = document.querySelector('[data-field-filters]');
        var list = document.querySelector('[data-field-card-list]');
        if (!filters || !list) return;

        filters.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-filter]');
            if (!btn) return;
            filters.querySelectorAll('button[data-filter]').forEach(function (b) {
                b.classList.toggle('is-active', b === btn);
            });
            list.setAttribute('data-filter-mode', btn.dataset.filter);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
