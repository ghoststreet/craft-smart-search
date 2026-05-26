(function () {
    'use strict';
    var ATTR_TARGET = 'data-craftsearch-target';
    var ATTR_CONTROL = 'data-craftsearch-control';
    var ATTR_STATE = 'data-craftsearch-state';

    function targetSel(name) { return '[' + ATTR_TARGET + '="' + name + '"]'; }
    function controlSel(name) { return '[' + ATTR_CONTROL + '="' + name + '"]'; }

    window.SmartSearch.core.DOM = {
        find: function (name, root) {
            return (root || document).querySelector(targetSel(name));
        },
        findControl: function (name, root) {
            return (root || document).querySelector(controlSel(name));
        },
        findAll: function (name, root) {
            return Array.from((root || document).querySelectorAll(targetSel(name)));
        },
        findAllControls: function (name, root) {
            return Array.from((root || document).querySelectorAll(controlSel(name)));
        },
        setState: function (el, value) {
            if (!el) return;
            if (value === '' || value == null) el.removeAttribute(ATTR_STATE);
            else el.setAttribute(ATTR_STATE, value);
        },
        getState: function (el) {
            return el ? el.getAttribute(ATTR_STATE) : null;
        },
        onDelegate: function (root, controlName, event, handler) {
            if (!root) return;
            root.addEventListener(event, function (e) {
                if (!(e.target instanceof Element)) return;
                var match = e.target.closest(controlSel(controlName));
                if (!match || !root.contains(match)) return;
                handler(e, match);
            });
        },
        ready: function (fn) {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fn);
            } else {
                fn();
            }
        }
    };
})();
