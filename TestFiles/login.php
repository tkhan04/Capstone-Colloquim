<?php
/**
 * LOGIN.PHP - Authentication handler
 *
 * Accepts: GET ?email=...&password=...
 * Checks AppUser table (email + password_hash).
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

// Auto-append @gettysburg.edu if user only typed username (no @ symbol)
if (!str_contains($email, '@')) {
    $email = $email . '@gettysburg.edu';
}

// Validate email format
if (!preg_match('/^[a-zA-Z0-9._%+-]+@gettysburg\.edu$/', $email)) {
    echo json_encode(['ok' => false, 'error' => 'Please enter your username or full email address.']);
    exit;
}

try {
    $pdo = getDB();

    // If the local part is all digits (student typed their 7-digit ID),
    // look up by user_id directly so login works even after they update their email.
    // Otherwise look up by email (professors, admins, and students using their username).
    $localPart = strstr($email, '@', true);
    if (preg_match('/^\d{7}$/', $localPart)) {
        $stmt = $pdo->prepare(
            "SELECT user_id, fname, lname, email, role, password_hash, is_active, account_needs_setup
             FROM AppUser WHERE user_id = ? LIMIT 1"
        );
        $stmt->execute([(int)$localPart]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT user_id, fname, lname, email, role, password_hash, is_active, account_needs_setup
             FROM AppUser WHERE email = ? LIMIT 1"
        );
        $stmt->execute([$email]);
    }
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['ok' => false, 'error' => 'Username or ID not found in system.']);
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
    $accountNeedsSetup = (int)($user['account_needs_setup'] ?? 0);

    // If student account needs setup, redirect to account completion page
    if ($accountNeedsSetup && $user['role'] === 'student') {
        $redirect = "account_setup.php?student_id={$userId}";
    } else {
        // Normal dashboard redirect based on role
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