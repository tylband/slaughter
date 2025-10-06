<?php
require_once "../config.php";
require_once "../token_auth.php";
require_once "../system_logger.php";

// Authenticate user with token
$user_data = TokenAuth::authenticate($conn);
if (!$user_data) {
    header("Location: login.php");
    exit();
}
$user = htmlspecialchars($user_data['username']);
$user_id = $user_data['user_id'];

// Initialize logger
$logger = new SystemLogger($conn, $user_id, $user);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Management | Slaughter House Management System</title>

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
    <link rel="stylesheet" href="../css/client_management.css">

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

        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 500;
        }

        .status-stall-owner {
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
            color: white;
        }

        .status-private {
            background: linear-gradient(135deg, #3182ce 0%, #2c5282 100%);
            color: white;
        }

        .status-individual {
            background: linear-gradient(135deg, #d69e2e 0%, #b7791f 100%);
            color: white;
        }

        .pagination .page-link {
            color: var(--bg-accent);
            border-color: var(--border-light);
            background: var(--card-bg);
            font-weight: 500;
            padding: 0.5rem 0.75rem;
            margin: 0 0.125rem;
            border-radius: 0.375rem !important;
            transition: all 0.2s ease;
        }

        .pagination .page-link:hover {
            background: var(--green-light);
            border-color: var(--bg-accent);
            color: var(--bg-accent);
            transform: translateY(-1px);
        }

        .pagination .page-item.active .page-link {
            background: var(--bg-accent) !important;
            border-color: var(--bg-accent) !important;
            color: white !important;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);
        }

        .pagination .page-item.disabled .page-link {
            color: var(--text-muted);
            background: var(--card-bg);
            border-color: var(--border-light);
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
                            <h2 class="mb-2"><i class="fas fa-users me-2"></i>Client Management</h2>
                            <p class="mb-1 opacity-8"><i class="fas fa-industry me-2"></i>Slaughter House Management System</p>
                            <p class="mb-0 opacity-8">Manage client information, add new clients, and update existing records.</p>
                        </div>
                        <div class="col-lg-3 text-end">
                            <button class="btn btn-primary btn-lg" onclick="showAddClientModal()">
                                <i class="fas fa-user-plus me-2"></i>Add New Client
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
                                    <input type="text" class="form-control" id="searchInput" placeholder="Search clients by name, address, or contact..." onkeyup="handleSearch()">
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

        <!-- Clients Table -->
        <div class="row">
            <div class="col-12">
                <div class="data-card card position-relative">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-users me-2"></i>Clients List</h5>
                    </div>
                    <div class="card-body p-0">
                        <div id="loadingOverlay" class="loading-overlay d-none">
                            <div class="text-center">
                                <div class="spinner-border" style="color: #10b981;" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <div class="mt-2">Loading clients...</div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Address</th>
                                        <th>Contact</th>
                                        <th>Gender</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="clientsTableBody">
                                    <tr>
                                        <td colspan="7" class="text-center py-5">
                                            <div class="spinner-border" style="color: #10b981;" role="status">
                                                <span class="visually-hidden">Loading...</span>
                                            </div>
                                            <div class="mt-2">Loading clients...</div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer">
                        <nav aria-label="Clients pagination">
                            <ul class="pagination justify-content-center mb-0" id="paginationControls"></ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Add/Edit Client Modal -->
<div class="modal fade" id="clientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="clientModalTitle">Add New Client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="clientForm">
                    <input type="hidden" id="clientId" name="CID">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="surname" class="form-label">Surname *</label>
                            <input type="text" class="form-control text-uppercase" id="surname" name="Surname" required style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="firstname" class="form-label">First Name *</label>
                            <input type="text" class="form-control text-uppercase" id="firstname" name="Firstname" required style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="middlename" class="form-label">Middle Name</label>
                            <input type="text" class="form-control text-uppercase" id="middlename" name="Middlename" style="text-transform: uppercase;">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="nameext" class="form-label">Name Extension</label>
                            <input type="text" class="form-control text-uppercase" id="nameext" name="NameExt" placeholder="JR., SR., III, ETC." style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="gender" class="form-label">Gender</label>
                            <select class="form-control" id="gender" name="Gender">
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="status" class="form-label">Status *</label>
                            <select class="form-control" id="status" name="Status" required>
                                <option value="">Select Status</option>
                                <option value="Stall Owner">Stall Owner</option>
                                <option value="Private">Private</option>
                                <option value="Individual">Individual</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="contact" class="form-label">Contact Number *</label>
                            <input type="text" class="form-control text-uppercase" id="contact" name="Contact_No" required style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="address" class="form-label">Address *</label>
                            <textarea class="form-control text-uppercase" id="address" name="Address" rows="2" required style="text-transform: uppercase;"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveClient()">
                    <i class="fas fa-save me-2"></i>Save
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
                <p>Are you sure you want to delete this client?</p>
                <p class="text-danger mb-0" id="deleteClientName"></p>
                <small class="text-muted">This action cannot be undone.</small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                    <i class="fas fa-trash me-2"></i>Delete Client
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
<script src="../js/client_management.js"></script>

<script>
let currentPage = 1;
let currentSearch = '';
let clientsData = [];
let clientToDelete = null;

function showLoading() {
    document.getElementById('loadingOverlay').classList.remove('d-none');
}

function hideLoading() {
    document.getElementById('loadingOverlay').classList.add('d-none');
}

function loadClients(page = 1, search = '') {
    showLoading();
    currentPage = page;
    currentSearch = search;

    const url = new URL(`${API_BASE_URL}/api_clients.php`);
    url.searchParams.set('page', page);
    url.searchParams.set('limit', 5);
    if (search) {
        url.searchParams.set('search', search);
    }

    fetch(url, {
        headers: getAuthHeaders()
    })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                clientsData = data.data;
                renderClientsTable(data.data);
                renderPagination(data.pagination);
                updateResultsInfo(data.pagination);
            } else {
                showToast('Error loading clients: ' + data.message, 'danger');
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            showToast('Error loading clients', 'danger');
        });
}

function renderClientsTable(clients) {
    const tbody = document.getElementById('clientsTableBody');

    if (clients.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="text-center py-5">
                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No clients found</h5>
                    <p class="text-muted">Try adjusting your search criteria or add a new client.</p>
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = clients.map(client => `
        <tr>
            <td>${client.CID}</td>
            <td>
                <strong>${client.full_name}</strong>
            </td>
            <td>${client.Address}</td>
            <td>${client.Contact_No}</td>
            <td>${client.Gender || '-'}</td>
            <td>
                <span class="status-badge status-${client.Status.toLowerCase().replace(' ', '-')}">
                    ${client.Status}
                </span>
            </td>
            <td>
                <button class="btn btn-sm btn-warning me-2" onclick="editClient(${client.CID})" title="Edit">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-sm btn-danger" onclick="deleteClient(${client.CID}, '${client.full_name}')" title="Delete">
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
        <a class="page-link" href="#" onclick="loadClients(${page - 1}, '${currentSearch}')">Previous</a>
    </li>`;

    // Page numbers
    for (let i = Math.max(1, page - 2); i <= Math.min(pages, page + 2); i++) {
        html += `<li class="page-item ${i === page ? 'active' : ''}">
            <a class="page-link" href="#" onclick="loadClients(${i}, '${currentSearch}')">${i}</a>
        </li>`;
    }

    // Next button
    html += `<li class="page-item ${page === pages ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="loadClients(${page + 1}, '${currentSearch}')">Next</a>
    </li>`;

    controls.innerHTML = html;
}

function updateResultsInfo(pagination) {
    const { page, limit, total, pages } = pagination;
    const start = (page - 1) * limit + 1;
    const end = Math.min(page * limit, total);

    document.getElementById('resultsInfo').textContent =
        total > 0 ? `Showing ${start}-${end} of ${total} clients` : 'No clients found';
}

function handleSearch() {
    const searchValue = document.getElementById('searchInput').value.trim();
    loadClients(1, searchValue);
}

function showAddClientModal() {
    document.getElementById('clientModalTitle').textContent = 'Add New Client';
    document.getElementById('clientForm').reset();
    document.getElementById('clientId').value = '';
    new bootstrap.Modal(document.getElementById('clientModal')).show();
}

function editClient(cid) {
    const client = clientsData.find(c => c.CID === cid);
    if (!client) return;

    document.getElementById('clientModalTitle').textContent = 'Edit Client';
    document.getElementById('clientId').value = client.CID;
    document.getElementById('surname').value = client.Surname || '';
    document.getElementById('firstname').value = client.Firstname || '';
    document.getElementById('middlename').value = client.Middlename || '';
    document.getElementById('nameext').value = client.NameExt || '';
    document.getElementById('address').value = client.Address || '';
    document.getElementById('contact').value = client.Contact_No || '';
    document.getElementById('gender').value = client.Gender || '';
    document.getElementById('status').value = client.Status || '';

    new bootstrap.Modal(document.getElementById('clientModal')).show();
}

function saveClient() {
    const form = document.getElementById('clientForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    const isEdit = data.CID !== '';

    // Remove empty optional fields
    Object.keys(data).forEach(key => {
        if (data[key] === '') {
            delete data[key];
        }
    });

    const method = isEdit ? 'PUT' : 'POST';
    const url = `${API_BASE_URL}/api_clients.php`;

    fetch(url, {
        method: method,
        headers: {
            'Content-Type': 'application/json',
            ...getAuthHeaders()
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            // Log the client creation/update
            if (isEdit) {
                $logger->logClientUpdated($data['CID'], null, $data);
            } else {
                $logger->logClientCreated($result.data.CID, $data);
            }

            bootstrap.Modal.getInstance(document.getElementById('clientModal')).hide();
            showToast(result.message, 'success');
            loadClients(currentPage, currentSearch);
        } else {
            showToast('Error: ' + result.message, 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error saving client', 'danger');
    });
}

function deleteClient(cid, name) {
    clientToDelete = cid;
    document.getElementById('deleteClientName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

function confirmDelete() {
    if (!clientToDelete) return;

    fetch(`${API_BASE_URL}/api_clients.php?id=${clientToDelete}`, {
        method: 'DELETE',
        headers: getAuthHeaders()
    })
    .then(response => response.json())
    .then(result => {
        bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
        if (result.success) {
            // Log the client deletion
            $logger->logClientDeleted($clientToDelete, document.getElementById('deleteClientName').textContent);

            showToast(result.message, 'success');
            loadClients(currentPage, currentSearch);
        } else {
            showToast('Error: ' + result.message, 'danger');
        }
        clientToDelete = null;
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error deleting client', 'danger');
        clientToDelete = null;
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
});
</script>

</body>
</html>