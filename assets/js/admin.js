/**
 * Sitevero Admin Dashboard JavaScript
 * Handles tabs, async capability toggles, rollback confirmations, and clipboard actions.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initTabs();
        initCapabilityToggles();
        initRollbackButtons();
        initCopyConfig();
    });

    /**
     * Tab Navigation Switching
     */
    function initTabs() {
        const buttons = document.querySelectorAll('.sitevero-tab-button');
        const panels = document.querySelectorAll('.sitevero-tab-panel');

        if (!buttons.length) return;

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                const targetId = this.getAttribute('data-target');

                buttons.forEach(btn => btn.classList.remove('active'));
                panels.forEach(panel => panel.classList.remove('active'));

                this.classList.add('active');
                const targetPanel = document.getElementById(targetId);
                if (targetPanel) {
                    targetPanel.classList.add('active');
                }

                if (window.sessionStorage) {
                    sessionStorage.setItem('sitevero_active_tab', targetId);
                }
            });
        });

        // Restore active tab from session storage if present
        if (window.sessionStorage) {
            const savedTab = sessionStorage.getItem('sitevero_active_tab');
            if (savedTab) {
                const savedBtn = document.querySelector(`.sitevero-tab-button[data-target="${savedTab}"]`);
                if (savedBtn) {
                    savedBtn.click();
                }
            }
        }
    }

    /**
     * Async Capability Enablement Toggles
     */
    function initCapabilityToggles() {
        const toggles = document.querySelectorAll('.sitevero-cap-toggle');

        toggles.forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                const capId = this.getAttribute('data-cap-id');
                const isEnabled = this.checked ? 1 : 0;
                const nonce = window.siteveroAdminData ? window.siteveroAdminData.nonce : '';

                showToast(`Updating ${capId}...`);

                const formData = new FormData();
                formData.append('action', 'sitevero_toggle_capability');
                formData.append('capability_id', capId);
                formData.append('enabled', isEnabled);
                formData.append('security', nonce);

                fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data && data.success) {
                            showToast(`${capId} is now ${isEnabled ? 'enabled' : 'disabled'}.`);
                        } else {
                            showToast(data.data?.message || 'Failed to update capability.', true);
                            toggle.checked = !toggle.checked;
                        }
                    })
                    .catch(err => {
                        console.error('Sitevero toggle error:', err);
                        showToast('Error updating capability setting.', true);
                        toggle.checked = !toggle.checked;
                    });
            });
        });
    }

    /**
     * Snapshot Rollback Handler with Confirmation Prompt
     */
    function initRollbackButtons() {
        const buttons = document.querySelectorAll('.sitevero-btn-rollback');

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const uuid = this.getAttribute('data-uuid');
                const entityType = this.getAttribute('data-entity-type');
                const entityId = this.getAttribute('data-entity-id');

                const confirmMsg = `Are you sure you want to rollback ${entityType} #${entityId} using snapshot ${uuid}?\n\nThis will restore the entity state captured before the mutation.`;
                if (!window.confirm(confirmMsg)) {
                    return;
                }

                btn.disabled = true;
                btn.textContent = 'Rolling back...';
                showToast(`Triggering rollback for ${uuid}...`);

                const nonce = window.siteveroAdminData ? window.siteveroAdminData.nonce : '';
                const formData = new FormData();
                formData.append('action', 'sitevero_admin_rollback');
                formData.append('snapshot_uuid', uuid);
                formData.append('security', nonce);

                fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data && data.success) {
                            showToast(data.data?.message || 'Rollback completed successfully.');
                            setTimeout(() => {
                                window.location.reload();
                            }, 1000);
                        } else {
                            showToast(data.data?.message || 'Rollback failed.', true);
                            btn.disabled = false;
                            btn.textContent = 'Rollback';
                        }
                    })
                    .catch(err => {
                        console.error('Sitevero rollback error:', err);
                        showToast('An unexpected network error occurred.', true);
                        btn.disabled = false;
                        btn.textContent = 'Rollback';
                    });
            });
        });
    }

    /**
     * Copy Configuration to Clipboard
     */
    function initCopyConfig() {
        const copyBtn = document.getElementById('sitevero-copy-config-btn');
        const codeElement = document.getElementById('sitevero-config-code');

        if (!copyBtn || !codeElement) return;

        copyBtn.addEventListener('click', function () {
            const textToCopy = codeElement.textContent;
            navigator.clipboard.writeText(textToCopy).then(() => {
                const originalText = copyBtn.textContent;
                copyBtn.textContent = 'Copied!';
                showToast('MCP configuration copied to clipboard.');
                setTimeout(() => {
                    copyBtn.textContent = originalText;
                }, 2000);
            }).catch(err => {
                console.error('Clipboard copy failed:', err);
                showToast('Failed to copy to clipboard.', true);
            });
        });
    }

    /**
     * Toast notification display
     */
    function showToast(message, isError) {
        let toast = document.getElementById('sitevero-toast-msg');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'sitevero-toast-msg';
            toast.className = 'sitevero-toast';
            document.body.appendChild(toast);
        }

        toast.textContent = message;
        toast.style.background = isError ? 'var(--sv-danger)' : '#0f172a';
        toast.classList.add('show');

        clearTimeout(toast._timer);
        toast._timer = setTimeout(() => {
            toast.classList.remove('show');
        }, 3000);
    }
})();
