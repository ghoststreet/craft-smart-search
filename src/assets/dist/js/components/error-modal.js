(function () {
    'use strict';
    var ns = window.SmartSearch;
    var DOM = ns.core.DOM;

    function open(message) {
        var $modal = $(
            '<div class="modal ss-error-modal">' +
                '<div class="body">' +
                    '<h2 data-craftsearch-target="error-modal-title"></h2>' +
                    '<pre class="ss-error-modal__message" data-craftsearch-target="error-modal-message"></pre>' +
                '</div>' +
                '<div class="footer">' +
                    '<button type="button" class="btn submit" data-craftsearch-control="error-modal-close"></button>' +
                '</div>' +
            '</div>'
        ).appendTo(Garnish.$bod);

        var modalEl = $modal[0];
        DOM.find('error-modal-title', modalEl).textContent = Craft.t('smart-search', 'Search error');
        DOM.find('error-modal-message', modalEl).textContent = message || 'No error message recorded.';
        var closeBtn = DOM.findControl('error-modal-close', modalEl);
        closeBtn.textContent = Craft.t('smart-search', 'Close');

        var modal = new Garnish.Modal($modal, { resizable: false });
        closeBtn.addEventListener('click', function () { modal.hide(); });
        modal.on('hide', function () { $modal.remove(); });
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
