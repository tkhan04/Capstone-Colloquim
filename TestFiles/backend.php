<?php

// Database configuration
$dbConfigPath = __DIR__ . '/../secrets/db.php';
if (!file_exists($dbConfigPath)) {
    $dbConfigPath = __DIR__ . '/../secrets/db.php.example';
}
require $dbConfigPath;

// Get user_id from request
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

// Initialize MySQLi connection
$conn = mysqli_init();
if ($conn === false) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to initialize MySQL',
    ]);
    exit;
}

try {
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $connected = @$conn->real_connect($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    
    if ($connected !== true) {
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
    $conn->close();
}
?>
