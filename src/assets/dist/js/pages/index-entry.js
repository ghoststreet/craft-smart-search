(function () {
    'use strict';
    var DOM = window.SmartSearch.core.DOM;

    function init() {
        var filters = DOM.find('field-filters');
        var list = DOM.find('field-card-list');
        if (!filters || !list) return;

        DOM.onDelegate(filters, 'field-filter-button', 'click', function (e, btn) {
            DOM.findAllControls('field-filter-button', filters).forEach(function (b) {
                DOM.setState(b, b === btn ? 'active' : '');
            });
            list.setAttribute('data-filter-mode', btn.getAttribute('data-filter-value'));
        });
    }

    DOM.ready(init);
})();
