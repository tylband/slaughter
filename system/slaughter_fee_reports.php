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
    <title>Slaughter Fee Reports | Slaughter House Management System</title>

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
        /* Enhanced Pagination Styles - Green Theme */
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
                            <h2 class="mb-2"><i class="fas fa-chart-bar me-2"></i>Slaughter Reports</h2>
                            <p class="mb-1 opacity-8"><i class="fas fa-file-invoice-dollar me-2"></i>Slaughter Fee Reports</p>
                            <p class="mb-0 opacity-8">View detailed fee reports with date range filtering.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Date Range Selection -->
        <div class="row mb-3">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="row align-items-end">
                            <div class="col-md-4">
                                <label for="startDate" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="startDate">
                            </div>
                            <div class="col-md-4">
                                <label for="endDate" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="endDate">
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-primary me-2" onclick="applyDateFilter()">
                                    <i class="fas fa-search me-1"></i>Apply Filter
                                </button>
                                <button class="btn btn-secondary" onclick="clearDateFilter()">
                                    <i class="fas fa-times me-1"></i>Clear
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reports Table -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-table me-2"></i>Slaughter Fee Report
                            <span id="dateRangeDisplay" class="text-muted ms-2"></span>
                        </h5>
                        <div class="export-buttons">
                            <button class="btn btn-success btn-sm" onclick="exportPDF()">
                                <i class="fas fa-print me-1"></i>Print Report
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Summary Cards -->
                        <div class="row mb-4" id="summaryCards" style="display: none;">
                            <div class="col-12">
                                <div class="card text-white" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                    <div class="card-body text-center">
                                        <div class="row">
                                            <div class="col-6">
                                                <h5 class="card-title">Total Operations</h5>
                                                <h3 id="totalOperations">0</h3>
                                            </div>
                                            <div class="col-6">
                                                <h5 class="card-title">Total Fees</h5>
                                                <h3 id="totalFees">₱0.00</h3>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Loading State -->
                        <div id="reportLoading" class="text-center py-5">
                            <div class="spinner-border" style="color: #10b981;" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <div class="mt-2">Loading report data...</div>
                        </div>

                        <!-- Report Table -->
                        <div class="table-responsive" id="reportTableContainer" style="display: none;">
                            <table class="table table-hover table-striped">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Date</th>
                                        <th>Client Name</th>
                                        <th>Animal</th>
                                        <th>Heads</th>
                                        <th>Kilos</th>
                                        <th>Slaughter Fee</th>
                                        <th>Corral Fee</th>
                                        <th>Ante Mortem</th>
                                        <th>Post Mortem</th>
                                        <th>Delivery Fee</th>
                                        <th>Total Fee</th>
                                    </tr>
                                </thead>
                                <tbody id="reportTableBody">
                                    <!-- Report data will be loaded here -->
                                </tbody>
                            </table>
                        </div>

                        <!-- No Data Message -->
                        <div id="noDataMessage" class="text-center py-5" style="display: none;">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Data Found</h5>
                            <p class="text-muted">No operations found for the selected date range. Try adjusting your date filters.</p>
                        </div>
                    </div>
                    <div class="card-footer">
                        <nav aria-label="Reports pagination">
                            <ul class="pagination justify-content-center mb-0" id="paginationControls"></ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Argon Core JS -->
<script src="argondashboard/assets/js/core/bootstrap.bundle.min.js"></script>
<script src="argondashboard/assets/js/plugins/perfect-scrollbar.min.js"></script>
<script src="argondashboard/assets/js/plugins/smooth-scrollbar.min.js"></script>
<script src="argondashboard/assets/js/argon-dashboard.min.js"></script>

<script src="../api_config.js.php"></script>

<script>
const USER_ID = <?php echo $uid; ?>;

// Pagination variables
let currentPage = 1;
let totalPages = 1;
let currentFilters = {};
let allReportsData = []; // Store all data for client-side pagination

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    loadReports();
});

function loadReports(page = 1) {
    // Show loading
    document.getElementById('reportLoading').style.display = 'block';
    document.getElementById('reportTableContainer').style.display = 'none';
    document.getElementById('summaryCards').style.display = 'none';
    document.getElementById('noDataMessage').style.display = 'none';
    document.getElementById('paginationControls').innerHTML = '';

    currentPage = page;

    // Get date filter parameters
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;

    // Store current filters
    currentFilters = { startDate, endDate };

    // Build query string for all data (no pagination)
    let queryString = 'limit=1000'; // Get all data for client-side pagination
    if (startDate) queryString += `&start_date=${startDate}`;
    if (endDate) queryString += `&end_date=${endDate}`;

    console.log('Loading all reports data with query:', queryString);

    apiCall(`../api/api_fee_reports.php?${queryString}`)
        .then(response => response.json())
        .then(data => {
            console.log('API Response:', data);
            document.getElementById('reportLoading').style.display = 'none';

            if (data.success && data.operations) {
                // Store all data for client-side pagination
                allReportsData = data.operations;

                // Calculate total pages based on 5 items per page
                totalPages = Math.ceil(allReportsData.length / 5) || 1;
                console.log('Client-side pagination:', {
                    totalItems: allReportsData.length,
                    itemsPerPage: 5,
                    totalPages,
                    currentPage
                });

                // Get paginated data for current page
                const paginatedData = getPaginatedData(allReportsData, currentPage, 5);

                if (paginatedData.length > 0) {
                    // Create paginated response structure
                    const paginatedResponse = {
                        ...data,
                        operations: paginatedData,
                        total_pages: totalPages,
                        current_page: currentPage,
                        total: allReportsData.length,
                        limit: 5
                    };

                    displayReports(paginatedResponse);
                    updateDateRangeDisplay(data.date_range);
                    renderPaginationControls();
                    document.getElementById('paginationControls').parentElement.style.display = 'block';
                } else {
                    document.getElementById('noDataMessage').style.display = 'block';
                    document.getElementById('paginationControls').innerHTML = '';
                }
            } else {
                console.error('Error loading reports:', data.message);
                showError('Failed to load reports: ' + data.message);
                document.getElementById('noDataMessage').style.display = 'block';
                document.getElementById('paginationControls').innerHTML = '';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('reportLoading').style.display = 'none';
            document.getElementById('noDataMessage').style.display = 'block';
            showError('Network error occurred while loading reports.');
        });
}

// Client-side pagination helper function
function getPaginatedData(data, page, limit) {
    const startIndex = (page - 1) * limit;
    const endIndex = startIndex + limit;
    return data.slice(startIndex, endIndex);
}

function displayReports(data) {
    // Update summary cards
    document.getElementById('totalOperations').textContent = data.summary.total_operations;
    document.getElementById('totalFees').textContent = '₱' + parseFloat(data.summary.total_fees).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    const tbody = document.getElementById('reportTableBody');
    tbody.innerHTML = '';

    if (data.operations.length === 0) {
        document.getElementById('reportLoading').style.display = 'none';
        document.getElementById('noDataMessage').style.display = 'block';
        return;
    }

    data.operations.forEach(operation => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${formatDate(operation.date)}</td>
            <td>${operation.Firstname} ${operation.Surname}</td>
            <td>${operation.Animal}</td>
            <td>${operation.No_of_Heads}</td>
            <td>${operation.No_of_Kilos}</td>
            <td>₱${parseFloat(operation.Slaughter_Fee).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
            <td>₱${parseFloat(operation.Corral_Fee).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
            <td>₱${parseFloat(operation.Ante_Mortem_Fee).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
            <td>₱${parseFloat(operation.Post_Mortem_Fee).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
            <td>₱${parseFloat(operation.Delivery_Fee).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
            <td class="fw-bold">₱${parseFloat(operation.total_fee).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
        `;
        tbody.appendChild(row);
    });

    // Show results
    document.getElementById('reportLoading').style.display = 'none';
    document.getElementById('summaryCards').style.display = 'block';
    document.getElementById('reportTableContainer').style.display = 'block';
}

function updateDateRangeDisplay(dateRange) {
    const display = document.getElementById('dateRangeDisplay');
    if (dateRange.start_date || dateRange.end_date) {
        let rangeText = '(';
        if (dateRange.start_date && dateRange.end_date) {
            rangeText += `${dateRange.start_date} to ${dateRange.end_date}`;
        } else if (dateRange.start_date) {
            rangeText += `From ${dateRange.start_date}`;
        } else if (dateRange.end_date) {
            rangeText += `Until ${dateRange.end_date}`;
        }
        rangeText += ')';
        display.textContent = rangeText;
    } else {
        display.textContent = '(All Dates)';
    }
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function getMonthName(monthNumber) {
    const months = ['January', 'February', 'March', 'April', 'May', 'June',
                   'July', 'August', 'September', 'October', 'November', 'December'];
    return months[monthNumber - 1];
}


function exportPDF() {
    // Show loading state
    const exportBtn = document.querySelector('button[onclick="exportPDF()"]');
    const originalText = exportBtn.innerHTML;
    exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Generating...';
    exportBtn.disabled = true;

    // Get date filter parameters
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;

    // Build query string for all data (no pagination)
    let queryString = 'export=1&limit=1000'; // Export all data
    if (startDate) queryString += `&start_date=${startDate}`;
    if (endDate) queryString += `&end_date=${endDate}`;

    // Fetch all report data for export
    apiCall(`../api/api_fee_reports.php?${queryString}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                generatePrintLayout(data);
            } else {
                alert('Error loading report data: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error generating print layout');
        })
        .finally(() => {
            exportBtn.innerHTML = originalText;
            exportBtn.disabled = false;
        });
}

function generatePrintLayout(data) {
    // Calculate totals
    const totalOperations = data.summary.total_operations;
    const totalFees = parseFloat(data.summary.total_fees).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    // Build date range display
    let dateRangeText = 'All Dates';
    if (data.date_range.start_date || data.date_range.end_date) {
        if (data.date_range.start_date && data.date_range.end_date) {
            dateRangeText = `${data.date_range.start_date} to ${data.date_range.end_date}`;
        } else if (data.date_range.start_date) {
            dateRangeText = `From ${data.date_range.start_date}`;
        } else if (data.date_range.end_date) {
            dateRangeText = `Until ${data.date_range.end_date}`;
        }
    }

    // Build table rows
    let tableRows = '';
    if (data.operations.length > 0) {
        data.operations.forEach(operation => {
            const date = formatDate(operation.date);
            const clientName = `${operation.Firstname} ${operation.Surname}`;
            const animal = operation.Animal;
            const heads = operation.No_of_Heads;
            const kilos = operation.No_of_Kilos;
            const slaughterFee = parseFloat(operation.Slaughter_Fee).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            const corralFee = parseFloat(operation.Corral_Fee).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            const anteMortemFee = parseFloat(operation.Ante_Mortem_Fee).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            const postMortemFee = parseFloat(operation.Post_Mortem_Fee).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            const deliveryFee = parseFloat(operation.Delivery_Fee).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            const totalFee = parseFloat(operation.total_fee).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

            tableRows += `
                <tr>
                    <td>${date}</td>
                    <td>${clientName}</td>
                    <td>${animal}</td>
                    <td class="text-center">${heads}</td>
                    <td class="text-end">${kilos}</td>
                    <td class="text-end">₱${slaughterFee}</td>
                    <td class="text-end">₱${corralFee}</td>
                    <td class="text-end">₱${anteMortemFee}</td>
                    <td class="text-end">₱${postMortemFee}</td>
                    <td class="text-end">₱${deliveryFee}</td>
                    <td class="text-end"><strong>₱${totalFee}</strong></td>
                </tr>
            `;
        });
    } else {
        tableRows = '<tr><td colspan="11" class="text-center">No operations found for the selected date range.</td></tr>';
    }

    // Create print window
    const printWindow = window.open('', '_blank', 'width=1200,height=800');

    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Slaughter Fee Report - ${dateRangeText}</title>
            <style>
                @page {
                    size: A4 landscape;
                    margin: 0.5in;
                }

                * {
                    box-sizing: border-box;
                }

                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    font-size: 10px;
                    line-height: 1.3;
                    color: #333;
                    margin: 0;
                    padding: 0;
                    background: white;
                }

                .print-container {
                    width: 100%;
                    max-width: none;
                    margin: 0;
                    padding: 0;
                }

                .header {
                    text-align: center;
                    border-bottom: 2px solid #333;
                    padding-bottom: 10px;
                    margin-bottom: 15px;
                }

                .header h1 {
                    font-size: 18px;
                    margin: 0 0 5px 0;
                    color: #059669;
                    font-weight: bold;
                }

                .header h2 {
                    font-size: 12px;
                    margin: 0;
                    color: #374151;
                }

                .summary-section {
                    margin-bottom: 15px;
                    padding: 10px;
                    background: #f8f9fa;
                    border: 1px solid #dee2e6;
                    border-radius: 4px;
                }

                .summary-grid {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 10px;
                }

                .summary-item {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 5px 0;
                    border-bottom: 1px solid #dee2e6;
                }

                .summary-item:last-child {
                    border-bottom: none;
                    font-weight: bold;
                    font-size: 11px;
                }

                .table-responsive {
                    width: 100%;
                    overflow-x: auto;
                }

                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 15px;
                    font-size: 9px;
                }

                th, td {
                    border: 1px solid #dee2e6;
                    padding: 4px 6px;
                    vertical-align: top;
                }

                th {
                    background-color: #f8f9fa !important;
                    font-weight: bold;
                    text-align: center;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }

                .text-center {
                    text-align: center;
                }

                .text-end {
                    text-align: right;
                }

                .footer {
                    text-align: center;
                    margin-top: 15px;
                    padding-top: 10px;
                    border-top: 1px solid #dee2e6;
                    font-size: 8px;
                    color: #6c757d;
                }

                @media print {
                    body {
                        -webkit-print-color-adjust: exact;
                        print-color-adjust: exact;
                    }

                    .print-container {
                        width: 100%;
                        max-width: none;
                    }

                    table {
                        width: 100%;
                        table-layout: fixed;
                    }

                    th, td {
                        word-wrap: break-word;
                    }
                }
            </style>
        </head>
        <body>
            <div class="print-container">
                <div class="header">
                    <h1>Slaughter House Management System</h1>
                    <h2>Fee Report - ${dateRangeText}</h2>
                    <p style="font-size: 9px; margin: 5px 0 0 0; color: #6c757d;">Generated on ${new Date().toLocaleString()}</p>
                </div>

                <div class="summary-section">
                    <div class="summary-grid">
                        <div class="summary-item">
                            <span>Total Operations:</span>
                            <span>${totalOperations}</span>
                        </div>
                        <div class="summary-item">
                            <span>Total Fees:</span>
                            <span>₱${totalFees}</span>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 8%;">Date</th>
                                <th style="width: 12%;">Client Name</th>
                                <th style="width: 10%;">Animal</th>
                                <th style="width: 6%;">Heads</th>
                                <th style="width: 8%;">Kilos</th>
                                <th style="width: 9%;">Slaughter Fee</th>
                                <th style="width: 9%;">Corral Fee</th>
                                <th style="width: 9%;">Ante Mortem</th>
                                <th style="width: 9%;">Post Mortem</th>
                                <th style="width: 9%;">Delivery Fee</th>
                                <th style="width: 10%;">Total Fee</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tableRows}
                        </tbody>
                    </table>
                </div>

                <div class="footer">
                    This is an official fee report from the Slaughter House Management System
                </div>
            </div>
        </body>
        </html>
    `);

    printWindow.document.close();

    // Wait for content to load then print
    printWindow.onload = function() {
        printWindow.print();
    };
}

function applyDateFilter() {
    loadReports(1); // Reset to page 1 when applying filters
}

function clearDateFilter() {
    document.getElementById('startDate').value = '';
    document.getElementById('endDate').value = '';
    loadReports(1); // Reset to page 1 when clearing filters
}

// Render pagination controls
function renderPaginationControls() {
    const controls = document.getElementById('paginationControls');
    if (!controls) return;

    if (totalPages <= 1) {
        controls.innerHTML = '';
        return;
    }

    let html = '';

    // Previous button
    html += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="loadReports(${currentPage - 1}); return false;" ${currentPage === 1 ? 'tabindex="-1"' : ''}>Previous</a>
    </li>`;

    // Page numbers
    for (let i = Math.max(1, currentPage - 2); i <= Math.min(totalPages, currentPage + 2); i++) {
        html += `<li class="page-item ${i === currentPage ? 'active' : ''}">
            <a class="page-link" href="#" onclick="loadReports(${i}); return false;">${i}</a>
        </li>`;
    }

    // Next button
    html += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="loadReports(${currentPage + 1}); return false;" ${currentPage === totalPages ? 'tabindex="-1"' : ''}>Next</a>
    </li>`;

    controls.innerHTML = html;
}

function showError(message) {
    // Simple error display - you can enhance this
    alert(message);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    loadReports();
});
</script>

</body>
</html>