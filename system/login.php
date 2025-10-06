<?php
require_once '../config.php';
require_once '../token_auth.php';

// Check if user is already logged in with a valid token
$user_data = TokenAuth::authenticate($conn);
if ($user_data) {
    header("Location: dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Slaughter House Management | Login</title>

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
    <link rel="stylesheet" href="../css/login.css">

</head>
<body data-theme="light">

<!-- Animated Background -->
<div class="background-animation">
    <div class="bg-shape"></div>
    <div class="bg-shape"></div>
    <div class="bg-shape"></div>
    <div class="bg-shape"></div>
</div>

<div class="login-container">
    <!-- Left Side - Branding -->
    <div class="login-branding">
        <div class="brand-logo">
            <div class="logo-container">
                <img src="assets/ceedmo_logo.ico" alt="PPMP Logo" class="enhanced-logo">
            </div>
        </div>
        <h1 class="brand-title">Slaughter House</h1>
        <p class="brand-subtitle">Management System</p>

        <div class="brand-features">
            <div class="feature-item">
                <i class="fas fa-check-circle"></i>
                <span>Client & Business Management</span>
            </div>
            <div class="feature-item">
                <i class="fas fa-check-circle"></i>
                <span>Animal Tracking & Fee Calculation</span>
            </div>
            <div class="feature-item">
                <i class="fas fa-check-circle"></i>
                <span>Slaughter Operations Recording</span>
            </div>
            <div class="feature-item">
                <i class="fas fa-check-circle"></i>
                <span>Comprehensive Reporting</span>
            </div>
        </div>
    </div>

    <!-- Right Side - Login Form -->
    <div class="login-form-container">
        <!-- Theme Toggle -->
        <button class="theme-toggle-btn" id="themeToggle" title="Toggle Theme">
            <i class="fas fa-moon" id="themeIcon"></i>
        </button>

        <div class="form-header">
            <h2 class="form-title">Welcome Back</h2>
            <p class="form-subtitle">Sign in to your account</p>
        </div>

        <!-- Error Message -->
        <div class="error-message" id="error-message" style="display: none;"></div>

        <!-- Login Form -->
        <form id="loginForm">
            <div class="form-group">
                <label for="username" class="form-label">Username</label>
                <input type="text" id="username" name="username" class="form-control" placeholder="Enter your username" required>
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required>
            </div>

            <div class="form-links">
                <a href="#" onclick="forgotPassword()">Forgot password?</a>
            </div>

            <button type="submit" class="btn btn-login" id="loginBtn">
                <span id="btnText">Sign In</span>
                <span class="loading" id="btnLoading" style="display: none;"></span>
            </button>
        </form>

    </div>
</div>


<!-- Registration Modal -->
<div class="modal fade" id="registerModal" tabindex="-1" aria-labelledby="registerModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="background: var(--login-card-bg); border: 1px solid var(--login-border);">
      <div class="modal-header" style="border-bottom: 1px solid var(--login-border);">
        <h5 class="modal-title" id="registerModalLabel" style="color: var(--login-text-primary);">Create Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="registerForm">
            <div class="mb-3">
                <label for="regName" class="form-label" style="color: var(--login-text-primary);">Full Name</label>
                <input type="text" class="form-control" id="regName" name="name" placeholder="Enter your full name" required>
            </div>
            <div class="mb-3">
                <label for="regUsername" class="form-label" style="color: var(--login-text-primary);">Username</label>
                <input type="text" class="form-control" id="regUsername" name="username" placeholder="Choose a username" required>
            </div>
            <div class="mb-3">
                <label for="regPassword" class="form-label" style="color: var(--login-text-primary);">Password</label>
                <input type="password" class="form-control" id="regPassword" name="password" placeholder="Create a strong password" required>
                <small style="color: var(--login-text-muted);">Minimum 6 characters</small>
            </div>
            <div class="mb-3">
                <label for="regConfirmPassword" class="form-label" style="color: var(--login-text-primary);">Confirm Password</label>
                <input type="password" class="form-control" id="regConfirmPassword" placeholder="Confirm your password" required>
            </div>
            <div class="mb-3">
                <label for="regRoles" class="form-label" style="color: var(--login-text-primary);">Role</label>
                <select id="regRoles" name="roles" class="form-control" required>
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <button type="submit" class="btn btn-login w-100" id="registerBtn">
                <span id="registerBtnText">Create Account</span>
                <span class="loading" id="registerBtnLoading" style="display: none;"></span>
            </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="argondashboard/assets/js/core/bootstrap.bundle.min.js"></script>
<script src="../api_config.js.php"></script>
<script src="../js/login.js"></script>

</script>

</body>
</html>
