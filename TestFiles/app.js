/**
 * APP.JS - Frontend JavaScript for Colloquium Attendance System
 *
 * handleLogin() - sends email + password to login.php, redirects on success
 */

/**
 * HANDLE LOGIN FORM SUBMISSION
 * Sends email and password to login.php via GET.
 * On success, login.php returns JSON with redirect URL.
 */
function handleLogin(event) {
    event.preventDefault();

    const username  = document.getElementById('username').value.trim();
    const password  = document.getElementById('password').value;
    const resultDiv = document.getElementById('loginResult');

    if (!username || !password) {
        resultDiv.innerHTML = '<div class="login-error"><i class="fas fa-exclamation-circle"></i> Please enter your username and password.</div>';
        return;
    }

    resultDiv.innerHTML = '<div class="login-loading"><i class="fas fa-spinner fa-spin"></i> Signing in...</div>';

    // Convert username to Gettysburg email and pass to login.php
    const email = username + '@gettysburg.edu';
    const url = 'login.php?email=' + encodeURIComponent(email) + '&password=' + encodeURIComponent(password);

    fetch(url)
        .then(r => r.text())
        .then(text => {
            let data;
            try { data = JSON.parse(text); }
            catch(e) {
                resultDiv.innerHTML = '<div class="login-error"><i class="fas fa-exclamation-triangle"></i> Unexpected server response.</div>';
                console.error('Raw response:', text);
                return;
            }

            if (data && data.ok && data.redirect) {
                // Show welcome then redirect
                const role = data.user.role.charAt(0).toUpperCase() + data.user.role.slice(1);
                resultDiv.innerHTML = `<div class="login-success"><i class="fas fa-check-circle"></i> Welcome, ${role}! Redirecting...</div>`;
                setTimeout(() => { window.location.href = data.redirect; }, 900);
            } else {
                const msg = (data && data.error) ? data.error : 'Login failed. Please check your credentials.';
                resultDiv.innerHTML = `<div class="login-error"><i class="fas fa-user-times"></i> ${msg}</div>`;
            }
        })
        .catch(err => {
            resultDiv.innerHTML = `<div class="login-error"><i class="fas fa-wifi"></i> Connection error: ${err.message}</div>`;
        });
}

// Clear any lingering login messages when page loads or gets focus (for back navigation)
document.addEventListener('DOMContentLoaded', function() {
    clearLoginMessage();
});

// Also clear when page gets focus (handles back navigation better)
window.addEventListener('pageshow', function(event) {
    // pageshow fires when navigating back (including from cache)
    clearLoginMessage();
});

// Clear when input fields get focus (user interaction)
document.addEventListener('focus', function(event) {
    if (event.target.id === 'username' || event.target.id === 'password') {
        clearLoginMessage();
    }
}, true);

function clearLoginMessage() {
    const resultDiv = document.getElementById('loginResult');
    if (resultDiv) {
        resultDiv.innerHTML = '';
    }
}
