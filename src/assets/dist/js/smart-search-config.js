(function () {
    'use strict';
    var ns = window.SmartSearch;
    var root = document.querySelector('[data-craftsearch-config]');
    var parsed = ns.core.Utils.parseJSON(root && root.getAttribute('data-craftsearch-config'), {});
    ns.config = Object.assign({
        csrfTokenName: Craft.csrfTokenName || null,
        csrfTokenValue: Craft.csrfTokenValue || null,
        actionUrl: Craft.getActionUrl ? Craft.getActionUrl.bind(Craft) : null
    }, parsed);
})();
