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

    const email     = document.getElementById('email').value.trim();
    const password  = document.getElementById('password').value;
    const resultDiv = document.getElementById('loginResult');

    if (!email || !password) {
        resultDiv.innerHTML = '<div class="login-error"><i class="fas fa-exclamation-circle"></i> Please enter your email and password.</div>';
        return;
    }

    resultDiv.innerHTML = '<div class="login-loading"><i class="fas fa-spinner fa-spin"></i> Signing in...</div>';

    // Pass credentials to login.php
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
