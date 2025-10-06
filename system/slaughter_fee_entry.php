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
$uid = $user_data['user_id'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Slaughter Fee Entry | Slaughter House Management System</title>

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
    <link rel="stylesheet" href="../css/slaughter_fee_entry.css">

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

    /* Pagination Styles - Green Theme */
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
                            <h2 class="mb-2"><i class="fas fa-calculator me-2"></i>Record Fees</h2>
                            <p class="mb-1 opacity-8"><i class="fas fa-money-bill-wave me-2"></i>Slaughter Fee Entry</p>
                            <p class="mb-0 opacity-8">Record detailed fee information for slaughter operations.</p>
                        </div>
                        <div class="col-lg-3 text-end">
                            <button class="btn btn-primary btn-lg" onclick="showFeeEntryModal()">
                                <i class="fas fa-plus-circle me-2"></i>Record Fees
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search Bar -->
        <div class="row mb-3">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-12">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    <input type="text" class="form-control" id="searchInput" placeholder="Search fee entries by client name, animal type, or date...">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Fee Entries -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="fee-entries-card card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>Entries</h5>
                    </div>
                    <div class="card-body p-0">
                        <div id="loadingEntries" class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <div class="mt-2">Loading fee entries...</div>
                        </div>
                        <div class="table-responsive" id="entriesTableContainer" style="display: none;">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Client & Business</th>
                                        <th>Date</th>
                                        <th>Animals</th>
                                        <th>Total Fees</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="feeEntriesTableBody">
                                    <!-- Fee entries will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                        <div id="noEntriesMessage" class="text-center py-5" style="display: none;">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Fee Entries Yet</h5>
                            <p class="text-muted">Start by recording your first fee entry above.</p>
                        </div>
                    </div>
                    <div class="card-footer">
                        <nav aria-label="Fee entries pagination">
                            <ul class="pagination justify-content-center mb-0" id="paginationControls"></ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Fee Entry Modal -->
<div class="modal fade" id="feeEntryModal" tabindex="-1" aria-labelledby="feeEntryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="feeEntryModalLabel">
                    <i class="fas fa-file-invoice-dollar me-2"></i>Record Slaughter Fee Entry
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="feeEntryForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="clientSearch" class="form-label">Client *</label>
                            <div class="client-search-container">
                                <input type="text" class="form-control" id="clientSearch" placeholder="Type to search clients..." autocomplete="off" required>
                                <input type="hidden" id="clientSelect" name="CID" required>
                                <div class="client-suggestions" id="clientSuggestions" style="display: none;"></div>
                            </div>
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

                    <!-- Animal Details Section -->
                    <div class="animal-details-section">
                        <h6 class="section-title"><i class="fas fa-list me-2"></i>Animal Details & Fee Breakdown</h6>
                        <div id="animalDetailsContainer">
                            <!-- Animal detail cards will be added here -->
                        </div>
                        <button type="button" class="btn btn-success btn-sm mt-3" onclick="addAnimalDetail()">
                            <i class="fas fa-plus me-1"></i>Add Animal Type
                        </button>
                    </div>

                    <!-- Fee Summary -->
                    <div class="fee-summary-section mt-4">
                        <div class="fee-summary-card">
                            <h6 class="summary-title"><i class="fas fa-calculator me-2"></i>Fee Summary</h6>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="summary-item">
                                        <label>Total Slaughter Fee:</label>
                                        <span id="totalSlaughterFee">₱0.00</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-item">
                                        <label>Total Corral Fee:</label>
                                        <span id="totalCorralFee">₱0.00</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-item">
                                        <label>Total Ante Mortem Fee:</label>
                                        <span id="totalAnteMortemFee">₱0.00</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-item">
                                        <label>Total Post Mortem Fee:</label>
                                        <span id="totalPostMortemFee">₱0.00</span>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="summary-item">
                                        <label>Total Delivery Fee:</label>
                                        <span id="totalDeliveryFee">₱0.00</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="summary-item total-grand">
                                        <label>GRAND TOTAL:</label>
                                        <span id="grandTotal">₱0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="saveFeeEntry()">
                    <i class="fas fa-save me-2"></i>Save Fee Entry
                </button>
            </div>
        </div>
    </div>
</div>

<!-- View Fee Details Modal -->
<div class="modal fade" id="viewFeeModal" tabindex="-1" aria-labelledby="viewFeeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewFeeModalLabel">
                    <i class="fas fa-eye me-2"></i>Fee Entry Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="feeDetailsContent">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <div class="mt-2">Loading fee details...</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Close
                </button>
                <button type="button" class="btn btn-primary" onclick="printFeeDetails()">
                    <i class="fas fa-print me-2"></i>Print
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

<script src="../js/slaughter_fee_entry.js"></script>

<script>
const USER_ID = <?php echo $uid; ?>;

let searchTimeout;

// Perform search with debouncing
function performSearch() {
    const searchTerm = document.getElementById('searchInput').value.trim();

    // Clear previous timeout
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }

    // Set new timeout for debounced search
    searchTimeout = setTimeout(() => {
        loadFeeEntries(searchTerm);
    }, 300); // 300ms delay
}

function clearSearch() {
    document.getElementById('searchInput').value = '';
    loadFeeEntries(''); // Reload all entries
}

// Add real-time search support
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', performSearch);
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                // Prevent form submission if inside a form
                e.preventDefault();
                performSearch();
            }
        });
    }
});
</script>

</body>
</html>