<?php
/**
 * LOGIN.PHP - Authentication Handler for Colloquium System
 * 
 * This file handles user authentication by email address.
 * It checks the AppUser table for the email and returns the user's role.
 * Based on role, the frontend redirects to the appropriate dashboard:
 * - professor -> prof_dashboard.php
 * - student -> stud_dashboard.php  
 * - admin -> admin_dashboard.php
 * 
 * Database Tables Used:
 * - AppUser: To verify email and get user role
 * - Professor: To get professor_id for professor users
 * - Student: To get student_id for student users
 */

header('Content-Type: application/json');
$dbConfigPath = __DIR__ . '/../secrets/db.php';
if (!file_exists($dbConfigPath)) {
    $dbConfigPath = __DIR__ . '/../secrets/db.php.example';
}
require $dbConfigPath;

/**
 * DATABASE CONNECTION
 * Establishes connection to MySQL database
 */
try {
    $pdo = new PDO("mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode([
        'ok' => false,
        'error' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit;
}

/**
 * GET EMAIL FROM REQUEST
 * Accepts both GET and POST requests
 */
$email = null;
if (isset($_GET['email'])) {
    $email = trim($_GET['email']);
} elseif (isset($_POST['email'])) {
    $email = trim($_POST['email']);
}

// Validate email is provided
if (empty($email)) {
    echo json_encode([
        'ok' => false,
        'error' => 'Email is required'
    ]);
    exit;
}

/**
 * LOOK UP USER BY EMAIL
 * Queries AppUser table to find matching user
 * Returns user_id and role if found
 */
$stmt = $pdo->prepare("SELECT user_id, username, email, role FROM AppUser WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// User not found
if (!$user) {
    echo json_encode([
        'ok' => true,
        'exists' => false,
        'error' => 'Email not found in system'
    ]);
    exit;
}

/**
 * GET ROLE-SPECIFIC ID
 * Based on user role, fetch the corresponding ID from Professor/Student table
 * This ID is used in the dashboard URLs
 */
$roleId = null;
$redirectUrl = null;

switch ($user['role']) {
    case 'professor':
        /**
         * PROFESSOR LOGIN
         * Fetch professor_id from Professor table using user_id
         */
        $profStmt = $pdo->prepare("SELECT professor_id FROM Professor WHERE user_id = ?");
        $profStmt->execute([$user['user_id']]);
        $professor = $profStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($professor) {
            $roleId = $professor['professor_id'];
            $redirectUrl = "prof_dashboard.php?prof_id=" . $roleId;
        } else {
            echo json_encode([
                'ok' => false,
                'error' => 'Professor profile not found'
            ]);
            exit;
        }
        break;
        
    case 'student':
        /**
         * STUDENT LOGIN
         * Fetch student_id from Student table using user_id
         */
        $stuStmt = $pdo->prepare("SELECT student_id FROM Student WHERE user_id = ?");
        $stuStmt->execute([$user['user_id']]);
        $student = $stuStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student) {
            $roleId = $student['student_id'];
            $redirectUrl = "stud_dashboard.php?student_id=" . $roleId . "&tab=upcoming";
        } else {
            echo json_encode([
                'ok' => false,
                'error' => 'Student profile not found'
            ]);
            exit;
        }
        break;
        
    case 'admin':
        /**
         * ADMIN LOGIN
         * Admins use their user_id directly
         */
        $roleId = $user['user_id'];
        $redirectUrl = "admin_dashboard.php?admin_id=" . $roleId;
        break;
        
    default:
        echo json_encode([
            'ok' => false,
            'error' => 'Unknown user role'
        ]);
        exit;
}

/**
 * RETURN SUCCESS RESPONSE
 * Includes user info and redirect URL for frontend
 */
echo json_encode([
    'ok' => true,
    'exists' => true,
    'user' => [
        'user_id' => $user['user_id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role'],
        'role_id' => $roleId
    ],
    'redirect' => $redirectUrl
]);

