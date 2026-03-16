<?php
/*
$dbHost = '';
$dbPort = '';
$dbName = '';
$dbUser = '';
$dbPass = '';
*/



$dbConfigPath = __DIR__ . '/../secrets/db.php';
if (!file_exists($dbConfigPath)) {
    $dbConfigPath = __DIR__ . '/../secrets/db.php.example';
}
require $dbConfigPath;
// refer to the creds in the commented line at the top and enter your local credentials

/*
 above me is just the credentials for the database
*/
$userId = null;
if (isset($_GET['user_id'])) {
    $userId = trim((string)$_GET['user_id']);
    if ($userId === '') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'Missing user_id',
        ]);
        exit;
    }
}

$conn = mysqli_init(); // Initialize the MySQLi connection
if ($conn === false) { // if the connection fails then they will get an error message
    http_response_code(500);
    header('Content-Type: application/json');
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
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => $conn->connect_error ?: 'Unknown MySQL connection error',
        ]);
        exit;
    }

    header('Content-Type: application/json');

    if ($userId !== null) {
        $stmt = $conn->prepare('SELECT user_id, email FROM AppUser WHERE user_id = ? LIMIT 1');
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'error' => 'Failed to prepare statement',
            ]);
            exit;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row !== null) {
            // User exists: return success
            $uid = (int)$row['user_id'];
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $successLink = $scheme . '://' . $host . '/success.html?user_id=' . urlencode($uid);
            echo json_encode([
                'ok' => true,
                'exists' => true,
                'user_id' => $uid,
                'link' => $successLink,
            ]);
            exit;
        } else {
            // User not found
            echo json_encode([
                'ok' => true,
                'exists' => false,
                'user_id' => null,
            ]);
            exit;
        }
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Connected to MySQL successfully',
        'database' => $dbName,
    ]);
} finally {
    $conn->close();//always close the connection
}
?>
