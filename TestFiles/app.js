/**
 * APP.JS - Frontend JavaScript for Colloquium Attendance System
 * 
 * This file contains all client-side JavaScript functions including:
 * - handleLogin(): Main login handler - authenticates by email and redirects based on role
 * - callPHP(): Legacy function for database connection testing
 * - checkUser(): Legacy function for checking if user exists
 */

/**
 * HANDLE LOGIN FORM SUBMISSION
 * 
 * This function handles the main login form on index.html
 * It sends the email to login.php which checks the database and returns:
 * - User role (professor, student, admin)
 * - Redirect URL for the appropriate dashboard
 * 
 * @param {Event} event - The form submit event
 */
function handleLogin(event) {
    event.preventDefault();
    
    // Get the email input value
    const email = document.getElementById('email').value.trim();
    const resultDiv = document.getElementById('loginResult');
    
    // Validate email is provided
    if (!email) {
        resultDiv.innerHTML = '<div class="login-error"><i class="fas fa-exclamation-circle"></i> Please enter your email address.</div>';
        return;
    }
    
    // Show loading state
    resultDiv.innerHTML = '<div class="login-loading"><i class="fas fa-spinner fa-spin"></i> Signing in...</div>';
    
    // Call login.php to authenticate user
    const url = 'login.php?email=' + encodeURIComponent(email);
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data && data.ok) {
                if (data.exists && data.redirect) {
                    // SUCCESS: Show welcome message then redirect
                    const roleName = data.user.role.charAt(0).toUpperCase() + data.user.role.slice(1);
                    resultDiv.innerHTML = `<div class="login-success"><i class="fas fa-check-circle"></i> Welcome, ${roleName}! Redirecting...</div>`;
                    
                    // Redirect to appropriate dashboard after short delay
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 1000);
                } else {
                    // User not found
                    resultDiv.innerHTML = '<div class="login-error"><i class="fas fa-user-times"></i> Email not found. Please contact administrator.</div>';
                }
                return;
            }
            
            // Error from server
            const errMsg = (data && data.error) ? data.error : 'Unknown error occurred';
            resultDiv.innerHTML = `<div class="login-error"><i class="fas fa-exclamation-triangle"></i> ${errMsg}</div>`;
        })
        .catch(error => {
            // Network or parsing error
            resultDiv.innerHTML = `<div class="login-error"><i class="fas fa-wifi"></i> Connection error: ${error.message || error}</div>`;
        });
}


/**
 * CALL PHP (Legacy Function)
 * 
 * Tests database connection by calling backend.php
 * Used for initial setup/debugging
 */
function callPHP() {
    fetch('backend.php')
        .then(response => response.json())
        .then(data => {
            if (data && data.ok) {
                document.getElementById('result').innerHTML = data.message;
                document.getElementById('database').innerHTML = data.database ? ('Database: ' + data.database) : '';
                return;
            }

            const errMsg = (data && data.error) ? data.error : 'Unknown error';
            document.getElementById('result').innerHTML = 'Error: ' + errMsg;
            document.getElementById('database').innerHTML = '';
        })
        .catch(error => {
            document.getElementById('result').innerHTML = 'Error: ' + error;
            document.getElementById('database').innerHTML = '';
        });
}


/**
 * CHECK USER (Legacy Function)
 * 
 * Checks if a professor exists by email
 * Used for debugging/testing
 * 
 * @param {string} profEmail - Professor's email to check
 */
function checkUser(profEmail) {
    const email = (profEmail ?? '').toString().trim();
    if (!email) {
        document.getElementById('result').innerHTML = 'Error: missing prof email';
        document.getElementById('database').innerHTML = '';
        return;
    }

    const url = 'backend.php?prof_email=' + encodeURIComponent(email);
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data && data.ok) {
                document.getElementById('result').innerHTML = data.exists
                    ? 'Professor exists'
                    : 'Professor not found';
                document.getElementById('database').innerHTML = data.prof_id ? ('prof_id: ' + data.prof_id) : '';
                return;
            }

            const errMsg = (data && data.error) ? data.error : 'Unknown error';
            document.getElementById('result').innerHTML = 'Error: ' + errMsg;
            document.getElementById('database').innerHTML = '';
        })
        .catch(error => {
            document.getElementById('result').innerHTML = 'Error: ' + error;
            document.getElementById('database').innerHTML = '';
        });
}
