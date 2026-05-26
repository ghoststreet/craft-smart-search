(function () {
    'use strict';
    var ns = window.SmartSearch;
    var DOM = ns.core.DOM;
    var craft = ns.core.craft;

    var current = null;

    function close() {
        if (!current) return;
        document.removeEventListener('keydown', onKey);
        current.remove();
        current = null;
    }

    function onKey(e) {
        if (e.key === 'Escape') close();
    }

    function open(message) {
        close();

        var backdrop = document.createElement('div');
        backdrop.className = 'modal-shade';
        backdrop.setAttribute('data-craftsearch-target', 'error-modal-backdrop');

        var modal = document.createElement('div');
        modal.className = 'modal ss-error-modal';
        modal.setAttribute('data-craftsearch-target', 'error-modal');
        modal.innerHTML =
            '<div class="body">' +
                '<h2></h2>' +
                '<pre data-craftsearch-target="error-modal-message"></pre>' +
            '</div>' +
            '<div class="footer">' +
                '<button type="button" class="btn submit" data-craftsearch-control="error-modal-close"></button>' +
            '</div>';

        DOM.find('error-modal-message', modal).textContent = message || 'No error message recorded.';
        modal.querySelector('h2').textContent = craft.t('smart-search', 'Search error');
        DOM.findControl('error-modal-close', modal).textContent = craft.t('smart-search', 'Close');

        document.body.appendChild(backdrop);
        document.body.appendChild(modal);
        current = { remove: function () { modal.remove(); backdrop.remove(); } };

        DOM.findControl('error-modal-close', modal).addEventListener('click', close);
        backdrop.addEventListener('click', close);
        document.addEventListener('keydown', onKey);
    }

    ns.components.ErrorModal = {
        init: function () {
            DOM.onDelegate(document, 'error-modal-trigger', 'click', function (e, trigger) {
                e.preventDefault();
                open(trigger.getAttribute('data-craftsearch-error-message'));
            });
        },
        show: open
    };

    DOM.ready(ns.components.ErrorModal.init);
})();
