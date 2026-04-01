<?php
/**
 * DB.PHP - Database credentials
 * Update these values to match your local or hosted MySQL environment.
 * This file is required by every PHP page in the system.
 */

$dbHost = 'cray.cc.gettysburg.edu';   // MySQL host
$dbPort = 3306;                         // MySQL port
$dbName = 'colloquium';                 // Database name
$dbUser = 'khanta01';                   // MySQL username
$dbPass = 'Khan2004';                   // MySQL password

// Shared PDO connection used by pages that call getDB()
function getDB() {
    global $dbHost, $dbPort, $dbName, $dbUser, $dbPass;
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
