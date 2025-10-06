// Slaughter Fee Entry JavaScript
let clientsData = [];
let businessesData = [];
let animalsData = [];
let codesData = [];
let animalDetailIndex = 0;

// Initialize the page
document.addEventListener('DOMContentLoaded', function() {
    loadClients();
    loadAnimals();
    loadCodes();
    addAnimalDetail(); // Add one default animal detail
    setDefaultDateTime();
    loadFeeEntries(); // Load existing fee entries
    initializeClientAutoSuggest();
});

// Load clients data
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

// Populate client select dropdown (legacy function for backward compatibility)
function populateClientSelect() {
    const select = document.getElementById('clientSelect');
    if (select) {
        select.innerHTML = '<option value="">Select Client</option>';

        clientsData.forEach(client => {
            const fullName = `${client.Firstname} ${client.Middlename || ''} ${client.Surname}`.trim();
            select.innerHTML += `<option value="${client.CID}">${fullName}</option>`;
        });
    }
}

// Initialize client auto-suggest functionality
function initializeClientAutoSuggest() {
    const clientSearchInput = document.getElementById('clientSearch');
    const clientSuggestions = document.getElementById('clientSuggestions');
    const clientSelectHidden = document.getElementById('clientSelect');

    if (!clientSearchInput || !clientSuggestions || !clientSelectHidden) return;

    let searchTimeout;
    let currentFocus = -1;

    // Handle input events
    clientSearchInput.addEventListener('input', function() {
        const searchTerm = this.value.trim();

        // Clear previous timeout
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }

        // Set new timeout for debounced search
        searchTimeout = setTimeout(() => {
            if (searchTerm.length >= 1) {
                showClientSuggestions(searchTerm);
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
            }
        } else if (e.key === 'Escape') {
            hideClientSuggestions();
        }
    });

    // Handle clicks outside to close suggestions
    document.addEventListener('click', function(e) {
        if (!clientSearchInput.contains(e.target) && !clientSuggestions.contains(e.target)) {
            hideClientSuggestions();
        }
    });
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
    } else {
        suggestionsHTML = '<div class="no-suggestions">No clients found</div>';
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
    const clientSuggestions = document.getElementById('clientSuggestions');

    if (clientSearchInput && clientSelectHidden) {
        clientSearchInput.value = clientName;
        clientSelectHidden.value = clientId;

        // Trigger the business loading
        loadClientBusinesses(clientId);

        // Hide suggestions
        hideClientSuggestions();

        // Remove focus from input
        clientSearchInput.blur();
    }
}

// Highlight search term in results
function highlightSearchTerm(text, searchTerm) {
    if (!searchTerm) return text;

    const regex = new RegExp(`(${searchTerm})`, 'gi');
    return text.replace(regex, '<strong>$1</strong>');
}

// Load client businesses when client is selected
function loadClientBusinesses(clientId = null) {
    const clientSelectHidden = document.getElementById('clientSelect');
    const selectedClientId = clientId || (clientSelectHidden ? clientSelectHidden.value : null);
    const businessSelect = document.getElementById('businessSelect');

    if (!selectedClientId) {
        if (businessSelect) {
            businessSelect.innerHTML = '<option value="">Select Business (Optional)</option>';
        }
        return Promise.resolve();
    }

    return apiCall(`${API_BASE_URL}/api_client_business.php?cid=${selectedClientId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                businessesData = data.data;
                populateBusinessSelect();
            }
            return Promise.resolve();
        })
        .catch(error => {
            console.error('Error loading businesses:', error);
            return Promise.resolve();
        });
}

// Populate business select dropdown
function populateBusinessSelect() {
    const select = document.getElementById('businessSelect');
    select.innerHTML = '<option value="">Select Business (Optional)</option>';

    businessesData.forEach(business => {
        select.innerHTML += `<option value="${business.BID}">${business.Business_Name}</option>`;
    });
}

// Load animals data
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

// Load code markings
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

// Populate code marking select
function populateCodeSelect() {
    const select = document.getElementById('codeMarking');
    select.innerHTML = '<option value="">Select Code (Optional)</option>';

    codesData.forEach(code => {
        select.innerHTML += `<option value="${code.MID}">${code.CODE}</option>`;
    });
}

// Set default date and time to now
function setDefaultDateTime() {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    document.getElementById('slaughterDate').value = now.toISOString().slice(0, 16);
}

// Add a new animal detail card
function addAnimalDetail(existingData = null) {
    const container = document.getElementById('animalDetailsContainer');
    const index = animalDetailIndex++;

    const animalOptions = animalsData.map(animal =>
        `<option value="${animal.AID}" ${existingData && existingData.AID == animal.AID ? 'selected' : ''}>${animal.Animal}</option>`
    ).join('');

    const card = document.createElement('div');
    card.className = 'animal-detail-card';
    card.id = `animalCard_${index}`;
    card.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">
                <i class="fas fa-paw me-2"></i>
                <select class="form-control d-inline-block w-auto" name="animal_${index}" onchange="updateAnimalName(${index})" required>
                    <option value="">Select Animal Type</option>
                    ${animalOptions}
                </select>
            </h6>
            <button type="button" class="btn btn-sm remove-animal-btn" onclick="removeAnimalDetail(${index})">
                <i class="fas fa-trash"></i> Remove
            </button>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Number of Heads *</label>
                <input type="number" class="form-control" name="heads_${index}" min="1" value="${existingData ? existingData.No_of_Heads : ''}" onchange="calculateFees()" required>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Total Weight (kg) *</label>
                <input type="number" class="form-control" name="kilos_${index}" step="0.01" min="0.01" value="${existingData ? existingData.No_of_Kilos : ''}" onchange="calculateFees()" required>
            </div>
        </div>

        <div class="fee-input-group">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Slaughter Fee (₱)</label>
                    <input type="number" class="form-control" name="slaughter_fee_${index}" step="0.01" min="0" value="${existingData ? existingData.Slaughter_Fee : '0.00'}" onchange="calculateTotalFees()">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Corral Fee (₱)</label>
                    <input type="number" class="form-control" name="corral_fee_${index}" step="0.01" min="0" value="${existingData ? existingData.Corral_Fee : '0.00'}" onchange="calculateTotalFees()">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Ante Mortem Fee (₱)</label>
                    <input type="number" class="form-control" name="ante_mortem_fee_${index}" step="0.01" min="0" value="${existingData ? existingData.Ante_Mortem_Fee : '0.00'}" onchange="calculateTotalFees()">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Post Mortem Fee (₱)</label>
                    <input type="number" class="form-control" name="post_mortem_fee_${index}" step="0.01" min="0" value="${existingData ? existingData.Post_Mortem_Fee : '0.00'}" onchange="calculateTotalFees()">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Delivery Fee (₱)</label>
                    <input type="number" class="form-control" name="delivery_fee_${index}" step="0.01" min="0" value="${existingData ? existingData.Delivery_Fee : '0.00'}" onchange="calculateTotalFees()">
                </div>
                <div class="col-md-6 mb-3 d-flex align-items-end">
                    <div class="fee-total-display">
                        <label class="form-label">Total Fee for this Animal:</label>
                        <div class="total-amount" id="animalTotal_${index}">₱0.00</div>
                    </div>
                </div>
            </div>
        </div>
    `;

    container.appendChild(card);
    calculateFees();
}

// Remove an animal detail card
function removeAnimalDetail(index) {
    const card = document.getElementById(`animalCard_${index}`);
    if (card) {
        card.remove();
        calculateTotalFees();
    }
}

// Update animal name display (for future enhancement)
function updateAnimalName(index) {
    // Could add logic to update card title based on selected animal
}

// Calculate fees based on animal type and inputs
function calculateFees() {
    const cards = document.querySelectorAll('[id^="animalCard_"]');

    cards.forEach(card => {
        const index = card.id.split('_')[1];
        const animalSelect = card.querySelector(`[name="animal_${index}"]`);
        const headsInput = card.querySelector(`[name="heads_${index}"]`);
        const kilosInput = card.querySelector(`[name="kilos_${index}"]`);

        if (animalSelect.value && headsInput.value && kilosInput.value) {
            const animalId = animalSelect.value;
            const heads = parseInt(headsInput.value) || 0;
            const kilos = parseFloat(kilosInput.value) || 0;

            const animal = animalsData.find(a => a.AID == animalId);
            if (animal) {
                const rates = getFeeRates(animal.Animal.toLowerCase());
                updateFeeInputs(card, index, heads, kilos, rates);
            }
        }
    });

    calculateTotalFees();
}

// Get fee rates based on animal type
function getFeeRates(animalName) {
    const name = animalName.toLowerCase();

    // Hogs, goats, sheep
    if (name.includes('hog') || name.includes('goat') || name.includes('sheep')) {
        return {
            slaughter: 250.00, // per head
            corral: 10.00,     // per head
            anteMortem: 10.00, // per head
            postMortem: 0.50,  // per kg
            delivery: 20.00    // per head
        };
    }
    // Large Animals (buffalo, carabao, cattle)
    else if (name.includes('buffalo') || name.includes('carabao') || name.includes('large') || name.includes('cattle')) {
        return {
            slaughter: 350.00, // per head
            corral: 20.00,     // per head
            anteMortem: 25.00, // per head
            postMortem: 0.50,  // per kg
            delivery: 40.00    // per head
        };
    }
    // Chicken
    else if (name.includes('chicken') || name.includes('dressed chicken')) {
        return {
            slaughter: 20.00,  // per kg
            corral: 0.00,      // no corral fee
            anteMortem: 0.50,  // per head
            postMortem: 0.50,  // per kg
            delivery: 0.00     // no delivery fee
        };
    }
    // Quail
    else if (name.includes('quail')) {
        return {
            slaughter: 40.00,  // per kg
            corral: 0.00,      // no corral fee
            anteMortem: 0.50,  // per head
            postMortem: 0.50,  // per kg
            delivery: 0.00     // no delivery fee
        };
    }
    // Default rates (fallback)
    else {
        return {
            slaughter: 250.00, // per head
            corral: 10.00,     // per head
            anteMortem: 10.00, // per head
            postMortem: 0.50,  // per kg
            delivery: 20.00    // per head
        };
    }
}

// Update fee input fields with calculated values
function updateFeeInputs(card, index, heads, kilos, rates) {
    const animalName = card.querySelector(`[name="animal_${index}"]`).selectedOptions[0].text.toLowerCase();

    let slaughterFee, corralFee, anteMortemFee, postMortemFee, deliveryFee;

    // Calculate fees based on animal type
    if (animalName.includes('chicken') || animalName.includes('quail')) {
        // For chicken and quail: slaughter fee is per kg
        slaughterFee = kilos * rates.slaughter;
        corralFee = rates.corral; // Usually 0 for these animals
        anteMortemFee = heads * rates.anteMortem;
        postMortemFee = kilos * rates.postMortem;
        deliveryFee = rates.delivery; // Usually 0 for these animals
    } else {
        // For other animals: slaughter fee is per head
        slaughterFee = heads * rates.slaughter;
        corralFee = heads * rates.corral;
        anteMortemFee = heads * rates.anteMortem;
        postMortemFee = kilos * rates.postMortem;
        deliveryFee = heads * rates.delivery;
    }

    card.querySelector(`[name="slaughter_fee_${index}"]`).value = slaughterFee.toFixed(2);
    card.querySelector(`[name="corral_fee_${index}"]`).value = corralFee.toFixed(2);
    card.querySelector(`[name="ante_mortem_fee_${index}"]`).value = anteMortemFee.toFixed(2);
    card.querySelector(`[name="post_mortem_fee_${index}"]`).value = postMortemFee.toFixed(2);
    card.querySelector(`[name="delivery_fee_${index}"]`).value = deliveryFee.toFixed(2);

    updateAnimalTotal(index);
}

// Update individual animal total
function updateAnimalTotal(index) {
    const card = document.getElementById(`animalCard_${index}`);
    if (!card) return;

    const slaughterFee = parseFloat(card.querySelector(`[name="slaughter_fee_${index}"]`).value) || 0;
    const corralFee = parseFloat(card.querySelector(`[name="corral_fee_${index}"]`).value) || 0;
    const anteMortemFee = parseFloat(card.querySelector(`[name="ante_mortem_fee_${index}"]`).value) || 0;
    const postMortemFee = parseFloat(card.querySelector(`[name="post_mortem_fee_${index}"]`).value) || 0;
    const deliveryFee = parseFloat(card.querySelector(`[name="delivery_fee_${index}"]`).value) || 0;

    const total = slaughterFee + corralFee + anteMortemFee + postMortemFee + deliveryFee;
    document.getElementById(`animalTotal_${index}`).textContent = `₱${total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
}

// Calculate total fees across all animals
function calculateTotalFees() {
    const cards = document.querySelectorAll('[id^="animalCard_"]');
    let totalSlaughter = 0, totalCorral = 0, totalAnteMortem = 0, totalPostMortem = 0, totalDelivery = 0;

    cards.forEach(card => {
        const index = card.id.split('_')[1];
        updateAnimalTotal(index);

        totalSlaughter += parseFloat(card.querySelector(`[name="slaughter_fee_${index}"]`).value) || 0;
        totalCorral += parseFloat(card.querySelector(`[name="corral_fee_${index}"]`).value) || 0;
        totalAnteMortem += parseFloat(card.querySelector(`[name="ante_mortem_fee_${index}"]`).value) || 0;
        totalPostMortem += parseFloat(card.querySelector(`[name="post_mortem_fee_${index}"]`).value) || 0;
        totalDelivery += parseFloat(card.querySelector(`[name="delivery_fee_${index}"]`).value) || 0;
    });

    const grandTotal = totalSlaughter + totalCorral + totalAnteMortem + totalPostMortem + totalDelivery;

    document.getElementById('totalSlaughterFee').textContent = `₱${totalSlaughter.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    document.getElementById('totalCorralFee').textContent = `₱${totalCorral.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    document.getElementById('totalAnteMortemFee').textContent = `₱${totalAnteMortem.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    document.getElementById('totalPostMortemFee').textContent = `₱${totalPostMortem.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    document.getElementById('totalDeliveryFee').textContent = `₱${totalDelivery.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    document.getElementById('grandTotal').textContent = `₱${grandTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
}

// Save fee entry
function saveFeeEntry() {
    const form = document.getElementById('feeEntryForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    // Collect basic data
    const clientSelectHidden = document.getElementById('clientSelect');
    const data = {
        CID: clientSelectHidden ? clientSelectHidden.value : null,
        BID: document.getElementById('businessSelect').value || null,
        MID: document.getElementById('codeMarking').value || null,
        Slaughter_Date: document.getElementById('slaughterDate').value,
        Added_by: USER_ID // User who is adding the record
    };

    // Validate required fields
    if (!data.CID || !data.Slaughter_Date) {
        showToast('Please fill in all required fields', 'warning');
        return;
    }

    // Collect animal details
    const animalCards = document.querySelectorAll('[id^="animalCard_"]');
    const details = [];

    animalCards.forEach(card => {
        const index = card.id.split('_')[1];
        const animalSelect = card.querySelector(`[name="animal_${index}"]`);
        const headsInput = card.querySelector(`[name="heads_${index}"]`);
        const kilosInput = card.querySelector(`[name="kilos_${index}"]`);
        const slaughterFeeInput = card.querySelector(`[name="slaughter_fee_${index}"]`);
        const corralFeeInput = card.querySelector(`[name="corral_fee_${index}"]`);
        const anteMortemFeeInput = card.querySelector(`[name="ante_mortem_fee_${index}"]`);
        const postMortemFeeInput = card.querySelector(`[name="post_mortem_fee_${index}"]`);
        const deliveryFeeInput = card.querySelector(`[name="delivery_fee_${index}"]`);

        if (animalSelect.value && headsInput.value && kilosInput.value) {
            details.push({
                AID: animalSelect.value,
                No_of_Heads: parseInt(headsInput.value),
                No_of_Kilos: parseFloat(kilosInput.value),
                Slaughter_Fee: parseFloat(slaughterFeeInput.value) || 0,
                Corral_Fee: parseFloat(corralFeeInput.value) || 0,
                Ante_Mortem_Fee: parseFloat(anteMortemFeeInput.value) || 0,
                Post_Mortem_Fee: parseFloat(postMortemFeeInput.value) || 0,
                Delivery_Fee: parseFloat(deliveryFeeInput.value) || 0,
                Add_On_Flag: 0
            });
        }
    });

    if (details.length === 0) {
        showToast('Please add at least one animal with complete details', 'warning');
        return;
    }

    data.details = details;

    // Check if we're editing or adding
    const isEditing = window.editingFeeId !== undefined;
    const method = isEditing ? 'PUT' : 'POST';
    const url = isEditing ?
        `${API_BASE_URL}/api_fees.php` :
        `${API_BASE_URL}/api_fees.php`;

    if (isEditing) {
        data.SID = window.editingFeeId; // Add the SID for updates
    }

    // Show loading state
    const saveBtn = document.querySelector('button[onclick="saveFeeEntry()"]');
    const originalText = saveBtn.innerHTML;
    const actionText = isEditing ? 'Updating...' : 'Saving...';
    saveBtn.innerHTML = `<i class="fas fa-spinner fa-spin me-2"></i>${actionText}`;
    saveBtn.disabled = true;

    // Save the data
    apiCall(url, {
        method: method,
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            const actionText = isEditing ? 'updated' : 'saved';
            showToast(`Fee entry ${actionText} successfully!`, 'success');
            resetForm();

            // Clear editing state
            delete window.editingFeeId;

            // Close the modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('feeEntryModal'));
            if (modal) {
                modal.hide();
            }

            // Refresh the fee entries table
            loadFeeEntries();
        } else {
            showToast('Error saving fee entry: ' + result.message, 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error saving fee entry', 'danger');
    })
    .finally(() => {
        saveBtn.innerHTML = originalText;
        saveBtn.disabled = false;
    });
}

// Reset the form
function resetForm() {
    const feeEntryForm = document.getElementById('feeEntryForm');
    const clientSearchInput = document.getElementById('clientSearch');
    const clientSelectHidden = document.getElementById('clientSelect');

    if (feeEntryForm) {
        feeEntryForm.reset();
    }

    // Clear client search fields
    if (clientSearchInput) {
        clientSearchInput.value = '';
    }
    if (clientSelectHidden) {
        clientSelectHidden.value = '';
    }

    // Hide client suggestions
    hideClientSuggestions();

    document.getElementById('animalDetailsContainer').innerHTML = '';
    animalDetailIndex = 0;
    addAnimalDetail(); // Add one default animal detail
    setDefaultDateTime();
    calculateTotalFees();
}

// Pagination variables
let currentPage = 1;
let totalPages = 1;
let currentSearchTerm = '';
const entriesPerPage = 5;

// Load fee entries from API with pagination and optional search
function loadFeeEntries(searchTerm = '', page = 1) {
    document.getElementById('loadingEntries').style.display = 'block';
    document.getElementById('entriesTableContainer').style.display = 'none';
    document.getElementById('noEntriesMessage').style.display = 'none';

    currentPage = page;
    currentSearchTerm = searchTerm;

    let url = `${API_BASE_URL}/api_fees.php?page=${page}&limit=${entriesPerPage}`;
    if (searchTerm) {
        url += `&search=${encodeURIComponent(searchTerm)}`;
    }

    apiCall(url)
        .then(response => response.json())
        .then(data => {
            document.getElementById('loadingEntries').style.display = 'none';

            if (data.success) {
                // Calculate total pages based on available data
                if (data.total_pages) {
                    totalPages = data.total_pages;
                } else if (data.total) {
                    totalPages = Math.ceil(data.total / entriesPerPage);
                } else {
                    // Fallback: estimate based on current page having data
                    totalPages = currentPage; // At minimum, current page exists
                    if (data.data && data.data.length === entriesPerPage) {
                        totalPages = currentPage + 1; // There's likely more pages
                    }
                }
                console.log('Pagination Debug:', {
                    currentPage,
                    totalPages,
                    entriesPerPage,
                    dataLength: data.data ? data.data.length : 0,
                    total: data.total
                });

                if (data.data && data.data.length > 0) {
                    renderFeeEntriesTable(data.data);
                    document.getElementById('entriesTableContainer').style.display = 'block';
                    renderPaginationControls();
                } else {
                    document.getElementById('noEntriesMessage').style.display = 'block';
                    document.getElementById('paginationControls').innerHTML = '';
                }
            } else {
                document.getElementById('noEntriesMessage').style.display = 'block';
                document.getElementById('paginationControls').innerHTML = '';
            }
        })
        .catch(error => {
            console.error('Error loading fee entries:', error);
            document.getElementById('loadingEntries').style.display = 'none';
            document.getElementById('noEntriesMessage').style.display = 'block';
            document.getElementById('paginationContainer').style.display = 'none';
            showToast('Error loading fee entries', 'danger');
        });
}

// Render pagination controls (matching client_management.php style)
function renderPaginationControls() {
    const controls = document.getElementById('paginationControls');
    if (!controls) return;

    console.log('Rendering pagination controls:', { currentPage, totalPages });

    if (totalPages <= 1) {
        controls.innerHTML = '';
        return;
    }

    let html = '';

    // Previous button
    html += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changePage(${currentPage - 1}); return false;" ${currentPage === 1 ? 'tabindex="-1"' : ''}>Previous</a>
    </li>`;

    // Page numbers
    for (let i = Math.max(1, currentPage - 2); i <= Math.min(totalPages, currentPage + 2); i++) {
        html += `<li class="page-item ${i === currentPage ? 'active' : ''}">
            <a class="page-link" href="#" onclick="changePage(${i}); return false;">${i}</a>
        </li>`;
    }

    // Next button
    html += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changePage(${currentPage + 1}); return false;" ${currentPage === totalPages ? 'tabindex="-1"' : ''}>Next</a>
    </li>`;

    controls.innerHTML = html;
    console.log('Pagination HTML rendered:', html);
}

// Change page function
function changePage(page) {
    if (page >= 1 && page <= totalPages) {
        loadFeeEntries(currentSearchTerm, page);
    }
}

// Render fee entries table
function renderFeeEntriesTable(entries) {
    const tbody = document.getElementById('feeEntriesTableBody');

    tbody.innerHTML = entries.map(entry => {
        const date = new Date(entry.slaughter_date_formatted).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });

        const totalAmount = (parseFloat(entry.Slaughter_Fee) || 0) +
                           (parseFloat(entry.Corral_Fee) || 0) +
                           (parseFloat(entry.Ante_Mortem_Fee) || 0) +
                           (parseFloat(entry.Post_Mortem_Fee) || 0) +
                           (parseFloat(entry.Delivery_Fee) || 0);

        const paymentStatus = entry.payment_status || 'unpaid';
        const statusBadge = paymentStatus === 'paid' ? 'bg-success' :
                           paymentStatus === 'partial' ? 'bg-warning' : 'bg-danger';

        return `
            <tr>
                <td>${entry.Fee_ID}</td>
                <td>
                    <strong>${entry.client_name || 'Unknown Client'}</strong>
                    ${entry.Business_Name ? `<br><small class="text-muted">${entry.Business_Name}</small>` : '<br><small class="text-muted">Individual</small>'}
                </td>
                <td>${date}</td>
                <td>
                    <span class="badge bg-info">${entry.Animal || 'N/A'}</span>
                    <br><small class="text-muted">${entry.No_of_Heads} heads, ${entry.No_of_Kilos} kg</small>
                </td>
                <td>
                    <strong class="text-success">₱${totalAmount.toLocaleString()}</strong>
                </td>
                <td>
                    <button class="btn btn-sm btn-info me-1" onclick="viewFeeEntry(${entry.Fee_ID})" title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-warning" onclick="editFeeEntry(${entry.Fee_ID})" title="Edit Entry">
                        <i class="fas fa-edit"></i>
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

// Edit fee entry
function editFeeEntry(feeId) {
    // Store the editing fee ID
    window.editingFeeId = feeId;

    // Fetch fee entry details
    apiCall(`${API_BASE_URL}/api_slaughter_details.php?sid=${feeId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                const details = data.data;
                const firstDetail = details[0];

                // Populate the modal with existing data
                populateEditForm(firstDetail, details);

                // Show the modal in edit mode
                const modal = new bootstrap.Modal(document.getElementById('feeEntryModal'));
                document.getElementById('feeEntryModalLabel').textContent = 'Edit Fee Entry';
                modal.show();
            } else {
                showToast('Fee entry not found', 'danger');
            }
        })
        .catch(error => {
            console.error('Error loading fee entry for edit:', error);
            showToast('Error loading fee entry for editing', 'danger');
        });
}

// Populate form with existing data for editing
function populateEditForm(feeEntry, details) {
    // Clear existing animal details
    document.getElementById('animalDetailsContainer').innerHTML = '';
    animalDetailIndex = 0;

    // Populate basic information
    const clientSelectHidden = document.getElementById('clientSelect');
    const clientSearchInput = document.getElementById('clientSearch');

    if (clientSelectHidden) {
        clientSelectHidden.value = feeEntry.CID || '';
    }

    // Find the client name for display in the search input
    if (clientSearchInput && feeEntry.CID) {
        const client = clientsData.find(c => c.CID == feeEntry.CID);
        if (client) {
            const fullName = `${client.Firstname} ${client.Middlename || ''} ${client.Surname}`.trim();
            clientSearchInput.value = fullName;
        }
    }

    // Load businesses for the selected client and then set the business value
    loadClientBusinesses(feeEntry.CID).then(() => {
        // Set business after businesses are loaded
        setTimeout(() => {
            const businessSelect = document.getElementById('businessSelect');
            if (businessSelect) {
                businessSelect.value = feeEntry.BID || '';
            }
        }, 100);
    });

    document.getElementById('codeMarking').value = feeEntry.MID || '';

    // Format date for datetime-local input
    const slaughterDate = new Date(feeEntry.slaughter_date_formatted);
    const formattedDate = slaughterDate.toISOString().slice(0, 16);
    document.getElementById('slaughterDate').value = formattedDate;

    // Add animal details
    details.forEach(detail => {
        addAnimalDetail({
            AID: detail.AID,
            No_of_Heads: detail.No_of_Heads,
            No_of_Kilos: detail.No_of_Kilos,
            Slaughter_Fee: detail.Slaughter_Fee,
            Corral_Fee: detail.Corral_Fee,
            Ante_Mortem_Fee: detail.Ante_Mortem_Fee,
            Post_Mortem_Fee: detail.Post_Mortem_Fee,
            Delivery_Fee: detail.Delivery_Fee
        });
    });

    // Calculate totals
    calculateTotalFees();
}

// View fee entry details
function viewFeeEntry(feeId) {
    const modal = new bootstrap.Modal(document.getElementById('viewFeeModal'));
    const content = document.getElementById('feeDetailsContent');

    // Show loading state
    content.innerHTML = `
        <div class="text-center">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div class="mt-2">Loading fee details...</div>
        </div>
    `;

    modal.show();

    // Store the current fee ID for printing
    window.currentFeeId = feeId;

    // Fetch fee entry details
    apiCall(`${API_BASE_URL}/api_slaughter_details.php?sid=${feeId}`)
        .then(response => response.json())
        .then(detailsData => {
            if (detailsData.success && detailsData.data.length > 0) {
                // Create a mock fee entry object from the details data
                const details = detailsData.data;
                const firstDetail = details[0];

                // Calculate totals
                const totalSlaughter = details.reduce((sum, d) => sum + (parseFloat(d.Slaughter_Fee) || 0), 0);
                const totalCorral = details.reduce((sum, d) => sum + (parseFloat(d.Corral_Fee) || 0), 0);
                const totalAnteMortem = details.reduce((sum, d) => sum + (parseFloat(d.Ante_Mortem_Fee) || 0), 0);
                const totalPostMortem = details.reduce((sum, d) => sum + (parseFloat(d.Post_Mortem_Fee) || 0), 0);
                const totalDelivery = details.reduce((sum, d) => sum + (parseFloat(d.Delivery_Fee) || 0), 0);

                // Get client and operation info from the first detail (they should be the same for all details in one operation)
                const feeEntry = {
                    Fee_ID: feeId,
                    client_name: firstDetail.client_name || 'Unknown Client',
                    Business_Name: firstDetail.Business_Name || null,
                    slaughter_date_formatted: firstDetail.slaughter_date_formatted || new Date().toISOString(),
                    Slaughter_Fee: totalSlaughter,
                    Corral_Fee: totalCorral,
                    Ante_Mortem_Fee: totalAnteMortem,
                    Post_Mortem_Fee: totalPostMortem,
                    Delivery_Fee: totalDelivery,
                    No_of_Heads: details.reduce((sum, d) => sum + (parseInt(d.No_of_Heads) || 0), 0),
                    No_of_Kilos: details.reduce((sum, d) => sum + (parseFloat(d.No_of_Kilos) || 0), 0),
                    Animal: details.map(d => {
                        const animal = animalsData.find(a => a.AID == d.AID);
                        return animal ? animal.Animal : 'Unknown';
                    }).join(', ')
                };

                renderFeeDetails(feeEntry, details);
            } else {
                content.innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Fee entry details not found.
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading fee details:', error);
            content.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Error loading fee details. Please try again.
                </div>
            `;
        });
}

// Render fee details in the modal
function renderFeeDetails(feeEntry, details) {
    const content = document.getElementById('feeDetailsContent');

    const operationDate = new Date(feeEntry.slaughter_date_formatted).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });


    // Build animal breakdown
    let animalBreakdown = '';
    if (details && details.length > 0) {
        animalBreakdown = details.map(detail => {
            const animal = animalsData.find(a => a.AID == detail.AID);
            const animalName = animal ? animal.Animal : 'Unknown';
            const detailTotal = (parseFloat(detail.Slaughter_Fee) || 0) +
                              (parseFloat(detail.Corral_Fee) || 0) +
                              (parseFloat(detail.Ante_Mortem_Fee) || 0) +
                              (parseFloat(detail.Post_Mortem_Fee) || 0) +
                              (parseFloat(detail.Delivery_Fee) || 0);

            return `
                <div class="animal-detail-item mb-3 p-3 border rounded">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="mb-2">${animalName}</h6>
                            <small class="text-muted">
                                ${detail.No_of_Heads} heads × ${parseFloat(detail.No_of_Kilos)} kg
                            </small>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-success">₱${detailTotal.toLocaleString()}</div>
                        </div>
                    </div>
                    <div class="fee-breakdown mt-2 pt-2 border-top">
                        <div class="row text-small">
                            <div class="col-6">
                                <div>Slaughter: ₱${(parseFloat(detail.Slaughter_Fee) || 0).toLocaleString()}</div>
                                <div>Corral: ₱${(parseFloat(detail.Corral_Fee) || 0).toLocaleString()}</div>
                            </div>
                            <div class="col-6">
                                <div>Ante Mortem: ₱${(parseFloat(detail.Ante_Mortem_Fee) || 0).toLocaleString()}</div>
                                <div>Post Mortem: ₱${(parseFloat(detail.Post_Mortem_Fee) || 0).toLocaleString()}</div>
                                <div>Delivery: ₱${(parseFloat(detail.Delivery_Fee) || 0).toLocaleString()}</div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    // Calculate totals
    const totalSlaughter = details.reduce((sum, d) => sum + (parseFloat(d.Slaughter_Fee) || 0), 0);
    const totalCorral = details.reduce((sum, d) => sum + (parseFloat(d.Corral_Fee) || 0), 0);
    const totalAnteMortem = details.reduce((sum, d) => sum + (parseFloat(d.Ante_Mortem_Fee) || 0), 0);
    const totalPostMortem = details.reduce((sum, d) => sum + (parseFloat(d.Post_Mortem_Fee) || 0), 0);
    const totalDelivery = details.reduce((sum, d) => sum + (parseFloat(d.Delivery_Fee) || 0), 0);
    const grandTotal = totalSlaughter + totalCorral + totalAnteMortem + totalPostMortem + totalDelivery;

    content.innerHTML = `
        <div class="fee-details-container">
            <!-- Header Information -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="info-section">
                        <h6 class="section-title"><i class="fas fa-user me-2"></i>Client Information</h6>
                        <div class="info-item">
                            <strong>${feeEntry.client_name || 'Unknown Client'}</strong>
                        </div>
                        <div class="info-item">
                            <span class="text-muted">Business:</span> ${feeEntry.Business_Name || 'Individual'}
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-section">
                        <h6 class="section-title"><i class="fas fa-calendar me-2"></i>Operation Details</h6>
                        <div class="info-item">
                            <span class="text-muted">Date & Time:</span> ${operationDate}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Animal Breakdown -->
            <div class="animal-breakdown-section mb-4">
                <h6 class="section-title"><i class="fas fa-list me-2"></i>Animal Breakdown</h6>
                ${animalBreakdown || '<div class="text-muted">No animal details available</div>'}
            </div>

            <!-- Fee Summary -->
            <div class="fee-summary-section">
                <h6 class="section-title"><i class="fas fa-calculator me-2"></i>Fee Summary</h6>
                <div class="fee-summary-grid">
                    <div class="summary-row">
                        <span>Total Slaughter Fee:</span>
                        <span>₱${totalSlaughter.toLocaleString()}</span>
                    </div>
                    <div class="summary-row">
                        <span>Total Corral Fee:</span>
                        <span>₱${totalCorral.toLocaleString()}</span>
                    </div>
                    <div class="summary-row">
                        <span>Total Ante Mortem Fee:</span>
                        <span>₱${totalAnteMortem.toLocaleString()}</span>
                    </div>
                    <div class="summary-row">
                        <span>Total Post Mortem Fee:</span>
                        <span>₱${totalPostMortem.toLocaleString()}</span>
                    </div>
                    <div class="summary-row">
                        <span>Total Delivery Fee:</span>
                        <span>₱${totalDelivery.toLocaleString()}</span>
                    </div>
                    <div class="summary-row total-row">
                        <span><strong>GRAND TOTAL:</strong></span>
                        <span><strong>₱${grandTotal.toLocaleString()}</strong></span>
                    </div>
                </div>
            </div>
        </div>
    `;
}

// Print fee details
function printFeeDetails() {
    if (!window.currentFeeId) {
        showToast('No fee entry selected for printing', 'warning');
        return;
    }

    const content = document.getElementById('feeDetailsContent');
    const printWindow = window.open('', '_blank', 'width=800,height=600');

    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Fee Entry Details - Slaughter House Management System</title>
            <style>
                @page {
                    size: A4;
                    margin: 0.5in;
                }

                * {
                    box-sizing: border-box;
                }

                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    font-size: 12px;
                    line-height: 1.4;
                    color: #333;
                    margin: 0;
                    padding: 0;
                    background: white;
                }

                .print-container {
                    max-width: 100%;
                    margin: 0 auto;
                    padding: 0;
                }

                .header {
                    text-align: center;
                    border-bottom: 2px solid #333;
                    padding-bottom: 15px;
                    margin-bottom: 20px;
                }

                .header h1 {
                    font-size: 18px;
                    margin: 0 0 5px 0;
                    color: #1e40af;
                    font-weight: bold;
                }

                .logo-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 15px;
                    padding-bottom: 10px;
                    border-bottom: 1px solid #d1d5db;
                }

                .logo-left, .logo-right {
                    flex: 0 0 auto;
                }

                .logo-image {
                    width: 80px;
                    height: 80px;
                    object-fit: contain;
                }

                .header h1 {
                    font-size: 18px;
                    margin: 0 0 5px 0;
                    color: #1e40af;
                    font-weight: bold;
                }

                .header h2 {
                    font-size: 14px;
                    margin: 0 0 5px 0;
                    color: #374151;
                }

                .header p {
                    font-size: 11px;
                    margin: 0;
                    color: #6b7280;
                }

                .content-grid {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 20px;
                    margin-bottom: 20px;
                }

                .section {
                    margin-bottom: 15px;
                }

                .section-title {
                    font-weight: bold;
                    color: #1e40af;
                    margin-bottom: 8px;
                    text-transform: uppercase;
                    font-size: 11px;
                    border-bottom: 1px solid #e5e7eb;
                    padding-bottom: 3px;
                }

                .info-section {
                    background: #f9fafb;
                    border: 1px solid #e5e7eb;
                    border-radius: 4px;
                    padding: 10px;
                }

                .info-item {
                    margin-bottom: 4px;
                    font-size: 11px;
                }

                .info-item strong {
                    color: #111827;
                    display: inline-block;
                    min-width: 80px;
                }

                .animal-breakdown-section {
                    grid-column: 1 / -1;
                }

                .animal-detail-item {
                    border: 1px solid #d1d5db;
                    border-radius: 4px;
                    padding: 8px;
                    margin-bottom: 8px;
                    background: #ffffff;
                }

                .animal-detail-item h6 {
                    font-size: 12px;
                    margin: 0 0 6px 0;
                    color: #374151;
                    font-weight: 600;
                }

                .animal-info {
                    font-size: 10px;
                    color: #6b7280;
                    margin-bottom: 6px;
                }

                .fee-breakdown {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 4px;
                    font-size: 10px;
                }

                .fee-breakdown div {
                    display: flex;
                    justify-content: space-between;
                }

                .fee-total {
                    font-weight: bold;
                    color: #059669;
                    border-top: 1px solid #d1d5db;
                    padding-top: 4px;
                    margin-top: 4px;
                }

                .fee-summary-section {
                    grid-column: 1 / -1;
                    background: #f9fafb;
                    border: 1px solid #e5e7eb;
                    border-radius: 4px;
                    padding: 10px;
                }

                .fee-summary-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                    gap: 6px;
                }

                .summary-row {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 3px 0;
                    border-bottom: 1px solid #e5e7eb;
                    font-size: 11px;
                }

                .summary-row:last-child {
                    border-bottom: none;
                }

                .total-row {
                    background: #ecfdf5;
                    border-radius: 3px;
                    padding: 6px 0;
                    margin-top: 4px;
                    border-bottom: 2px solid #059669 !important;
                    font-size: 12px;
                    font-weight: bold;
                }

                .total-row span {
                    color: #059669;
                }

                .footer {
                    text-align: center;
                    margin-top: 20px;
                    padding-top: 10px;
                    border-top: 1px solid #d1d5db;
                    font-size: 10px;
                    color: #6b7280;
                }

                /* Print-specific optimizations */
                @media print {
                    body {
                        -webkit-print-color-adjust: exact;
                        print-color-adjust: exact;
                    }

                    .print-container {
                        width: 100%;
                        max-width: none;
                    }

                    .content-grid {
                        display: block;
                    }

                    .content-grid > div {
                        display: inline-block;
                        width: 48%;
                        vertical-align: top;
                        margin-right: 4%;
                    }

                    .content-grid > div:last-child {
                        margin-right: 0;
                    }

                    .animal-breakdown-section,
                    .fee-summary-section {
                        display: block !important;
                        width: 100% !important;
                        margin-top: 15px;
                        page-break-inside: avoid;
                    }
                }
            </style>
        </head>
        <body>
            <div class="print-container">
                <!-- Logo Header -->
                <div class="logo-header">
                    <div class="logo-left">
                        <img src="../system/image/citylogo.png" alt="City Logo" class="logo-image">
                    </div>
                    <div class="logo-right">
                        <img src="../system/image/ceedmo_logo.ico" alt="CEEDMO Logo" class="logo-image">
                    </div>
                </div>

                <div class="header">
                    <h1>Slaughter House Management System</h1>
                    <h2>Fee Entry Details</h2>
                    <p>Entry ID: ${window.currentFeeId} | Printed on ${new Date().toLocaleString()}</p>
                </div>

                <div class="content-grid">
                    <!-- Client Information -->
                    <div class="section">
                        <div class="section-title">Client Information</div>
                        <div class="info-section">
                            ${extractClientInfo(content)}
                        </div>
                    </div>

                    <!-- Operation Details -->
                    <div class="section">
                        <div class="section-title">Operation Details</div>
                        <div class="info-section">
                            ${extractOperationInfo(content)}
                        </div>
                    </div>

                    <!-- Animal Breakdown -->
                    <div class="section animal-breakdown-section">
                        <div class="section-title">Animal Breakdown</div>
                        ${extractAnimalBreakdown(content)}
                    </div>

                    <!-- Fee Summary -->
                    <div class="section fee-summary-section">
                        <div class="section-title">Fee Summary</div>
                        ${extractFeeSummary(content)}
                    </div>
                </div>

                <div class="footer">
                    This is an official fee breakdown report from the Slaughter House Management System
                </div>
            </div>
        </body>
        </html>
    `);

    printWindow.document.close();
    printWindow.onload = function() {
        printWindow.print();
    };
}

// Helper functions to extract content from the modal
function extractClientInfo(content) {
    const clientSection = content.querySelector('.info-section');
    if (clientSection) {
        const items = clientSection.querySelectorAll('.info-item');
        return Array.from(items).map(item => `<div class="info-item">${item.innerHTML}</div>`).join('');
    }
    return '<div class="info-item">Client information not available</div>';
}

function extractOperationInfo(content) {
    const operationSection = content.querySelectorAll('.info-section')[1];
    if (operationSection) {
        const items = operationSection.querySelectorAll('.info-item');
        return Array.from(items).map(item => `<div class="info-item">${item.innerHTML}</div>`).join('');
    }
    return '<div class="info-item">Operation information not available</div>';
}

function extractAnimalBreakdown(content) {
    const animalItems = content.querySelectorAll('.animal-detail-item');
    if (animalItems.length > 0) {
        return Array.from(animalItems).map(item => {
            const title = item.querySelector('h6')?.innerHTML || '';
            const info = item.querySelector('.text-muted')?.innerHTML || '';
            const feeBreakdown = item.querySelector('.fee-breakdown')?.innerHTML || '';
            return `
                <div class="animal-detail-item">
                    <h6>${title}</h6>
                    <div class="animal-info">${info}</div>
                    <div class="fee-breakdown">${feeBreakdown}</div>
                </div>
            `;
        }).join('');
    }
    return '<div class="animal-detail-item">No animal details available</div>';
}

function extractFeeSummary(content) {
    const feeSummary = content.querySelector('.fee-summary-grid');
    if (feeSummary) {
        return `<div class="fee-summary-grid">${feeSummary.innerHTML}</div>`;
    }
    return '<div class="summary-row">Fee summary not available</div>';
}


// Show fee entry modal
function showFeeEntryModal() {
    // Reset form and initialize
    resetForm();

    // Clear any editing state
    delete window.editingFeeId;

    // Set modal title for adding
    document.getElementById('feeEntryModalLabel').textContent = 'Record Fee Entry';

    // Show the modal
    const modal = new bootstrap.Modal(document.getElementById('feeEntryModal'));
    modal.show();
}

// Show toast notifications
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type} alert-dismissible fade show toast-notification`;
    toast.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-triangle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.remove();
    }, 5000);
}