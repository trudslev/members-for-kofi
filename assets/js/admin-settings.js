/**
 * Members for Ko-fi admin page.
 *
 * The tabs, filters and paging are plain links and forms; this only adds
 * what needs a script: copying the webhook URL, adding and removing tier
 * rows, and confirming a log deletion.
 */
(function () {
    'use strict';

    function init() {
        const copyButton = document.getElementById('copy-webhook-url');
        const urlInput = document.getElementById('webhook-kofi-url');
        const copyStatus = document.getElementById('mfk-copy-status');

        if (copyButton && urlInput) {
            copyButton.addEventListener('click', function () {
                const done = function () {
                    if (copyStatus) {
                        copyStatus.textContent = (window.kofiMembers && window.kofiMembers.copied) || 'Copied';
                        window.setTimeout(function () { copyStatus.textContent = ''; }, 3000);
                    }
                };
                urlInput.select();
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(urlInput.value).then(done).catch(function () {
                        document.execCommand('copy');
                        done();
                    });
                } else {
                    document.execCommand('copy');
                    done();
                }
            });
        }

        const tbody = document.querySelector('#tier-role-map-table tbody');
        const template = document.getElementById('tier-role-row-template');

        function addRow(tierName) {
            if (!tbody || !template) {
                return null;
            }
            const row = template.content.firstElementChild.cloneNode(true);
            tbody.appendChild(row);
            const input = row.querySelector('.tier-name-input');
            if (input) {
                if (tierName) {
                    input.value = tierName;
                    const select = row.querySelector('.tier-role-select');
                    if (select) {
                        select.focus();
                    }
                } else {
                    input.focus();
                }
            }
            return row;
        }

        const addButton = document.getElementById('add-tier-role-row');
        if (addButton) {
            addButton.addEventListener('click', function () {
                addRow('');
            });
        }

        document.addEventListener('click', function (event) {
            const remove = event.target.closest('.remove-tier-role-row');
            if (remove && tbody && tbody.contains(remove)) {
                const row = remove.closest('tr');
                if (row) {
                    row.remove();
                }
                if (addButton) {
                    addButton.focus();
                }
                return;
            }

            const unmapped = event.target.closest('.mfk-add-unmapped');
            if (unmapped) {
                addRow(unmapped.getAttribute('data-tier') || '');
                const box = unmapped.closest('.mfk-unmapped');
                unmapped.remove();
                if (box && !box.querySelector('.mfk-add-unmapped')) {
                    box.remove();
                }
            }
        });

        document.querySelectorAll('form[data-mfk-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.getAttribute('data-mfk-confirm'))) {
                    event.preventDefault();
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
