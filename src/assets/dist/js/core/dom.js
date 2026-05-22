(function () {
    'use strict';
    var ATTR_TARGET = 'data-craftsearch-target';
    var ATTR_CONTROL = 'data-craftsearch-control';

    function selector(attr, name) {
        return '[' + attr + '="' + name + '"]';
    }

    window.SmartSearch.core.DOM = {
        find: function (name, root) {
            return (root || document).querySelector(selector(ATTR_TARGET, name));
        },
        findControl: function (name, root) {
            return (root || document).querySelector(selector(ATTR_CONTROL, name));
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
