(function () {
    'use strict';

    var ns = window.SmartSearch;
    var DOM = ns.core.DOM;

    // -----------------------------------------------------------------------
    // Overview & Sync — per-site cards
    // -----------------------------------------------------------------------
    // One SiteBlock per `[data-craftsearch-target="site-card"]` card. Each block owns its
    // DOM and its sync form. State (`synced` / `needs-sync` / `not-synced` /
    // `indexing` / `error`) is derived from the polled payload via a single
    // table-driven render path — see STATE_PRESETS below.
    //
    // The server's actionGetStats endpoint is the single source of truth; we
    // poll it on a 1s cadence while any block is active, and stop otherwise.
    // Clicking Sync optimistically flips the card to "Queued" synchronously,
    // then POSTs and kicks the queue worker — no waiting on the next tick.

    var STATUS_RESERVED = 2;
    var STATUS_FAILED = 4;
    var POLL_ACTIVE_MS = 1000;
    var SYNC_ACTION = 'smart-search/index/sync';
    var CANCEL_ACTION = 'smart-search/index/cancel-sync';
    var STATS_ACTION = 'smart-search/index/get-stats';

    // State presets describe everything that changes between states. Dynamic
    // bits (numbers, error text, running/queued sub-label) come from ctx.
    var STATE_PRESETS = {
        'synced': {
            pillLabel: 'Healthy', pillDot: 'on',
            buttonStyle: 'plain', buttonDisabled: false,
            showProgress: false, hideGaps: false,
            heroPrefix: 'entries indexed · ',
        },
        'needs-sync': {
            pillLabel: 'Needs sync', pillDot: 'orange',
            buttonStyle: 'danger', buttonDisabled: false,
            showProgress: false, hideGaps: false,
            heroPrefix: 'entries indexed · ',
        },
        'not-synced': {
            pillLabel: 'Not synced', pillDot: 'off',
            buttonStyle: 'plain', buttonDisabled: false,
            showProgress: false, hideGaps: false,
            heroPrefix: 'entries indexed · ',
        },
        'indexing': {
            pillDot: 'blue',
            buttonStyle: 'plain', buttonDisabled: false,
            showProgress: true, hideGaps: true,
        },
        'error': {
            pillLabel: 'Failed', pillDot: 'red',
            buttonStyle: 'danger', buttonDisabled: false,
            showProgress: true, hideGaps: true,
            heroPrefix: 'sync failed · ',
        },
    };
    var STATE_NAMES = Object.keys(STATE_PRESETS);

    var blocks = [];
    var pollTimer = null;
    var pollInFlight = false;

    // -----------------------------------------------------------------------
    // Tiny helpers
    // -----------------------------------------------------------------------

    function parseSteps(label) {
        if (!label) return null;
        var m = label.match(/([\d,]+)\D+([\d,]+)/);
        if (!m) return null;
        return {
            step: parseInt(m[1].replace(/,/g, ''), 10),
            total: parseInt(m[2].replace(/,/g, ''), 10),
        };
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function setText(el, text) {
        if (el && el.textContent !== text) el.textContent = text;
    }

    function setHidden(el, hidden) {
        if (el) el.hidden = !!hidden;
    }

    function runQueue() {
        if (Craft.cp && typeof Craft.cp.runQueue === 'function') Craft.cp.runQueue();
    }

    function notice(msg) {
        if (Craft.cp && Craft.cp.displayNotice) Craft.cp.displayNotice(Craft.t('smart-search', msg));
    }

    function displayError(msg) {
        if (Craft.cp && Craft.cp.displayError) Craft.cp.displayError(msg);
    }

    // -----------------------------------------------------------------------
    // SiteBlock
    // -----------------------------------------------------------------------

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
            ? new Craft.ProgressBar(barContainer, /* displaySteps */ false, { announceProgress: false })
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
        this._setButtonDisabled(true);
        setText(this.els.buttonLabel, 'Cancelling…');
        Craft.sendActionRequest('POST', CANCEL_ACTION, { data: { siteId: this.siteId } })
            .then(function () { pollNow(); })
            .catch(function () { pollNow(); });
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

    // Authoritative update from a poll response.
    SiteBlock.prototype.update = function (job, row) {
        var ctx = this._buildCtx(job, row);
        this.render(ctx.state, ctx);
        if (ctx.fireSyncedNotice) notice('Synced.');
    };

    // Build the rendering context: state + dynamic values per preset hook.
    SiteBlock.prototype._buildCtx = function (job, row) {
        var indexed = row ? (row.indexed || 0) : 0;
        var total = row ? (row.total || 0) : 0;
        var stale = row ? (row.stale || 0) : 0;
        var notIdx = row ? (row.notIndexed || 0) : 0;
        var pct = total > 0 ? Math.round((indexed / total) * 100) : 0;

        // error
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

        // indexing
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

        // idle (synced / needs-sync / not-synced)
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

    // Apply state preset + ctx to the DOM. Single render path for every state.
    SiteBlock.prototype.render = function (state, ctx) {
        var preset = STATE_PRESETS[state];
        if (!preset) return;
        this._setStateClass(state);
        var els = this.els;

        // Pill
        setText(els.pillLabel, ctx.pillLabel || preset.pillLabel || '');
        if (els.pillDot) els.pillDot.className = 'status ' + preset.pillDot;

        // Hero numbers + label
        if ('heroIndexed' in ctx) setText(els.heroIndexed, String(ctx.heroIndexed));
        if ('heroTotal' in ctx) setText(els.heroTotal, String(ctx.heroTotal));
        if (els.heroLabel) {
            var prefix = ctx.heroPrefix || preset.heroPrefix || '';
            els.heroLabel.innerHTML = escapeHtml(prefix)
                + '<span data-craftsearch-target="hero-percent">' + (ctx.heroPercent || 0) + '</span>%';
        }

        // Gaps
        setHidden(els.gaps, preset.hideGaps || !(ctx.stale > 0 || ctx.notIndexed > 0));
        if (!preset.hideGaps) {
            setHidden(els.gapStale, !(ctx.stale > 0));
            setText(els.gapStaleN, String(ctx.stale || 0));
            setHidden(els.gapNotIdx, !(ctx.notIndexed > 0));
            setText(els.gapNotIdxN, String(ctx.notIndexed || 0));
        }

        // Progress pane (bar + error message)
        setHidden(els.progress, !preset.showProgress);
        setHidden(els.errorEl, !ctx.errorText);
        if (ctx.errorText) setText(els.errorEl, ctx.errorText);
        if (this.bar && 'barPercent' in ctx) this.bar.setProgressPercentage(ctx.barPercent);

        // Sync button
        this._styleButton(preset.buttonStyle);
        this._setButtonDisabled(preset.buttonDisabled);
        setText(els.buttonLabel, ctx.buttonLabel || this.defaultButtonLabel);

        // Footer
        if (els.lastSync && 'lastSyncLabel' in ctx) {
            els.lastSync.textContent = ctx.lastSyncLabel
                ? 'Last sync ' + ctx.lastSyncLabel
                : 'Never synced';
        }
    };

    SiteBlock.prototype._setStateClass = function (next) {
        if (this.state === next) return;
        var card = this.card;
        STATE_NAMES.forEach(function (s) { card.classList.remove('craftsearch-card--' + s); });
        card.classList.add('craftsearch-card--' + next);
        this.state = next;
    };

    SiteBlock.prototype._setButtonDisabled = function (disabled) {
        var btn = this.els.button;
        if (!btn) return;
        btn.disabled = !!disabled;
        btn.classList.toggle('disabled', !!disabled);
        if (disabled) btn.setAttribute('aria-disabled', 'true');
        else btn.removeAttribute('aria-disabled');
    };

    SiteBlock.prototype._styleButton = function (kind) {
        var btn = this.els.button;
        if (!btn) return;
        btn.classList.toggle('caution', kind === 'danger');
        btn.classList.toggle('submit', kind !== 'danger');
    };

    SiteBlock.prototype._currentTotal = function () {
        return parseInt((this.els.heroTotal && this.els.heroTotal.textContent) || '0', 10) || 0;
    };

    // -----------------------------------------------------------------------
    // Sync submission (shared between per-block and "sync all")
    // -----------------------------------------------------------------------

    function submitSync(data, optimisticBlocks) {
        return Craft.sendActionRequest('POST', SYNC_ACTION, { data: data })
            .then(function (r) {
                var payload = (r && r.data) || {};
                if (!payload.success) {
                    optimisticBlocks.forEach(function (b) { b.optimistic = false; });
                    displayError(payload.error || Craft.t('smart-search', 'Failed to start sync.'));
                    return pollNow();
                }
                runQueue();
                return pollNow();
            })
            .catch(function () {
                optimisticBlocks.forEach(function (b) { b.optimistic = false; });
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

    // -----------------------------------------------------------------------
    // Polling
    // -----------------------------------------------------------------------

    function indexByKey(list, key) {
        var out = {};
        // Tolerate both arrays and objects — PHP assoc arrays serialize as
        // JSON objects, which is easy to regress.
        if (!list) return out;
        var items = Array.isArray(list) ? list : Object.keys(list).map(function (k) { return list[k]; });
        items.forEach(function (item) {
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
            // in the queue or the queue empties (i.e. sync already finished).
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

    // -----------------------------------------------------------------------
    // Per-entry reindex / exclude / include (entries tab)
    // -----------------------------------------------------------------------

    function setButtonBusy(button, busy) {
        if (!button) return;
        button.classList.toggle('disabled', !!busy);
        if (busy) button.setAttribute('aria-disabled', 'true');
        else button.removeAttribute('aria-disabled');
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
        var forms = {
            reindex: DOM.findControl('reindex-form', row),
            exclude: DOM.findControl('exclude-form', row),
            include: DOM.findControl('include-form', row),
        };
        if (forms.reindex) forms.reindex.style.display = excluded ? 'none' : 'inline';
        if (forms.exclude) forms.exclude.style.display = excluded ? 'none' : 'inline';
        if (forms.include) forms.include.style.display = excluded ? 'inline' : 'none';
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

    // Poll a per-entry job until done. Used after re-index / include actions.
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
                        if (opts.completionMsg) notice(opts.completionMsg);
                        return;
                    }
                    runQueue();
                    setTimeout(tick, 2000);
                })
                .catch(function (err) {
                    if (window.console) console.error('Entry job poll failed', err);
                    setButtonBusy(opts.button, false);
                });
        }
        setTimeout(tick, 1500);
    }

    function bindEntryForm(selector, action, onSuccess) {
        document.querySelectorAll(selector).forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var button = form.querySelector('button[type="submit"]');
                if (button && button.classList.contains('disabled')) return;
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
                            if (window.console) console.error(action + ' returned unexpected payload', data);
                            setButtonBusy(button, false);
                            return;
                        }
                        onSuccess(row, data, button);
                    })
                    .catch(function (err) {
                        if (window.console) console.error(action + ' request failed', err);
                        setButtonBusy(button, false);
                    });
            });
        });
    }

    function bindReindexForms() {
        bindEntryForm('[data-craftsearch-control="reindex-form"]', 'smart-search/index/reindex-entry',
            function (row, data, button) {
                if (!data.jobId) { setButtonBusy(button, false); return; }
                runQueue();
                pollEntryJob(data.jobId, { button: button, row: row, completionMsg: 'Re-index finished.' });
            });
    }

    function bindExcludeForms() {
        bindEntryForm('[data-craftsearch-control="exclude-form"]', 'smart-search/index/exclude-entry',
            function (row, data, button) {
                setButtonBusy(button, false);
                if (row) {
                    setRowExcluded(row, true);
                    setRowStatus(row, 'grey', Craft.t('smart-search', 'Excluded'));
                    setRowChunks(row, 0);
                    setRowDate(row, null);
                }
                notice('Entry excluded from index.');
            });
    }

    function bindIncludeForms() {
        bindEntryForm('[data-craftsearch-control="include-form"]', 'smart-search/index/include-entry',
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
                    runQueue();
                    pollEntryJob(data.jobId, { button: reindexButton, row: row });
                }
            });
    }

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------

    function bootOverview() {
        var grid = DOM.find('overview-grid');
        if (!grid) return;

        blocks = Array.prototype.map.call(
            grid.querySelectorAll('[data-craftsearch-target="site-card"]'),
            function (card) { return new SiteBlock(card); }
        );
        if (!blocks.length) return;

        bindSyncAllForm();
        pollNow();
    }

    ns.pages.indexMgmt = {
        init: function () {
            bindReindexForms();
            bindExcludeForms();
            bindIncludeForms();
        },
    };

    DOM.ready(ns.pages.indexMgmt.init);

    DOM.ready(function () {
        var region = document.querySelector('[data-craftsearch-target="overview-region"]');
        if (!region) return;
        var slot = region.querySelector('[data-craftsearch-target="overview-content"]');
        if (!slot) return;

        Craft.sendActionRequest('GET', 'smart-search/index/get-overview').then(function (r) {
            if (r && r.data && r.data.success && typeof r.data.html === 'string') {
                slot.innerHTML = r.data.html;
                bootOverview();
            } else {
                slot.innerHTML = '<blockquote class="note warning"><p>Failed to load overview.</p></blockquote>';
            }
        }).catch(function () {
            slot.innerHTML = '<blockquote class="note warning"><p>Failed to load overview.</p></blockquote>';
        });
    });

    DOM.ready(function () {
        var region = document.querySelector('[data-craftsearch-target="entries-region"]');
        if (!region) return;
        var contentSlot = region.querySelector('[data-craftsearch-target="entries-content"]');
        if (!contentSlot) return;

        var toolbar = DOM.find('filter-bar', region);
        var spinner = '<div class="craftsearch-entries__loading" data-craftsearch-target="entries-loading"><div class="spinner"></div></div>';

        function showOverlay() {
            if (contentSlot.classList.contains('is-loading')) return;
            contentSlot.classList.add('is-loading');
            contentSlot.insertAdjacentHTML('beforeend', spinner);
        }

        function revealToolbar() {
            if (toolbar) toolbar.hidden = false;
        }

        function finishLoad(html, isError) {
            contentSlot.classList.remove('is-loading');
            contentSlot.innerHTML = isError
                ? '<blockquote class="note warning"><p>Failed to load entries.</p></blockquote>'
                : html;
            revealToolbar();
        }

        function loadRows() {
            var params = parseQuery(window.location.search.replace(/^\?/, ''));
            Craft.sendActionRequest('GET', 'smart-search/index/get-entry-rows', {
                params: params,
            }).then(function (r) {
                if (r && r.data && r.data.success && typeof r.data.html === 'string') {
                    finishLoad(r.data.html, false);
                } else {
                    finishLoad('', true);
                }
            }).catch(function () {
                finishLoad('', true);
            });
        }

        function parseQuery(qs) {
            var out = {};
            if (!qs) return out;
            qs.split('&').forEach(function (pair) {
                if (!pair) return;
                var idx = pair.indexOf('=');
                var k = idx >= 0 ? pair.slice(0, idx) : pair;
                var v = idx >= 0 ? pair.slice(idx + 1) : '';
                out[decodeURIComponent(k)] = decodeURIComponent(v.replace(/\+/g, ' '));
            });
            return out;
        }

        function buildSearchFromForm(formEl) {
            var fd = new FormData(formEl);
            var pairs = [];
            fd.forEach(function (value, key) {
                if (value === '' || value === null) return;
                pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
            });
            return pairs.join('&');
        }

        function navigateAjax(search) {
            var newUrl = window.location.pathname + (search ? '?' + search : '');
            window.history.pushState({ entries: true }, '', newUrl);
            showOverlay();
            loadRows();
        }

        var form = DOM.find('filter-bar-form', region);
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                navigateAjax(buildSearchFromForm(form));
            });
        }

        region.addEventListener('click', function (e) {
            var target = e.target;
            if (!(target instanceof Element)) return;
            var link = target.closest('.pagination a.btn[href], [data-craftsearch-target="filter-bar-reset"][href]');
            if (!link || link.classList.contains('disabled')) return;
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1) return;
            e.preventDefault();
            var href = link.getAttribute('href') || '';
            var qIdx = href.indexOf('?');
            navigateAjax(qIdx >= 0 ? href.slice(qIdx + 1) : '');
        });

        window.addEventListener('popstate', function () {
            showOverlay();
            loadRows();
        });

        loadRows();
    });

    DOM.ready(function () {
        var region = document.querySelector('[data-craftsearch-target="coverage-region"]');
        if (!region) return;
        var contentSlot = region.querySelector('[data-craftsearch-target="coverage-content"]');
        if (!contentSlot) return;

        Craft.sendActionRequest('GET', 'smart-search/index/get-coverage').then(function (r) {
            if (r && r.data && r.data.success && typeof r.data.html === 'string') {
                contentSlot.innerHTML = r.data.html;
            } else {
                contentSlot.innerHTML = '<blockquote class="note warning"><p>Failed to load coverage.</p></blockquote>';
            }
        }).catch(function () {
            contentSlot.innerHTML = '<blockquote class="note warning"><p>Failed to load coverage.</p></blockquote>';
        });
    });
})();
