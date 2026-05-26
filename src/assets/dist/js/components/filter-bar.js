(function () {
    'use strict';
    var ns = window.SmartSearch;
    var DOM = ns.core.DOM;

    // A control counts as "active" when it holds a non-default value.
    function isActive(el) {
        return el.type === 'checkbox' ? el.checked : (el.value !== '' && el.value != null);
    }

    // Shows the Reset link whenever any filter control is active, and hides it
    // again once every control is back to its default. Changing a control never
    // submits the form — only the Filter button does that.
    ns.components.FilterBar = {
        init: function () {
            var forms = document.querySelectorAll('[data-craftsearch-target="filter-bar-form"]');
            Array.prototype.forEach.call(forms, function (form) {
                var reset = DOM.find('filter-bar-reset', form);
                if (!reset) {
                    return;
                }
                var controls = form.querySelectorAll('select, input[type="checkbox"]');
                var sync = function () {
                    reset.hidden = !Array.prototype.some.call(controls, isActive);
                };
                form.addEventListener('change', sync);
                sync();
            });
        }
    };

    ns.core.DOM.ready(ns.components.FilterBar.init);
})();
