<?php
require_once "../config.php";
require_once "../token_auth.php";

// Authenticate user with token
$user_data = TokenAuth::authenticate($conn);
if (!$user_data) {
    header("Location: login.php");
    exit();
}
$user = htmlspecialchars($user_data['username']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Management | Slaughter House Management System</title>

    <!-- Argon Core CSS -->
    <link rel="stylesheet" href="argondashboard/assets/css/argon-dashboard.css">
    <!-- Local Fonts -->
    <link rel="stylesheet" href="argondashboard/assets/css/custom-fonts.css">
    <!-- Nucleo Icons -->
    <link rel="stylesheet" href="argondashboard/assets/css/nucleo-icons.css">
    <link rel="stylesheet" href="argondashboard/assets/css/nucleo-svg.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="assets/logo.svg">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="../css/business_management.css">

    <style>
        :root {
            /* Green theme - White + Green */
            --welcome-bg: linear-gradient(135deg, #059669 0%, #047857 100%);
            --card-bg: #ffffff;
            --text-on-dark: #ffffff;
            --text-muted-dark: #9ca3af;
            --border-dark: rgba(5, 150, 105, 0.2);
            --shadow-dark: rgba(0,0,0,0.1);
            --bg-primary: #f8f9fa;
            --bg-accent: #10b981;
            --bg-secondary: #059669;
            --text-primary: #1f2937;
            --text-muted: #6b7280;
            --border-light: #e5e7eb;
        }

        /* Dark theme overrides - Charcoal Gray + White + Green */
        [data-theme="dark"] {
            --welcome-bg: linear-gradient(135deg, #065f46 0%, #064e3b 100%);
            --card-bg: linear-gradient(135deg, #1f2937 0%, #374151 100%);
            --text-on-dark: #ffffff;
            --text-muted-dark: #9ca3af;
            --border-dark: rgba(16, 185, 129, 0.3);
            --shadow-dark: rgba(0,0,0,0.4);
            --bg-primary: #111827;
            --bg-accent: #10b981;
            --bg-secondary: #059669;
            --text-primary: #f9fafb;
            --text-muted: #9ca3af;
            --border-light: #374151;
        }

        .welcome-card {
            background: var(--welcome-bg);
            color: var(--text-on-dark) !important;
            border-radius: 15px;
            box-shadow: 0 8px 25px var(--shadow-dark);
            border: 1px solid var(--border-dark);
        }

        .welcome-card h2,
        .welcome-card p,
        .welcome-card .opacity-8 {
            color: var(--text-on-dark) !important;
        }

        .welcome-card .text-muted {
            color: var(--text-muted-dark) !important;
        }

        .data-card {
            background: var(--card-bg);
            color: var(--text-primary) !important;
            border-radius: 15px;
            box-shadow: 0 4px 20px var(--shadow-dark);
            border: 1px solid var(--border-light);
        }

        .data-card .card-header {
            background: var(--card-bg);
            border-bottom: 1px solid var(--border-light);
        }

        .table-responsive {
            border-radius: 15px;
            overflow: hidden;
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: var(--bg-accent);
            color: var(--text-on-dark);
            border: none;
            font-weight: 600;
            padding: 15px;
        }

        .table tbody td {
            padding: 15px;
            border-bottom: 1px solid var(--border-light);
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background-color: rgba(16, 185, 129, 0.05);
        }

        .btn-primary {
            background: var(--bg-accent);
            border: 1px solid rgba(255,255,255,0.2);
            color: var(--text-on-dark);
        }

        .btn-primary:hover {
            background: var(--bg-secondary);
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .btn-danger {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .btn-warning {
            background: linear-gradient(135deg, #d69e2e 0%, #b7791f 100%);
            border: 1px solid rgba(255,255,255,0.2);
        }

        /* Enhanced Pagination Styles - Matching client_management.php */
        .pagination .page-link {
            color: #10b981;
            border-color: #e5e7eb;
            background: #ffffff;
            font-weight: 500;
            padding: 0.5rem 0.75rem;
            margin: 0 0.125rem;
            border-radius: 0.375rem !important;
            transition: all 0.2s ease;
        }

        .pagination .page-link:hover {
            background: #ecfdf5;
            border-color: #10b981;
            color: #10b981;
            transform: translateY(-1px);
        }

        .pagination .page-item.active .page-link {
            background: #10b981 !important;
            border-color: #10b981 !important;
            color: white !important;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);
        }

        .pagination .page-item.disabled .page-link {
            color: #6b7280;
            background: #ffffff;
            border-color: #e5e7eb;
            opacity: 0.6;
        }

        .form-control:focus {
            border-color: var(--bg-accent);
            box-shadow: 0 0 0 0.2rem rgba(16, 185, 129, 0.25);
        }

        .modal-content {
            background: var(--card-bg);
            color: var(--text-primary);
        }

        .modal-header {
            border-bottom: 1px solid var(--border-light);
        }

        .modal-footer {
            border-top: 1px solid var(--border-light);
        }

        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            border-radius: 15px;
        }

        .text-primary { color: #10b981 !important; }
        .text-success { color: #10b981 !important; }
        .text-info { color: #06b6d4 !important; }
        .text-warning { color: #f59e0b !important; }
        .text-danger { color: #ef4444 !important; }

        /* Client Search Styles - Matching slaughter_fee_entry.php */
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
    </style>
</head>

<body class="g-sidenav-show" style="background: var(--bg-primary); min-height: 100vh; color: var(--text-primary);">

<?php include 'sidebar.php'; ?>

<main class="main-content position-relative border-radius-lg ps ps--active-y" style="margin-left: 280px; padding: 1.5rem;">
    <div class="container-fluid py-4">

        <!-- Welcome Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="welcome-card p-4">
                    <div class="row align-items-center">
                        <div class="col-lg-2 text-center">
                            <div class="logo-container">
                                <img src="assets/ceedmo_logo.ico" alt="Slaughter House Logo" class="enhanced-logo">
                            </div>
                        </div>
                        <div class="col-lg-7">
                            <h2 class="mb-2"><i class="fas fa-building me-2"></i>Business Management</h2>
                            <p class="mb-1 opacity-8"><i class="fas fa-industry me-2"></i>Slaughter House Management System</p>
                            <p class="mb-0 opacity-8">Manage client businesses, add new businesses, and update existing records.</p>
                        </div>
                        <div class="col-lg-3 text-end">
                            <button class="btn btn-primary btn-lg" onclick="showAddBusinessModal()">
                                <i class="fas fa-plus me-2"></i>Add New Business
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search and Filters -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="data-card card">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    <input type="text" class="form-control" id="searchInput" placeholder="Search businesses by name or client..." onkeyup="handleSearch()">
                                </div>
                            </div>
                            <div class="col-md-6 text-end">
                                <small class="text-muted" id="resultsInfo">Loading...</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Businesses Table -->
        <div class="row">
            <div class="col-12">
                <div class="data-card card position-relative">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-building me-2"></i>Businesses List</h5>
                    </div>
                    <div class="card-body p-0">
                        <div id="loadingOverlay" class="loading-overlay d-none">
                            <div class="text-center">
                                <div class="spinner-border" style="color: #10b981;" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <div class="mt-2">Loading businesses...</div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Business Name</th>
                                        <th>Client Name</th>
                                        <th>Stall Number</th>
                                        <th>Market Place</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="businessesTableBody">
                                    <tr>
                                        <td colspan="6" class="text-center py-5">
                                            <div class="spinner-border" style="color: #10b981;" role="status">
                                                <span class="visually-hidden">Loading...</span>
                                            </div>
                                            <div class="mt-2">Loading businesses...</div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer">
                        <nav aria-label="Businesses pagination">
                            <ul class="pagination justify-content-center mb-0" id="paginationControls"></ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Add/Edit Business Modal -->
<div class="modal fade" id="businessModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="businessModalTitle">Add New Business</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="businessForm">
                    <input type="hidden" id="businessId" name="BID">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="clientSearch" class="form-label">Client *</label>
                            <div class="client-search-container">
                                <input type="text" class="form-control" id="clientSearch" placeholder="Type to search clients..." autocomplete="off" onkeyup="searchClientsInline()" required>
                                <input type="hidden" id="clientSelect" name="CID" required>
                                <div class="client-suggestions" id="clientSuggestions" style="display: none;"></div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="businessName" class="form-label">Business Name *</label>
                            <input type="text" class="form-control text-uppercase" id="businessName" name="Business_Name" required style="text-transform: uppercase;">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="stallNumber" class="form-label">Stall Number</label>
                            <input type="text" class="form-control text-uppercase" id="stallNumber" name="Stall_Number" style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="marketPlace" class="form-label">Market Place</label>
                            <input type="text" class="form-control text-uppercase" id="marketPlace" name="Market_Place" style="text-transform: uppercase;">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveBusiness()">
                    <i class="fas fa-save me-2"></i>Save Business
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this business?</p>
                <p class="text-danger mb-0" id="deleteBusinessName"></p>
                <small class="text-muted">This action cannot be undone.</small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                    <i class="fas fa-trash me-2"></i>Delete Business
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Argon Core JS -->
<script src="argondashboard/assets/js/core/bootstrap.bundle.min.js"></script>
<script src="argondashboard/assets/js/plugins/perfect-scrollbar.min.js"></script>
<script src="argondashboard/assets/js/plugins/smooth-scrollbar.min.js"></script>
<script src="argondashboard/assets/js/argon-dashboard.min.js"></script>

<script src="../api_config.js.php"></script>

<script>
let currentPage = 1;
let currentSearch = '';
let businessesData = [];
let businessToDelete = null;
let clientsData = [];

function showLoading() {
    document.getElementById('loadingOverlay').classList.remove('d-none');
}

function hideLoading() {
    document.getElementById('loadingOverlay').classList.add('d-none');
}

function loadClients() {
    fetch(`${API_BASE_URL}/api_clients.php?limit=1000`) // Large limit to get all clients
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                clientsData = data.data;
                populateClientDropdown();
            }
        })
        .catch(error => {
            console.error('Error loading clients:', error);
        });
}

// Client search functionality - Matching slaughter_fee_entry.php pattern
let searchTimeout;
let currentFocus = -1;

function searchClientsInline() {
    const searchInput = document.getElementById('clientSearch');
    const suggestionsContainer = document.getElementById('clientSuggestions');
    const hiddenInput = document.getElementById('clientSelect');

    const query = searchInput.value.trim();

    // Clear previous timeout
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }

    // Clear hidden input when searching
    hiddenInput.value = '';

    // Remove active class from all suggestions
    const suggestions = suggestionsContainer.querySelectorAll('.client-suggestion');
    suggestions.forEach(suggestion => suggestion.classList.remove('active'));

    if (!query || query.length < 2) {
        hideSuggestions();
        currentFocus = -1;
        return;
    }

    // Set new timeout for debounced search
    searchTimeout = setTimeout(() => {
        performClientSearch(query);
    }, 300);
}

function performClientSearch(query) {
    const suggestionsContainer = document.getElementById('clientSuggestions');

    // Show loading state
    suggestionsContainer.innerHTML = `
        <div class="text-center py-2">
            <div class="spinner-border spinner-border-sm" role="status">
                <span class="visually-hidden">Searching...</span>
            </div>
            <small class="text-muted ms-2">Searching clients...</small>
        </div>
    `;
    suggestionsContainer.style.display = 'block';

    fetch(`${API_BASE_URL}/api_clients.php?search=${encodeURIComponent(query)}&limit=10`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                displayClientSuggestions(data.data);
            } else {
                suggestionsContainer.innerHTML = '<div class="no-suggestions">No clients found</div>';
            }
        })
        .catch(error => {
            console.error('Error searching clients:', error);
            suggestionsContainer.innerHTML = '<div class="no-suggestions">Error searching clients</div>';
        });
}

function displayClientSuggestions(clients) {
    const suggestionsContainer = document.getElementById('clientSuggestions');

    const suggestionsHTML = clients.map(client => `
        <div class="client-suggestion" onclick="selectClient('${client.CID}', '${client.full_name.replace(/'/g, "\\'")}')" onmouseenter="setActiveSuggestion(this)">
            <div class="client-name">${client.full_name}</div>
            <div class="client-details">${client.Address || 'No address'} • ${client.Contact_No || 'No contact'}</div>
        </div>
    `).join('');

    suggestionsContainer.innerHTML = suggestionsHTML;
    suggestionsContainer.style.display = 'block';
    currentFocus = -1;
}

function selectClient(clientId, clientName) {
    const searchInput = document.getElementById('clientSearch');
    const hiddenInput = document.getElementById('clientSelect');
    const suggestionsContainer = document.getElementById('clientSuggestions');

    searchInput.value = clientName;
    hiddenInput.value = clientId;
    hideSuggestions();
    currentFocus = -1;
}

function hideSuggestions() {
    const suggestionsContainer = document.getElementById('clientSuggestions');
    suggestionsContainer.style.display = 'none';
}

function setActiveSuggestion(element) {
    // Remove active class from all suggestions
    const suggestions = document.querySelectorAll('.client-suggestion');
    suggestions.forEach(suggestion => suggestion.classList.remove('active'));

    // Add active class to current suggestion
    element.classList.add('active');
}

function handleClientSearchKeydown(e) {
    const suggestionsContainer = document.getElementById('clientSuggestions');
    const suggestions = suggestionsContainer.querySelectorAll('.client-suggestion');

    if (suggestions.length === 0) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        currentFocus = currentFocus < suggestions.length - 1 ? currentFocus + 1 : 0;
        setActiveSuggestion(suggestions[currentFocus]);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        currentFocus = currentFocus > 0 ? currentFocus - 1 : suggestions.length - 1;
        setActiveSuggestion(suggestions[currentFocus]);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (currentFocus > -1 && suggestions[currentFocus]) {
            suggestions[currentFocus].click();
        }
    } else if (e.key === 'Escape') {
        hideSuggestions();
        currentFocus = -1;
    }
}

// Click outside to close suggestions
document.addEventListener('click', function(e) {
    const searchContainer = document.querySelector('.client-search-container');
    const suggestionsContainer = document.getElementById('clientSuggestions');

    if (!searchContainer.contains(e.target)) {
        hideSuggestions();
        currentFocus = -1;
    }
});

function loadBusinesses(page = 1, search = '') {
    showLoading();
    currentPage = page;
    currentSearch = search;

    const url = new URL(`${API_BASE_URL}/api_client_business.php`);
    url.searchParams.set('page', page);
    url.searchParams.set('limit', 5);
    if (search) {
        url.searchParams.set('search', search);
    }

    fetch(url)
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                businessesData = data.data;
                renderBusinessesTable(data.data);
                renderPagination(data.pagination);
                updateResultsInfo(data.pagination);
            } else {
                showToast('Error loading businesses: ' + data.message, 'danger');
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            showToast('Error loading businesses', 'danger');
        });
}

function renderBusinessesTable(businesses) {
    const tbody = document.getElementById('businessesTableBody');

    if (businesses.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-5">
                    <i class="fas fa-building fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No businesses found</h5>
                    <p class="text-muted">Try adjusting your search criteria or add a new business.</p>
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = businesses.map(business => `
        <tr>
            <td>${business.BID}</td>
            <td>
                <strong>${business.Business_Name}</strong>
            </td>
            <td>${business.client_name}</td>
            <td>${business.Stall_Number || '-'}</td>
            <td>${business.Market_Place || '-'}</td>
            <td>
                <button class="btn btn-sm btn-warning me-2" onclick="editBusiness(${business.BID})" title="Edit">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-sm btn-danger" onclick="deleteBusiness(${business.BID}, '${business.Business_Name}')" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
    `).join('');
}

function renderPagination(pagination) {
    const controls = document.getElementById('paginationControls');
    const { page, pages, total } = pagination;

    if (pages <= 1) {
        controls.innerHTML = '';
        return;
    }

    let html = '';

    // Previous button
    html += `<li class="page-item ${page === 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="loadBusinesses(${page - 1}, '${currentSearch}')">Previous</a>
    </li>`;

    // Page numbers
    for (let i = Math.max(1, page - 2); i <= Math.min(pages, page + 2); i++) {
        html += `<li class="page-item ${i === page ? 'active' : ''}">
            <a class="page-link" href="#" onclick="loadBusinesses(${i}, '${currentSearch}')">${i}</a>
        </li>`;
    }

    // Next button
    html += `<li class="page-item ${page === pages ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="loadBusinesses(${page + 1}, '${currentSearch}')">Next</a>
    </li>`;

    controls.innerHTML = html;
}

function updateResultsInfo(pagination) {
    const { page, limit, total, pages } = pagination;
    const start = (page - 1) * limit + 1;
    const end = Math.min(page * limit, total);

    document.getElementById('resultsInfo').textContent =
        total > 0 ? `Showing ${start}-${end} of ${total} businesses` : 'No businesses found';
}

function handleSearch() {
    const searchValue = document.getElementById('searchInput').value.trim();
    loadBusinesses(1, searchValue);
}

function showAddBusinessModal() {
    document.getElementById('businessModalTitle').textContent = 'Add New Business';
    document.getElementById('businessForm').reset();
    document.getElementById('businessId').value = '';

    // Initialize client search
    document.getElementById('clientSearch').value = '';
    document.getElementById('clientSelect').value = '';
    hideSuggestions();
    currentFocus = -1;

    new bootstrap.Modal(document.getElementById('businessModal')).show();
}

function editBusiness(bid) {
    const business = businessesData.find(b => b.BID === bid);
    if (!business) return;

    document.getElementById('businessModalTitle').textContent = 'Edit Business';
    document.getElementById('businessId').value = business.BID;
    document.getElementById('businessName').value = business.Business_Name || '';
    document.getElementById('stallNumber').value = business.Stall_Number || '';
    document.getElementById('marketPlace').value = business.Market_Place || '';

    // Find the client and set the search inputs
    const client = clientsData.find(c => c.CID === business.CID);
    if (client) {
        document.getElementById('clientSearch').value = client.full_name;
        document.getElementById('clientSelect').value = client.CID;
    }

    hideSuggestions();
    currentFocus = -1;

    new bootstrap.Modal(document.getElementById('businessModal')).show();
}

function saveBusiness() {
    const form = document.getElementById('businessForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    const isEdit = data.BID !== '';

    // Remove empty optional fields
    Object.keys(data).forEach(key => {
        if (data[key] === '') {
            delete data[key];
        }
    });

    const method = isEdit ? 'PUT' : 'POST';
    const url = `${API_BASE_URL}/api_client_business.php`;

    fetch(url, {
        method: method,
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            bootstrap.Modal.getInstance(document.getElementById('businessModal')).hide();
            showToast(result.message, 'success');
            loadBusinesses(currentPage, currentSearch);
        } else {
            showToast('Error: ' + result.message, 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error saving business', 'danger');
    });
}

function deleteBusiness(bid, name) {
    businessToDelete = bid;
    document.getElementById('deleteBusinessName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

function confirmDelete() {
    if (!businessToDelete) return;

    fetch(`${API_BASE_URL}/api_client_business.php?id=${businessToDelete}`, {
        method: 'DELETE'
    })
    .then(response => response.json())
    .then(result => {
        bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
        if (result.success) {
            showToast(result.message, 'success');
            loadBusinesses(currentPage, currentSearch);
        } else {
            showToast('Error: ' + result.message, 'danger');
        }
        businessToDelete = null;
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error deleting business', 'danger');
        businessToDelete = null;
    });
}

function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    toast.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.remove();
    }, 5000);
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    loadClients();
    loadBusinesses();

    // Add keyboard navigation for client search
    const clientSearchInput = document.getElementById('clientSearch');
    if (clientSearchInput) {
        clientSearchInput.addEventListener('keydown', handleClientSearchKeydown);
    }
});
</script>

</body>
</html>