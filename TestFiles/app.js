/**
 * APP.JS - Frontend JavaScript for Colloquium Attendance System
 *
 * handleLogin() - sends email + password to login.php, redirects on success
 * The login form accepts a plain username; this script appends @gettysburg.edu
 * before sending to login.php so users never have to type the full email.
 */

/**
 * HANDLE LOGIN FORM SUBMISSION
 * Converts username → email, sends to login.php via GET.
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

    // Append domain so the server always receives a full Gettysburg email
    const email = username.includes('@') ? username : username + '@gettysburg.edu';
    const url   = 'login.php?email=' + encodeURIComponent(email) + '&password=' + encodeURIComponent(password);

    fetch(url)
        .then(r => r.text())
        .then(text => {
            let data;
            try { data = JSON.parse(text); }
            catch (e) {
                resultDiv.innerHTML = '<div class="login-error"><i class="fas fa-exclamation-triangle"></i> Unexpected server response.</div>';
                console.error('Raw response:', text);
                return;
            }

            if (data && data.ok && data.redirect) {
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

// ── Page lifecycle helpers ───────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function () {
    // Show success message from registration redirect
    const urlParams = new URLSearchParams(window.location.search);
    const message   = urlParams.get('message');
    if (message) {
        const resultDiv = document.getElementById('loginResult');
        if (resultDiv) {
            resultDiv.innerHTML = `<div class="login-success"><i class="fas fa-check-circle"></i> ${message}</div>`;
        }
    } else {
        clearLoginMessage();
    }
});

// Clear stale messages when navigating back to the page (bfcache)
window.addEventListener('pageshow', function () {
    const urlParams = new URLSearchParams(window.location.search);
    if (!urlParams.get('message')) {
        clearLoginMessage();
    }
});

// Clear message when user starts typing credentials again
document.addEventListener('focus', function (event) {
    if (event.target.id === 'username' || event.target.id === 'password') {
        clearLoginMessage();
    }
}, true);

function clearLoginMessage() {
    const resultDiv = document.getElementById('loginResult');
    if (resultDiv) resultDiv.innerHTML = '';
}
