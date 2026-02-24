<?php

/*
$dbHost = '';
$dbPort = '';
$dbName = '';
$dbUser = '';
$dbPass = '';
*/

require __DIR__ . '/../secrets/db.php'; //ignore this line, it has the actual credentials, 
// refer to the creds in the commented line at the top and enter your local credentials

/*
 above me is just the credentials for the database
*/
$conn = mysqli_init(); // Initialize the MySQLi connection
if ($conn === false) { // if the connection fails then they will get an error message
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to initialize MySQL',
    ]);
    exit;
}

try {
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5); // Set the connection timeout to 5 seconds
    $connected = @$conn->real_connect($dbHost, $dbUser, $dbPass, $dbName, $dbPort); // Attempt to connect to the database
    if ($connected !== true) { //connection failed but connecting to db was successful, so your credentials are wrong
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => $conn->connect_error ?: 'Unknown MySQL connection error',
        ]);
        exit;
    }

    //connection successful\
    /*
        if connection is successful, send this json response to our js file
    */
    echo json_encode([
        'ok' => true, 
        'message' => 'Connected to MySQL successfully',
        'database' => $dbName,
    ]);
} finally {
    $conn->close();//always close the connection
}
?>
