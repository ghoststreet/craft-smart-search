(function () {
    'use strict';
    var DOM = window.SmartSearch.core.DOM;

    function isActive(el) {
        if (el.type === 'checkbox') return el.checked;
        return el.value !== (el.dataset.smartSearchDefault || '');
    }

    DOM.ready(function () {
        DOM.findAllControls('report-select').forEach(function (select) {
            select.addEventListener('change', function () {
                var url = select.options[select.selectedIndex].dataset.url;
                if (url) window.location.href = url;
            });
        });

        DOM.findAll('filter-bar-form').forEach(function (form) {
            var reset = DOM.find('filter-bar-reset', form);
            var controls = Array.from(form.elements).filter(function (el) {
                return el.tagName === 'SELECT' || el.type === 'checkbox';
            });
            var sync = function () { reset.hidden = !controls.some(isActive); };
            form.addEventListener('change', sync);
            sync();
        });
    });
})();
