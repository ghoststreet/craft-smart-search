(function () {
    'use strict';

    var ns = window.SmartSearch;
    var DOM = ns.core.DOM;

    var STATUS_RESERVED = 2;
    var STATUS_FAILED = 4;

    var cardState = {};

    function parseSteps(label) {
        if (!label) return null;
        var m = label.match(/([\d,]+)\D+([\d,]+)/);
        if (!m) return null;
        return { step: parseInt(m[1].replace(/,/g, ''), 10), total: parseInt(m[2].replace(/,/g, ''), 10) };
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function getCardsBySite() {
        var map = {};
        var cards = document.querySelectorAll('[data-craftsearch-site-id]');
        Array.prototype.forEach.call(cards, function (card) {
            var sid = parseInt(card.getAttribute('data-craftsearch-site-id'), 10);
            if (!isNaN(sid)) map[sid] = card;
        });
        return map;
    }

    function ensureCardState(siteId, card) {
        if (cardState[siteId]) return cardState[siteId];
        var barContainer = DOM.find('site-progress-bar', card);
        var bar = barContainer ? new Craft.ProgressBar(barContainer, true, { announceProgress: false }) : null;
        if (bar) bar.showProgressBar();
        cardState[siteId] = {
            card: card,
            bar: bar,
            pane: DOM.find('site-progress', card),
            label: DOM.find('site-progress-label', card),
            errorEl: DOM.find('site-progress-error', card),
            button: DOM.findControl('sync-site-btn', card),
            wasActive: false,
        };
        return cardState[siteId];
    }

    function renderCardJob(state, job) {
        if (state.pane) state.pane.hidden = false;
        if (state.button) state.button.disabled = true;
        if (state.errorEl) { state.errorEl.hidden = true; state.errorEl.textContent = ''; }

        if (job.status === STATUS_FAILED) {
            if (state.errorEl) {
                state.errorEl.hidden = false;
                state.errorEl.textContent = job.error || 'Sync failed. Check the queue log.';
            }
            if (state.label) state.label.textContent = 'Failed';
            if (state.button) state.button.disabled = false;
            state.wasActive = false;
            return;
        }

        var running = job.status === STATUS_RESERVED;
        var steps = parseSteps(job.progressLabel);
        if (state.bar) {
            if (steps && steps.total > 0) {
                state.bar.setItemCount(steps.total);
                state.bar.setProcessedItemCount(steps.step);
                state.bar.updateProgressBar();
            } else {
                state.bar.setProgressPercentage(job.progress || 0);
            }
        }
        if (state.label) {
            state.label.textContent = running
                ? (job.progressLabel || 'Indexing…')
                : 'Queued — waiting for worker…';
        }
        state.wasActive = true;
    }

    function renderCardIdle(state, perSiteRow) {
        if (state.wasActive && state.pane) {
            state.pane.hidden = true;
            if (state.label) state.label.textContent = 'Synced.';
        }
        if (state.button) state.button.disabled = false;
        if (perSiteRow) {
            updateStat(state.card, 'chunks', perSiteRow.chunkCount.toLocaleString());
            updateStat(state.card, 'lastSync',
                perSiteRow.lastIndexed ? 'Last sync ' + formatDate(perSiteRow.lastIndexed) : 'Never synced');
        }
        state.wasActive = false;
    }

    function updateStat(card, key, value) {
        var el = card.querySelector('[data-craftsearch-stat="' + key + '"]');
        if (el) el.textContent = value;
    }

    function formatDate(iso) {
        try {
            var d = new Date(iso.replace(' ', 'T'));
            if (isNaN(d.getTime())) return iso;
            return d.toLocaleString();
        } catch (e) {
            return iso;
        }
    }

    function render(data) {
        var cards = getCardsBySite();
        var jobsBySite = {};
        (data.jobs || []).forEach(function (job) {
            if (job.siteId !== null && job.siteId !== undefined) jobsBySite[job.siteId] = job;
        });
        var perSiteBySite = {};
        (data.perSite || []).forEach(function (row) { perSiteBySite[row.siteId] = row; });

        var anyActive = false;
        Object.keys(cards).forEach(function (sid) {
            var card = cards[sid];
            var state = ensureCardState(sid, card);
            var job = jobsBySite[sid];
            if (job) {
                renderCardJob(state, job);
                if (job.status !== STATUS_FAILED) anyActive = true;
            } else {
                renderCardIdle(state, perSiteBySite[sid]);
            }
        });

        return anyActive || data.queueRemaining > 0;
    }

    function poll() {
        return Craft.sendActionRequest('POST', 'smart-search/index/get-stats').then(function (r) {
            if (!r.data || !r.data.success) return true;
            return render(r.data);
        }).catch(function () { return true; });
    }

    function startPolling() {
        poll().then(function (keepGoing) {
            if (keepGoing === false) return;
            var interval = setInterval(function () {
                poll().then(function (keep) {
                    if (keep === false) clearInterval(interval);
                });
            }, 2000);
        });
    }

    function pollReindexJob(jobId, button) {
        function tick() {
            Craft.sendActionRequest('GET', 'smart-search/index/job-status', { params: { id: jobId } })
                .then(function (r) {
                    var data = (r && r.data) || {};
                    if (data.done) {
                        enableReindexButton(button);
                        var row = button.closest('[data-craftsearch-entry-row]');
                        if (row) {
                            setRowStatus(row, 'green', Craft.t('smart-search', 'Indexed'));
                            refreshRowIndexState(row);
                        }
                        if (Craft.cp && Craft.cp.displayNotice) {
                            Craft.cp.displayNotice(Craft.t('smart-search', 'Re-index finished.'));
                        }
                        return;
                    }
                    if (Craft.cp && typeof Craft.cp.runQueue === 'function') {
                        Craft.cp.runQueue();
                    }
                    setTimeout(tick, 2000);
                })
                .catch(function (err) {
                    if (window.console) console.error('Re-index poll failed', err);
                    enableReindexButton(button);
                });
        }
        setTimeout(tick, 1500);
    }

    function disableReindexButton(button) {
        button.classList.add('disabled');
        button.setAttribute('aria-disabled', 'true');
        button.disabled = true;
    }

    function enableReindexButton(button) {
        button.classList.remove('disabled');
        button.removeAttribute('aria-disabled');
        button.disabled = false;
    }

    function setRowStatus(row, statusClass, label) {
        var cell = row.querySelector('[data-craftsearch-status-cell]');
        if (!cell) return;
        cell.innerHTML = '<span class="status ' + statusClass + '"></span> ' + escapeHtml(label);
    }

    function setRowChunks(row, count) {
        var cell = row.querySelector('[data-craftsearch-chunks-cell]');
        if (cell) cell.textContent = count;
    }

    function setRowDate(row, text) {
        var cell = row.querySelector('[data-craftsearch-date-cell]');
        if (!cell) return;
        if (text) {
            cell.textContent = text;
        } else {
            cell.innerHTML = '<span class="light">&mdash;</span>';
        }
    }

    function refreshRowIndexState(row) {
        if (!row) return;
        var payload = {
            elementId: parseInt(row.getAttribute('data-element-id'), 10),
            siteId: parseInt(row.getAttribute('data-site-id'), 10),
        };
        Craft.sendActionRequest('GET', 'smart-search/index/entry-state', { params: payload })
            .then(function (r) {
                var data = (r && r.data) || {};
                if (!data.success) return;
                setRowChunks(row, data.chunkCount);
                setRowDate(row, data.lastIndexed);
            })
            .catch(function () {});
    }

    function setRowExcluded(row, excluded) {
        var reindex = row.querySelector('form[data-craftsearch-reindex="entry"]');
        var exclude = row.querySelector('form[data-craftsearch-exclude="entry"]');
        var include = row.querySelector('form[data-craftsearch-include="entry"]');
        if (reindex) reindex.style.display = excluded ? 'none' : 'inline';
        if (exclude) exclude.style.display = excluded ? 'none' : 'inline';
        if (include) include.style.display = excluded ? 'inline' : 'none';
    }

    function bindToggleForms(selector, action, onSuccess) {
        var forms = document.querySelectorAll(selector);
        Array.prototype.forEach.call(forms, function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var button = form.querySelector('button[type="submit"]');
                if (button && button.classList.contains('disabled')) return;
                if (button) disableReindexButton(button);

                var row = form.closest('[data-craftsearch-entry-row]');
                var payload = {
                    elementId: parseInt(form.getAttribute('data-element-id'), 10),
                    siteId: parseInt(form.getAttribute('data-site-id'), 10),
                };

                Craft.sendActionRequest('POST', action, { data: payload })
                    .then(function (r) {
                        var data = (r && r.data) || {};
                        if (!data.success) {
                            if (window.console) console.error(action + ' returned unexpected payload', data);
                            if (button) enableReindexButton(button);
                            return;
                        }
                        onSuccess(row, data, button);
                    })
                    .catch(function (err) {
                        if (window.console) console.error(action + ' request failed', err);
                        if (button) enableReindexButton(button);
                    });
            });
        });
    }

    function bindExcludeForms() {
        bindToggleForms('form[data-craftsearch-exclude="entry"]', 'smart-search/index/exclude-entry',
            function (row, data, button) {
                if (button) enableReindexButton(button);
                if (row) {
                    setRowExcluded(row, true);
                    setRowStatus(row, 'grey', Craft.t('smart-search', 'Excluded'));
                    setRowChunks(row, 0);
                    setRowDate(row, null);
                }
                if (Craft.cp && Craft.cp.displayNotice) {
                    Craft.cp.displayNotice(Craft.t('smart-search', 'Entry excluded from index.'));
                }
            });
    }

    function bindIncludeForms() {
        bindToggleForms('form[data-craftsearch-include="entry"]', 'smart-search/index/include-entry',
            function (row, data, button) {
                if (button) enableReindexButton(button);
                if (row) {
                    setRowExcluded(row, false);
                    setRowStatus(row, 'off', Craft.t('smart-search', 'Not indexed'));
                    setRowChunks(row, 0);
                    setRowDate(row, null);
                }
                var reindexButton = row
                    ? row.querySelector('form[data-craftsearch-reindex="entry"] button[type="submit"]')
                    : null;
                if (reindexButton) disableReindexButton(reindexButton);
                if (data.jobId) {
                    if (Craft.cp && typeof Craft.cp.runQueue === 'function') {
                        Craft.cp.runQueue();
                    }
                    pollIncludeJob(data.jobId, row, reindexButton);
                }
            });
    }

    function pollIncludeJob(jobId, row, reindexButton) {
        function tick() {
            Craft.sendActionRequest('GET', 'smart-search/index/job-status', { params: { id: jobId } })
                .then(function (r) {
                    var data = (r && r.data) || {};
                    if (data.done) {
                        if (reindexButton) enableReindexButton(reindexButton);
                        if (row) {
                            setRowStatus(row, 'green', Craft.t('smart-search', 'Indexed'));
                            refreshRowIndexState(row);
                        }
                        return;
                    }
                    if (Craft.cp && typeof Craft.cp.runQueue === 'function') {
                        Craft.cp.runQueue();
                    }
                    setTimeout(tick, 2000);
                })
                .catch(function (err) {
                    if (window.console) console.error('Re-include poll failed', err);
                    if (reindexButton) enableReindexButton(reindexButton);
                });
        }
        setTimeout(tick, 1500);
    }

    function bindReindexForms() {
        var forms = document.querySelectorAll('form[data-craftsearch-reindex="entry"]');
        Array.prototype.forEach.call(forms, function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var button = form.querySelector('button[type="submit"]');
                if (!button || button.classList.contains('disabled')) return;

                disableReindexButton(button);

                var payload = {
                    elementId: parseInt(form.getAttribute('data-element-id'), 10),
                    siteId: parseInt(form.getAttribute('data-site-id'), 10),
                };

                Craft.sendActionRequest('POST', 'smart-search/index/reindex-entry', { data: payload })
                    .then(function (r) {
                        var data = (r && r.data) || {};
                        if (!data.success || !data.jobId) {
                            if (window.console) console.error('Re-index push returned unexpected payload', data);
                            enableReindexButton(button);
                            return;
                        }
                        if (Craft.cp && typeof Craft.cp.runQueue === 'function') {
                            Craft.cp.runQueue();
                        }
                        pollReindexJob(data.jobId, button);
                    })
                    .catch(function (err) {
                        if (window.console) console.error('Re-index request failed', err);
                        enableReindexButton(button);
                    });
            });
        });
    }

    ns.pages.indexMgmt = {
        init: function () {
            bindReindexForms();
            bindExcludeForms();
            bindIncludeForms();

            var grid = DOM.find('overview-grid');
            if (!grid) return;

            if (grid.getAttribute('data-craftsearch-sync-started') === '1') {
                startPolling();
                return;
            }

            poll().then(function (keepGoing) {
                if (keepGoing !== false) startPolling();
            });
        }
    };

    DOM.ready(ns.pages.indexMgmt.init);
})();
