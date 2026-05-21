(function () {
    'use strict';
    var ns = window.SmartSearch;

    // A control counts as "active" when it holds a non-default value.
    function isActive(el) {
        return el.type === 'checkbox' ? el.checked : (el.value !== '' && el.value != null);
    }

    // Shows the Reset link whenever any filter control is active, and hides it
    // again once every control is back to its default. Changing a control never
    // submits the form — only the Filter button does that.
    ns.components.FilterBar = {
        init: function () {
            var forms = document.querySelectorAll('.filter-bar__form');
            Array.prototype.forEach.call(forms, function (form) {
                var reset = form.querySelector('.filter-bar__reset');
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
