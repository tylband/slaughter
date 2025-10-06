document.addEventListener("DOMContentLoaded", function () {
    // Attach event listener for login form submission
    const loginForm = document.getElementById("loginForm");
    if (loginForm) {
        loginForm.addEventListener("submit", loginUser);
    }

    // Attach event listener for registration form submission
    const registerForm = document.getElementById("registerForm");
    if (registerForm) {
        registerForm.addEventListener("submit", registerUser);
    }

    // Focus on the username field on page load
    document.getElementById("username").focus();

    // Move focus to login button when pressing Tab on password field
    document.getElementById("password").addEventListener("keydown", function (event) {
        if (event.key === "Tab") {
            event.preventDefault();
            document.getElementById("loginBtn").focus();
        }
    });
});



/**
 * Login Function
 * Handles user login by sending credentials to the API.
 */
function loginUser(event) {
    event.preventDefault(); // Prevent default form submission

    let username = document.getElementById("username").value.trim();
    let password = document.getElementById("password").value.trim();
    let csrfToken = document.getElementById("csrf_token") ? document.getElementById("csrf_token").value : "";
    let errorMessage = document.getElementById("error-message");

    // Clear previous error messages
    errorMessage.innerText = "";

    // Validate inputs
    if (!username || !password) {
        errorMessage.innerText = "Please fill in all fields.";
        return;
    }

fetch(`${API_BASE_URL}/api_auth.php`, {
    method: "POST",
    credentials: "include",
    headers: {
        "Content-Type": "application/json",
        "Accept": "application/json"
    },
    body: JSON.stringify({ username, password, csrf_token: csrfToken })
})
.then(response => response.text()) // <-- get raw text
.then(text => {
    console.log("Raw response:", text); // Log the raw output

    try {
        const data = JSON.parse(text); // Try to parse it
        if (data.status === "success") {
            alert("You have successfully logged in!");
            window.location.href = "dashboard.php";
        } else {
            errorMessage.innerText = data.message || "Login failed. Please try again.";
        }
    } catch (err) {
        console.error("Failed to parse JSON:", err);
        errorMessage.innerText = "Server returned invalid JSON. Check console.";
    }
})
.catch(error => {
    console.error("Login Error:", error);
    errorMessage.innerText = "Something went wrong. Please try again later.";
});
}

/**
 * Registration Function
 * Handles user registration by sending form data to the API.
 */
function registerUser(event) {
    event.preventDefault(); // Prevent default form submission

    let formData = {
        username: document.getElementById("uname").value.trim(),
        firstname: document.getElementById("firstname").value.trim(),
        lastname: document.getElementById("lastname").value.trim(),
        role: document.getElementById("role").value,
        password: document.getElementById("pw").value
    };

    if (!formData.username || !formData.firstname || !formData.lastname || !formData.password) {
        alert("All fields are required.");
        return;
    }

    fetch(`${API_BASE_URL}/api_register.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message);
        if (data.status === "success") {
            document.getElementById("registerForm").reset();
            let modalElement = document.getElementById("adminModal");
            if (modalElement) {
                let modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) modal.hide(); // Close modal after successful registration
            }
        }
    })
    .catch(error => {
        console.error("Registration Error:", error);
        alert("Registration failed. Please try again.");
    });
}

/**
 * Forgot Password Function
 * Displays an alert for password recovery.
 */
function forgotPassword() {
    alert("Please contact your administrator.");
}

/**
 * Logout Confirmation
 */
function confirmLogout() {
    return confirm("Are you sure you want to log out?");
}
