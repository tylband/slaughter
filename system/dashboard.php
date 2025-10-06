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

// Get user department (if needed for this system)
$department = "Slaughter House Management";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Slaughter House Management System</title>

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
            --text-muted-dark: #6b7280;
            --border-dark: rgba(5, 150, 105, 0.2);
            --shadow-dark: rgba(0,0,0,0.1);
            --green-primary: #10b981;
            --green-secondary: #059669;
            --green-light: #d1fae5;
            --green-accent: #ecfdf5;
        }

        /* Dark theme overrides - Dark Green + White + Green */
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
        .stat-card {
            background: var(--card-bg);
            color: var(--text-primary) !important;
            border-radius: 15px;
            box-shadow: 0 4px 20px var(--shadow-dark);
            border: 1px solid var(--border-light);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(16, 185, 129, 0.2);
        }
        .stat-card .text-muted {
            color: var(--text-muted) !important;
        }
        .stat-card h6 {
            color: var(--text-muted) !important;
        }
        .stat-card h3 {
            color: var(--text-primary) !important;
        }
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            background: var(--bg-accent);
            border: 2px solid rgba(255,255,255,0.2);
        }
        .chart-container {
            background: var(--card-bg);
            color: var(--text-primary) !important;
            border-radius: 15px;
            box-shadow: 0 4px 20px var(--shadow-dark);
            border: 1px solid var(--border-light);
            padding: 25px;
        }
        .chart-container h5 {
            color: var(--text-primary) !important;
        }
        .recent-activity {
            background: var(--card-bg);
            color: var(--text-on-dark);
            border-radius: 15px;
            box-shadow: 0 4px 20px var(--shadow-dark);
            border: 1px solid var(--border-dark);
        }
        .activity-item {
            padding: 15px;
            border-bottom: 1px solid var(--border-dark);
            transition: background-color 0.3s ease;
        }
        .activity-item:hover {
            background-color: rgba(16, 185, 129, 0.05);
        }
        .activity-item:last-child {
            border-bottom: none;
        }
        .activity-item .text-muted {
            color: var(--text-muted-dark) !important;
        }
        .btn-primary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: 1px solid rgba(255,255,255,0.2);
            color: var(--text-on-dark);
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-2px);
        }
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: 1px solid rgba(255,255,255,0.2);
        }
        .btn-info {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            border: 1px solid rgba(255,255,255,0.2);
        }
        .btn-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border: 1px solid rgba(255,255,255,0.2);
        }

        /* Statistics card styling */
        .stat-card .numbers {
            text-align: left;
        }

        .stat-card .numbers p {
            margin: 0;
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .stat-card .numbers h3 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .bg-primary { background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important; }
        .bg-success { background: linear-gradient(135deg, #059669 0%, #047857 100%) !important; }
        .bg-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important; }
        .bg-info { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%) !important; }
        .text-primary { color: #10b981 !important; }
        .text-success { color: #10b981 !important; }
        .text-info { color: #06b6d4 !important; }
        .text-warning { color: #f59e0b !important; }

        /* Department overview table styling */
        .status-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 4px;
        }

        .status-indicator.bg-primary { background-color: #10b981 !important; }
        .status-indicator.bg-warning { background-color: #f59e0b !important; }
        .status-indicator.bg-success { background-color: #059669 !important; }
        .status-indicator.bg-danger { background-color: #ef4444 !important; }

        #departmentTable {
            margin-bottom: 0;
        }

        #departmentTable thead th {
            border-bottom: 2px solid var(--border-light);
            font-weight: 600;
            padding: 12px 8px;
        }

        #departmentTable tbody td {
            padding: 12px 8px;
            vertical-align: middle;
        }

        #departmentTable tbody tr:hover {
            background: rgba(16, 185, 129, 0.05);
        }

        .department-name {
            font-weight: 600;
            color: var(--text-primary);
        }

        .status-count {
            font-weight: 500;
            text-align: center;
            min-width: 60px;
        }

        .status-count.draft { color: #10b981; }
        .status-count.submitted { color: #f59e0b; }
        .status-count.approved { color: #059669; }
        .status-count.rejected { color: #ef4444; }

        .total-count {
            font-weight: 700;
            color: var(--text-primary);
            text-align: center;
        }

        /* Logs table styling */
        .logs-table-container {
            max-height: 600px;
            overflow-y: auto;
        }

        .log-activity-type {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .log-activity-type.user_login { background-color: rgba(16, 185, 129, 0.1); color: #059669; }
        .log-activity-type.user_logout { background-color: rgba(239, 68, 68, 0.1); color: #dc2626; }
        .log-activity-type.client_created { background-color: rgba(59, 130, 246, 0.1); color: #2563eb; }
        .log-activity-type.client_updated { background-color: rgba(245, 158, 11, 0.1); color: #d97706; }
        .log-activity-type.business_created { background-color: rgba(139, 69, 19, 0.1); color: #92400e; }
        .log-activity-type.slaughter_created { background-color: rgba(168, 85, 247, 0.1); color: #7c3aed; }
        .log-activity-type.fee_entry_created { background-color: rgba(34, 197, 94, 0.1); color: #16a34a; }
        .log-activity-type.system_config { background-color: rgba(156, 163, 175, 0.1); color: #6b7280; }

        .log-timestamp {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .log-user {
            font-weight: 500;
            color: var(--text-primary);
        }

        .log-description {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .log-record-info {
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .pagination-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .pagination-btn {
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            border: 1px solid var(--border-light);
            background: var(--card-bg);
            color: #374151;
            border-radius: 0.375rem;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 500;
            margin: 0 0.125rem;
        }

        .pagination-btn:hover:not(:disabled) {
            background: var(--green-light);
            border-color: var(--green-primary);
            color: var(--green-primary);
            transform: translateY(-1px);
        }

        .pagination-btn.active {
            background: var(--green-primary) !important;
            color: white !important;
            border-color: var(--green-primary) !important;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);
        }

        .pagination-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            background: var(--card-bg);
        }

        /* Dark theme pagination */
        [data-theme="dark"] .pagination-btn {
            color: #f9fafb;
            border-color: rgba(16, 185, 129, 0.3);
        }

        [data-theme="dark"] .pagination-btn:hover:not(:disabled) {
            background: rgba(16, 185, 129, 0.1);
            border-color: var(--green-primary);
            color: var(--green-primary);
        }

        [data-theme="dark"] .pagination-btn.active {
            background: var(--green-primary) !important;
            color: white !important;
            border-color: var(--green-primary) !important;
            box-shadow: 0 2px 4px rgba(16, 185, 129, 0.4);
        }

        /* Dark theme pagination visibility */
        [data-theme="dark"] .pagination-btn {
            color: #f9fafb;
            border-color: rgba(16, 185, 129, 0.3);
        }

        [data-theme="dark"] .pagination-btn:hover:not(:disabled) {
            background: rgba(16, 185, 129, 0.1);
            border-color: var(--green-primary);
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
                            <h2 class="mb-2"><i class="fas fa-chart-line me-2"></i>Welcome back, <?php echo $user; ?>!</h2>
                            <p class="mb-1 opacity-8"><i class="fas fa-building me-2"></i><?php echo $department; ?></p>
                            <p class="mb-0 opacity-8">Here's what's happening with your slaughter house operations today.</p>
                        </div>
                        <div class="col-lg-3 text-end">
                            <div class="dashboard-icon">
                                <i class="fas fa-tachometer-alt fa-3x opacity-4"></i>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted" id="lastUpdate">Loading...</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-xl-6 col-md-6 mb-4">
                <div class="stat-card card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Total Clients</p>
                                    <h3 class="font-weight-bolder mb-0" id="totalClients">
                                        <i class="fas fa-spinner fa-spin"></i>
                                    </h3>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="stat-icon bg-primary">
                                    <i class="fas fa-users text-white"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-6 col-md-6 mb-4">
                <div class="stat-card card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Active Businesses</p>
                                    <h3 class="font-weight-bolder mb-0" id="totalBusinesses">
                                        <i class="fas fa-spinner fa-spin"></i>
                                    </h3>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="stat-icon bg-warning">
                                    <i class="fas fa-building text-white"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>




        <!-- System Logs Section -->
        <div class="row">
            <!-- System Logs -->
            <div class="col-xl-12 mb-4">
                <div class="recent-activity card">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list-alt me-2"></i>System Activity Logs</h5>
                        <div class="d-flex gap-2">
                            <select class="form-select form-select-sm" id="activityTypeFilter" style="width: auto;">
                                <option value="">All Activities</option>
                            </select>
                            <button class="btn btn-sm btn-outline-primary" onclick="refreshLogs()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <!-- Logs Table -->
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="logsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th width="5%">#</th>
                                        <th width="15%">Timestamp</th>
                                        <th width="15%">User</th>
                                        <th width="15%">Activity Type</th>
                                        <th width="35%">Description</th>
                                        <th width="15%">Table/Record</th>
                                    </tr>
                                </thead>
                                <tbody id="logsTableBody">
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
                                            <div class="d-flex align-items-center justify-content-center">
                                                <div class="activity-icon bg-info text-white rounded me-3">
                                                    <i class="fas fa-spinner fa-spin"></i>
                                                </div>
                                                <div>
                                                    <small class="text-muted">Loading system logs...</small>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div class="d-flex justify-content-between align-items-center p-3 border-top">
                            <div class="text-muted">
                                <small>Showing <span id="logsCount">0</span> logs</small>
                            </div>
                            <div id="logsPagination">
                                <!-- Pagination buttons will be inserted here -->
                            </div>
                        </div>
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

function loadDashboardStats() {
    // Show loading indicator
    const lastUpdateEl = document.getElementById('lastUpdate');
    lastUpdateEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';

    apiCall(`${API_BASE_URL}/api_dashboard_stats.php`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update statistics cards

                // Update charts with real data
                updateMonthlyChart(data.monthly_data);
                updateDepartmentOverview(data.department_data);

                // Update last update time
                const now = new Date();
                lastUpdateEl.innerHTML = '<i class="fas fa-clock"></i> Updated ' + now.toLocaleTimeString('en-US', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
            } else {
                console.error('Failed to load dashboard data:', data.message);
                lastUpdateEl.innerHTML = '<i class="fas fa-exclamation-triangle text-warning"></i> Update failed';
            }
        })
        .catch(error => {
            console.error('Error loading dashboard stats:', error);
            lastUpdateEl.innerHTML = '<i class="fas fa-exclamation-triangle text-warning"></i> Update failed';
        });
}

function loadSlaughterStats() {
    apiCall(`${API_BASE_URL}/api_slaughter_dashboard_stats.php`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update slaughter statistics cards
                document.getElementById('totalClients').textContent = data.stats.total_clients;
                document.getElementById('totalBusinesses').textContent = data.stats.active_businesses;
            } else {
                console.error('Failed to load slaughter data:', data.message);
            }
        })
        .catch(error => {
            console.error('Error loading slaughter stats:', error);
        });
}

let currentLogs = [];
let currentPage = 1;
let logsPerPage = 5;
let filteredLogs = [];
let activityTypes = [];

function loadSystemLogs() {
    // Load system activity logs from the logging API
    apiCall(`${API_BASE_URL}/api_logs.php?action=get_recent&limit=50`)
        .then(response => {
            // Check if response is ok
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text(); // Get raw text first to debug
        })
        .then(text => {
            try {
                // Clean the response text - remove any potential BOM or extra whitespace
                const cleanText = text.trim();

                // Try to parse as JSON
                const data = JSON.parse(cleanText);

                if (data.success) {
                    currentLogs = data.logs || [];
                    filteredLogs = [...currentLogs];

                    // Extract unique activity types for filter dropdown
                    activityTypes = [...new Set(currentLogs.map(log => log.activity_type).filter(Boolean))];
                    populateActivityTypeFilter();

                    // Update the logs display
                    updateLogsDisplay();
                } else {
                    console.error('Error loading logs:', data.message);
                    showLogsError(data.message || 'Unknown error');
                }
            } catch (parseError) {
                console.error('JSON parse error:', parseError);
                console.error('Response text (first 300 chars):', text.substring(0, 300));
                console.error('Response text (last 300 chars):', text.substring(text.length - 300));

                // Try to extract valid JSON if response contains extra content
                try {
                    // Look for the first { and last } to extract JSON
                    const firstBrace = text.indexOf('{');
                    const lastBrace = text.lastIndexOf('}');

                    if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
                        const jsonPart = text.substring(firstBrace, lastBrace + 1);
                        console.log('Attempting to parse extracted JSON portion...');
                        const data = JSON.parse(jsonPart);

                        if (data.success) {
                            currentLogs = data.logs || [];
                            filteredLogs = [...currentLogs];
                            activityTypes = [...new Set(currentLogs.map(log => log.activity_type).filter(Boolean))];
                            populateActivityTypeFilter();
                            updateLogsDisplay();
                            return;
                        }
                    }
                } catch (extractError) {
                    console.error('Failed to extract JSON portion:', extractError);
                }

                // Check if response contains PHP errors or warnings
                if (text.includes('Warning:') || text.includes('Notice:') || text.includes('Fatal error:')) {
                    console.error('Response contains PHP errors/warnings');
                    showLogsError('Server error occurred while loading logs');
                } else {
                    showLogsError('Invalid response format from server');
                }
            }
        })
        .catch(error => {
            console.error('Error loading system logs:', error);

            // Provide more specific error messages
            let errorMessage = 'Failed to load system logs';
            if (error.message.includes('fetch')) {
                errorMessage = 'Network error - unable to connect to server';
            } else if (error.message.includes('401')) {
                errorMessage = 'Authentication error - please log in again';
            } else if (error.message.includes('403')) {
                errorMessage = 'Access denied - insufficient permissions';
            } else if (error.message.includes('500')) {
                errorMessage = 'Server error - please try again later';
            }

            showLogsError(errorMessage);
        });
}

function populateActivityTypeFilter() {
    const filterSelect = document.getElementById('activityTypeFilter');
    if (!filterSelect) return;

    // Clear existing options (except "All Activities")
    while (filterSelect.children.length > 1) {
        filterSelect.removeChild(filterSelect.lastChild);
    }

    // Add activity types
    activityTypes.forEach(type => {
        const option = document.createElement('option');
        option.value = type;
        option.textContent = formatActivityTypeName(type);
        filterSelect.appendChild(option);
    });
}

function formatActivityTypeName(type) {
    return type.split('_').map(word =>
        word.charAt(0).toUpperCase() + word.slice(1)
    ).join(' ');
}

function filterLogs() {
    const selectedType = document.getElementById('activityTypeFilter').value;

    if (!selectedType) {
        filteredLogs = [...currentLogs];
    } else {
        filteredLogs = currentLogs.filter(log => log.activity_type === selectedType);
    }

    currentPage = 1; // Reset to first page when filtering
    updateLogsDisplay();
}

function refreshLogs() {
    // Show loading state
    showLogsLoading();

    // Reload logs
    loadSystemLogs();
}

function updateLogsDisplay() {
    const tableBody = document.getElementById('logsTableBody');
    const logsCount = document.getElementById('logsCount');
    const paginationContainer = document.getElementById('logsPagination');

    if (!tableBody || !logsCount || !paginationContainer) return;

    // Update count
    logsCount.textContent = filteredLogs.length;

    // Calculate pagination
    const totalPages = Math.ceil(filteredLogs.length / logsPerPage);
    const startIndex = (currentPage - 1) * logsPerPage;
    const endIndex = startIndex + logsPerPage;
    const logsToShow = filteredLogs.slice(startIndex, endIndex);

    // Update table
    if (logsToShow.length === 0) {
        tableBody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-4">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No Logs Found</h5>
                    <p class="text-muted">No system activities match the current filter</p>
                </td>
            </tr>
        `;
    } else {
        tableBody.innerHTML = logsToShow.map((log, index) => createLogRow(log, startIndex + index + 1)).join('');
    }

    // Update pagination
    updatePagination(totalPages);
}

function createLogRow(log, displayIndex) {
    const timestamp = new Date(log.created_at).toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });

    const activityTypeClass = `log-activity-type ${log.activity_type}`;
    const recordInfo = log.table_affected && log.record_id
        ? `${log.table_affected} #${log.record_id}`
        : (log.table_affected || 'N/A');

    return `
        <tr>
            <td>${displayIndex}</td>
            <td>
                <div class="log-timestamp">${timestamp}</div>
            </td>
            <td>
                <div class="log-user">
                    <i class="fas fa-user me-1"></i>${log.username || 'System'}
                </div>
                ${log.ip_address ? `<small class="text-muted">${log.ip_address}</small>` : ''}
            </td>
            <td>
                <span class="${activityTypeClass}">${formatActivityTypeName(log.activity_type)}</span>
            </td>
            <td>
                <div class="log-description" title="${log.activity_description}">
                    ${log.activity_description}
                </div>
                ${log.old_values || log.new_values ? `
                    <div class="mt-1">
                        <button class="btn btn-sm btn-outline-secondary" onclick="toggleLogDetails(${displayIndex})">
                            <i class="fas fa-info-circle me-1"></i>Details
                        </button>
                    </div>
                    <div id="logDetails-${displayIndex}" class="log-details mt-2" style="display: none;">
                        ${createLogDetails(log)}
                    </div>
                ` : ''}
            </td>
            <td>
                <div class="log-record-info">${recordInfo}</div>
            </td>
        </tr>
    `;
}

function createLogDetails(log) {
    let details = '';

    if (log.old_values) {
        details += `
            <div class="mb-2">
                <strong>Previous Values:</strong>
                <pre class="bg-light p-2 rounded small">${JSON.stringify(log.old_values, null, 2)}</pre>
            </div>
        `;
    }

    if (log.new_values) {
        details += `
            <div class="mb-2">
                <strong>New Values:</strong>
                <pre class="bg-light p-2 rounded small">${JSON.stringify(log.new_values, null, 2)}</pre>
            </div>
        `;
    }

    return details;
}

function toggleLogDetails(index) {
    const detailsElement = document.getElementById(`logDetails-${index}`);
    if (detailsElement) {
        const isVisible = detailsElement.style.display !== 'none';
        detailsElement.style.display = isVisible ? 'none' : 'block';
    }
}

function updatePagination(totalPages) {
    const paginationContainer = document.getElementById('logsPagination');
    if (!paginationContainer) return;

    if (totalPages <= 1) {
        paginationContainer.innerHTML = '';
        return;
    }

    let paginationHtml = '<div class="pagination-controls">';

    // Previous button
    paginationHtml += `
        <button class="pagination-btn ${currentPage === 1 ? 'disabled' : ''}"
                onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>
            <i class="fas fa-chevron-left"></i>
        </button>
    `;

    // Page numbers
    const startPage = Math.max(1, currentPage - 2);
    const endPage = Math.min(totalPages, currentPage + 2);

    for (let i = startPage; i <= endPage; i++) {
        paginationHtml += `
            <button class="pagination-btn ${i === currentPage ? 'active' : ''}"
                    onclick="changePage(${i})">
                ${i}
            </button>
        `;
    }

    // Next button
    paginationHtml += `
        <button class="pagination-btn ${currentPage === totalPages ? 'disabled' : ''}"
                onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>
            <i class="fas fa-chevron-right"></i>
        </button>
    `;

    paginationHtml += '</div>';
    paginationContainer.innerHTML = paginationHtml;
}

function changePage(page) {
    if (page >= 1 && page <= Math.ceil(filteredLogs.length / logsPerPage)) {
        currentPage = page;
        updateLogsDisplay();
    }
}

function showLogsLoading() {
    const tableBody = document.getElementById('logsTableBody');
    if (tableBody) {
        tableBody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-4">
                    <div class="d-flex align-items-center justify-content-center">
                        <div class="activity-icon bg-info text-white rounded me-3">
                            <i class="fas fa-spinner fa-spin"></i>
                        </div>
                        <div>
                            <small class="text-muted">Loading system logs...</small>
                        </div>
                    </div>
                </td>
            </tr>
        `;
    }
}

function showLogsError(message) {
    const tableBody = document.getElementById('logsTableBody');
    if (tableBody) {
        tableBody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-4">
                    <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                    <h5 class="text-warning">Error Loading Logs</h5>
                    <p class="text-muted">${message}</p>
                </td>
            </tr>
        `;
    }
}





function updateRecentActivity(data) {
    const activityContainer = document.getElementById('recentActivity');

    if (data.length === 0) {
        activityContainer.innerHTML = `
            <div class="activity-item">
                <div class="d-flex align-items-center">
                    <div class="activity-icon bg-secondary text-white rounded me-3">
                        <i class="fas fa-inbox"></i>
                    </div>
                    <div>
                        <small class="text-muted">No recent activity</small>
                        <p class="mb-0">No system activities logged yet</p>
                    </div>
                </div>
            </div>
        `;
        return;
    }

    activityContainer.innerHTML = '';

    data.forEach(log => {
        const date = new Date(log.created_at).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });

        const activityClass = getActivityClass(log.activity_type);
        const activityIcon = getActivityIcon(log.activity_type);

        activityContainer.innerHTML += `
            <div class="activity-item">
                <div class="d-flex align-items-center">
                    <div class="activity-icon ${activityClass} text-white rounded me-3">
                        <i class="${activityIcon}"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted">${date}</small>
                        <p class="mb-0">${log.activity_description || getActivityDescription(log)}</p>
                        <small class="text-muted">by ${log.username || log.user || 'System'}</small>
                    </div>
                </div>
            </div>
        `;
    });
}

function getActivityClass(type) {
    switch (type?.toLowerCase()) {
        case 'client_added': case 'client': return 'bg-success';
        case 'client_updated': return 'bg-info';
        case 'animal_processed': case 'slaughter': return 'bg-warning';
        case 'fee_entry': case 'payment': return 'bg-primary';
        case 'business_added': case 'business': return 'bg-secondary';
        case 'user_login': case 'login': return 'bg-dark';
        case 'report_generated': case 'report': return 'bg-info';
        case 'system_update': case 'update': return 'bg-danger';
        default: return 'bg-secondary';
    }
}

function getActivityIcon(type) {
    switch (type?.toLowerCase()) {
        case 'client_added': case 'client': return 'fas fa-user-plus';
        case 'client_updated': return 'fas fa-user-edit';
        case 'animal_processed': case 'slaughter': return 'fas fa-paw';
        case 'fee_entry': case 'payment': return 'fas fa-dollar-sign';
        case 'business_added': case 'business': return 'fas fa-building';
        case 'user_login': case 'login': return 'fas fa-sign-in-alt';
        case 'report_generated': case 'report': return 'fas fa-chart-bar';
        case 'system_update': case 'update': return 'fas fa-cog';
        default: return 'fas fa-circle';
    }
}

function getActivityDescription(transaction) {
    const type = transaction.activity_type || transaction.type || 'unknown';
    const details = transaction.details || transaction.description || {};

    switch (type?.toLowerCase()) {
        case 'client_added':
            return `New client registered: ${details.name || 'Unknown Client'}`;
        case 'client_updated':
            return `Client updated: ${details.name || 'Client record modified'}`;
        case 'animal_processed':
            return `Animal processed: ${details.animal_type || 'Livestock'} - ${details.quantity || 0} heads`;
        case 'fee_entry':
            return `Fee recorded: ₱${details.amount || 0} - ${details.description || 'Transaction fee'}`;
        case 'business_added':
            return `Business registered: ${details.name || 'New Business'}`;
        case 'user_login':
            return `User logged in: ${details.username || 'User session started'}`;
        case 'report_generated':
            return `Report generated: ${details.report_type || 'System report'}`;
        case 'system_update':
            return `System updated: ${details.description || 'Configuration change'}`;
        default:
            return `${type || 'Transaction'}: ${details.description || 'Activity recorded'}`;
    }
}

function updateDepartmentOverview(data) {
    const tableBody = document.getElementById('departmentTableBody');

    if (!data || data.length === 0) {
        tableBody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-4">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No Department Data</h5>
                    <p class="text-muted">No PPMP documents found for any department</p>
                </td>
            </tr>
        `;
        return;
    }

    tableBody.innerHTML = '';

    data.forEach(dept => {
        const totalPPMP = parseInt(dept.total_ppmp) || 0;
        const draftCount = parseInt(dept.draft_count) || 0;
        const submittedCount = parseInt(dept.submitted_count) || 0;
        const approvedCount = parseInt(dept.approved_count) || 0;
        const rejectedCount = parseInt(dept.rejected_count) || 0;

        tableBody.innerHTML += `
            <tr>
                <td>
                    <div class="department-name">
                        <i class="fas fa-building me-2"></i>${dept.department}
                    </div>
                </td>
                <td class="total-count">${totalPPMP}</td>
                <td class="status-count draft">${draftCount}</td>
                <td class="status-count submitted">${submittedCount}</td>
                <td class="status-count approved">${approvedCount}</td>
                <td class="status-count rejected">${rejectedCount}</td>
            </tr>
        `;
    });
}

function getStatusClass(status) {
    switch (status?.toLowerCase()) {
        case 'draft': return 'bg-primary';
        case 'submitted': return 'bg-warning';
        case 'approved': return 'bg-success';
        default: return 'bg-secondary';
    }
}

function getStatusIcon(status) {
    switch (status?.toLowerCase()) {
        case 'saved': return 'fas fa-save';
        case 'draft': return 'fas fa-edit';
        case 'submitted': return 'fas fa-paper-plane';
        default: return 'fas fa-question';
    }
}


// Initialize everything when DOM loads
document.addEventListener('DOMContentLoaded', function() {
    loadDashboardStats();
    loadSlaughterStats();
    loadSystemLogs(); // Load system activity logs

    // Add filter event listener
    const activityFilter = document.getElementById('activityTypeFilter');
    if (activityFilter) {
        activityFilter.addEventListener('change', filterLogs);
    }

    // Auto-refresh dashboard data every 30 seconds
    setInterval(() => {
        if (document.hasFocus()) {
            loadDashboardStats();
            loadSlaughterStats();
            loadSystemLogs(); // Refresh system logs
        }
    }, 30000); // 30 seconds
});
</script>

</body>
</html>
