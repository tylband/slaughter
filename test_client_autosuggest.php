<?php
require_once 'config.php';
require_once 'token_auth.php';

// Skip authentication for testing
// $user_data = TokenAuth::authenticate($conn);
// if (!$user_data) {
//     header("Location: system/login.php");
//     exit();
// }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Auto-Suggest Test</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
    .client-search-container {
        position: relative;
    }

    .client-suggestions {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #ddd;
        border-top: none;
        border-radius: 0 0 0.375rem 0.375rem;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        max-height: 200px;
        overflow-y: auto;
        z-index: 1000;
        display: none;
    }

    .client-suggestion {
        padding: 0.75rem 1rem;
        cursor: pointer;
        border-bottom: 1px solid #eee;
        transition: background-color 0.15s ease-in-out;
    }

    .client-suggestion:hover,
    .client-suggestion.active {
        background-color: #f8f9fa;
    }

    .client-suggestion:last-child {
        border-bottom: none;
    }

    .client-name {
        font-weight: 500;
        color: #333;
    }

    .client-details {
        font-size: 0.875rem;
        color: #666;
        margin-top: 0.25rem;
    }

    .no-suggestions {
        padding: 0.75rem 1rem;
        color: #999;
        font-style: italic;
    }

    .test-results {
        margin-top: 2rem;
        padding: 1rem;
        background: #f8f9fa;
        border-radius: 0.5rem;
    }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h1>Client Auto-Suggest Test</h1>

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="clientSearch" class="form-label">Search Clients</label>
                    <div class="client-search-container">
                        <input type="text" class="form-control" id="clientSearch" placeholder="Type to search clients..." autocomplete="off">
                        <input type="hidden" id="clientSelect">
                        <div class="client-suggestions" id="clientSuggestions"></div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Selected Client ID:</label>
                    <input type="text" class="form-control" id="selectedClientId" readonly>
                </div>

                <div class="mb-3">
                    <label class="form-label">Selected Client Name:</label>
                    <input type="text" class="form-control" id="selectedClientName" readonly>
                </div>
            </div>

            <div class="col-md-6">
                <div class="test-results">
                    <h5>Test Results:</h5>
                    <div id="testOutput">Start typing in the search field above to test the auto-suggest functionality...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    // Mock API configuration for testing
    const API_BASE_URL = '';

    // Test clients data (in a real app, this would come from the API)
    let clientsData = [];

    // Initialize the page
    document.addEventListener('DOMContentLoaded', function() {
        loadClients();
        initializeClientAutoSuggest();
        logTest('Page loaded and initialized');
    });

    // Load clients data (mock data for testing)
    function loadClients() {
        // Mock client data for testing
        clientsData = [
            { CID: 1, Firstname: 'John', Middlename: 'Doe', Surname: 'Smith' },
            { CID: 2, Firstname: 'Jane', Middlename: '', Surname: 'Johnson' },
            { CID: 3, Firstname: 'Michael', Middlename: 'Robert', Surname: 'Williams' },
            { CID: 4, Firstname: 'Sarah', Middlename: 'Elizabeth', Surname: 'Brown' },
            { CID: 5, Firstname: 'David', Middlename: 'James', Surname: 'Jones' },
            { CID: 6, Firstname: 'Emily', Middlename: 'Grace', Surname: 'Davis' },
            { CID: 7, Firstname: 'Robert', Middlename: 'Thomas', Surname: 'Miller' },
            { CID: 8, Firstname: 'Lisa', Middlename: 'Marie', Surname: 'Wilson' },
            { CID: 9, Firstname: 'James', Middlename: 'Edward', Surname: 'Moore' },
            { CID: 10, Firstname: 'Jennifer', Middlename: 'Lynn', Surname: 'Taylor' },
            { CID: 11, Firstname: 'William', Middlename: 'Henry', Surname: 'Anderson' },
            { CID: 12, Firstname: 'Michelle', Middlename: 'Ann', Surname: 'Thomas' },
            { CID: 13, Firstname: 'Richard', Middlename: 'Allen', Surname: 'Jackson' },
            { CID: 14, Firstname: 'Amanda', Middlename: 'Nicole', Surname: 'White' },
            { CID: 15, Firstname: 'Joseph', Middlename: 'Matthew', Surname: 'Harris' }
        ];

        logTest(`Loaded ${clientsData.length} mock clients for testing`);
    }

    // Initialize client auto-suggest functionality
    function initializeClientAutoSuggest() {
        const clientSearchInput = document.getElementById('clientSearch');
        const clientSuggestions = document.getElementById('clientSuggestions');
        const clientSelectHidden = document.getElementById('clientSelect');

        if (!clientSearchInput || !clientSuggestions || !clientSelectHidden) {
            logTest('Required elements not found');
            return;
        }

        let searchTimeout;
        let currentFocus = -1;

        // Handle input events
        clientSearchInput.addEventListener('input', function() {
            console.log('Input event triggered, value:', this.value);
            const searchTerm = this.value.trim();

            // Clear previous timeout
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }

            // Set new timeout for debounced search
            searchTimeout = setTimeout(() => {
                if (searchTerm.length >= 1) {
                    showClientSuggestions(searchTerm);
                    logTest(`Searching for: "${searchTerm}"`);
                } else {
                    hideClientSuggestions();
                }
            }, 300);
        });

        // Handle keyboard navigation
        clientSearchInput.addEventListener('keydown', function(e) {
            const suggestions = clientSuggestions.querySelectorAll('.client-suggestion');

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                currentFocus = currentFocus < suggestions.length - 1 ? currentFocus + 1 : 0;
                setActiveSuggestion(suggestions, currentFocus);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                currentFocus = currentFocus > 0 ? currentFocus - 1 : suggestions.length - 1;
                setActiveSuggestion(suggestions, currentFocus);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (currentFocus > -1 && suggestions[currentFocus]) {
                    suggestions[currentFocus].click();
                    logTest('Client selected via keyboard navigation');
                }
            } else if (e.key === 'Escape') {
                hideClientSuggestions();
                logTest('Suggestions hidden via Escape key');
            }
        });

        // Handle clicks outside to close suggestions
        document.addEventListener('click', function(e) {
            if (!clientSearchInput.contains(e.target) && !clientSuggestions.contains(e.target)) {
                hideClientSuggestions();
            }
        });

        logTest('Auto-suggest functionality initialized');
    }

    // Show client suggestions based on search term
    function showClientSuggestions(searchTerm) {
        const clientSuggestions = document.getElementById('clientSuggestions');
        const clientSearchInput = document.getElementById('clientSearch');

        if (!clientSuggestions || !clientSearchInput) return;

        // Filter clients based on search term
        const filteredClients = clientsData.filter(client => {
            const fullName = `${client.Firstname} ${client.Middlename || ''} ${client.Surname}`.trim().toLowerCase();
            const searchLower = searchTerm.toLowerCase();
            return fullName.includes(searchLower);
        });

        // Create suggestions HTML
        let suggestionsHTML = '';

        if (filteredClients.length > 0) {
            filteredClients.forEach((client, index) => {
                const fullName = `${client.Firstname} ${client.Middlename || ''} ${client.Surname}`.trim();
                suggestionsHTML += `
                    <div class="client-suggestion" data-client-id="${client.CID}" onclick="selectClient('${client.CID}', '${fullName.replace(/'/g, "\\'")}')">
                        <div class="client-name">${highlightSearchTerm(fullName, searchTerm)}</div>
                    </div>
                `;
            });
            logTest(`Found ${filteredClients.length} matching clients`);
        } else {
            suggestionsHTML = '<div class="no-suggestions">No clients found</div>';
            logTest('No clients found matching search term');
        }

        clientSuggestions.innerHTML = suggestionsHTML;
        clientSuggestions.style.display = 'block';
    }

    // Hide client suggestions
    function hideClientSuggestions() {
        const clientSuggestions = document.getElementById('clientSuggestions');
        if (clientSuggestions) {
            clientSuggestions.style.display = 'none';
        }
    }

    // Set active suggestion for keyboard navigation
    function setActiveSuggestion(suggestions, index) {
        suggestions.forEach((suggestion, i) => {
            if (i === index) {
                suggestion.classList.add('active');
            } else {
                suggestion.classList.remove('active');
            }
        });
    }

    // Select a client from suggestions
    function selectClient(clientId, clientName) {
        const clientSearchInput = document.getElementById('clientSearch');
        const clientSelectHidden = document.getElementById('clientSelect');
        const selectedClientIdInput = document.getElementById('selectedClientId');
        const selectedClientNameInput = document.getElementById('selectedClientName');
        const clientSuggestions = document.getElementById('clientSuggestions');

        if (clientSearchInput && clientSelectHidden) {
            clientSearchInput.value = clientName;
            clientSelectHidden.value = clientId;

            // Update display fields
            if (selectedClientIdInput) selectedClientIdInput.value = clientId;
            if (selectedClientNameInput) selectedClientNameInput.value = clientName;

            // Hide suggestions
            hideClientSuggestions();

            // Remove focus from input
            clientSearchInput.blur();

            logTest(`Selected client: ${clientName} (ID: ${clientId})`);
        }
    }

    // Highlight search term in results
    function highlightSearchTerm(text, searchTerm) {
        if (!searchTerm) return text;

        const regex = new RegExp(`(${searchTerm})`, 'gi');
        return text.replace(regex, '<strong>$1</strong>');
    }

    // Log test results
    function logTest(message) {
        const testOutput = document.getElementById('testOutput');
        if (testOutput) {
            const timestamp = new Date().toLocaleTimeString();
            testOutput.innerHTML += `<div>[${timestamp}] ${message}</div>`;
            testOutput.scrollTop = testOutput.scrollHeight; // Auto-scroll to bottom
        }
        console.log(`[${new Date().toLocaleTimeString()}] ${message}`);
    }
    </script>
</body>
</html>