(function () {
    'use strict';
    var ns = window.SmartSearch;
    var DOM = ns.core.DOM;

    function isActive(el) {
        return el.type === 'checkbox' ? el.checked : (el.value !== '' && el.value != null);
    }

    ns.components.FilterBar = {
        init: function () {
            DOM.findAll('filter-bar-form').forEach(function (form) {
                var reset = DOM.find('filter-bar-reset', form);
                if (!reset) return;
                var controls = Array.from(form.querySelectorAll('select, input[type="checkbox"]'));
                var sync = function () { reset.hidden = !controls.some(isActive); };
                form.addEventListener('change', sync);
                sync();
            });
        }
    };

    DOM.ready(ns.components.FilterBar.init);
})();
