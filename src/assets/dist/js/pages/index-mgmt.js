(function () {
    'use strict';

    var ns = window.SmartSearch;
    var DOM = ns.core.DOM;
    var escapeHtml = Craft.escapeHtml;

    function setText(el, text) { if (el) el.textContent = text; }
    function setHidden(el, hidden) { if (el) el.hidden = !!hidden; }

    var STATUS_RESERVED = 2;
    var STATUS_FAILED = 4;
    var POLL_ACTIVE_MS = 1000;
    var ENTRY_POLL_INITIAL_MS = 1500;
    var ENTRY_POLL_MS = 2000;
    var SYNC_ACTION = 'smart-search/index/sync';
    var CANCEL_ACTION = 'smart-search/index/cancel-sync';
    var STATS_ACTION = 'smart-search/index/get-stats';

    // State presets describe everything that changes between states. Dynamic
    // bits (numbers, error text, running/queued sub-label) come from ctx.
    var STATE_PRESETS = {
        'synced': {
            pillLabel: 'Healthy', pillDot: 'on',
            showProgress: false,
            heroPrefix: 'entries indexed · ',
        },
        'needs-sync': {
            pillLabel: 'Needs sync', pillDot: 'orange',
            showProgress: false,
            heroPrefix: 'entries indexed · ',
        },
        'not-synced': {
            pillLabel: 'Not synced', pillDot: 'off',
            showProgress: false,
            heroPrefix: 'entries indexed · ',
        },
        'indexing': {
            pillDot: 'blue',
            showProgress: true,
        },
        'error': {
            pillLabel: 'Failed', pillDot: 'red',
            showProgress: true,
            heroPrefix: 'sync failed · ',
        },
    };

    var blocks = [];
    var pollTimer = null;
    var pollInFlight = false;

    function parseSteps(label) {
        if (!label) return null;
        var m = label.match(/([\d,]+)\D+([\d,]+)/);
        if (!m) return null;
        return {
            step: parseInt(m[1].replace(/,/g, ''), 10),
            total: parseInt(m[2].replace(/,/g, ''), 10),
        };
    }

    function SiteBlock(card) {
        this.card = card;
        this.siteId = parseInt(card.getAttribute('data-craftsearch-site-id'), 10);
        var barContainer = DOM.find('site-progress-bar', card);
        this.els = {
            pillDot:     DOM.find('pill-dot', card),
            pillLabel:   DOM.find('pill-label', card),
            heroIndexed: DOM.find('hero-indexed', card),
            heroTotal:   DOM.find('hero-total', card),
            heroLabel:   DOM.find('hero-label', card),
            gaps:        DOM.find('gaps', card),
            gapStale:    DOM.find('gap-stale', card),
            gapStaleN:   DOM.find('gap-stale-count', card),
            gapNotIdx:   DOM.find('gap-not-indexed', card),
            gapNotIdxN:  DOM.find('gap-not-indexed-count', card),
            progress:    DOM.find('site-progress', card),
            errorEl:     DOM.find('site-progress-error', card),
            lastSync:    DOM.find('last-sync', card),
            button:      DOM.findControl('sync-site-btn', card),
            buttonLabel: DOM.find('button-label', card),
        };
        this.bar = barContainer
            ? new Craft.ProgressBar(barContainer, false, { announceProgress: false })
            : null;
        if (this.bar) this.bar.showProgressBar();

        this.state = null;
        this.wasActive = false;
        this.optimistic = false;
        this.defaultButtonLabel = 'Sync Index';
        this.bindForm();
    }

    SiteBlock.prototype.bindForm = function () {
        var btn = this.els.button;
        if (!btn) return;
        var form = btn.closest('form');
        if (!form) return;
        var self = this;
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (self.state === 'indexing') {
                self.requestCancel();
            } else {
                self.markQueued();
                submitSync({ siteId: self.siteId }, [self]);
            }
        });
    };

    SiteBlock.prototype.requestCancel = function () {
        setButtonBusy(this.els.button, true);
        setText(this.els.buttonLabel, 'Cancelling…');
        Craft.sendActionRequest('POST', CANCEL_ACTION, { data: { siteId: this.siteId } })
            .then(pollNow, pollNow);
    };

    SiteBlock.prototype.isActive = function () {
        return this.state === 'indexing';
    };

    // Synchronously paint the "Queued" state, before any network. Real poll
    // responses overwrite this with authoritative values from the queue.
    SiteBlock.prototype.markQueued = function () {
        this.optimistic = true;
        this.wasActive = true;
        this.render('indexing', {
            pillLabel: 'Queued',
            buttonLabel: 'Cancel',
            heroPrefix: 'waiting for worker · ',
            heroIndexed: 0,
            heroTotal: this._currentTotal(),
            heroPercent: 0,
            barPercent: 0,
        });
    };

    SiteBlock.prototype.update = function (job, row) {
        var ctx = this._buildCtx(job, row);
        this.render(ctx.state, ctx);
        if (ctx.fireSyncedNotice) Craft.cp.displayNotice(Craft.t('smart-search', 'Synced.'));
    };

    SiteBlock.prototype._buildCtx = function (job, row) {
        var indexed = row ? (row.indexed || 0) : 0;
        var total = row ? (row.total || 0) : 0;
        var stale = row ? (row.stale || 0) : 0;
        var notIdx = row ? (row.notIndexed || 0) : 0;
        var pct = total > 0 ? Math.round((indexed / total) * 100) : 0;

        if (job && job.status === STATUS_FAILED) {
            this.wasActive = false;
            return {
                state: 'error',
                heroIndexed: indexed, heroTotal: total, heroPercent: pct,
                barPercent: 0,
                errorText: job.error || 'Sync failed. Check the queue log.',
                buttonLabel: 'Retry sync',
            };
        }

        if (job) {
            var running = job.status === STATUS_RESERVED;
            var steps = parseSteps(job.progressLabel);
            if (steps && steps.total > 0) {
                indexed = steps.step;
                total = steps.total;
                pct = Math.round((indexed / total) * 100);
            } else if (typeof job.progress === 'number') {
                pct = Math.round(job.progress);
            }
            this.wasActive = true;
            return {
                state: 'indexing',
                pillLabel: running ? 'Indexing' : 'Queued',
                heroPrefix: running ? 'indexing · ' : 'waiting for worker · ',
                heroIndexed: indexed, heroTotal: total, heroPercent: pct,
                barPercent: running ? pct : 0,
                buttonLabel: 'Cancel',
            };
        }

        var state = 'synced';
        if (!row || !row.lastIndexed || indexed === 0) state = 'not-synced';
        else if (stale > 0 || notIdx > 0) state = 'needs-sync';

        var fireSyncedNotice = this.wasActive;
        this.wasActive = false;
        return {
            state: state,
            heroIndexed: indexed, heroTotal: total, heroPercent: pct,
            buttonLabel: this.defaultButtonLabel,
            stale: stale, notIndexed: notIdx,
            lastSyncLabel: row && row.lastIndexedLabel,
            fireSyncedNotice: fireSyncedNotice,
        };
    };

    SiteBlock.prototype.render = function (state, ctx) {
        var preset = STATE_PRESETS[state];
        if (!preset) return;
        this._setStateAttr(state);
        var els = this.els;

        setText(els.pillLabel, ctx.pillLabel || preset.pillLabel || '');
        if (els.pillDot) els.pillDot.className = 'status ' + preset.pillDot;

        if ('heroIndexed' in ctx) setText(els.heroIndexed, String(ctx.heroIndexed));
        if ('heroTotal' in ctx) setText(els.heroTotal, String(ctx.heroTotal));
        if (els.heroLabel) {
            var prefix = ctx.heroPrefix || preset.heroPrefix || '';
            els.heroLabel.innerHTML = escapeHtml(prefix)
                + '<span data-craftsearch-target="hero-percent">' + (ctx.heroPercent || 0) + '</span>%';
        }

        setHidden(els.gaps, preset.showProgress || !(ctx.stale > 0 || ctx.notIndexed > 0));
        if (!preset.showProgress) {
            setHidden(els.gapStale, !(ctx.stale > 0));
            setText(els.gapStaleN, String(ctx.stale || 0));
            setHidden(els.gapNotIdx, !(ctx.notIndexed > 0));
            setText(els.gapNotIdxN, String(ctx.notIndexed || 0));
        }

        setHidden(els.progress, !preset.showProgress);
        setHidden(els.errorEl, !ctx.errorText);
        if (ctx.errorText) setText(els.errorEl, ctx.errorText);
        if (this.bar && 'barPercent' in ctx) this.bar.setProgressPercentage(ctx.barPercent);

        setButtonBusy(els.button, false);
        setText(els.buttonLabel, ctx.buttonLabel || this.defaultButtonLabel);

        if (els.lastSync && 'lastSyncLabel' in ctx) {
            els.lastSync.textContent = ctx.lastSyncLabel
                ? 'Last sync ' + ctx.lastSyncLabel
                : 'Never synced';
        }
    };

    SiteBlock.prototype._setStateAttr = function (next) {
        if (this.state === next) return;
        DOM.setState(this.card, next);
        this.state = next;
    };

    SiteBlock.prototype._currentTotal = function () {
        return parseInt((this.els.heroTotal && this.els.heroTotal.textContent) || '0', 10) || 0;
    };

    function submitSync(data, optimisticBlocks) {
        function clearOptimistic() {
            optimisticBlocks.forEach(function (b) { b.optimistic = false; });
        }
        return Craft.sendActionRequest('POST', SYNC_ACTION, { data: data })
            .then(function (r) {
                var payload = (r && r.data) || {};
                if (!payload.success) {
                    clearOptimistic();
                    Craft.cp.displayError(payload.error || Craft.t('smart-search', 'Failed to start sync.'));
                    return pollNow();
                }
                Craft.cp.runQueue();
                return pollNow();
            })
            .catch(function () {
                clearOptimistic();
                return pollNow();
            });
    }

    function bindSyncAllForm() {
        var btn = DOM.findControl('sync-all-btn');
        var form = btn && btn.closest('form');
        if (!form) return;
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            blocks.forEach(function (b) { b.markQueued(); });
            submitSync({}, blocks.slice());
        });
    }

    function indexByKey(list, key) {
        var out = {};
        (list || []).forEach(function (item) {
            if (item && item[key] != null) out[item[key]] = item;
        });
        return out;
    }

    function render(data) {
        var jobsBySite = indexByKey(data.jobs, 'siteId');
        var coverageBySite = indexByKey(data.coverage, 'siteId');

        var anyActive = false;
        blocks.forEach(function (block) {
            var job = jobsBySite[block.siteId] || null;
            var row = coverageBySite[block.siteId] || null;

            // Hold the optimistic "Queued" state until either the job appears
            // in the queue or the queue empties (sync already finished).
            if (!job && block.optimistic) {
                if (data.queueRemaining > 0) { anyActive = true; return; }
                block.optimistic = false;
            } else if (job) {
                block.optimistic = false;
            }

            block.update(job, row);
            if (block.isActive()) anyActive = true;
        });
        return anyActive || data.queueRemaining > 0;
    }

    function pollNow() {
        if (pollInFlight) return Promise.resolve(true);
        pollInFlight = true;
        return Craft.sendActionRequest('POST', STATS_ACTION).then(function (r) {
            pollInFlight = false;
            if (!r.data || !r.data.success) return true;
            var keep = render(r.data);
            schedulePoll(keep ? POLL_ACTIVE_MS : null);
            return keep;
        }).catch(function () {
            pollInFlight = false;
            schedulePoll(POLL_ACTIVE_MS);
            return true;
        });
    }

    function schedulePoll(delay) {
        if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
        if (delay == null) return;
        pollTimer = setTimeout(function () { pollTimer = null; pollNow(); }, delay);
    }

    function setButtonBusy(button, busy) {
        if (!button) return;
        button.classList.toggle('disabled', !!busy);
        button.disabled = !!busy;
    }

    function setRowStatus(row, statusClass, label) {
        var cell = DOM.find('status-cell', row);
        if (cell) cell.innerHTML = '<span class="status ' + statusClass + '"></span> ' + escapeHtml(label);
    }

    function setRowChunks(row, count) {
        var cell = DOM.find('chunks-cell', row);
        if (cell) cell.textContent = count;
    }

    function setRowDate(row, text) {
        var cell = DOM.find('date-cell', row);
        if (!cell) return;
        if (text) cell.textContent = text;
        else cell.innerHTML = '<span class="light">&mdash;</span>';
    }

    function setRowExcluded(row, excluded) {
        setHidden(DOM.findControl('reindex-form', row), excluded);
        setHidden(DOM.findControl('exclude-form', row), excluded);
        setHidden(DOM.findControl('include-form', row), !excluded);
    }

    function refreshRowIndexState(row) {
        if (!row) return;
        Craft.sendActionRequest('GET', 'smart-search/index/entry-state', {
            params: {
                elementId: parseInt(row.getAttribute('data-element-id'), 10),
                siteId: parseInt(row.getAttribute('data-site-id'), 10),
            },
        }).then(function (r) {
            var data = (r && r.data) || {};
            if (!data.success) return;
            setRowChunks(row, data.chunkCount);
            setRowDate(row, data.lastIndexed);
        }).catch(function () {});
    }

    function pollEntryJob(jobId, opts) {
        function tick() {
            Craft.sendActionRequest('GET', 'smart-search/index/job-status', { params: { id: jobId } })
                .then(function (r) {
                    var data = (r && r.data) || {};
                    if (data.done) {
                        setButtonBusy(opts.button, false);
                        if (opts.row) {
                            setRowStatus(opts.row, 'green', Craft.t('smart-search', 'Indexed'));
                            refreshRowIndexState(opts.row);
                        }
                        if (opts.completionMsg) Craft.cp.displayNotice(Craft.t('smart-search', opts.completionMsg));
                        return;
                    }
                    Craft.cp.runQueue();
                    setTimeout(tick, ENTRY_POLL_MS);
                })
                .catch(function (err) {
                    console.error('Entry job poll failed', err);
                    setButtonBusy(opts.button, false);
                });
        }
        setTimeout(tick, ENTRY_POLL_INITIAL_MS);
    }

    function bindEntryForm(controlName, action, onSuccess) {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!(form instanceof Element)) return;
            if (form.getAttribute('data-craftsearch-control') !== controlName) return;

            e.preventDefault();
            e.stopPropagation();
            var button = form.querySelector('button[type="submit"]');
            if (button && button.disabled) return;
            setButtonBusy(button, true);

            var row = form.closest('[data-craftsearch-entry-row]');
            var payload = {
                elementId: parseInt(form.getAttribute('data-element-id'), 10),
                siteId: parseInt(form.getAttribute('data-site-id'), 10),
            };
            Craft.sendActionRequest('POST', action, { data: payload })
                .then(function (r) {
                    var data = (r && r.data) || {};
                    if (!data.success) {
                        console.error(action + ' returned unexpected payload', data);
                        setButtonBusy(button, false);
                        return;
                    }
                    onSuccess(row, data, button);
                })
                .catch(function (err) {
                    console.error(action + ' request failed', err);
                    setButtonBusy(button, false);
                });
        });
    }

    function bootOverview() {
        var grid = DOM.find('overview-grid');
        if (!grid) return;

        blocks = DOM.findAll('site-card', grid).map(function (card) { return new SiteBlock(card); });
        if (!blocks.length) return;

        bindSyncAllForm();
        pollNow();
    }

    function init() {
        bindEntryForm('reindex-form', 'smart-search/index/reindex-entry',
            function (row, data, button) {
                if (!data.jobId) { setButtonBusy(button, false); return; }
                Craft.cp.runQueue();
                pollEntryJob(data.jobId, { button: button, row: row, completionMsg: 'Re-index finished.' });
            });
        bindEntryForm('exclude-form', 'smart-search/index/exclude-entry',
            function (row, data, button) {
                setButtonBusy(button, false);
                if (row) {
                    setRowExcluded(row, true);
                    setRowStatus(row, 'grey', Craft.t('smart-search', 'Excluded'));
                    setRowChunks(row, 0);
                    setRowDate(row, null);
                }
                Craft.cp.displayNotice(Craft.t('smart-search', 'Entry excluded from index.'));
            });
        bindEntryForm('include-form', 'smart-search/index/include-entry',
            function (row, data, button) {
                setButtonBusy(button, false);
                if (row) {
                    setRowExcluded(row, false);
                    setRowStatus(row, 'off', Craft.t('smart-search', 'Not indexed'));
                    setRowChunks(row, 0);
                    setRowDate(row, null);
                }
                var reindexForm = row && DOM.findControl('reindex-form', row);
                var reindexButton = reindexForm && reindexForm.querySelector('button[type="submit"]');
                if (reindexButton) setButtonBusy(reindexButton, true);
                if (data.jobId) {
                    Craft.cp.runQueue();
                    pollEntryJob(data.jobId, { button: reindexButton, row: row });
                }
            });
        bootOverview();
    }

    DOM.ready(init);
})();
