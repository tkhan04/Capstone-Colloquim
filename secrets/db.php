<?php
$dbHost = 'cray.cc.gettysburg.edu';
$dbPort = 3306;
$dbName = 'colloquium';
$dbUser = 'khanta01';
$dbPass = 'Khan2004';

function getDB() {
    global $pdo;
    return $pdo;
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;port=$dbPort;dbname=$dbName",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}
?>