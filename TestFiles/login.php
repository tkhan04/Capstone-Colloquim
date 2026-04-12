<?php

header('Content-Type: application/json');
ini_set('display_errors', 0); // hide PHP errors from JSON output

// Load DB helper
require __DIR__ . '/../secrets/db.php';

// Read credentials from GET (matches existing app.js approach)
$email    = trim($_GET['email']    ?? $_POST['email']    ?? '');
$password =      $_GET['password'] ?? $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    echo json_encode(['ok' => false, 'error' => 'Username and password are required.']);
    exit;
}

// Validate Gettysburg email format
if (!preg_match('/^[a-zA-Z0-9._%+-]+@gettysburg\.edu$/', $email)) {
    echo json_encode(['ok' => false, 'error' => 'Must be a valid Gettysburg email address (username@gettysburg.edu).']);
    exit;
}

try {
    $pdo = getDB();

    // Fetch user by email from AppUser
    $stmt = $pdo->prepare(
        "SELECT user_id, fname, lname, email, role, password_hash, is_active
         FROM AppUser WHERE email = ? LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['ok' => false, 'error' => 'Email not found in system.']);
        exit;
    }

    // Verify password (supports both hashed passwords and plain-text fallback for dev)
    $hashOk = password_verify($password, $user['password_hash'])
           || $password === $user['password_hash']; // plain-text fallback for dev seeds

    if (!$hashOk) {
        echo json_encode(['ok' => false, 'error' => 'Incorrect password.']);
        exit;
    }

    if (!(int)$user['is_active']) {
        echo json_encode(['ok' => false, 'error' => 'Account is inactive. Contact administrator.']);
        exit;
    }

    // Build redirect based on role
    $userId   = (int)$user['user_id'];
    $redirect = '';

    switch ($user['role']) {
        case 'professor':
            // professor_id = user_id (FK in Professor table)
            $redirect = "prof_dashboard.php?prof_id={$userId}";
            break;

        case 'student':
            // student_id = user_id (FK in Student table)
            $redirect = "stud_dashboard.php?student_id={$userId}&tab=upcoming";
            break;

        case 'admin':
            $redirect = "admin_dashboard.php?admin_id={$userId}";
            break;

        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown role.']);
            exit;
    }

    echo json_encode([
        'ok'       => true,
        'redirect' => $redirect,
        'user'     => [
            'user_id' => $userId,
            'fname'   => $user['fname'],
            'lname'   => $user['lname'],
            'email'   => $user['email'],
            'role'    => $user['role'],
        ],
    ]);

} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
