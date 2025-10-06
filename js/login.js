// Theme Management for Login Page
class LoginThemeManager {
  constructor() {
    this.currentTheme = this.getInitialTheme();
    this.init();
  }

  getInitialTheme() {
    // Check for saved theme first
    const savedTheme = localStorage.getItem('ppmp-theme');
    if (savedTheme) {
      return savedTheme;
    }

    // Auto-detect system preference
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      return 'dark';
    }

    return 'light';
  }

  init() {
    this.applyTheme(this.currentTheme);
    this.setupToggle();
    this.updateToggleIcon();
  }

  setupToggle() {
    const toggleBtn = document.getElementById('themeToggle');
    if (toggleBtn) {
      toggleBtn.addEventListener('click', () => {
        this.toggleTheme();
      });
    }
  }

  toggleTheme() {
    this.currentTheme = this.currentTheme === 'dark' ? 'light' : 'dark';
    this.applyTheme(this.currentTheme);
    this.saveTheme();
    this.updateToggleIcon();
  }

  applyTheme(theme) {
    document.body.setAttribute('data-theme', theme);

    // Update CSS variables
    const root = document.documentElement;
    if (theme === 'dark') {
      root.style.setProperty('--login-bg', 'linear-gradient(135deg, #064e3b 0%, #065f46 100%)');
      root.style.setProperty('--login-card-bg', 'linear-gradient(135deg, #065f46 0%, #064e3b 100%)');
      root.style.setProperty('--login-text-primary', '#ffffff');
      root.style.setProperty('--login-text-muted', '#a0aec0');
      root.style.setProperty('--login-border', 'rgba(255,255,255,0.1)');
    } else {
      root.style.setProperty('--login-bg', 'linear-gradient(135deg, #059669 0%, #064e3b 100%)');
      root.style.setProperty('--login-card-bg', 'rgba(255, 255, 255, 0.95)');
      root.style.setProperty('--login-text-primary', '#1a202c');
      root.style.setProperty('--login-text-muted', '#718096');
      root.style.setProperty('--login-border', 'rgba(255, 255, 255, 0.2)');
    }
  }

  updateToggleIcon() {
    const icon = document.getElementById('themeIcon');
    if (icon) {
      if (this.currentTheme === 'dark') {
        icon.className = 'fas fa-sun';
        icon.parentElement.title = 'Switch to Light Theme';
      } else {
        icon.className = 'fas fa-moon';
        icon.parentElement.title = 'Switch to Dark Theme';
      }
    }
  }

  saveTheme() {
    localStorage.setItem('ppmp-theme', this.currentTheme);
  }
}

// Login Form Handler
document.addEventListener('DOMContentLoaded', function() {
  // Initialize theme manager
  new LoginThemeManager();

  // Fade in login container
  setTimeout(() => {
    const loginContainer = document.querySelector('.login-container');
    if (loginContainer) {
      loginContainer.classList.add('fade-in');
    }
  }, 100);

  // Setup form handlers
  const loginForm = document.getElementById('loginForm');
  const registerForm = document.getElementById('registerForm');

  if (loginForm) {
    loginForm.addEventListener('submit', handleLogin);
  }

  if (registerForm) {
    registerForm.addEventListener('submit', handleRegister);
  }

  // Focus on username field
  const usernameField = document.getElementById('username');
  if (usernameField) {
    usernameField.focus();
  }
});


async function handleLogin(event) {
  event.preventDefault();

  const username = document.getElementById('username').value.trim();
  const password = document.getElementById('password').value.trim();
  const errorMessage = document.getElementById('error-message');
  const loginBtn = document.getElementById('loginBtn');
  const btnText = document.getElementById('btnText');
  const btnLoading = document.getElementById('btnLoading');

  // Clear previous error
  errorMessage.style.display = 'none';

  // Validate inputs
  if (!username || !password) {
    showError('Please fill in all fields.');
    return;
  }

  // Show loading state
  loginBtn.disabled = true;
  btnText.style.display = 'none';
  btnLoading.style.display = 'inline-block';

  try {
    const response = await fetch(`${API_BASE_URL}/api_auth.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ username, password })
    });

    const data = await response.json();

    if (data.status === 'success') {
      // Success - store token and redirect to dashboard
      if (data.token) {
        // Store token in localStorage
        localStorage.setItem('auth_token', data.token);

        // Also set as cookie for API calls
        document.cookie = `auth_token=${data.token}; path=/; max-age=86400; samesite=strict`;

        // Store user info
        localStorage.setItem('user_info', JSON.stringify(data.user));
      }

      window.location.href = 'dashboard.php';
    } else {
      // Error - show message
      showError(data.message || 'Login failed. Please try again.');
    }
  } catch (error) {
    console.error('Login error:', error);
    showError('Network error. Please check your connection and try again.');
  } finally {
    // Reset loading state
    loginBtn.disabled = false;
    btnText.style.display = 'inline';
    btnLoading.style.display = 'none';
  }
}


function showError(message) {
  const errorMessage = document.getElementById('error-message');
  errorMessage.textContent = message;
  errorMessage.style.display = 'block';
}

async function handleRegister(event) {
  event.preventDefault();

  const name = document.getElementById('regName').value.trim();
  const username = document.getElementById('regUsername').value.trim();
  const password = document.getElementById('regPassword').value.trim();
  const confirmPassword = document.getElementById('regConfirmPassword').value.trim();
  const roles = document.getElementById('regRoles').value;

  // Validate inputs
  if (!name || !username || !password || !confirmPassword) {
    alert('Please fill in all fields.');
    return;
  }

  if (password !== confirmPassword) {
    alert('Passwords do not match.');
    return;
  }

  if (password.length < 6) {
    alert('Password must be at least 6 characters long.');
    return;
  }

  // Show loading state
  const registerBtn = document.getElementById('registerBtn');
  const btnText = document.getElementById('registerBtnText');
  const btnLoading = document.getElementById('registerBtnLoading');

  registerBtn.disabled = true;
  btnText.style.display = 'none';
  btnLoading.style.display = 'inline-block';

  try {
    const response = await fetch(`${API_BASE_URL}/api_register.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ name, username, password, roles })
    });

    const data = await response.json();

    if (data.status === 'success') {
      alert('Registration successful! You can now log in.');
      // Close modal
      const modal = bootstrap.Modal.getInstance(document.getElementById('registerModal'));
      if (modal) modal.hide();
      // Reset form
      document.getElementById('registerForm').reset();
    } else {
      alert(data.message || 'Registration failed. Please try again.');
    }
  } catch (error) {
    console.error('Registration error:', error);
    alert('Network error. Please check your connection and try again.');
  } finally {
    // Reset loading state
    registerBtn.disabled = false;
    btnText.style.display = 'inline';
    btnLoading.style.display = 'none';
  }
}

function forgotPassword() {
  alert('Please contact your administrator to reset your password.');
}