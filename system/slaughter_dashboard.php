<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}
session_regenerate_id(true);
$user = htmlspecialchars($_SESSION['username']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Slaughter Dashboard | Slaughter House Management System</title>

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
            /* Light theme - White + Gray + Navy */
            --welcome-bg: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
            --card-bg: #ffffff;
            --text-on-dark: #ffffff;
            --text-muted-dark: #9ca3af;
            --border-dark: rgba(30, 58, 138, 0.2);
            --shadow-dark: rgba(0,0,0,0.1);
        }

        /* Dark theme overrides - Charcoal Gray + White + Blue */
        [data-theme="dark"] {
            --welcome-bg: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            --card-bg: linear-gradient(135deg, #1f2937 0%, #374151 100%);
            --text-on-dark: #ffffff;
            --text-muted-dark: #9ca3af;
            --border-dark: rgba(59, 130, 246, 0.3);
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
            box-shadow: 0 8px 30px var(--shadow-dark);
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
            background-color: rgba(255,255,255,0.05);
        }
        .activity-item:last-child {
            border-bottom: none;
        }
        .activity-item .text-muted {
            color: var(--text-muted-dark) !important;
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
        .btn-info {
            background: linear-gradient(135deg, #3182ce 0%, #2c5282 100%);
            border: 1px solid rgba(255,255,255,0.2);
        }
        .btn-warning {
            background: linear-gradient(135deg, #d69e2e 0%, #b7791f 100%);
            border: 1px solid rgba(255,255,255,0.2);
        }
        .text-primary { color: #63b3ed !important; }
        .text-success { color: #68d391 !important; }
        .text-info { color: #4fd1c9 !important; }
        .text-warning { color: #f6e05e !important; }

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
                            <p class="mb-1 opacity-8"><i class="fas fa-industry me-2"></i>Slaughter House Management System</p>
                            <p class="mb-0 opacity-8">Here's what's happening with your slaughter operations today.</p>
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
                <div class="stat-card card h-100">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col">
                                <h6 class="text-muted text-uppercase mb-2">Total Clients</h6>
                                <h3 class="mb-0" id="totalClients">0</h3>
                            </div>
                            <div class="col-auto">
                                <div class="stat-icon bg-primary text-white">
                                    <i class="fas fa-users"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-6 col-md-6 mb-4">
                <div class="stat-card card h-100">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col">
                                <h6 class="text-muted text-uppercase mb-2">Recent Operations</h6>
                                <h3 class="mb-0" id="recentOperationsCount">0</h3>
                            </div>
                            <div class="col-auto">
                                <div class="stat-icon bg-warning text-white">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts and Activity -->
        <div class="row">
            <!-- Monthly Slaughter Chart -->
            <div class="col-xl-12 mb-4">
                <div class="chart-container">
                    <h5 class="mb-4"><i class="fas fa-chart-line me-2"></i>Monthly Slaughter Operations (<?php echo date('Y'); ?>)</h5>
                    <canvas id="monthlyChart" height="250"></canvas>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="row">
            <div class="col-12">
                <div class="recent-activity card">
                    <div class="card-header bg-transparent">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Slaughter Operations</h5>
                    </div>
                    <div class="card-body p-0" id="recentActivity">
                        <div class="activity-item">
                            <div class="d-flex align-items-center">
                                <div class="activity-icon bg-info text-white rounded me-3">
                                    <i class="fas fa-spinner fa-spin"></i>
                                </div>
                                <div>
                                    <small class="text-muted">Loading...</small>
                                    <p class="mb-0">Loading recent operations...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row">
            <div class="col-12">
                <div class="chart-container">
                    <h5 class="mb-4"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <a href="#" class="btn btn-primary btn-lg w-100">
                                <i class="fas fa-user-plus me-2"></i>Add Client
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="#" class="btn btn-success btn-lg w-100">
                                <i class="fas fa-plus-circle me-2"></i>Record Slaughter
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button class="btn btn-info btn-lg w-100" onclick="refreshStats()">
                                <i class="fas fa-sync me-2"></i>Refresh Data
                            </button>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button class="btn btn-warning btn-lg w-100" onclick="exportData()">
                                <i class="fas fa-download me-2"></i>Export Report
                            </button>
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

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script src="../api_config.js.php"></script>

<script>
let monthlyChart, animalChart;

function loadDashboardStats() {
    // Show loading indicator
    const lastUpdateEl = document.getElementById('lastUpdate');
    lastUpdateEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';

    fetch(`${API_BASE_URL}/api_slaughter_dashboard_stats.php`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update statistics cards
                document.getElementById('totalClients').textContent = data.stats.total_clients;
                document.getElementById('activeBusinesses').textContent = data.stats.active_businesses;
                document.getElementById('animalsProcessed').textContent = data.stats.animals_processed.toLocaleString('en-US');
                document.getElementById('recentOperationsCount').textContent = data.stats.recent_operations_count;

                // Update charts with real data
                updateMonthlyChart(data.monthly_data);
                updateAnimalChart(data.animal_distribution);
                updateRecentActivity(data.recent_operations);

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
            lastUpdateEl.innerHTML = '<i class="fas fa-exclamation-triangle text-danger"></i> Connection error';
        });
}

function initializeCharts() {
    // Monthly Chart
    const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
    monthlyChart = new Chart(monthlyCtx, {
        type: 'line',
        data: {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            datasets: [{
                label: 'Animals Processed',
                data: [],
                borderColor: '#667eea',
                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#667eea',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'top' }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });

    // Animal Chart
    const animalCtx = document.getElementById('animalChart').getContext('2d');
    animalChart = new Chart(animalCtx, {
        type: 'doughnut',
        data: {
            labels: [],
            datasets: [{
                data: [],
                backgroundColor: ['#007bff', '#ffc107', '#28a745', '#dc3545', '#6f42c1', '#e83e8c', '#fd7e14', '#20c997'],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
}

function updateMonthlyChart(data) {
    if (monthlyChart) {
        monthlyChart.data.datasets[0].data = data;
        monthlyChart.update();
    }
}

function updateAnimalChart(data) {
    if (animalChart) {
        const labels = data.map(item => item.Animal || 'Unknown');
        const values = data.map(item => item.total_heads || 0);
        animalChart.data.labels = labels;
        animalChart.data.datasets[0].data = values;
        animalChart.update();
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
                        <p class="mb-0">No slaughter operations found</p>
                    </div>
                </div>
            </div>
        `;
        return;
    }

    activityContainer.innerHTML = '';

    data.forEach(operation => {
        const date = new Date(operation.Slaughter_Date).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });

        const clientName = `${operation.Firstname} ${operation.Surname}`;
        const business = operation.Business_Name || 'Individual';

        activityContainer.innerHTML += `
            <div class="activity-item">
                <div class="d-flex align-items-center">
                    <div class="activity-icon bg-success text-white rounded me-3">
                        <i class="fas fa-paw"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="text-muted">${date}</small>
                        <p class="mb-0">${clientName} - ${business}</p>
                        <small class="text-muted">${operation.total_heads} heads, ${operation.total_kilos} kg by ${operation.added_by}</small>
                    </div>
                </div>
            </div>
        `;
    });
}

function refreshStats() {
    // Show loading state on button
    const refreshBtn = document.querySelector('button[onclick="refreshStats()"]');
    const originalHTML = refreshBtn.innerHTML;
    refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
    refreshBtn.disabled = true;

    loadDashboardStats();

    // Show success message after a short delay
    setTimeout(() => {
        const toast = document.createElement('div');
        toast.className = 'alert alert-success alert-dismissible fade show position-fixed';
        toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        toast.innerHTML = `
            <i class="fas fa-check-circle me-2"></i>Dashboard data refreshed successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(toast);

        // Reset button
        refreshBtn.innerHTML = originalHTML;
        refreshBtn.disabled = false;

        setTimeout(() => {
            toast.remove();
        }, 3000);
    }, 1000);
}

function exportData() {
    // Export dashboard data as JSON
    fetch(`${API_BASE_URL}/api_slaughter_dashboard_stats.php`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const exportData = {
                    exported_at: new Date().toISOString(),
                    stats: data.stats,
                    monthly_data: data.monthly_data,
                    animal_distribution: data.animal_distribution,
                    recent_operations: data.recent_operations
                };

                const blob = new Blob([JSON.stringify(exportData, null, 2)], { type: 'application/json' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `slaughter_dashboard_${new Date().toISOString().split('T')[0]}.json`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            }
        })
        .catch(error => {
            console.error('Export error:', error);
            alert('Export failed. Please try again.');
        });
}

// Initialize everything when DOM loads
document.addEventListener('DOMContentLoaded', function() {
    initializeCharts();
    loadDashboardStats();

    // Auto-refresh dashboard data every 30 seconds
    setInterval(() => {
        if (document.hasFocus()) {
            loadDashboardStats();
        }
    }, 30000); // 30 seconds
});
</script>

</body>
</html>