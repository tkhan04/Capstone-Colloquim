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
require __DIR__ . '/../secrets/db.php';

/**
 * DATABASE CONNECTION
 * Establishes connection to MySQL database
 */
$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);

if ($conn->connect_error) {
    echo json_encode([
        'ok' => false,
        'error' => 'Database connection failed: ' . $conn->connect_error
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
$stmt = $conn->prepare("SELECT user_id, username, email, role FROM AppUser WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

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
        $profStmt = $conn->prepare("SELECT professor_id FROM Professor WHERE user_id = ?");
        $profStmt->bind_param('i', $user['user_id']);
        $profStmt->execute();
        $profResult = $profStmt->get_result();
        $professor = $profResult->fetch_assoc();
        $profStmt->close();
        
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
        $stuStmt = $conn->prepare("SELECT student_id FROM Student WHERE user_id = ?");
        $stuStmt->bind_param('i', $user['user_id']);
        $stuStmt->execute();
        $stuResult = $stuStmt->get_result();
        $student = $stuResult->fetch_assoc();
        $stuStmt->close();
        
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

$conn->close();
