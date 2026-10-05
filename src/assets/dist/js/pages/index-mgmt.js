(function () {
    'use strict';

    var ns = window.SmartSearch;
    var DOM = ns.core.DOM;
    var escapeHtml = Craft.escapeHtml;

    var POLL_MS = 1500;
    var statuses = {};

    function setRowStatus(row, key) {
        var status = statuses[key];
        DOM.find('status-cell', row).innerHTML = '<span class="status ' + status.color + '"></span> ' + escapeHtml(status.label);
    }

    function setRowChunks(row, count) {
        DOM.find('chunks-cell', row).textContent = count;
    }

    function setRowDate(row, text) {
        var cell = DOM.find('date-cell', row);
        if (text) cell.textContent = text;
        else cell.innerHTML = '<span class="light">-</span>';
    }

    function setRowExcluded(row, excluded) {
        DOM.findControl('reindex-form', row).hidden = excluded;
        DOM.findControl('exclude-form', row).hidden = excluded;
        DOM.findControl('include-form', row).hidden = !excluded;
    }

    function rowIds(row) {
        return {
            elementId: parseInt(row.getAttribute('data-element-id'), 10),
            siteId: parseInt(row.getAttribute('data-site-id'), 10),
        };
    }

    function refreshRowIndexState(row) {
        Craft.sendActionRequest('GET', 'smart-search/index/entry-state', { params: rowIds(row) })
            .then(function (r) {
                setRowChunks(row, r.data.chunkCount);
                setRowDate(row, r.data.lastIndexed);
            })
            .catch(function (err) { console.error('Entry state refresh failed', err); });
    }

    function pollEntryJob(jobId, button, row, completionMsg) {
        function tick() {
            Craft.sendActionRequest('GET', 'smart-search/index/job-status', { params: { id: jobId } })
                .then(function (r) {
                    if (!r.data.done) {
                        setTimeout(tick, POLL_MS);
                        return;
                    }
                    DOM.setBusy(button, false);
                    setRowStatus(row, 'indexed');
                    refreshRowIndexState(row);
                    if (completionMsg) Craft.cp.displayNotice(completionMsg);
                })
                .catch(function (err) {
                    console.error('Entry job poll failed', err);
                    DOM.setBusy(button, false);
                });
        }
        setTimeout(tick, POLL_MS);
    }

    function bindEntryForm(controlName, action, onSuccess) {
        DOM.onDelegate(document, controlName, 'submit', function (e, form) {
            e.preventDefault();
            var button = DOM.find('entry-action-button', form);
            if (button.disabled) return;
            DOM.setBusy(button, true);

            var row = form.closest('[data-smart-search-entry-row]');
            Craft.sendActionRequest('POST', action, { data: rowIds(row) })
                .then(function (r) { onSuccess(row, r.data, button); })
                .catch(function (err) {
                    console.error(action + ' request failed', err);
                    DOM.setBusy(button, false);
                });
        });
    }

    function init() {
        statuses = ns.core.Utils.parseJSON(DOM.find('entries-table').getAttribute('data-smart-search-statuses'), {});

        bindEntryForm('reindex-form', 'smart-search/index/reindex-entry',
            function (row, data, button) {
                if (!data.jobId) { DOM.setBusy(button, false); return; }
                Craft.cp.runQueue();
                pollEntryJob(data.jobId, button, row, Craft.t('smart-search', 'Re-index finished.'));
            });
        bindEntryForm('exclude-form', 'smart-search/index/exclude-entry',
            function (row, data, button) {
                DOM.setBusy(button, false);
                setRowExcluded(row, true);
                setRowStatus(row, 'excluded');
                setRowChunks(row, 0);
                setRowDate(row, null);
                Craft.cp.displayNotice(Craft.t('smart-search', 'Entry excluded from index.'));
            });
        bindEntryForm('include-form', 'smart-search/index/include-entry',
            function (row, data, button) {
                DOM.setBusy(button, false);
                setRowExcluded(row, false);
                setRowStatus(row, 'not-indexed');
                setRowChunks(row, 0);
                setRowDate(row, null);
                if (!data.jobId) return;
                var reindexButton = DOM.find('entry-action-button', DOM.findControl('reindex-form', row));
                DOM.setBusy(reindexButton, true);
                Craft.cp.runQueue();
                pollEntryJob(data.jobId, reindexButton, row);
            });
    }

    DOM.ready(init);
})();
