let currentPage = 1;
let currentSearch = '';
let animalsData = [];
let animalToDelete = null;

function showLoading() {
    document.getElementById('loadingOverlay').classList.remove('d-none');
}

function hideLoading() {
    document.getElementById('loadingOverlay').classList.add('d-none');
}

function loadAnimals(page = 1, search = '') {
    showLoading();
    currentPage = page;
    currentSearch = search;

    const url = new URL(`${API_BASE_URL}/api_animals.php`);
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
                animalsData = data.data;
                renderAnimalsTable(data.data);
                renderPagination(data.pagination);
                updateResultsInfo(data.pagination);
            } else {
                showToast('Error loading animals: ' + data.message, 'danger');
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            showToast('Error loading animals', 'danger');
        });
}

function renderAnimalsTable(animals) {
    const tbody = document.getElementById('animalsTableBody');

    if (animals.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="4" class="text-center py-5">
                    <i class="fas fa-paw fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No animals found</h5>
                    <p class="text-muted">Try adjusting your search criteria or add a new animal type.</p>
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = animals.map(animal => `
        <tr>
            <td>${animal.AID}</td>
            <td>
                <strong>${animal.Animal}</strong>
            </td>
            <td>
                <span class="badge bg-info">${animal.fee_count} fees</span>
            </td>
            <td>
                <button class="btn btn-sm btn-warning me-2" onclick="editAnimal(${animal.AID})" title="Edit">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-sm btn-danger" onclick="deleteAnimal(${animal.AID}, '${animal.Animal}')" title="Delete">
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
        <a class="page-link" href="#" onclick="loadAnimals(${page - 1}, '${currentSearch}')">Previous</a>
    </li>`;

    // Page numbers
    for (let i = Math.max(1, page - 2); i <= Math.min(pages, page + 2); i++) {
        html += `<li class="page-item ${i === page ? 'active' : ''}">
            <a class="page-link" href="#" onclick="loadAnimals(${i}, '${currentSearch}')">${i}</a>
        </li>`;
    }

    // Next button
    html += `<li class="page-item ${page === pages ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="loadAnimals(${page + 1}, '${currentSearch}')">Next</a>
    </li>`;

    controls.innerHTML = html;
}

function updateResultsInfo(pagination) {
    const { page, limit, total, pages } = pagination;
    const start = (page - 1) * limit + 1;
    const end = Math.min(page * limit, total);

    document.getElementById('resultsInfo').textContent =
        total > 0 ? `Showing ${start}-${end} of ${total} animals` : 'No animals found';
}

function handleSearch() {
    const searchValue = document.getElementById('searchInput').value.trim();
    loadAnimals(1, searchValue);
}

function showAddAnimalModal() {
    document.getElementById('animalModalTitle').textContent = 'Add New Animal';
    document.getElementById('animalForm').reset();
    document.getElementById('animalId').value = '';
    new bootstrap.Modal(document.getElementById('animalModal')).show();
}

function editAnimal(aid) {
    const animal = animalsData.find(a => a.AID === aid);
    if (!animal) return;

    document.getElementById('animalModalTitle').textContent = 'Edit Animal';
    document.getElementById('animalId').value = animal.AID;
    document.getElementById('animalName').value = animal.Animal || '';

    new bootstrap.Modal(document.getElementById('animalModal')).show();
}

function saveAnimal() {
    const form = document.getElementById('animalForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    const isEdit = data.AID !== '';

    // Remove empty optional fields
    Object.keys(data).forEach(key => {
        if (data[key] === '') {
            delete data[key];
        }
    });

    const method = isEdit ? 'PUT' : 'POST';
    const url = `${API_BASE_URL}/api_animals.php`;

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
            bootstrap.Modal.getInstance(document.getElementById('animalModal')).hide();
            showToast(result.message, 'success');
            loadAnimals(currentPage, currentSearch);
        } else {
            showToast('Error: ' + result.message, 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error saving animal', 'danger');
    });
}

function deleteAnimal(aid, name) {
    animalToDelete = aid;
    document.getElementById('deleteAnimalName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

function confirmDelete() {
    if (!animalToDelete) return;

    fetch(`${API_BASE_URL}/api_animals.php?id=${animalToDelete}`, {
        method: 'DELETE'
    })
    .then(response => response.json())
    .then(result => {
        bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
        if (result.success) {
            showToast(result.message, 'success');
            loadAnimals(currentPage, currentSearch);
        } else {
            showToast('Error: ' + result.message, 'danger');
        }
        animalToDelete = null;
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error deleting animal', 'danger');
        animalToDelete = null;
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
    loadAnimals();
});