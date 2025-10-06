<?php
require_once "../config.php";
require_once "../token_auth.php";

// Authenticate user with token
$user_data = TokenAuth::authenticate($conn);
if (!$user_data) {
    header("Location: login.php");
    exit();
}
$user = htmlspecialchars($user_data['username']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Animal Management | Slaughter House Management System</title>

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
    <link rel="stylesheet" href="../css/animal_management.css">

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
                            <h2 class="mb-2"><i class="fas fa-paw me-2"></i>Animal Management</h2>
                            <p class="mb-1 opacity-8"><i class="fas fa-industry me-2"></i>Slaughter House Management System</p>
                            <p class="mb-0 opacity-8">Manage animal types, add new animals, and update existing records.</p>
                        </div>
                        <div class="col-lg-3 text-end">
                            <button class="btn btn-primary btn-lg" onclick="showAddAnimalModal()">
                                <i class="fas fa-plus me-2"></i>Add New Animal
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
                                    <input type="text" class="form-control" id="searchInput" placeholder="Search animals by name..." onkeyup="handleSearch()">
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

        <!-- Animals Table -->
        <div class="row">
            <div class="col-12">
                <div class="data-card card position-relative">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-paw me-2"></i>Animal Types List</h5>
                    </div>
                    <div class="card-body p-0">
                        <div id="loadingOverlay" class="loading-overlay d-none">
                            <div class="text-center">
                                <div class="spinner-border" style="color: #10b981;" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <div class="mt-2">Loading animals...</div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Animal Name</th>
                                        <th>Associated Fees</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="animalsTableBody">
                                    <tr>
                                        <td colspan="4" class="text-center py-5">
                                            <div class="spinner-border" style="color: #10b981;" role="status">
                                                <span class="visually-hidden">Loading...</span>
                                            </div>
                                            <div class="mt-2">Loading animals...</div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer">
                        <nav aria-label="Animals pagination">
                            <ul class="pagination justify-content-center mb-0" id="paginationControls"></ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Add/Edit Animal Modal -->
<div class="modal fade" id="animalModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="animalModalTitle">Add New Animal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="animalForm">
                    <input type="hidden" id="animalId" name="AID">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label for="animalName" class="form-label">Animal Name *</label>
                            <input type="text" class="form-control" id="animalName" name="Animal" required>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveAnimal()">
                    <i class="fas fa-save me-2"></i>Save Animal
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
                <p>Are you sure you want to delete this animal type?</p>
                <p class="text-danger mb-0" id="deleteAnimalName"></p>
                <small class="text-muted">This action cannot be undone.</small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                    <i class="fas fa-trash me-2"></i>Delete Animal
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
<script src="../js/animal_management.js"></script>

</script>

</body>
</html>