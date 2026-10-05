(function () {
    'use strict';

    var DOM = window.SmartSearch.core.DOM;

    var STATUS_RESERVED = 2;
    var STATUS_FAILED = 4;
    var POLL_MS = 1500;
    var MAX_POLL_FAILURES = 5;
    var SYNC_ACTION = 'smart-search/index/sync';
    var CANCEL_ACTION = 'smart-search/index/cancel-sync';
    var STATS_ACTION = 'smart-search/index/get-stats';

    function setText(el, text) { if (el) el.textContent = text; }

    function SyncBar(root) {
        this.siteId = parseInt(root.getAttribute('data-smart-search-site-id'), 10);
        this.button = DOM.findControl('sync-site-btn', root);
        this.label = DOM.find('sync-label', root);
        this.caption = DOM.find('sync-caption', root);
        this.progress = DOM.find('site-progress', root);
        this.fill = DOM.find('site-progress-fill', root);
        this.running = root.getAttribute('data-smart-search-state') === 'indexing';
        this.failures = 0;

        var self = this;
        this.button.form.addEventListener('submit', function (e) {
            e.preventDefault();
            self.submit();
        });
        if (this.running) this.poll();
    }

    SyncBar.prototype.submit = function () {
        var self = this;
        var action = this.running ? CANCEL_ACTION : SYNC_ACTION;
        DOM.setBusy(this.button, true);
        Craft.sendActionRequest('POST', action, { data: { siteId: this.siteId } })
            .then(function () {
                if (action === SYNC_ACTION) Craft.cp.runQueue();
                self.poll();
            })
            .catch(function (err) {
                var data = err.response && err.response.data;
                Craft.cp.displayError((data && data.error) || Craft.t('smart-search', 'Failed to start sync.'));
                DOM.setBusy(self.button, false);
            });
    };

    SyncBar.prototype.schedule = function () {
        setTimeout(this.poll.bind(this), POLL_MS);
    };

    SyncBar.prototype.poll = function () {
        var self = this;
        Craft.sendActionRequest('POST', STATS_ACTION)
            .then(function (r) {
                self.failures = 0;
                var job = r.data.jobs.filter(function (j) { return j.siteId === self.siteId; })[0];
                if (job) {
                    self.render(job);
                    if (job.status !== STATUS_FAILED) self.schedule();
                } else if (r.data.queueRemaining > 0) {
                    self.schedule();
                } else {
                    window.location.reload();
                }
            })
            .catch(function () {
                if (++self.failures < MAX_POLL_FAILURES) {
                    self.schedule();
                } else {
                    Craft.cp.displayError(Craft.t('smart-search', 'Lost track of the sync. Reload the page to see its progress.'));
                }
            });
    };

    SyncBar.prototype.render = function (job) {
        var failed = job.status === STATUS_FAILED;
        var working = job.status === STATUS_RESERVED;

        this.running = !failed;
        if (this.progress) this.progress.hidden = failed;
        if (this.fill) this.fill.style.width = job.progress + '%';
        if (failed) Craft.cp.displayError(job.error || Craft.t('smart-search', 'Sync failed. Check the queue log.'));

        setText(this.label, job.progressLabel || job.progress + '%');
        setText(this.caption, failed ? 'Sync failed' : working ? 'Indexing' : 'Queued');

        DOM.setBusy(this.button, false);
        setText(this.button, failed ? 'Retry' : 'Cancel');
    };

    DOM.ready(function () {
        DOM.findAll('sync-bar').forEach(function (root) { new SyncBar(root); });
    });
})();
