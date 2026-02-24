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
