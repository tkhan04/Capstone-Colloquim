function callPHP() {
    // This calls our PHP file (backend.php) from the browser.
    // The PHP file connects to MySQL and returns a JSON response.
    fetch('backend.php')
        // Convert the HTTP response into JSON so we can read fields like data.ok, data.message, etc.
        .then(response => response.json())
        .then(data => {
            // If the backend says ok=true, display the success message + database name.
            if (data && data.ok) {
                document.getElementById('result').innerHTML = data.message;
                document.getElementById('database').innerHTML = data.database ? ('Database: ' + data.database) : '';
                return;
            }

            // If ok=false, show the error message the backend sent (or a generic fallback).
            const errMsg = (data && data.error) ? data.error : 'Unknown error';
            document.getElementById('result').innerHTML = 'Error: ' + errMsg;
            document.getElementById('database').innerHTML = '';
        })
        .catch(error => {
            // This catches network/parse errors (example: backend is down, PHP error, non-JSON response).
            document.getElementById('result').innerHTML = 'Error: ' + error;
            document.getElementById('database').innerHTML = '';
        });
}


//check if user exists in database
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

// Handle login form submission
function handleLogin(event) {
    event.preventDefault();
    const email = document.getElementById('email').value;
    const resultDiv = document.getElementById('loginResult');

    // Show loading state
    resultDiv.textContent = 'Checking...';

    // Reuse checkUser to verify professor exists
    const url = 'backend.php?prof_email=' + encodeURIComponent(email);
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data && data.ok) {
                if (data.exists) {
                    // Real email attempted
                    if (data.mail_sent) {
                        resultDiv.innerHTML = `<div style="color: green;">Login link sent to your email.</div>`;
                    } else {
                        resultDiv.innerHTML = `<div style="color: orange;">Failed to send email (check server mail config). <a href="success.html?prof_id=${encodeURIComponent(data.prof_id)}">Click here to continue anyway</a></div>`;
                    }
                } else {
                    resultDiv.innerHTML = `<div style="color: red;">User does not exist, contact administrator.</div>`;
                }
                return;
            }

            const errMsg = (data && data.error) ? data.error : 'Unknown error';
            resultDiv.innerHTML = `<div style="color: red;">Error: ${errMsg}</div>`;
        })
        .catch(error => {
            resultDiv.innerHTML = `<div style="color: red;">Error: ${error}</div>`;
        });
}
