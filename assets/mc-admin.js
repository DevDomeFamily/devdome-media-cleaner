/**
 * DevDome Media Cleaner — admin UI behaviours (vanilla JS, no build step; matches bp-admin.js).
 * Self-guards on the elements of each screen so loading it everywhere is safe.
 */
(function () {
    'use strict';

    var CFG = window.DevdSame || {};
    var I18N = CFG.i18n || {};

    function api(path, method, body) {
        return fetch(CFG.root + path, {
            method: method || 'GET',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, data: j }; }); });
    }

    function fmtEta(sec) {
        if (sec === null || sec === undefined) { return ''; }
        if (sec < 60) { return sec + 's left'; }
        var m = Math.floor(sec / 60);
        return m + 'm ' + (sec % 60) + 's left';
    }

    /* ---- Custom .dd-dd dropdowns (close on outside click). ---- */
    document.querySelectorAll('.dd-dd').forEach(function (dd) {
        var trigger = dd.querySelector('.dd-dd-trigger');
        if (!trigger) { return; }
        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            dd.classList.toggle('is-open');
        });
        dd.querySelectorAll('.dd-dd-opt').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var val = opt.getAttribute('data-value');
                dd.setAttribute('data-value', val);
                var lbl = dd.querySelector('.dd-dd-label');
                if (lbl) { lbl.textContent = opt.textContent; }
                // Settings dropdowns are backed by a hidden form input — keep it in sync so the
                // value is posted (this is the .dd-dd replacement for a native <select>).
                var hidden = dd.querySelector('input[type="hidden"]');
                if (hidden) { hidden.value = val; }
                dd.classList.remove('is-open');
                dd.dispatchEvent(new CustomEvent('dd:change', { detail: val }));
            });
        });
    });
    document.addEventListener('click', function () {
        document.querySelectorAll('.dd-dd.is-open').forEach(function (dd) { dd.classList.remove('is-open'); });
    });

    /* ---- Scan progress polling (shared by Overview scans + Review trash). All hooks are
     * CLASSES updated together. The Overview is TWO tools (Media Library Cleaner + Disk
     * Cleaner), each with its own progress wrap tagged data-mc-scope="library|disk" — only
     * the wrap matching the running job's scope is shown (untagged wraps always show). ---- */
    var pollTimer = null;
    var peekTimer = null;
    var polling = false;   // request-in-flight guard (ticks can take >1 interval)
    var peeking = false;   // same guard for the display-only ?peek=1 poll
    var cancelled = false; // set the instant the user hits Cancel — ignore late responses
    var jobScope = '';     // scope of the job we started / resumed ('' = show everywhere)
    function wrapMatches(wrap) {
        var ws = wrap.getAttribute('data-mc-scope');
        if (!ws || !jobScope || jobScope === 'full') { return true; }
        return ws === jobScope;
    }
    function showProgress(show) {
        document.querySelectorAll('.mc-progress-wrap').forEach(function (wrap) {
            wrap.style.display = (show && wrapMatches(wrap)) ? 'block' : 'none';
            wrap.classList.remove('is-done');
        });
    }
    function setProgress(p) {
        document.querySelectorAll('.mc-progress-bar').forEach(function (bar) {
            bar.style.width = (p.percent || 0) + '%';
        });
        document.querySelectorAll('.mc-progress-msg').forEach(function (msg) {
            var counts = (p.total && p.processed !== null && p.processed !== undefined) ? ' (' + p.processed + '/' + p.total + ')' : '';
            msg.textContent = (p.message || I18N.scanning || 'Working...') + counts;
        });
        document.querySelectorAll('.mc-progress-eta').forEach(function (eta) {
            eta.textContent = fmtEta(p.eta);
        });
    }
    function markDone(label) {
        document.querySelectorAll('.mc-progress-wrap').forEach(function (wrap) { wrap.classList.add('is-done'); });
        document.querySelectorAll('.mc-progress-bar').forEach(function (bar) { bar.style.width = '100%'; });
        document.querySelectorAll('.mc-progress-msg').forEach(function (msg) { msg.textContent = label || 'Completed'; });
        document.querySelectorAll('.mc-progress-eta').forEach(function (eta) { eta.textContent = ''; });
        document.querySelectorAll('.mc-pause-btn, .mc-cancel-btn').forEach(function (b) { b.style.display = 'none'; });
    }
    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        if (peekTimer) { clearInterval(peekTimer); peekTimer = null; }
        polling = false;
        peeking = false;
    }
    function poll(onDone) {
        if (pollTimer) { return; }
        cancelled = false;
        showProgress(true);
        var tick = function () {
            if (polling || cancelled) { return; }
            polling = true;
            api('scan-progress').then(function (res) {
                polling = false;
                if (cancelled) { return; }
                var p = res.data || {};
                setProgress(p);
                if (!p.active && p.status !== 'running') {
                    stopPolling();
                    if (p.status === 'completed') {
                        // Green, explicit, visible — then refresh the stats.
                        markDone(I18N.completed || 'Completed');
                        setTimeout(function () { if (onDone) { onDone(p); } }, 1400);
                    } else {
                        showProgress(false);
                        if (onDone) { onDone(p); }
                    }
                }
            }).catch(function () { polling = false; });
        };
        tick(); // fire IMMEDIATELY — never make the user wait for the first interval
        pollTimer = setInterval(tick, 700);
        startPeeking();
    }
    // Display-only peek: the driver request blocks for the length of a server tick (manifest
    // build, zip chunk) — and the start-* POST itself can take seconds — so a second cheap
    // poll keeps the bar/message live. Never advances work, never ends the job UI.
    function startPeeking() {
        if (peekTimer) { return; }
        peekTimer = setInterval(function () {
            if (peeking || cancelled) { return; }
            peeking = true;
            api('scan-progress?peek=1').then(function (res) {
                peeking = false;
                if (cancelled) { return; }
                var p = res.data || {};
                if (p.active) { setProgress(p); }
            }).catch(function () { peeking = false; });
        }, 900);
    }

    /* ---- Overview: scan + backup + retry buttons (backup ids hit start-backup). ---- */
    var jobButtons = ['mc-scan-btn', 'mc-disk-scan-btn', 'mc-backup-btn', 'mc-disk-backup-btn', 'mc-retry-btn', 'mc-clean-btn', 'mc-disk-clean-btn'].map(function (id) { return document.getElementById(id); }).filter(Boolean);
    function setJobButtons(disabled) { jobButtons.forEach(function (b) { b.disabled = disabled; }); }
    function startJob(btn, what) {
        var isBackup = btn.id.indexOf('backup') !== -1;
        var isClean = btn.id.indexOf('clean') !== -1;
        setJobButtons(true);
        jobScope = btn.getAttribute('data-scope') || 'full';
        // Instant feedback: the bar appears NOW, not after the server answers — and the
        // peek loop starts too, so live job state shows even while the start POST is slow.
        cancelled = false;
        showProgress(true);
        setProgress({ percent: 0, message: I18N.starting || 'Starting...' });
        startPeeking();
        var route = isBackup ? 'start-backup' : (isClean ? 'clean' : 'start-scan');
        api(route, 'POST', { mode: btn.getAttribute('data-mode') || 'scan', scope: jobScope, what: what || btn.getAttribute('data-what') || '' }).then(function (res) {
            if (!res.ok) {
                stopPolling();
                showProgress(false);
                setJobButtons(false);
                alert((res.data && res.data.message) || 'Could not start.');
                return;
            }
            poll(function () { location.reload(); });
        });
    }
    ['mc-scan-btn', 'mc-disk-scan-btn', 'mc-retry-btn'].forEach(function (id) {
        var btn = document.getElementById(id);
        if (btn) { btn.addEventListener('click', function () { startJob(btn, ''); }); }
    });

    /* ---- Clean Unused / Clean Orphans: confirm (count + size), then trash-all job. ---- */
    ['mc-clean-btn', 'mc-disk-clean-btn'].forEach(function (id) {
        var btn = document.getElementById(id);
        if (!btn) { return; }
        btn.addEventListener('click', function () {
            var msg = (I18N.confirmClean || 'Move %1$s images (%2$s) to Recycle Bin? You can restore them anytime.')
                .replace('%1$s', btn.getAttribute('data-count') || '0')
                .replace('%2$s', btn.getAttribute('data-size') || '');
            if (!confirm(msg)) { return; }
            startJob(btn, '');
        });
    });

    /* ---- Create Backup buttons: the WHOLE button opens the 2-option menu; a backup only
     * starts when an option (with its size) is clicked. ---- */
    document.querySelectorAll('.mc-bk-split').forEach(function (split) {
        var main = split.querySelector('.mc-bk-menu-btn');
        if (!main) { return; }
        main.addEventListener('click', function (e) {
            e.stopPropagation();
            split.classList.toggle('is-open');
        });
        split.querySelectorAll('.mc-bk-split-menu button').forEach(function (opt) {
            opt.addEventListener('click', function () {
                split.classList.remove('is-open');
                startJob(main, opt.getAttribute('data-what') || '');
            });
        });
    });
    document.addEventListener('click', function () {
        document.querySelectorAll('.mc-bk-split.is-open').forEach(function (s) { s.classList.remove('is-open'); });
    });
    document.querySelectorAll('.mc-pause-btn').forEach(function (pauseBtn) {
        pauseBtn.addEventListener('click', function () {
            var paused = pauseBtn.getAttribute('data-paused') === '1';
            // Optimistic flip — the server honours it at the next chunk boundary.
            pauseBtn.setAttribute('data-paused', paused ? '0' : '1');
            pauseBtn.textContent = paused ? (I18N.pause || 'Pause') : (I18N.resume || 'Resume');
            if (!paused) {
                document.querySelectorAll('.mc-progress-msg').forEach(function (m) { m.textContent = I18N.pausing || 'Pausing after the current batch...'; });
            }
            api('job-control', 'POST', { action: paused ? 'resume' : 'pause' });
        });
    });
    document.querySelectorAll('.mc-cancel-btn').forEach(function (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            // INSTANT: hide first, tell the server in the background.
            cancelled = true;
            stopPolling();
            showProgress(false);
            setJobButtons(false);
            api('job-control', 'POST', { action: 'cancel' }).then(function () {
                // A cancelled clean rolls itself back (all-or-nothing). The rollback is a new
                // 'restore' job — it can appear a few seconds later (an in-flight chunk defers
                // it until its last files landed), so retry, then drive + SHOW it.
                var tries = 0;
                var pickup = function () {
                    api('scan-progress').then(function (res) {
                        var p = res.data || {};
                        if (p.active && p.type === 'restore') {
                            cancelled = false;
                            jobScope = p.scope || '';
                            setJobButtons(true);
                            showProgress(true);
                            setProgress(p);
                            poll(function () { location.reload(); });
                        } else if (++tries < 20) {
                            setTimeout(pickup, 1000); // the call itself also drives pending ticks
                        }
                    }).catch(function () { if (++tries < 20) { setTimeout(pickup, 1000); } });
                };
                pickup();
            });
        });
    });

    /* ---- If a job is already running (page reopened mid-scan), resume the live bar. ---- */
    if (document.querySelector('.mc-progress-wrap')) {
        api('scan-progress').then(function (res) {
            var p = res.data || {};
            if (p.active) {
                setJobButtons(true);
                jobScope = p.scope || '';
                setProgress(p);
                poll(function () { location.reload(); });
            }
        });
    }

    /* ---- Settings: conditional fields — only visible while their checkbox is on. ---- */
    document.querySelectorAll('[data-mc-showif]').forEach(function (el) {
        var cb = document.querySelector('input[name="' + el.getAttribute('data-mc-showif') + '"]');
        if (!cb) { return; }
        var sync = function () { el.style.display = cb.checked ? '' : 'none'; };
        cb.addEventListener('change', sync);
        sync();
    });

    /* ---- Settings: chip lists (CDN domains, never-scan folders). The hidden textarea
     * keeps the newline-joined value, so the server-side save is unchanged. ---- */
    document.querySelectorAll('.mc-chips').forEach(function (box) {
        var ta = box.querySelector('textarea.mc-chip-store');
        var input = box.querySelector('textarea.mc-list-in');
        var addBtn = box.querySelector('.mc-chip-add');
        var list = box.querySelector('.mc-chip-list');
        if (!ta || !input || !addBtn || !list) { return; }
        function items() { return ta.value.split(/\n/).map(function (s) { return s.trim(); }).filter(Boolean); }
        function render() {
            list.innerHTML = '';
            var it = items();
            if (!it.length) { return; }
            var wrap = document.createElement('div');
            wrap.className = 'dd-sel-group';
            var head = document.createElement('div');
            head.className = 'dd-sel-head';
            head.textContent = (I18N.added || 'Added') + ' (' + it.length + ')';
            wrap.appendChild(head);
            it.forEach(function (v) {
                var row = document.createElement('div');
                row.className = 'dd-sel-row mc-chip';
                var info = document.createElement('span');
                info.textContent = v;
                info.style.minWidth = '0';
                info.style.flex = '1';
                var x = document.createElement('span');
                x.className = 'dd-sel-x mc-chip-x';
                x.textContent = I18N.remove || 'Remove';
                x.addEventListener('click', function () {
                    ta.value = items().filter(function (i) { return i !== v; }).join('\n');
                    render();
                });
                row.appendChild(info);
                row.appendChild(x);
                wrap.appendChild(row);
            });
            list.appendChild(wrap);
        }
        function add() {
            var lines = input.value.split(/\n/).map(function (s) { return s.trim(); }).filter(Boolean);
            if (!lines.length) { return; }
            var it = items();
            lines.forEach(function (v) { if (it.indexOf(v) === -1) { it.push(v); } });
            ta.value = it.join('\n');
            input.value = '';
            input.rows = 3;
            render();
        }
        addBtn.addEventListener('click', add);
        // Paste-many field: grows with content, 3 -> 10 visible rows.
        input.addEventListener('input', function () {
            input.rows = Math.min(10, Math.max(3, input.value.split(/\n/).length));
        });
        render();
    });

    /* ---- Settings: async save — no reload, green "Saved." next to the button. ---- */
    var settingsForm = document.querySelector('[data-dd-panel="settings"] form');
    if (settingsForm) {
        settingsForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(settingsForm);
            fd.append('devdsame_settings_save', '1');
            // Feedback the instant the button is pressed — the POST finishes in the background.
            var note = document.getElementById('mc-saved-note');
            if (note) {
                note.style.display = 'inline';
                setTimeout(function () { note.style.display = 'none'; }, 2600);
            }
            fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
        });
    }

    /* ---- Recycle Bin: clear history — removes finished (restored/deleted) rows. ---- */
    document.querySelectorAll('.mc-clear-history-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm(I18N.confirmClearHistory || 'Clear the batch list? Everything with nothing left to restore is removed.')) { return; }
            api('clear-trash-history', 'POST', {}).then(function (res) {
                var ids = (res.data && res.data.ids) || [];
                document.querySelectorAll('[data-dd-panel="trash"] tbody tr').forEach(function (tr) {
                    var cell = tr.querySelector('td');
                    var id = cell ? parseInt((cell.textContent || '').replace('#', ''), 10) : 0;
                    if (ids.indexOf(id) !== -1) { tr.style.display = 'none'; }
                });
            });
        });
    });

    /* ---- Backup & Restore: async restore. Instant bar in the backup's own section, REST
     * starts the job in the background — no navigation, no stuck ?mc_bk=restoring URL. ---- */
    document.querySelectorAll('.mc-bk-restore-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm(I18N.confirmRestoreBackup || 'Restore every file in this backup? Existing files with the same name are overwritten.')) { return; }
            cancelled = false;
            jobScope = btn.getAttribute('data-scope') || '';
            showProgress(true);
            setProgress({ percent: 0, message: I18N.starting || 'Starting...' });
            startPeeking();
            api('restore-backup', 'POST', { backup_id: btn.getAttribute('data-id') || '' }).then(function (res) {
                if (!res.ok) {
                    stopPolling();
                    showProgress(false);
                    alert((res.data && res.data.message) || 'Could not start the restore.');
                    return;
                }
                poll(function () { location.reload(); });
            });
        });
    });

    /* ---- Backup & Restore: async delete. A plain form POST navigates and its PRG lands on
     * ?mc_tab=backup, yanking the user back if they switched tabs meanwhile. Instead: hide
     * the row instantly, POST in the background, flash a banner that clears on tab switch. ---- */
    document.querySelectorAll('.mc-bk-del-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!confirm(I18N.confirmDeleteBackup || 'Delete this backup file? This cannot be undone.')) { return; }
            var tr = form.closest('tr');
            if (tr) { tr.style.display = 'none'; }
            var fd = new FormData(form);
            var btn = form.querySelector('button[name="backup_id"]');
            if (btn) { fd.append('backup_id', btn.value); } // submit-button values are not in FormData
            fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
            var panel = document.querySelector('[data-dd-panel="backup"] .max-w-5xl');
            if (panel && !panel.querySelector('.mc-bk-flash')) {
                var b = document.createElement('div');
                b.className = 'dd-banner dd-banner-error mc-bk-flash';
                b.style.marginBottom = '16px';
                b.textContent = I18N.backupDeleted || 'Backup deleted.';
                panel.insertBefore(b, panel.firstChild);
            }
        });
    });
    // Flash notices come from PRG query params — strip them from the URL immediately after
    // render so F5 NEVER resurrects a stale banner. The banner still shows this one time.
    (function () {
        try {
            var u = new URL(window.location.href);
            var dirty = false;
            ['mc_deleted', 'mc_restored', 'mc_saved', 'mc_bk', 'mc_conn'].forEach(function (k) {
                if (u.searchParams.has(k)) { u.searchParams.delete(k); dirty = true; }
            });
            if (dirty) { history.replaceState(null, '', u.toString()); }
        } catch (e) { /* old browser — banners just behave like before */ }
    })();

    // Flash notices (deleted/restored/saved/uploaded banners) only live while you stay on
    // the tab — any tab switch clears every one of them.
    document.querySelectorAll('.dd-tab').forEach(function (t) {
        t.addEventListener('click', function () {
            document.querySelectorAll('.mc-bk-flash, .dd-flash').forEach(function (x) { x.remove(); });
        });
    });
    // "View progress" style buttons: jump to another tab client-side.
    document.querySelectorAll('[data-dd-goto]').forEach(function (b) {
        b.addEventListener('click', function () {
            var tab = document.querySelector('[data-dd-tab="' + b.getAttribute('data-dd-goto') + '"]');
            if (tab) { tab.click(); }
        });
    });

    /* ---- Backup & Restore: chunked upload. Whole-file uploads 413 on default host limits
     * (nginx 1M, PHP 2M/8M), so the file is sliced into small chunks that the server appends;
     * a 413 on any chunk halves the chunk size and retries the same offset. ---- */
    document.querySelectorAll('.mc-bk-upload-form').forEach(function (form) {
        var input = form.querySelector('input[type="file"]');
        var btn = form.querySelector('.mc-bk-upload-go');
        var cancelBtn = form.querySelector('.mc-bk-upload-cancel');
        if (!input || !btn) { return; }
        var idleLabel = btn.innerHTML;
        var aborted = false;
        var uploadId = '';
        btn.addEventListener('click', function () { if (!btn.disabled) { input.click(); } });
        function resetUi() {
            btn.disabled = false; btn.innerHTML = idleLabel; input.value = '';
            if (cancelBtn) { cancelBtn.style.display = 'none'; }
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                // INSTANT: stop the loop now, tell the server to discard the part-file behind the scenes.
                aborted = true;
                var id = uploadId;
                resetUi();
                if (id) {
                    var fd = new FormData();
                    fd.append('cancel', '1'); fd.append('upload_id', id);
                    fetch(CFG.root + 'upload-backup', { method: 'POST', headers: { 'X-WP-Nonce': CFG.nonce }, credentials: 'same-origin', body: fd });
                }
            });
        }
        input.addEventListener('change', function () {
            if (!input.files.length) { return; }
            var file = input.files[0];
            var scope = form.getAttribute('data-scope') || 'library';
            var max = parseInt(CFG.maxUpload, 10) || 2097152;
            var chunk = Math.max(262144, Math.min(1572864, Math.floor(max / 2)));
            var offset = 0;
            uploadId = '';
            aborted = false;
            btn.disabled = true;
            if (cancelBtn) { cancelBtn.style.display = ''; }
            function fail(msg) {
                resetUi();
                alert(msg || 'Upload failed. Please try again.');
            }
            function step() {
                if (aborted) { return; }
                var end = Math.min(file.size, offset + chunk);
                var done = end >= file.size ? 1 : 0;
                var fd = new FormData();
                fd.append('chunk', file.slice(offset, end), 'chunk.bin');
                fd.append('scope', scope);
                fd.append('name', file.name);
                fd.append('upload_id', uploadId);
                fd.append('done', done);
                fetch(CFG.root + 'upload-backup', { method: 'POST', headers: { 'X-WP-Nonce': CFG.nonce }, credentials: 'same-origin', body: fd })
                    .then(function (r) {
                        if (aborted) { return null; }
                        if (r.status === 413) {
                            // Too big for this server — halve and retry the SAME offset.
                            if (chunk > 262144) { chunk = Math.floor(chunk / 2); step(); }
                            else { fail('The server rejected the upload (413) even at the smallest chunk size.'); }
                            return null;
                        }
                        return r.json().then(function (j) { return { ok: r.ok, data: j }; });
                    })
                    .then(function (res) {
                        if (!res || aborted) { return; }
                        if (!res.ok) { fail(res.data && res.data.message); return; }
                        uploadId = res.data.upload_id || uploadId;
                        offset = end;
                        if (res.data.complete) {
                            btn.textContent = 'Uploaded';
                            location.reload();
                        } else {
                            btn.textContent = 'Uploading... ' + Math.round(100 * offset / file.size) + '%';
                            step();
                        }
                    })
                    .catch(function () { if (!aborted) { fail(); } });
            }
            btn.textContent = 'Uploading... 0%';
            step();
        });
    });

    /* ---- Review grid. ---- */
    var review = document.getElementById('mc-review');
    if (review) {
        var grid = document.getElementById('mc-grid');
        var empty = document.getElementById('mc-empty');
        var pager = document.getElementById('mc-pager');
        var statusDD = document.getElementById('mc-filter-status');
        var largeChk = document.getElementById('mc-filter-large');
        var sortDD = document.getElementById('mc-sort');
        var perPageDD = document.getElementById('mc-per-page');
        var pageBar = document.getElementById('mc-pagebar');
        var selectAll = document.getElementById('mc-select-all');
        var scanId = parseInt(review.getAttribute('data-scan'), 10) || 0;
        var diskScanId = parseInt(review.getAttribute('data-scan-disk'), 10) || 0;
        var largeBytes = parseInt(review.getAttribute('data-large'), 10) || 0;
        var preset = review.getAttribute('data-preset') || '';
        var page = 1;
        var selected = {};

        function currentStatus() { return statusDD ? (statusDD.getAttribute('data-value') || 'unused') : 'unused'; }
        // Two tools, two scans: the 'orphan' bucket lives in the disk scan, everything else
        // in the library scan.
        function currentScanId() { return currentStatus() === 'orphan' ? diskScanId : scanId; }

        function load() {
            var sid = currentScanId();
            var exportBtn = document.getElementById('mc-export-btn');
            if (exportBtn && sid) {
                exportBtn.href = exportBtn.href.replace(/([?&]scan_id=)\d+/, '$1' + sid);
            }
            if (!sid) {
                grid.innerHTML = '';
                empty.style.display = 'block';
                pager.innerHTML = '';
                if (pageBar) { pageBar.style.display = 'none'; }
                return;
            }
            var params = new URLSearchParams();
            params.set('scan_id', sid);
            params.set('status', currentStatus());
            params.set('page', page);
            params.set('per_page', perPageDD ? (parseInt(perPageDD.getAttribute('data-value'), 10) || 20) : 20);
            var sort = sortDD ? (sortDD.getAttribute('data-value') || 'size_desc') : 'size_desc';
            var orderby = 'file_size';
            if (sort.indexOf('date') === 0) { orderby = 'upload_date'; }
            else if (sort.indexOf('dim') === 0) { orderby = 'dimensions'; }
            params.set('orderby', orderby);
            params.set('order', sort.indexOf('_asc') !== -1 ? 'ASC' : 'DESC');
            if (largeChk && largeChk.checked && largeBytes) { params.set('min_bytes', largeBytes); }
            // Filename search: each side (Library/Disk) keeps its own box + query.
            var side = currentStatus() === 'orphan' ? 'disk' : 'library';
            var searchIn = document.querySelector('.mc-search[data-side-group="' + side + '"] .mc-search-in');
            if (searchIn && searchIn.value.trim() !== '') { params.set('search', searchIn.value.trim()); }
            grid.innerHTML = '<div class="dd-hint" style="padding:20px;">' + (I18N.scanning || 'Loading...') + '</div>';
            api('scan-results?' + params.toString()).then(function (res) {
                render(res.data || {});
            });
        }

        function badge(item) {
            // Orphans all share one identical state (no library record) — the filter already
            // says it, so a badge on every one of 7,000 cards is pure noise.
            if (item.status === 'orphan') { return ''; }
            var cls = 'mc-badge-warn', txt = item.conf_label;
            if (item.status === 'used') { cls = 'mc-badge-prot'; }
            else if (item.conf_label === 'Safe to remove') { cls = 'mc-badge-safe'; }
            return '<span class="mc-badge ' + cls + '">' + esc(txt) + '</span>';
        }

        function esc(s) { var d = document.createElement('div'); d.textContent = (s === null || s === undefined) ? '' : String(s); return d.innerHTML; }

        function render(data) {
            grid.innerHTML = '';
            var items = data.items || [];
            if (!items.length) {
                empty.style.display = 'block';
                pager.innerHTML = '';
                if (pageBar) { pageBar.style.display = 'none'; }
                return;
            }
            empty.style.display = 'none';
            items.forEach(function (it) {
                var card = document.createElement('div');
                card.className = 'mc-card' + (selected[it.id] ? ' is-selected' : '');
                var reasons = (it.reasons || []).slice(0, 3).map(esc).join(' · ');
                var canSelect = (it.status === 'unused' || it.status === 'uncertain' || it.status === 'duplicate' || it.status === 'orphan');
                card.innerHTML =
                    (canSelect ? '<input type="checkbox" class="dd-check mc-check"' + (selected[it.id] ? ' checked' : '') + '>' : '') +
                    '<div class="mc-thumb">' + (it.thumb ? '<img loading="lazy" src="' + esc(it.thumb) + '" alt="">' : '<span class="dashicons dashicons-format-image" style="font-size:36px;color:#cbd5e1;"></span>') + '</div>' +
                    '<div class="mc-meta">' +
                        '<div class="mc-fn" title="' + esc(it.filename) + '">' + esc(it.filename) + '</div>' +
                        '<div class="mc-sub">' + esc(it.size_h) + (it.width ? ' · ' + it.width + '×' + it.height : '') + '</div>' +
                        '<div class="mc-sub">' + esc((it.date || '').substring(0, 10)) + ' · #' + it.attachment_id + '</div>' +
                        (badge(it) ? '<div style="margin-top:6px;">' + badge(it) + '</div>' : '') +
                        (reasons ? '<div class="mc-reasons">' + reasons + '</div>' : '') +
                    '</div>' +
                    '<div class="mc-cardactions">' +
                        (it.edit_url ? '<a class="dd-btn dd-btn-sm" target="_blank" rel="noopener" href="' + esc(it.edit_url) + '">Edit</a>' : '') +
                        (it.url ? '<a class="dd-btn dd-btn-sm" target="_blank" rel="noopener" href="' + esc(it.url) + '">View</a>' : '') +
                    '</div>';
                var chk = card.querySelector('.mc-check');
                if (chk) {
                    chk.addEventListener('change', function () {
                        if (chk.checked) { selected[it.id] = it.attachment_id || 0; card.classList.add('is-selected'); }
                        else { delete selected[it.id]; card.classList.remove('is-selected'); }
                        updateSelCount();
                    });
                }
                grid.appendChild(card);
            });
            renderPager(data);
        }

        function renderPager(data) {
            pager.innerHTML = '';
            var pages = data.pages || 1;
            var per = data.per_page || (perPageDD ? (parseInt(perPageDD.getAttribute('data-value'), 10) || 20) : 20);
            var total = data.total || 0;
            // Left side: "Showing X–Y of Z".
            if (pageBar) {
                pageBar.style.display = 'flex';
                var range = document.getElementById('mc-range');
                if (range) {
                    var from = (data.page - 1) * per + 1;
                    var to = Math.min(total, data.page * per);
                    range.textContent = (I18N.showing || 'Showing') + ' ' + from.toLocaleString() + '–' + to.toLocaleString() + ' ' + (I18N.of || 'of') + ' ' + total.toLocaleString();
                }
            }
            if (pages <= 1) { return; }
            var mk = function (label, target, disabled, active) {
                var b = document.createElement('button');
                b.className = 'dd-btn dd-btn-sm mc-page-btn' + (active ? ' dd-btn-primary' : '');
                b.textContent = label;
                b.disabled = !!disabled;
                b.addEventListener('click', function () { page = target; load(); window.scrollTo(0, 0); });
                return b;
            };
            pager.appendChild(mk('‹', page - 1, page <= 1));
            // Numbered window: 1 ... p-1 p p+1 ... N (same pattern as the other DevDome plugins).
            var nums = [];
            for (var i = 1; i <= pages; i++) {
                if (i === 1 || i === pages || Math.abs(i - page) <= 2) { nums.push(i); }
                else if (nums[nums.length - 1] !== '…') { nums.push('…'); }
            }
            nums.forEach(function (n) {
                if (n === '…') {
                    var dots = document.createElement('span');
                    dots.className = 'dd-hint';
                    dots.textContent = '…';
                    pager.appendChild(dots);
                } else {
                    pager.appendChild(mk(String(n), n, false, n === page));
                }
            });
            pager.appendChild(mk('›', page + 1, page >= pages));
        }

        function updateSelCount() {
            var el = document.getElementById('mc-sel-count');
            if (el) {
                var n = Object.keys(selected).length;
                el.textContent = n ? n + ' ' + (I18N.selectedLbl || 'selected') : '';
            }
        }

        if (statusDD) {
            // Library/Disk switch + per-side sub-pills. The hidden #mc-filter-status keeps
            // the current status value for load()/currentScanId().
            var setStatus = function (val) {
                statusDD.setAttribute('data-value', val);
                document.querySelectorAll('.mc-sub-pills .mc-pill-btn').forEach(function (b) {
                    b.classList.toggle('is-on', b.getAttribute('data-value') === val);
                });
                page = 1; selected = {}; updateSelCount(); load();
            };
            document.querySelectorAll('.mc-swch-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    document.querySelectorAll('.mc-swch-btn').forEach(function (b) { b.classList.toggle('is-on', b === btn); });
                    var side = btn.getAttribute('data-side');
                    document.querySelectorAll('.mc-sub-pills, .mc-search').forEach(function (g) {
                        g.style.display = g.getAttribute('data-side-group') === side ? '' : 'none';
                    });
                    setStatus(side === 'disk' ? 'orphan' : 'unused');
                });
            });
            document.querySelectorAll('.mc-pill-btn').forEach(function (b) {
                b.addEventListener('click', function () { setStatus(b.getAttribute('data-value')); });
            });
            // Debounced live search (350ms after the last keystroke).
            document.querySelectorAll('.mc-search-in').forEach(function (inp) {
                var t;
                inp.addEventListener('input', function () {
                    clearTimeout(t);
                    t = setTimeout(function () { page = 1; load(); }, 350);
                });
            });
        }
        if (largeChk) { largeChk.addEventListener('change', function () { page = 1; load(); }); }
        [sortDD, perPageDD].forEach(function (dd) {
            if (dd) { dd.addEventListener('dd:change', function () { page = 1; load(); }); }
        });

        // Catalogue / list view switch (remembered per user).
        var viewBtns = document.querySelectorAll('.mc-view-btn');
        function setView(v) {
            grid.classList.toggle('is-list', v === 'list');
            viewBtns.forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-view') === v); });
            try { localStorage.setItem('devdsame_view', v); } catch (e) { }
        }
        viewBtns.forEach(function (b) {
            b.addEventListener('click', function () { setView(b.getAttribute('data-view')); });
        });
        try {
            var savedView = localStorage.getItem('devdsame_view');
            if (savedView === 'list') { setView('list'); }
        } catch (e) { }
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                grid.querySelectorAll('.mc-check').forEach(function (c) { c.checked = selectAll.checked; c.dispatchEvent(new Event('change')); });
            });
        }

        function selectedItemIds() { return Object.keys(selected).map(function (k) { return parseInt(k, 10); }); }
        function selectedAttachIds() { return Object.keys(selected).map(function (k) { return parseInt(selected[k], 10); }).filter(Boolean); }

        var trashBtn = document.getElementById('mc-trash-btn');
        if (trashBtn) {
            trashBtn.addEventListener('click', function () {
                var ids = selectedItemIds();
                if (!ids.length) { alert(I18N.noResults ? 'Select images first.' : 'Select images first.'); return; }
                if (!confirm(I18N.confirmTrash || 'Move to Recycle Bin?')) { return; }
                trashBtn.disabled = true;
                api('trash', 'POST', { item_ids: ids }).then(function (res) {
                    if (!res.ok) { alert((res.data && res.data.message) || 'Could not start.'); trashBtn.disabled = false; return; }
                    poll(function () {
                        // Reload back onto the SAME bucket — never jump sides because it emptied.
                        var u = new URL(window.location.href);
                        u.searchParams.set('mc_tab', 'review');
                        u.searchParams.set('filter', currentStatus());
                        window.location.href = u.toString();
                    });
                });
            });
        }
        function protectAction(mode) {
            var ids = selectedAttachIds();
            if (!ids.length) { alert('Select images first.'); return; }
            api('protect', 'POST', { attachment_ids: ids, mode: mode }).then(function () { selected = {}; load(); });
        }
        var protectBtn = document.getElementById('mc-protect-btn');
        if (protectBtn) { protectBtn.addEventListener('click', function () { protectAction('protect'); }); }
        var ignoreBtn = document.getElementById('mc-ignore-btn');
        if (ignoreBtn) { ignoreBtn.addEventListener('click', function () { protectAction('ignore'); }); }

        // Honour a preset from the dashboard (large unused).
        if (preset === 'large' && largeChk) { largeChk.checked = true; }
        load();
    }
})();
