/* global aceReprocess */
(function () {
    'use strict';

    // State
    var state = {
        running:   false,
        paused:    false,
        offset:    0,
        total:     0,
        processed: 0,
        skipped:   0,
        failed:    0,
        contentMode: false,
        contentImageIds: null,
        contentParams: null,
    };

    var STORAGE_KEY = 'ace_image_reprocess_state';

    function saveState() {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    }

    function loadState() {
        var saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
            try {
                var loaded = JSON.parse(saved);
                Object.assign(state, loaded);
                // Update UI
                setProgress(state.processed, state.skipped, state.failed, state.total);
                setRunning(state.running);
                if (state.paused) {
                    el.resumeBtn.style.display = '';
                    el.pauseBtn.style.display = 'none';
                }
            } catch (e) {
                // Ignore
            }
        }
    }

    function clearState() {
        localStorage.removeItem(STORAGE_KEY);
    }

    // DOM refs (populated in init)
    var el = {};

    function getSelectedTypes() {
        var types = [];
        document.querySelectorAll('.ace-filter-type:checked').forEach(function (cb) {
            types.push(cb.value);
        });
        return types;
    }

    function buildParams(extra) {
        var params = {
            file_types: getSelectedTypes(),
            date_after: el.dateFilter.value,
            batch_size: 1,
            overwrite:  el.overwrite.checked,
        };
        return Object.assign(params, extra || {});
    }

    function apiFetch(path, method, body) {
        var opts = {
            method:  method || 'GET',
            headers: {
                'X-WP-Nonce':   aceReprocess.nonce,
                'Content-Type': 'application/json',
            },
        };
        if (body) {
            opts.body = JSON.stringify(body);
        }
        return fetch(aceReprocess.restUrl + path, opts).then(function (r) {
            if (!r.ok) {
                return r.json().then(function (d) { throw new Error(d.message || r.statusText); });
            }
            return r.json();
        });
    }

    function setProgress(processed, skipped, failed, total) {
        var done    = processed + skipped + failed;
        var pct     = total > 0 ? Math.round((done / total) * 100) : 0;
        el.bar.style.width = pct + '%';
        el.progressText.textContent =
            'Processed: ' + processed +
            '  |  Skipped: ' + skipped +
            '  |  Failed: ' + failed +
            '  |  Total: ' + total +
            ' (' + pct + '%)';
        saveState();
    }

    function appendLog(entries) {
        entries.forEach(function (entry) {
            var row    = document.createElement('div');
            row.className = 'ace-log-entry';

            var statusEl = document.createElement('span');
            statusEl.className = 'ace-log-status ace-log-status--' + entry.status;
            statusEl.textContent = entry.status;

            var titleEl = document.createElement('span');
            titleEl.className = 'ace-log-title';
            titleEl.textContent = entry.title || '#' + entry.id;

            row.appendChild(statusEl);
            row.appendChild(titleEl);

            if (entry.reason) {
                var reasonEl = document.createElement('span');
                reasonEl.className = 'ace-log-reason';
                reasonEl.textContent = '(' + entry.reason + ')';
                row.appendChild(reasonEl);
            }

            el.log.appendChild(row);
        });
        el.log.scrollTop = el.log.scrollHeight;
    }

    function setRunning(yes) {
        state.running    = yes;
        el.startBtn.style.display  = yes ? 'none' : '';
        el.pauseBtn.style.display  = yes && !state.paused ? '' : 'none';
        el.resumeBtn.style.display = state.paused ? '' : 'none';
        el.countBtn.disabled       = yes;
    }

    function runBatch() {
        if (state.contentMode) {
            runContentBatch();
            return;
        }

        if (!state.running || state.paused) return;

        var params = buildParams({ offset: state.offset });

        apiFetch('batch-run', 'POST', params).then(function (data) {
            state.processed += data.processed;
            state.skipped   += data.skipped;
            state.failed    += data.failed;
            state.total      = data.total;
            state.offset     = data.offset;

            setProgress(state.processed, state.skipped, state.failed, state.total);
            appendLog(data.log || []);

            if (data.complete) {
                setRunning(false);
                el.startBtn.disabled = false;
                el.startBtn.textContent = 'Start reprocessing';
                el.resumeBtn.style.display = 'none';
                appendLog([{ status: 'processed', title: '— Done. ' + state.processed + ' converted, ' + state.skipped + ' skipped, ' + state.failed + ' failed.' }]);
                clearState();
            } else {
                runBatch();
            }
        }).catch(function (err) {
            setRunning(false);
            appendLog([{ status: 'failed', title: 'Request error: ' + err.message, reason: '' }]);
        });
    }

    function handleStart() {
        if (getSelectedTypes().length === 0) {
            alert('Please select at least one file type.');
            return;
        }
        // Reset state
        state.running   = true;
        state.paused    = false;
        state.offset    = 0;
        state.total     = 0;
        state.processed = 0;
        state.skipped   = 0;
        state.failed    = 0;
        state.contentMode = false;
        state.contentImageIds = null;
        state.contentParams = null;

        clearState();
        el.log.innerHTML = '';
        el.startBtn.disabled    = true;
        el.startBtn.textContent = 'Running…';
        setRunning(true);
        setProgress(0, 0, 0, 0);
        runBatch();
    }

    function handlePause() {
        state.paused = true;
        el.pauseBtn.style.display  = 'none';
        el.resumeBtn.style.display = '';
        saveState();
    }

    function handleResume() {
        state.paused = false;
        el.resumeBtn.style.display = 'none';
        el.pauseBtn.style.display  = '';
        saveState();
        runBatch();
    }

    function handleCount() {
        el.countResult.textContent = 'Counting…';
        var params = buildParams();
        var url = aceReprocess.restUrl + 'batch-count?' + new URLSearchParams({
            file_types: params.file_types,
            date_after: params.date_after,
            overwrite:  params.overwrite ? '1' : '0',
        }).toString();

        // URLSearchParams won't repeat file_types[] properly, build manually
        var qs = 'date_after=' + encodeURIComponent(params.date_after) +
                 '&overwrite=' + (params.overwrite ? '1' : '0');
        params.file_types.forEach(function (t) { qs += '&file_types[]=' + encodeURIComponent(t); });

        fetch(aceReprocess.restUrl + 'batch-count?' + qs, {
            headers: { 'X-WP-Nonce': aceReprocess.nonce }
        }).then(function (r) { return r.json(); }).then(function (data) {
            el.countResult.textContent = data.count + ' image' + (data.count === 1 ? '' : 's') + ' to convert';
        }).catch(function () {
            el.countResult.textContent = 'Error fetching count.';
        });
    }

    function handleContentPreview() {
        el.contentPreviewResult.textContent = 'Loading…';
        el.contentStartBtn.style.display = 'none';

        var params = {
            post_count: parseInt(el.contentCount.value, 10) || 100,
            include_pages: el.includePages.checked,
        };

        apiFetch('batch-from-content?' + new URLSearchParams(params).toString()).then(function (data) {
            el.contentPreviewResult.textContent = data.count + ' unique image' + (data.count === 1 ? '' : 's') + ' found in ' + data.post_count + ' recent post' + (data.post_count === 1 ? '' : 's') + '/page' + (data.post_count === 1 ? '' : 's');
            if (data.count > 0) {
                el.contentStartBtn.style.display = '';
            }
        }).catch(function (err) {
            el.contentPreviewResult.textContent = 'Error: ' + err.message;
        });
    }

    function handleContentStart() {
        if (getSelectedTypes().length === 0) {
            alert('Please select at least one file type.');
            return;
        }

        // Set up state for content-based processing
        state.running   = true;
        state.paused    = false;
        state.offset    = 0;
        state.total     = 0;
        state.processed = 0;
        state.skipped   = 0;
        state.failed    = 0;
        state.contentMode = true;
        state.contentParams = {
            post_count: parseInt(el.contentCount.value, 10) || 100,
            include_pages: el.includePages.checked,
        };

        clearState();
        el.log.innerHTML = '';
        el.contentStartBtn.disabled = true;
        el.contentStartBtn.textContent = 'Running…';
        setRunning(true);
        setProgress(0, 0, 0, 0);

        // First get the image IDs from content
        apiFetch('batch-from-content?' + new URLSearchParams(state.contentParams).toString()).then(function (data) {
            state.contentImageIds = data.image_ids;
            state.total = data.count;
            setProgress(0, 0, 0, state.total);
            runContentBatch();
        }).catch(function (err) {
            setRunning(false);
            el.contentStartBtn.disabled = false;
            el.contentStartBtn.textContent = 'Start Reprocessing from Content';
            appendLog([{ status: 'failed', title: 'Error getting content images: ' + err.message, reason: '' }]);
        });
    }

    function runContentBatch() {
        if (!state.running || state.paused || !state.contentImageIds) return;

        var batchSize = 1; // Process one at a time
        var batch = state.contentImageIds.slice(state.offset, state.offset + batchSize);

        if (batch.length === 0) {
            // Done
            setRunning(false);
            el.contentStartBtn.disabled = false;
            el.contentStartBtn.textContent = 'Start Reprocessing from Content';
            appendLog([{ status: 'processed', title: '— Done. ' + state.processed + ' converted, ' + state.skipped + ' skipped, ' + state.failed + ' failed.' }]);
            clearState();
            return;
        }

        // Process each image in the batch
        var promises = batch.map(function(id) {
            return apiFetch('reprocess-single', 'POST', { attachment_id: id }).then(function (result) {
                var status = result.success ? 'processed' : (result.status || 'skipped');
                if (status === 'processed') state.processed++;
                else if (status === 'skipped') state.skipped++;
                else state.failed++;

                appendLog([{
                    status: status,
                    title: result.title || '#' + id,
                    reason: result.reason || '',
                }]);
                return result;
            });
        });

        Promise.all(promises).then(function() {
            state.offset += batch.length;
            setProgress(state.processed, state.skipped, state.failed, state.total);
            runContentBatch();
        }).catch(function (err) {
            setRunning(false);
            appendLog([{ status: 'failed', title: 'Request error: ' + err.message, reason: '' }]);
        });
    }

    function init() {
        el.log          = document.getElementById('ace-log');
        el.bar          = document.getElementById('ace-progress-bar');
        el.progressText = document.getElementById('ace-progress-text');
        el.startBtn     = document.getElementById('ace-start-btn');
        el.pauseBtn     = document.getElementById('ace-pause-btn');
        el.resumeBtn    = document.getElementById('ace-resume-btn');
        el.countBtn     = document.getElementById('ace-count-btn');
        el.countResult  = document.getElementById('ace-count-result');
        el.dateFilter   = document.getElementById('ace-filter-date');
        el.overwrite    = document.getElementById('ace-overwrite');

        // Content-based reprocessing elements
        el.contentPreviewBtn = document.getElementById('ace-content-preview-btn');
        el.contentPreviewResult = document.getElementById('ace-content-preview-result');
        el.contentStartBtn = document.getElementById('ace-content-start-btn');
        el.includePages = document.getElementById('ace-include-pages');
        el.contentCount = document.getElementById('ace-content-count');

        if (!el.startBtn) return; // not on batch page

        loadState();

        el.startBtn.addEventListener('click', handleStart);
        el.pauseBtn.addEventListener('click', handlePause);
        el.resumeBtn.addEventListener('click', handleResume);
        el.countBtn.addEventListener('click', handleCount);

        // Content-based events
        if (el.contentPreviewBtn) {
            el.contentPreviewBtn.addEventListener('click', handleContentPreview);
        }
        if (el.contentStartBtn) {
            el.contentStartBtn.addEventListener('click', handleContentStart);
        }

        window.addEventListener('beforeunload', function() {
            if (state.running && !state.paused) {
                handlePause();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
