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

function populateClientDropdown() {
    const dropdown = document.getElementById('client');
    dropdown.innerHTML = '<option value="">Select Client</option>';
    clientsData.forEach(client => {
        const option = document.createElement('option');
        option.value = client.CID;
        option.textContent = client.full_name;
        dropdown.appendChild(option);
    });
}

function loadBusinesses(page = 1, search = '') {
    showLoading();
    currentPage = page;
    currentSearch = search;

    const url = new URL(`${API_BASE_URL}/api_client_business.php`);
    url.searchParams.set('page', page);
    url.searchParams.set('limit', 10);
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
    new bootstrap.Modal(document.getElementById('businessModal')).show();
}

function editBusiness(bid) {
    const business = businessesData.find(b => b.BID === bid);
    if (!business) return;

    document.getElementById('businessModalTitle').textContent = 'Edit Business';
    document.getElementById('businessId').value = business.BID;
    document.getElementById('client').value = business.CID;
    document.getElementById('businessName').value = business.Business_Name || '';
    document.getElementById('stallNumber').value = business.Stall_Number || '';
    document.getElementById('marketPlace').value = business.Market_Place || '';

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
});