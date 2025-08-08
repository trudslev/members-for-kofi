document.addEventListener('DOMContentLoaded', function () {
    const tabs = document.querySelectorAll('.nav-tab');
    const tabContents = document.querySelectorAll('.members-for-kofi-tab');
    const activeTabInput = document.getElementById('active_tab');
    const saveButton = document.querySelector('input[type="submit"]'); // Select the Save Settings button

    tabs.forEach(tab => {
        tab.addEventListener('click', function (e) {
            e.preventDefault();

            // Remove active class from all tabs
            tabs.forEach(t => t.classList.remove('nav-tab-active'));

            // Hide all tab contents
            tabContents.forEach(content => content.style.display = 'none');

            // Add active class to clicked tab
            this.classList.add('nav-tab-active');

            // Show the corresponding tab content
            const activeTabId = this.getAttribute('href').split('tab=')[1];
            document.getElementById(`members-for-kofi-tab-${activeTabId}`).style.display = '';

            // Update the hidden input field with the active tab
            if (activeTabInput) {
                activeTabInput.value = activeTabId;
            }

            // Show or hide the Save Settings button based on the active tab
            if (activeTabId === 'user_logs' && saveButton) {
                saveButton.style.display = 'none'; // Hide the button on User Logs tab
            } else if (saveButton) {
                saveButton.style.display = ''; // Show the button on other tabs
            }
        });
    });

    // Initial state: Hide the Save Settings button if the active tab is User Logs
    if (activeTabInput && activeTabInput.value === 'user_logs' && saveButton) {
        saveButton.style.display = 'none';
    }

    function reapplyEventListeners() {
        // Handle Clear Logs button click
        const clearLogsButton = document.querySelector('button[name="clear_logs"]');
        if (clearLogsButton) {
            clearLogsButton.addEventListener('click', function (e) {
                e.preventDefault();

                if (confirm(kofiMembers.clearLogsConfirm)) {
                    fetch(kofiMembers.ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'kofi_members_clear_logs',
                            _ajax_nonce: kofiMembers.clearLogsNonce,
                        }),
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                document.querySelector('#logs-table-container').innerHTML = data.data;
                                reapplyEventListeners(); // Reapply listeners after DOM update
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
        if (rowsPerPageDropdown) {
            rowsPerPageDropdown.addEventListener('change', function (e) {
                e.preventDefault();

                const rowsPerPage = rowsPerPageDropdown.value;

                fetch(kofiMembers.ajaxurl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'kofi_members_update_rows_per_page',
                        rows_per_page: rowsPerPage,
                        _ajax_nonce: kofiMembers.rowsPerPageNonce,
                    }),
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            document.querySelector('#logs-table-container').innerHTML = data.data;
                            reapplyEventListeners(); // Reapply listeners after DOM update
                        } else {
                            alert(data.data || kofiMembers.errorMessage);
                        }
                    })
                    .catch(error => console.error('Error updating rows per page:', error));
            });
        }
    }

    reapplyEventListeners();

    // Handle Pagination link clicks
    document.addEventListener('click', function (e) {
        const target = e.target;

        if (target.closest('.pagination-links a')) {
            e.preventDefault();

            const pageLink = target.closest('.pagination-links a');
            const page = new URL(pageLink.href).searchParams.get('paged');

            fetch(kofiMembers.ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'kofi_members_pagination',
                    paged: page,
                    _ajax_nonce: kofiMembers.paginationNonce,
                }),
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.querySelector('#logs-table-container').innerHTML = data.data;
                        reapplyEventListeners(); // Reapply listeners after DOM update
                    } else {
                        console.error('Error fetching logs:', data.data);
                    }
                })
                .catch(error => console.error('Error fetching logs:', error));
        }
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
});