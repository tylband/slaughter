<!-- Slaughter House Management Sidebar -->
<aside class="sidenav navbar navbar-vertical navbar-expand-xs border-0 fixed-start" id="sidenav-main" style="background: #ffffff; box-shadow: 4px 0 15px rgba(0,0,0,0.1); border-right: 1px solid #e2e8f0;">
  <div class="sidenav-header text-center py-4" style="background: #f8f9fa; border-radius: 0 0 20px 20px; border-bottom: 1px solid #e2e8f0;">
    <a class="navbar-brand text-white fw-bold d-block" href="dashboard.php" style="text-decoration: none;">
      <div class="text-center d-flex flex-column align-items-center justify-content-center" style="min-height: 80px;">
        <div class="brand-text text-center">
          <div class="h6 mb-1 fw-bold" style="font-size: 1.1rem; letter-spacing: 0.5px; color: #1e3a8a;" id="brandTitle">Slaughter System</div>
          <small style="font-size: 0.7rem; opacity: 0.9; color: #6b7280;" id="brandSubtitle">Management System</small>
        </div>
      </div>
    </a>
  </div>

  <!-- Search Bar -->
  <div class="px-3 py-2" id="searchContainer">
    <div class="input-group input-group-sm">
      <span class="input-group-text border-0" style="color: #065f46; background: #f0fdf4;">
        <i class="fas fa-search"></i>
      </span>
      <input type="text" class="form-control border-0" placeholder="Search..." id="sidebarSearch" style="color: #374151; font-size: 0.9rem; background: #f0fdf4;">
    </div>
  </div>

  <div class="collapse navbar-collapse w-auto">
    <ul class="navbar-nav">

      <!-- Dashboard -->
      <li class="nav-item mb-3">
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>" href="slaughter_dashboard.php" style="border-radius: 12px; transition: all 0.3s ease; padding: 0.75rem 1rem; color: #374151;">
          <div class="d-flex align-items-center">
            <div class="icon-shape me-3">
              <i class="fas fa-tachometer-alt"></i>
            </div>
            <span class="nav-link-text fw-semibold">Dashboard</span>
          </div>
        </a>
      </li>

      <li class="nav-item mb-2">
        <a class="nav-link text-white <?= basename($_SERVER['PHP_SELF']) == 'slaughter_fee_entry.php' ? 'active' : '' ?>" href="slaughter_fee_entry.php" style="border-radius: 12px; transition: all 0.3s ease; padding: 0.75rem 1rem;">
          <div class="d-flex align-items-center">
            <div class="icon-shape me-3">
              <i class="fas fa-plus-circle"></i>
            </div>
            <span class="nav-link-text fw-semibold">Fee Entry</span>
          </div>
        </a>
      </li>

      <li class="nav-item mb-2">
        <a class="nav-link text-white <?= basename($_SERVER['PHP_SELF']) == 'animal_management.php' ? 'active' : '' ?>" href="animal_management.php" style="border-radius: 12px; transition: all 0.3s ease; padding: 0.75rem 1rem;">
          <div class="d-flex align-items-center">
            <div class="icon-shape me-3">
              <i class="fas fa-paw"></i>
            </div>
            <span class="nav-link-text fw-semibold">Animal Management</span>
          </div>
        </a>
      </li>

      <li class="nav-item mb-2">
        <a class="nav-link text-white <?= basename($_SERVER['PHP_SELF']) == 'client_management.php' ? 'active' : '' ?>" href="client_management.php" style="border-radius: 12px; transition: all 0.3s ease; padding: 0.75rem 1rem;">
          <div class="d-flex align-items-center">
            <div class="icon-shape me-3">
              <i class="fas fa-user-friends"></i>
            </div>
            <span class="nav-link-text fw-semibold">Client Management</span>
          </div>
        </a>
      </li>

      <li class="nav-item mb-2">
        <a class="nav-link text-white <?= basename($_SERVER['PHP_SELF']) == 'business_management.php' ? 'active' : '' ?>" href="business_management.php" style="border-radius: 12px; transition: all 0.3s ease; padding: 0.75rem 1rem;">
          <div class="d-flex align-items-center">
            <div class="icon-shape me-3">
              <i class="fas fa-building"></i>
            </div>
            <span class="nav-link-text fw-semibold">Business Management</span>
          </div>
        </a>
      </li>

      <li class="nav-item mb-2">
        <a class="nav-link text-white <?= basename($_SERVER['PHP_SELF']) == 'slaughter_fee_reports.php' ? 'active' : '' ?>" href="slaughter_fee_reports.php" style="border-radius: 12px; transition: all 0.3s ease; padding: 0.75rem 1rem;">
          <div class="d-flex align-items-center">
            <div class="icon-shape me-3">
              <i class="fas fa-file-alt"></i>
            </div>
            <span class="nav-link-text fw-semibold">Slaughter Reports</span>
          </div>
        </a>
      </li>

      <?php if (isset($user_data) && $user_data['role'] === 'admin'): ?>
      <li class="nav-item mb-2">
        <a class="nav-link text-white <?= basename($_SERVER['PHP_SELF']) == 'register.php' ? 'active' : '' ?>" href="register.php" style="border-radius: 12px; transition: all 0.3s ease; padding: 0.75rem 1rem;">
          <div class="d-flex align-items-center">
            <div class="icon-shape me-3">
              <i class="fas fa-user-plus"></i>
            </div>
            <span class="nav-link-text fw-semibold">Register User</span>
          </div>
        </a>
      </li>
      <?php endif; ?>

    </ul>
  </div>

  <!-- Footer -->
  <div class="sidenav-footer mt-auto p-3" style="border-top: 1px solid #10b981;">
    <div class="d-flex flex-column">
      <a class="nav-link mb-2" href="logout.php" style="border-radius: 12px; transition: all 0.3s ease; padding: 0.75rem 1rem; color: #374151;">
        <div class="d-flex align-items-center">
          <div class="icon-shape me-3">
            <i class="fas fa-sign-out-alt"></i>
          </div>
          <span class="nav-link-text fw-semibold">Logout</span>
        </div>
      </a>
      <div class="text-center">
        <small style="color: #6b7280; font-size: 0.7rem;" id="footerText">© 2025 Slaughter System</small>
      </div>
    </div>
  </div>
</aside>

<!-- Include Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
.icon-shape {
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  background: #f0fdf4;
  border: 1px solid #d1fae5;
}

.icon-shape i {
  font-size: 1.1rem;
  color: #065f46;
}


/* Nav Link Styles */
.sidenav .nav-link {
  color: #374151 !important;
  margin: 0 0.5rem;
}

.sidenav .nav-link:hover {
  color: #065f46 !important;
  background: #ecfdf5 !important;
  transform: translateX(5px);
  box-shadow: 0 4px 15px rgba(16, 185, 129, 0.1);
}

.sidenav .nav-link.active {
  color: white !important;
  background: #10b981 !important;
  box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
  transform: translateX(5px);
  border: 1px solid #059669;
}

.sidenav .nav-link.active .icon-shape i {
  color: white !important;
}



/* Search Styles */
#sidebarSearch {
  background: #f0fdf4 !important;
  border: 1px solid #d1fae5 !important;
  border-radius: 20px !important;
}

#sidebarSearch:focus {
  background: #ffffff !important;
  border-color: #10b981 !important;
  box-shadow: 0 0 0 0.2rem rgba(16, 185, 129, 0.1) !important;
}



</style>

<script>
// Search functionality only
document.addEventListener('DOMContentLoaded', () => {
  const searchInput = document.getElementById('sidebarSearch');
  if (searchInput) {
    searchInput.addEventListener('input', (e) => {
      const query = e.target.value.toLowerCase();
      const navItems = document.querySelectorAll('.navbar-nav .nav-item .nav-link');

      navItems.forEach(item => {
        const text = item.textContent.toLowerCase();
        const li = item.closest('.nav-item');
        if (text.includes(query) || query === '') {
          li.style.display = '';
        } else {
          li.style.display = 'none';
        }
      });
    });
  }
});
</script>
