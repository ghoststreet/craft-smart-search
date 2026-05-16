(function () {
    'use strict';

    var ns = window.CraftSearch;
    var DOM = ns.core.DOM;

    var STATUS_WAITING = 1;
    var STATUS_RESERVED = 2;
    var STATUS_FAILED = 4;

    var progressBar = null;
    var cancelling = false;

    function ensureBar() {
        if (progressBar) return progressBar;
        var container = DOM.find('progress-bar-container');
        if (!container) return null;
        progressBar = new Craft.ProgressBar(container, true, { announceProgress: false });
        progressBar.showProgressBar();
        return progressBar;
    }

    function parseSteps(label) {
        if (!label) return null;
        var m = label.match(/([\d,]+)\D+([\d,]+)/);
        if (!m) return null;
        return { step: parseInt(m[1].replace(/,/g, ''), 10), total: parseInt(m[2].replace(/,/g, ''), 10) };
    }

    function showTerminal(html) {
        var pane = DOM.find('reindex-progress');
        if (!pane) return;
        pane.innerHTML = '<div class="pane">' + html + '</div>';
        var btn = DOM.findControl('reindex-btn');
        if (btn) btn.disabled = false;
        progressBar = null;
    }

    function render(data) {
        var statEntries = DOM.find('stat-entries');
        var statChunks = DOM.find('stat-chunks');
        if (statEntries) statEntries.textContent = data.entryCount.toLocaleString();
        if (statChunks) statChunks.textContent = data.chunkCount.toLocaleString();

        var pane = DOM.find('reindex-progress');
        var title = DOM.find('progress-title');
        var label = DOM.find('progress-label');
        var errorEl = DOM.find('progress-error');
        var startBtn = DOM.findControl('reindex-btn');
        var cancelBtn = DOM.findControl('cancel-btn');

        if (data.sync && data.sync.status === STATUS_FAILED) {
            var msg = data.sync.error || 'Sync job failed. Check the queue log for details.';
            showTerminal('<p><strong>Sync failed.</strong></p><p class="error">' + escapeHtml(msg) + '</p>');
            return false;
        }

        if (!data.sync && data.queueRemaining === 0) {
            if (pane && !pane.hidden) {
                var doneMsg = cancelling ? '<p><strong>Sync cancelled.</strong></p>' : '<p><strong>Sync complete.</strong></p>';
                showTerminal(doneMsg);
            }
            cancelling = false;
            return false;
        }

        if (pane) pane.hidden = false;
        if (startBtn) startBtn.disabled = true;
        if (cancelBtn) cancelBtn.disabled = cancelling;
        if (errorEl) { errorEl.hidden = true; errorEl.textContent = ''; }
        var bar = ensureBar();

        if (data.sync) {
            var running = data.sync.status === STATUS_RESERVED;
            if (title) {
                title.textContent = cancelling
                    ? 'Cancelling…'
                    : (running ? (data.sync.description || 'Syncing AI search index') : 'Sync queued — waiting for worker…');
            }
            var steps = parseSteps(data.sync.progressLabel);
            if (bar) {
                if (steps && steps.total > 0) {
                    bar.setItemCount(steps.total);
                    bar.setProcessedItemCount(steps.step);
                    bar.updateProgressBar();
                } else {
                    bar.setProgressPercentage(data.sync.progress || 0);
                }
            }
            if (label) {
                label.textContent = running
                    ? (data.sync.progressLabel || 'Starting…')
                    : 'Waiting for worker…';
            }
        } else {
            if (title) title.textContent = 'Other queue jobs ahead of sync…';
            if (bar) bar.setProgressPercentage(0);
            if (label) label.textContent = data.queueRemaining + ' jobs waiting';
        }
        return true;
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function poll() {
        return Craft.sendActionRequest('POST', 'ai-search/index/get-stats').then(function (r) {
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

    function bindCancel() {
        var btn = DOM.findControl('cancel-btn');
        if (!btn) return;
        btn.addEventListener('click', function () {
            if (cancelling) return;
            if (!confirm('Cancel the current sync? The running batch will exit at the next entry and no further work will be queued.')) return;
            cancelling = true;
            btn.disabled = true;
            Craft.sendActionRequest('POST', 'ai-search/index/cancel-sync').then(function () {
                poll();
            }).catch(function () {
                cancelling = false;
                btn.disabled = false;
            });
        });
    }

    function pollReindexJob(jobId, button) {
        function tick() {
            Craft.sendActionRequest('GET', 'ai-search/index/job-status', { params: { id: jobId } })
                .then(function (r) {
                    var data = (r && r.data) || {};
                    if (data.done) {
                        enableReindexButton(button);
                        if (Craft.cp && Craft.cp.displayNotice) {
                            Craft.cp.displayNotice(Craft.t('ai-search', 'Re-index finished.'));
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

                Craft.sendActionRequest('POST', 'ai-search/index/reindex-entry', { data: payload })
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

            var pane = DOM.find('reindex-progress');
            if (!pane) return;

            bindCancel();

            if (pane.getAttribute('data-craftsearch-sync-started') === '1') {
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
