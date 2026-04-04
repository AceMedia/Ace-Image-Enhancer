/* global aceAttachTools */
(function () {
    'use strict';

    function apiFetch(path, body) {
        return fetch(aceAttachTools.restUrl + path, {
            method:  'POST',
            headers: {
                'X-WP-Nonce':   aceAttachTools.nonce,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(body),
        }).then(function (r) {
            return r.json();
        });
    }

    // ---- Modal helpers ----
    function openModal(modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    function bindModalClose(modal) {
        modal.querySelectorAll('.ace-tool-modal-close, .ace-tool-modal-overlay').forEach(function (el) {
            el.addEventListener('click', function () { closeModal(modal); });
        });
    }

    // ---- Reprocess single ----
    function handleReprocess(btn) {
        var id = btn.dataset.id;
        if (!id) return;

        btn.classList.add('is-busy');
        btn.textContent = 'Reprocessing...';

        apiFetch('reprocess-single', { attachment_id: parseInt(id, 10) }).then(function (data) {
            btn.classList.remove('is-busy');
            if (data && data.success) {
                btn.classList.add('is-done');
                btn.textContent = 'Reprocessed to ' + aceAttachTools.format + ' ✓';
            } else {
                btn.textContent = 'Failed — ' + (data && data.reason ? data.reason : 'error');
            }
        }).catch(function () {
            btn.classList.remove('is-busy');
            btn.textContent = 'Request failed';
        });
    }

    // ---- Rename ----
    function handleRenameConfirm(btn) {
        var id     = btn.dataset.id;
        var input  = document.getElementById('ace-rename-input-' + id);
        var status = document.getElementById('ace-rename-status-' + id);
        if (!input) return;

        var newName = input.value.trim();
        if (!newName) {
            status.textContent = 'Please enter a filename.';
            status.style.color = '#c0392b';
            return;
        }

        btn.disabled    = true;
        btn.textContent = 'Renaming…';
        status.textContent = '';

        apiFetch('rename', {
            attachment_id: parseInt(id, 10),
            new_name:      newName,
        }).then(function (data) {
            btn.disabled = false;
            if (data && data.success) {
                status.style.color = '#1d8348';
                var refs = data.references_updated || {};
                status.textContent =
                    'Renamed successfully. References updated: ' +
                    (refs.posts || 0) + ' posts, ' +
                    (refs.postmeta || 0) + ' postmeta, ' +
                    (refs.options || 0) + ' options.';
                btn.textContent = 'Rename & Update References';
                // Update input to reflect new name so user can re-rename if needed
                input.dataset.original = newName;
            } else {
                status.style.color = '#c0392b';
                status.textContent = 'Error: ' + (data && data.message ? data.message : 'Unknown error');
                btn.textContent = 'Rename & Update References';
            }
        }).catch(function () {
            btn.disabled    = false;
            btn.textContent = 'Rename & Update References';
            status.style.color = '#c0392b';
            status.textContent = 'Request failed.';
        });
    }

    // ---- SVG editor (kept in attachment-tools so one enqueue handles all) ----
    function handleSvgEdit(btn) {
        var id    = btn.dataset.attachmentId;
        var modal = document.getElementById('ace-svg-editor-modal-' + id);
        if (!modal) return;
        openModal(modal);

        var frame = document.getElementById('ace-svg-editor-frame-' + id);
        if (frame && !frame.src) {
            // Load SVG content via AJAX then set iframe src
            if (typeof aceSvgEditor !== 'undefined') {
                frame.src = aceSvgEditor.svgEditUrl;
            }
        }
    }

    function init() {
        // Reprocess buttons
        document.querySelectorAll('.ace-reprocess-btn').forEach(function (btn) {
            btn.addEventListener('click', function () { handleReprocess(btn); });
        });

        // Rename buttons — open modal
        document.querySelectorAll('.ace-rename-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id    = btn.dataset.id;
                var modal = document.getElementById('ace-rename-modal-' + id);
                if (modal) {
                    bindModalClose(modal);
                    openModal(modal);
                    var input = document.getElementById('ace-rename-input-' + id);
                    if (input) input.focus();
                }
            });
        });

        // Rename confirm buttons
        document.querySelectorAll('.ace-rename-confirm').forEach(function (btn) {
            btn.addEventListener('click', function () { handleRenameConfirm(btn); });
        });

        // Rename input: also confirm on Enter
        document.querySelectorAll('[id^="ace-rename-input-"]').forEach(function (input) {
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    var id  = input.id.replace('ace-rename-input-', '');
                    var btn = document.querySelector('.ace-rename-confirm[data-id="' + id + '"]');
                    if (btn) handleRenameConfirm(btn);
                }
            });
        });

        // SVG edit buttons (only present when SVG editor enabled)
        document.querySelectorAll('.ace-edit-svg').forEach(function (btn) {
            btn.addEventListener('click', function () { handleSvgEdit(btn); });
        });

        // Close all modals on overlay / close-button click (initial bind for modals in DOM at load)
        document.querySelectorAll('.ace-tool-modal').forEach(function (modal) {
            bindModalClose(modal);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
