<?php
/**
 * BACKEND.PHP - Legacy connection-test / user-lookup endpoint
 * Kept for compatibility with any existing tests.
 * Uses AppUser table with correct schema columns.
 */

header('Content-Type: application/json');
require __DIR__ . '/../secrets/db.php';

try {
    $pdo = getDB();

    // Optional: look up a user by user_id
    if (isset($_GET['user_id'])) {
        $uid  = (int)$_GET['user_id'];
        $stmt = $pdo->prepare("SELECT user_id, email FROM AppUser WHERE user_id = ? LIMIT 1");
        $stmt->execute([$uid]);
        $row  = $stmt->fetch();

        echo json_encode([
            'ok'     => true,
            'exists' => (bool)$row,
            'user_id' => $row ? (int)$row['user_id'] : null,
        ]);
        exit;
    }

    // Optional: look up professor by email
    if (isset($_GET['prof_email'])) {
        $email = trim($_GET['prof_email']);
        $stmt  = $pdo->prepare(
            "SELECT p.professor_id FROM Professor p WHERE p.email = ? LIMIT 1"
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        echo json_encode([
            'ok'      => true,
            'exists'  => (bool)$row,
            'prof_id' => $row ? (int)$row['professor_id'] : null,
        ]);
        exit;
    }

    // Default: connection test
    echo json_encode([
        'ok'       => true,
        'message'  => 'Connected to MySQL successfully',
        'database' => DB_NAME ?? 'colloquium',
    ]);

} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
