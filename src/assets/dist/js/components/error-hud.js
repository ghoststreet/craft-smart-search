(function () {
    'use strict';
    var DOM = window.SmartSearch.core.DOM;

    function open(trigger) {
        var message = document.createElement('p');
        message.className = 'ss-error-message';
        message.textContent = trigger.getAttribute('data-smart-search-error-message') ||
            Craft.t('smart-search', 'No error message recorded.');

        var hud = new Garnish.HUD(trigger, message, {
            onHide: function () { hud.destroy(); }
        });
    }

    DOM.ready(function () {
        DOM.onDelegate(document, 'error-hud-trigger', 'click', function (e, trigger) {
            e.preventDefault();
            open(trigger);
        });
    });
})();
