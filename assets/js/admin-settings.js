function membersForKofiInit() {
    const tabs = document.querySelectorAll('.nav-tab');
    const tabContents = document.querySelectorAll('.members-for-kofi-tab');
    const activeTabInput = document.getElementById('active_tab');
    // Save button now lives only inside the visible general tab container; no JS hide needed.

    tabs.forEach(tab => {
        tab.addEventListener('click', function (e) {
            e.preventDefault();
            const activeTabId = this.dataset.tab;
            if (!activeTabId) return;

            // Update active classes
            tabs.forEach(t => {
                t.classList.remove('nav-tab-active');
                t.setAttribute('aria-selected', 'false');
            });
            this.classList.add('nav-tab-active');
            this.setAttribute('aria-selected', 'true');

            // Toggle content visibility
            tabContents.forEach(content => {
                if (content.id === `members-for-kofi-tab-${activeTabId}`) {
                    content.style.display = '';
                } else {
                    content.style.display = 'none';
                }
            });

            // Hidden input (used only if form submission needs to know last active tab)
            if (activeTabInput) activeTabInput.value = activeTabId;

            // Optionally reflect tab in URL without reload
            if (window.history && window.history.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.set('tab', activeTabId);
                window.history.replaceState({}, '', url.toString());
            }
        });
    });

    // Handle log type selector dropdown
    function handleLogTypeChange() {
        const logTypeSelector = document.getElementById('log-type-selector');
        if (logTypeSelector && !logTypeSelector.dataset.bound) {
            logTypeSelector.dataset.bound = '1';
            logTypeSelector.addEventListener('change', function() {
                const logType = this.value;
                const searchInput = document.getElementById('kofi-members-log-search');
                const rowsPerPage = document.getElementById('rows_per_page')?.value || 10;
                
                // Update the search label based on log type
                const searchLabel = document.querySelector('label[for="kofi-members-log-search"]');
                if (searchLabel) {
                    if (logType === 'request') {
                        searchLabel.textContent = kofiMembers.searchLabelRequest || 'Search (email / tier / status)';
                    } else {
                        searchLabel.textContent = kofiMembers.searchLabelUser || 'Search (email / action / role)';
                    }
                }
                
                // Clear search input
                if (searchInput) searchInput.value = '';
                
                // Reload the logs table with the new log type
                loadLogsWithType(logType, '', 1, rowsPerPage);
            });
        }
    }

    function loadLogsWithType(logType, search, paged, rowsPerPage) {
        fetch(kofiMembers.ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'members_for_kofi_switch_log_type',
                log_type: logType,
                search: search || '',
                paged: paged || 1,
                rows_per_page: rowsPerPage || 10,
                _ajax_nonce: kofiMembers.switchLogTypeNonce
            }),
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const tableContainer = document.querySelector('#logs-table-container');
                    if (tableContainer) {
                        tableContainer.innerHTML = data.data;
                        tableContainer.dataset.logType = logType;
                    }
                    reapplyEventListeners();
                    handleLogTypeChange(); // Re-bind after content update
                } else {
                    alert(data.data || kofiMembers.errorMessage);
                }
            })
            .catch(err => console.error('Log type switch error:', err));
    }

    // No need to toggle save button; it is only present within the general tab container.

    function reapplyEventListeners() {
        // Handle Clear Logs button click
        const clearLogsButton = document.querySelector('button[name="clear_logs"]');
        if (clearLogsButton && !clearLogsButton.dataset.bound) {
            clearLogsButton.dataset.bound = '1';
            clearLogsButton.addEventListener('click', function (e) {
                e.preventDefault();
                const currentSearch = document.getElementById('kofi-members-current-search')?.value || '';
                const logType = document.querySelector('#log-type-selector')?.value || 'user';

                if (confirm(kofiMembers.clearLogsConfirm)) {
                    fetch(kofiMembers.ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'members_for_kofi_clear_logs',
                            _ajax_nonce: kofiMembers.clearLogsNonce,
                            search: currentSearch,
                            log_type: logType,
                        }),
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                document.querySelector('#logs-table-container').innerHTML = data.data;
                                reapplyEventListeners(); // Reapply listeners after DOM update
                                // Don't call handleLogTypeChange() here - dropdown is outside the container
                            } else {
                                alert(data.data || kofiMembers.errorMessage);
                            }
                        })
                        .catch(error => console.error('Error clearing logs:', error));
                }
            });
        }

        // Handle Rows Per Page dropdown change
    const rowsPerPageDropdown = document.querySelector('#rows_per_page');
        if (rowsPerPageDropdown && !rowsPerPageDropdown.dataset.bound) {
            rowsPerPageDropdown.dataset.bound = '1';
            rowsPerPageDropdown.addEventListener('change', function (e) {
                e.preventDefault();

                const rowsPerPage = rowsPerPageDropdown.value;
        const currentSearch = document.getElementById('kofi-members-current-search')?.value || '';
                const logType = document.querySelector('#log-type-selector')?.value || 'user';

                fetch(kofiMembers.ajaxurl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'members_for_kofi_update_rows_per_page',
                        rows_per_page: rowsPerPage,
            search: currentSearch,
                        log_type: logType,
                        _ajax_nonce: kofiMembers.rowsPerPageNonce,
                    }),
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            document.querySelector('#logs-table-container').innerHTML = data.data;
                            reapplyEventListeners(); // Reapply listeners after DOM update
                            // Don't call handleLogTypeChange() here - dropdown is outside the container and doesn't need rebinding
                        } else {
                            alert(data.data || kofiMembers.errorMessage);
                        }
                    })
                    .catch(error => console.error('Error updating rows per page:', error));
            });
        }

        // Search / Reset / Refresh buttons (bind after lazy load and after table updates)
        const searchBtn = document.getElementById('kofi-members-log-search-btn');
        const resetBtn = document.getElementById('kofi-members-log-reset-btn');
        const refreshBtn = document.getElementById('kofi-members-log-refresh-btn');
        const searchInput = document.getElementById('kofi-members-log-search');

        function loadLogs(params) {
            fetch(kofiMembers.ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(params),
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const tableContainer = document.querySelector('#logs-table-container');
                        if (tableContainer) tableContainer.innerHTML = data.data;
                        const hiddenSearch = document.getElementById('kofi-members-current-search');
                        if (hiddenSearch) hiddenSearch.value = params.search || '';
                        reapplyEventListeners();
                        handleLogTypeChange();
                    } else {
                        alert(data.data || kofiMembers.errorMessage);
                    }
                })
                .catch(err => console.error('Log load error:', err));
        }

        if (searchBtn && !searchBtn.dataset.bound) {
            searchBtn.dataset.bound = '1';
            searchBtn.addEventListener('click', function (e) {
                e.preventDefault();
                loadLogs({
                    action: 'members_for_kofi_filter_logs',
                    search: (searchInput?.value || '').trim(),
                    paged: 1,
                    rows_per_page: document.getElementById('rows_per_page')?.value || 10,
                    _ajax_nonce: kofiMembers.filterNonce,
                });
            });
        }
        if (resetBtn && !resetBtn.dataset.bound) {
            resetBtn.dataset.bound = '1';
            resetBtn.addEventListener('click', function (e) {
                e.preventDefault();
                if (searchInput) searchInput.value = '';
                loadLogs({
                    action: 'members_for_kofi_filter_logs',
                    search: '',
                    paged: 1,
                    rows_per_page: document.getElementById('rows_per_page')?.value || 10,
                    _ajax_nonce: kofiMembers.filterNonce,
                });
            });
        }
        if (refreshBtn && !refreshBtn.dataset.bound) {
            refreshBtn.dataset.bound = '1';
            refreshBtn.addEventListener('click', function (e) {
                e.preventDefault();
                const currentSearch = document.getElementById('kofi-members-current-search')?.value || '';
                loadLogs({
                    action: 'members_for_kofi_refresh_logs',
                    search: currentSearch,
                    paged: 1,
                    rows_per_page: document.getElementById('rows_per_page')?.value || 10,
                    _ajax_nonce: kofiMembers.refreshNonce,
                });
            });
        }
    }

    reapplyEventListeners();
    handleLogTypeChange();

    // Handle Pagination link clicks
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.members-for-kofi-page-btn');
        if (!btn) return;
        e.preventDefault();
        const page = btn.getAttribute('data-page');
        const currentSearch = document.getElementById('kofi-members-current-search')?.value || '';
        const logType = document.querySelector('#log-type-selector')?.value || 'user';
        fetch(kofiMembers.ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'members_for_kofi_pagination',
                paged: page,
                search: currentSearch,
                log_type: logType,
                _ajax_nonce: kofiMembers.paginationNonce,
            }),
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.querySelector('#logs-table-container').innerHTML = data.data;
                    reapplyEventListeners();
                    handleLogTypeChange();
                } else {
                    console.error('Error fetching logs:', data.data);
                }
            })
            .catch(err => console.error('Error fetching logs:', err));
    });

    const copyButton = document.getElementById('copy-webhook-url');
    const webhookInput = document.getElementById('webhook-kofi-url');

    if (copyButton && webhookInput) {
        copyButton.addEventListener('click', function () {
            // Select the text in the input field
            webhookInput.select();
            webhookInput.setSelectionRange(0, 99999); // For mobile devices

            // Copy the text to the clipboard
            navigator.clipboard.writeText(webhookInput.value).then(() => {
                alert('Webhook URL copied to clipboard!');
            }).catch(err => {
                console.error('Failed to copy webhook URL:', err);
            });
        });
    }

    // Tier to Role Mapping dynamic rows
    const addTierRoleBtn = document.getElementById('add-tier-role-row');
    const tierRoleTableBody = document.querySelector('#tier-role-map-table tbody');
    const tierRowTemplate = document.getElementById('tier-role-row-template');

    function addTierRoleRow() {
        if (!tierRowTemplate || !tierRoleTableBody) return;
        const html = tierRowTemplate.innerHTML.trim();
        const temp = document.createElement('tbody');
        temp.innerHTML = html;
        const row = temp.firstElementChild;
        if (row) {
            tierRoleTableBody.appendChild(row);
        }
    }

    if (addTierRoleBtn) {
        addTierRoleBtn.addEventListener('click', function (e) {
            e.preventDefault();
            addTierRoleRow();
        });
    }

    // Delegate remove button clicks
    document.addEventListener('click', function (e) {
        const removeBtn = e.target.closest('.remove-tier-role-row');
        if (removeBtn && tierRoleTableBody && tierRoleTableBody.contains(removeBtn)) {
            e.preventDefault();
            const row = removeBtn.closest('tr');
            if (row) {
                row.remove();
            }
        }
    });

}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', membersForKofiInit);
} else {
    // DOM already ready (script loaded late in footer) -> initialize immediately.
    membersForKofiInit();
}

// Auto-activate user logs tab if URL param present (after init attaches listeners)
(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('tab') === 'user_logs') {
        const userLogsTab = document.querySelector('.nav-tab[data-tab="user_logs"]');
        if (userLogsTab) {
            // Defer to allow initial init to finish.
            setTimeout(() => userLogsTab.click(), 0);
        }
    }
})();