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