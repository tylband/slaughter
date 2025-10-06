<?php
require_once '../config.php';
require_once '../token_auth.php';

// Authenticate user with token
$user_data = TokenAuth::authenticate($conn);
if (!$user_data) {
    header("Location: login.php");
    exit();
}

$user = htmlspecialchars($user_data['username']);
$user_id = $user_data['user_id'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Slaughter Operations | Slaughter House Management System</title>

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

    <style>
        :root {
            /* Green theme - White + Green */
            --welcome-bg: linear-gradient(135deg, #059669 0%, #047857 100%);
            --card-bg: #ffffff;
            --text-on-dark: #ffffff;
            --text-muted-dark: #9ca3af;
            --border-dark: rgba(5, 150, 105, 0.2);
            --shadow-dark: rgba(0,0,0,0.1);
        }

        /* Dark theme overrides - Charcoal Gray + White + Green */
        [data-theme="dark"] {
            --welcome-bg: linear-gradient(135deg, #065f46 0%, #064e3b 100%);
            --card-bg: linear-gradient(135deg, #1f2937 0%, #374151 100%);
            --text-on-dark: #ffffff;
            --text-muted-dark: #9ca3af;
            --border-dark: rgba(16, 185, 129, 0.3);
            --shadow-dark: rgba(0,0,0,0.4);
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

        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 500;
        }

        .pagination .page-link {
            color: var(--bg-accent);
            border-color: var(--border-light);
        }

        .pagination .page-item.active .page-link {
            background: var(--bg-accent);
            border-color: var(--bg-accent);
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

        .animal-detail-row {
            background: rgba(16, 185, 129, 0.05);
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
        }

        .fee-summary {
            background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
            padding: 15px;
            border-radius: 10px;
            margin-top: 15px;
        }

        /* Uniform Enhanced Logo Styling */
        .logo-container {
            display: inline-block;
            padding: 10px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.15) 0%, rgba(255, 255, 255, 0.05) 100%);
            border: 2px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }

        .logo-container::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.15), transparent);
            transform: rotate(45deg);
            transition: all 0.6s ease;
            opacity: 0;
        }

        .logo-container::after {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: 50%;
            background: linear-gradient(45deg, rgba(255, 255, 255, 0.1), transparent, rgba(255, 255, 255, 0.1));
            z-index: -1;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .logo-container:hover {
            transform: scale(1.08) translateY(-2px);
            border-color: rgba(255, 255, 255, 0.5);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.25) 0%, rgba(255, 255, 255, 0.1) 100%);
            box-shadow:
                0 12px 30px rgba(0, 0, 0, 0.15),
                0 0 30px rgba(255, 255, 255, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }

        .logo-container:hover::before {
            opacity: 1;
            animation: shine 0.8s ease-in-out;
        }

        .logo-container:hover::after {
            opacity: 1;
        }

        .enhanced-logo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.15));
            object-fit: contain;
            position: relative;
            z-index: 2;
        }

        .logo-container:hover .enhanced-logo {
            transform: rotate(8deg) scale(1.05);
            filter: drop-shadow(0 8px 20px rgba(0, 0, 0, 0.25));
        }

        @keyframes shine {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            50% { opacity: 0.8; }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }

        /* Responsive logo adjustments */
        @media (max-width: 768px) {
            .enhanced-logo {
                width: 65px;
                height: 65px;
            }
            .logo-container {
                padding: 8px;
            }
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
                            <h2 class="mb-2"><i class="fas fa-cut me-2"></i>Slaughter Operations</h2>
                            <p class="mb-1 opacity-8"><i class="fas fa-industry me-2"></i>Slaughter House Management System</p>
                            <p class="mb-0 opacity-8">Manage slaughter operations, record animal details, and track fees.</p>
                        </div>
                        <div class="col-lg-3 text-end">
                            <button class="btn btn-primary btn-lg" onclick="showAddSlaughterModal()">
                                <i class="fas fa-plus me-2"></i>Add New Operation
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
                            <div class="col-md-3">
                                <label for="searchInput" class="form-label">Search</label>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search operations..." onkeyup="handleSearch()">
                            </div>
                            <div class="col-md-2">
                                <label for="dateFrom" class="form-label">Date From</label>
                                <input type="date" class="form-control" id="dateFrom" onchange="handleSearch()">
                            </div>
                            <div class="col-md-2">
                                <label for="dateTo" class="form-label">Date To</label>
                                <input type="date" class="form-control" id="dateTo" onchange="handleSearch()">
                            </div>
                            <div class="col-md-2">
                                <label for="clientFilter" class="form-label">Client</label>
                                <select class="form-control" id="clientFilter" onchange="handleSearch()">
                                    <option value="">All Clients</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="businessFilter" class="form-label">Business</label>
                                <select class="form-control" id="businessFilter" onchange="handleSearch()">
                                    <option value="">All Businesses</option>
                                </select>
                            </div>
                            <div class="col-md-1">
                                <label class="form-label">&nbsp;</label>
                                <button class="btn btn-secondary w-100" onclick="clearFilters()">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-12 text-end">
                                <small class="text-muted" id="resultsInfo">Loading...</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Operations Table -->
        <div class="row">
            <div class="col-12">
                <div class="data-card card position-relative">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Slaughter Operations</h5>
                    </div>
                    <div class="card-body p-0">
                        <div id="loadingOverlay" class="loading-overlay d-none">
                            <div class="text-center">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <div class="mt-2">Loading operations...</div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Client</th>
                                        <th>Business</th>
                                        <th>Date</th>
                                        <th>Code</th>
                                        <th>Details</th>
                                        <th>Total Fees</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="operationsTableBody">
                                    <tr>
                                        <td colspan="8" class="text-center py-5">
                                            <div class="spinner-border text-primary" role="status">
                                                <span class="visually-hidden">Loading...</span>
                                            </div>
                                            <div class="mt-2">Loading operations...</div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer">
                        <nav aria-label="Operations pagination">
                            <ul class="pagination justify-content-center mb-0" id="paginationControls"></ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Add/Edit Slaughter Operation Modal -->
<div class="modal fade" id="slaughterModal" tabindex="-1" size="xl">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="slaughterModalTitle">Add New Slaughter Operation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="slaughterForm">
                    <input type="hidden" id="slaughterId" name="SID">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="clientSelect" class="form-label">Client *</label>
                            <select class="form-control" id="clientSelect" name="CID" required onchange="loadClientBusinesses()">
                                <option value="">Select Client</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="businessSelect" class="form-label">Business</label>
                            <select class="form-control" id="businessSelect" name="BID">
                                <option value="">Select Business (Optional)</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="slaughterDate" class="form-label">Slaughter Date & Time *</label>
                            <input type="datetime-local" class="form-control" id="slaughterDate" name="Slaughter_Date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="codeMarking" class="form-label">Code Marking</label>
                            <select class="form-control" id="codeMarking" name="MID">
                                <option value="">Select Code (Optional)</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="form-label">Animal Details</label>
                            <div id="animalDetailsContainer">
                                <!-- Dynamic animal detail rows will be added here -->
                            </div>
                            <button type="button" class="btn btn-success btn-sm mt-2" onclick="addAnimalDetail()">
                                <i class="fas fa-plus me-1"></i>Add Animal
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveSlaughterOperation()">
                    <i class="fas fa-save me-2"></i>Save Operation
                </button>
            </div>
        </div>
    </div>
</div>

<!-- View Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" size="lg">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Slaughter Operation Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="detailsContent">
                    <!-- Details will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
                <p>Are you sure you want to delete this slaughter operation?</p>
                <p class="text-danger mb-0" id="deleteOperationInfo"></p>
                <small class="text-muted">This action cannot be undone and will also delete all associated animal details.</small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                    <i class="fas fa-trash me-2"></i>Delete Operation
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
let currentDateFrom = '';
let currentDateTo = '';
let currentClientId = 0;
let currentBusinessId = 0;
let operationsData = [];
let clientsData = [];
let businessesData = [];
let animalsData = [];
let codesData = [];
let operationToDelete = null;
let animalDetailIndex = 0;

function showLoading() {
    document.getElementById('loadingOverlay').classList.remove('d-none');
}

function hideLoading() {
    document.getElementById('loadingOverlay').classList.add('d-none');
}

function loadOperations(page = 1, search = '', dateFrom = '', dateTo = '', clientId = 0, businessId = 0) {
    showLoading();
    currentPage = page;
    currentSearch = search;
    currentDateFrom = dateFrom;
    currentDateTo = dateTo;
    currentClientId = clientId;
    currentBusinessId = businessId;

    const url = new URL(`${API_BASE_URL}/api_slaughter.php`);
    url.searchParams.set('page', page);
    url.searchParams.set('limit', 10);
    if (search) url.searchParams.set('search', search);
    if (dateFrom) url.searchParams.set('date_from', dateFrom);
    if (dateTo) url.searchParams.set('date_to', dateTo);
    if (clientId > 0) url.searchParams.set('client_id', clientId);
    if (businessId > 0) url.searchParams.set('business_id', businessId);

    apiCall(url)
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                operationsData = data.data;
                renderOperationsTable(data.data);
                renderPagination(data.pagination);
                updateResultsInfo(data.pagination);
            } else {
                showToast('Error loading operations: ' + data.message, 'danger');
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            showToast('Error loading operations', 'danger');
        });
}

function renderOperationsTable(operations) {
    const tbody = document.getElementById('operationsTableBody');

    if (operations.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" class="text-center py-5">
                    <i class="fas fa-cut fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No operations found</h5>
                    <p class="text-muted">Try adjusting your search criteria or add a new operation.</p>
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = operations.map(operation => `
        <tr>
            <td>${operation.SID}</td>
            <td>
                <strong>${operation.client_name}</strong>
            </td>
            <td>${operation.Business_Name || '-'}</td>
            <td>${operation.slaughter_date_formatted}</td>
            <td>${operation.CODE || '-'}</td>
            <td>
                <span class="badge bg-info">${operation.details_count} animals</span>
            </td>
            <td>
                <strong class="text-success">₱${operation.total_fees.toLocaleString()}</strong>
            </td>
            <td>
                <button class="btn btn-sm btn-info me-2" onclick="viewDetails(${operation.SID})" title="View Details">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="btn btn-sm btn-warning me-2" onclick="editOperation(${operation.SID})" title="Edit">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-sm btn-danger" onclick="deleteOperation(${operation.SID}, '${operation.client_name}', '${operation.slaughter_date_formatted}')" title="Delete">
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
        <a class="page-link" href="#" onclick="loadOperations(${page - 1}, '${currentSearch}', '${currentDateFrom}', '${currentDateTo}', ${currentClientId}, ${currentBusinessId})">Previous</a>
    </li>`;

    // Page numbers
    for (let i = Math.max(1, page - 2); i <= Math.min(pages, page + 2); i++) {
        html += `<li class="page-item ${i === page ? 'active' : ''}">
            <a class="page-link" href="#" onclick="loadOperations(${i}, '${currentSearch}', '${currentDateFrom}', '${currentDateTo}', ${currentClientId}, ${currentBusinessId})">${i}</a>
        </li>`;
    }

    // Next button
    html += `<li class="page-item ${page === pages ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="loadOperations(${page + 1}, '${currentSearch}', '${currentDateFrom}', '${currentDateTo}', ${currentClientId}, ${currentBusinessId})">Next</a>
    </li>`;

    controls.innerHTML = html;
}

function updateResultsInfo(pagination) {
    const { page, limit, total, pages } = pagination;
    const start = (page - 1) * limit + 1;
    const end = Math.min(page * limit, total);

    document.getElementById('resultsInfo').textContent =
        total > 0 ? `Showing ${start}-${end} of ${total} operations` : 'No operations found';
}

function handleSearch() {
    const searchValue = document.getElementById('searchInput').value.trim();
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    const clientId = document.getElementById('clientFilter').value;
    const businessId = document.getElementById('businessFilter').value;
    loadOperations(1, searchValue, dateFrom, dateTo, clientId, businessId);
}

function clearFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
    document.getElementById('clientFilter').value = '';
    document.getElementById('businessFilter').value = '';
    loadOperations();
}

function loadClients() {
    apiCall(`${API_BASE_URL}/api_clients.php?limit=1000`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                clientsData = data.data;
                populateClientSelect();
            }
        })
        .catch(error => console.error('Error loading clients:', error));
}

function populateClientSelect() {
    const select = document.getElementById('clientSelect');
    const filterSelect = document.getElementById('clientFilter');

    select.innerHTML = '<option value="">Select Client</option>';
    filterSelect.innerHTML = '<option value="">All Clients</option>';

    clientsData.forEach(client => {
        select.innerHTML += `<option value="${client.CID}">${client.full_name}</option>`;
        filterSelect.innerHTML += `<option value="${client.CID}">${client.full_name}</option>`;
    });
}

function loadClientBusinesses() {
    const clientId = document.getElementById('clientSelect').value;
    if (!clientId) {
        document.getElementById('businessSelect').innerHTML = '<option value="">Select Business (Optional)</option>';
        return;
    }

    apiCall(`${API_BASE_URL}/api_client_business.php?cid=${clientId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                businessesData = data.data;
                populateBusinessSelect();
            }
        })
        .catch(error => console.error('Error loading businesses:', error));
}

function populateBusinessSelect() {
    const select = document.getElementById('businessSelect');
    const filterSelect = document.getElementById('businessFilter');

    select.innerHTML = '<option value="">Select Business (Optional)</option>';
    filterSelect.innerHTML = '<option value="">All Businesses</option>';

    businessesData.forEach(business => {
        select.innerHTML += `<option value="${business.BID}">${business.Business_Name}</option>`;
        filterSelect.innerHTML += `<option value="${business.BID}">${business.Business_Name}</option>`;
    });
}

function loadAnimals() {
    apiCall(`${API_BASE_URL}/api_animals.php?limit=1000`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                animalsData = data.data;
            }
        })
        .catch(error => console.error('Error loading animals:', error));
}

function loadCodes() {
    apiCall(`${API_BASE_URL}/api_codemarkings.php?limit=1000`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                codesData = data.data;
                populateCodeSelect();
            }
        })
        .catch(error => console.error('Error loading codes:', error));
}

function populateCodeSelect() {
    const select = document.getElementById('codeMarking');
    select.innerHTML = '<option value="">Select Code (Optional)</option>';

    codesData.forEach(code => {
        select.innerHTML += `<option value="${code.MID}">${code.CODE}</option>`;
    });
}

function showAddSlaughterModal() {
    document.getElementById('slaughterModalTitle').textContent = 'Add New Slaughter Operation';
    document.getElementById('slaughterForm').reset();
    document.getElementById('slaughterId').value = '';
    document.getElementById('animalDetailsContainer').innerHTML = '';
    animalDetailIndex = 0;
    addAnimalDetail(); // Add one empty detail row
    new bootstrap.Modal(document.getElementById('slaughterModal')).show();
}

function addAnimalDetail(existingData = null) {
    const container = document.getElementById('animalDetailsContainer');
    const index = animalDetailIndex++;

    const animalOptions = animalsData.map(animal =>
        `<option value="${animal.AID}" ${existingData && existingData.AID == animal.AID ? 'selected' : ''}>${animal.Animal}</option>`
    ).join('');

    const row = document.createElement('div');
    row.className = 'animal-detail-row';
    row.id = `animalDetail_${index}`;
    row.innerHTML = `
        <div class="row align-items-end">
            <div class="col-md-3">
                <label class="form-label">Animal Type *</label>
                <select class="form-control" name="animal_${index}" required>
                    <option value="">Select Animal</option>
                    ${animalOptions}
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">No. of Heads</label>
                <input type="number" class="form-control" name="heads_${index}" value="${existingData ? existingData.No_of_Heads : ''}" min="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">No. of Kilos</label>
                <input type="number" class="form-control" name="kilos_${index}" value="${existingData ? existingData.No_of_Kilos : ''}" step="0.01" min="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">Add-on</label>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="addon_${index}" ${existingData && existingData.Add_On_Flag ? 'checked' : ''}>
                    <label class="form-check-label">Yes</label>
                </div>
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-danger btn-sm" onclick="removeAnimalDetail(${index})">
                    <i class="fas fa-trash"></i> Remove
                </button>
            </div>
        </div>
    `;

    container.appendChild(row);
}

function removeAnimalDetail(index) {
    const row = document.getElementById(`animalDetail_${index}`);
    if (row) {
        row.remove();
    }
}

function saveSlaughterOperation() {
    const form = document.getElementById('slaughterForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    const isEdit = data.SID !== '';

    // Collect animal details
    const animalDetails = [];
    const detailRows = document.querySelectorAll('[id^="animalDetail_"]');

    detailRows.forEach(row => {
        const index = row.id.split('_')[1];
        const animalSelect = row.querySelector(`[name="animal_${index}"]`);
        const headsInput = row.querySelector(`[name="heads_${index}"]`);
        const kilosInput = row.querySelector(`[name="kilos_${index}"]`);
        const addonCheck = row.querySelector(`[name="addon_${index}"]`);

        if (animalSelect.value) {
            animalDetails.push({
                AID: animalSelect.value,
                No_of_Heads: headsInput.value || 0,
                No_of_Kilos: kilosInput.value || 0,
                Add_On_Flag: addonCheck.checked ? 1 : 0
            });
        }
    });

    if (animalDetails.length === 0) {
        showToast('Please add at least one animal detail', 'warning');
        return;
    }

    data.Added_by = <?php echo $user_id; ?>;
    data.Slaughter_Date = new Date(data.Slaughter_Date).toISOString().slice(0, 19).replace('T', ' ');

    const method = isEdit ? 'PUT' : 'POST';
    const url = `${API_BASE_URL}/api_slaughter.php`;

    // First save the slaughter operation
    apiCall(url, {
        method: method,
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            const sid = result.data.SID;

            // Now save the animal details
            const detailsData = animalDetails.map(detail => ({ ...detail, SID: sid }));

            return apiCall(`${API_BASE_URL}/api_slaughter_details.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(detailsData)
            });
        } else {
            throw new Error(result.message);
        }
    })
    .then(response => response.json())
    .then(detailsResult => {
        if (detailsResult.success) {
            bootstrap.Modal.getInstance(document.getElementById('slaughterModal')).hide();
            showToast('Slaughter operation saved successfully', 'success');
            loadOperations(currentPage, currentSearch, currentDateFrom, currentDateTo, currentClientId, currentBusinessId);
        } else {
            throw new Error(detailsResult.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error saving operation: ' + error.message, 'danger');
    });
}

function editOperation(sid) {
    const operation = operationsData.find(o => o.SID === sid);
    if (!operation) return;

    document.getElementById('slaughterModalTitle').textContent = 'Edit Slaughter Operation';
    document.getElementById('slaughterId').value = operation.SID;
    document.getElementById('clientSelect').value = operation.CID;
    document.getElementById('slaughterDate').value = operation.Slaughter_Date.slice(0, 16);
    document.getElementById('codeMarking').value = operation.MID || '';

    // Load businesses for this client
    loadClientBusinesses().then(() => {
        document.getElementById('businessSelect').value = operation.BID || '';
    });

    // Load existing details
    apiCall(`${API_BASE_URL}/api_slaughter_details.php?sid=${sid}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('animalDetailsContainer').innerHTML = '';
                animalDetailIndex = 0;
                data.data.forEach(detail => {
                    addAnimalDetail(detail);
                });
                if (data.data.length === 0) {
                    addAnimalDetail();
                }
            }
        });

    new bootstrap.Modal(document.getElementById('slaughterModal')).show();
}

function viewDetails(sid) {
    apiCall(`${API_BASE_URL}/api_slaughter_details.php?sid=${sid}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '<div class="table-responsive"><table class="table table-sm">';
                html += '<thead><tr><th>Animal</th><th>Heads</th><th>Kilos</th><th>Slaughter Fee</th><th>Corral Fee</th><th>Ante Mortem</th><th>Post Mortem</th><th>Delivery Fee</th><th>Total</th></tr></thead><tbody>';

                let totalFees = 0;
                data.data.forEach(detail => {
                    const rowTotal = detail.Slaughter_Fee + detail.Corral_Fee + detail.Ante_Mortem_Fee + detail.Post_Mortem_Fee + detail.Delivery_Fee;
                    totalFees += rowTotal;
                    html += `<tr>
                        <td>${detail.Animal}</td>
                        <td>${detail.No_of_Heads}</td>
                        <td>${detail.No_of_Kilos}</td>
                        <td>₱${detail.Slaughter_Fee.toLocaleString()}</td>
                        <td>₱${detail.Corral_Fee.toLocaleString()}</td>
                        <td>₱${detail.Ante_Mortem_Fee.toLocaleString()}</td>
                        <td>₱${detail.Post_Mortem_Fee.toLocaleString()}</td>
                        <td>₱${detail.Delivery_Fee.toLocaleString()}</td>
                        <td><strong>₱${rowTotal.toLocaleString()}</strong></td>
                    </tr>`;
                });

                html += `</tbody></table></div>`;
                html += `<div class="fee-summary"><h6>Total Fees: <strong class="text-success">₱${totalFees.toLocaleString()}</strong></h6></div>`;

                document.getElementById('detailsContent').innerHTML = html;
                new bootstrap.Modal(document.getElementById('detailsModal')).show();
            } else {
                showToast('Error loading details: ' + data.message, 'danger');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error loading details', 'danger');
        });
}

function deleteOperation(sid, clientName, date) {
    operationToDelete = sid;
    document.getElementById('deleteOperationInfo').textContent = `${clientName} - ${date}`;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

function confirmDelete() {
    if (!operationToDelete) return;

    apiCall(`${API_BASE_URL}/api_slaughter.php?id=${operationToDelete}`, {
        method: 'DELETE'
    })
    .then(response => response.json())
    .then(result => {
        bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
        if (result.success) {
            showToast(result.message, 'success');
            loadOperations(currentPage, currentSearch, currentDateFrom, currentDateTo, currentClientId, currentBusinessId);
        } else {
            showToast('Error: ' + result.message, 'danger');
        }
        operationToDelete = null;
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error deleting operation', 'danger');
        operationToDelete = null;
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
    loadOperations();
    loadClients();
    loadAnimals();
    loadCodes();
});
</script>

</body>
</html>