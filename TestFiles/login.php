<?php
/**
 * LOGIN.PHP - Authentication handler
 *
 * Accepts: GET ?email=...&password=...
 * Checks AppUser table (email + password_hash).
 * Returns JSON: { ok, redirect, user } on success
 *               { ok, error }          on failure
 *
 * DB columns used (matches provided schema):
 *   AppUser: user_id, fname, lname, email, role, password_hash, is_active
 *   Professor: professor_id (= user_id FK)
 *   Student:   student_id  (= user_id FK)
 */

header('Content-Type: application/json');
ini_set('display_errors', 0);

require __DIR__ . '/../secrets/db.php';

// Accept credentials from either GET or POST
$email    = trim($_GET['email']    ?? $_POST['email']    ?? '');
$password =      $_GET['password'] ?? $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    echo json_encode(['ok' => false, 'error' => 'Username and password are required.']);
    exit;
}

// Enforce Gettysburg email domain
if (!preg_match('/^[a-zA-Z0-9._%+-]+@gettysburg\.edu$/', $email)) {
    echo json_encode(['ok' => false, 'error' => 'Must be a valid Gettysburg email address (username@gettysburg.edu).']);
    exit;
}

try {
    $pdo = getDB();

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

    // Support bcrypt hashes AND plain-text seeds used during development
    $hashOk = password_verify($password, $user['password_hash'])
           || $password === $user['password_hash'];

    if (!$hashOk) {
        echo json_encode(['ok' => false, 'error' => 'Incorrect password.']);
        exit;
    }

    if (!(int)$user['is_active']) {
        echo json_encode(['ok' => false, 'error' => 'Account is inactive. Contact administrator.']);
        exit;
    }

    $userId   = (int)$user['user_id'];
    $redirect = '';

    switch ($user['role']) {
        case 'professor':
            $redirect = "prof_dashboard.php?prof_id={$userId}";
            break;
        case 'student':
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
