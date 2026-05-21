(function () {
    'use strict';
    var ns = window.SmartSearch;

    // Opens a Garnish modal showing the full error message for a search-log row.
    // Triggered by clicking any [data-error-modal] element (the Error badge).
    ns.components.ErrorModal = {
        init: function () {
            document.addEventListener('click', function (e) {
                var trigger = e.target.closest('[data-error-modal]');
                if (!trigger) {
                    return;
                }
                e.preventDefault();
                ns.components.ErrorModal.show(trigger.getAttribute('data-error-message'));
            });
        },

        show: function (message) {
            var $modal = $(
                '<div class="modal ss-error-modal">' +
                    '<div class="body">' +
                        '<h2>' + Craft.t('app', 'Search error') + '</h2>' +
                        '<pre class="ss-error-modal__message"></pre>' +
                    '</div>' +
                    '<div class="footer">' +
                        '<button type="button" class="btn submit">' + Craft.t('app', 'Close') + '</button>' +
                    '</div>' +
                '</div>'
            ).appendTo(Garnish.$bod);

            $modal.find('.ss-error-modal__message').text(message || 'No error message recorded.');

            var modal = new Garnish.Modal($modal, { resizable: false });
            $modal.find('.btn').on('click', function () {
                modal.hide();
            });
            modal.on('hide', function () {
                $modal.remove();
            });
        }
    };

    ns.core.DOM.ready(ns.components.ErrorModal.init);
})();
