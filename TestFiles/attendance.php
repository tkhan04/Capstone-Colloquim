<?php
/**
 * ATTENDANCE.PHP - Colloquium Event Attendance Sign-In/Out Page
 * 
 * This file handles the attendance tracking for colloquium events.
 * Students can sign in at the start of an event and sign out at the end.
 * The system automatically detects active events and records timestamps.
 * 
 * Database Tables Used:
 * - Event: To find currently active events
 * - Student: To validate student IDs
 * - AttendsEventSessions: To record sign-in/sign-out timestamps
 */

$dbConfigPath = __DIR__ . '/../secrets/db.php';
if (!file_exists($dbConfigPath)) {
    $dbConfigPath = __DIR__ . '/../secrets/db.php.example';
}
require $dbConfigPath;

/**
 * DATABASE CONNECTION
 * Establishes connection to MySQL database using credentials from db.php
 * Parameters: $dbHost, $dbUser, $dbPass, $dbName, $dbPort (from secrets/db.php)
 */
$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$dbError = null;      // Stores any database connection errors
$activeEvent = null;  // Stores the currently active event (if any)
$message = '';        // Feedback message to display to user
$messageType = '';    // Type of message: 'success', 'error', or 'info'

/**
 * CHECK DATABASE CONNECTION
 * If connection fails, store error message for display
 * If successful, query for any currently active event
 */
if ($conn->connect_error) {
    $dbError = "Database connection failed: " . $conn->connect_error;
} else {
    /**
     * FETCH ACTIVE EVENT
     * Queries the Event table to find any event currently in progress
     * An event is "active" if current time is between start_time and end_time
     * Uses prepared statements to prevent SQL injection
     */
    $now = date('Y-m-d H:i:s');
    $eventQuery = "SELECT event_id, event_name, event_type, start_time, end_time, location 
                   FROM Event 
                   WHERE start_time <= ? AND end_time >= ? 
                   LIMIT 1";
    $stmt = $conn->prepare($eventQuery);
    $stmt->bind_param('ss', $now, $now);  // 'ss' = two string parameters
    $stmt->execute();
    $result = $stmt->get_result();
    $activeEvent = $result->fetch_assoc();  // Returns null if no active event
    $stmt->close();
}

/**
 * HANDLE FORM SUBMISSION (POST REQUEST)
 * Processes the sign-in/sign-out form when submitted
 * Only processes if no database error exists
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$dbError) {
    // Sanitize and retrieve student ID from form
    $studentId = trim($_POST['student_id'] ?? '');
    
    /**
     * VALIDATION CHECKS
     * 1. Check if student ID is provided
     * 2. Check if there's an active event to sign into
     */
    if (empty($studentId)) {
        $message = "Please enter a valid Student ID.";
        $messageType = "error";
    } elseif (!$activeEvent) {
        $message = "No active event to sign in/out.";
        $messageType = "error";
    } else {
        /**
         * VERIFY STUDENT EXISTS IN DATABASE
         * Checks the Student table to ensure the entered ID is valid
         */
        $checkStudent = $conn->prepare("SELECT student_id FROM Student WHERE student_id = ?");
        $checkStudent->bind_param('i', $studentId);  // 'i' = integer parameter
        $checkStudent->execute();
        $studentResult = $checkStudent->get_result();
        
        if ($studentResult->num_rows === 0) {
            $message = "Student ID not found in system.";
            $messageType = "error";
        } else {
            /**
             * CHECK EXISTING ATTENDANCE RECORD
             * Looks up if this student already has an attendance record for the active event
             * This determines whether to create new record, update with sign-out, or reject
             */
            $checkAttendance = $conn->prepare(
                "SELECT attendance_id, start_scan_time, end_scan_time 
                 FROM AttendsEventSessions 
                 WHERE student_id = ? AND event_id = ?
                 LIMIT 1"
            );
            $checkAttendance->bind_param('ii', $studentId, $activeEvent['event_id']);
            $checkAttendance->execute();
            $attendanceResult = $checkAttendance->get_result();
            $attendance = $attendanceResult->fetch_assoc();
            
            $now = date('Y-m-d H:i:s');
            
            /**
             * ATTENDANCE LOGIC - Three possible scenarios:
             * 
             * 1. NO RECORD EXISTS: Create new attendance record (sign-in)
             * 2. HAS SIGN-IN BUT NO SIGN-OUT: Update record with sign-out time
             * 3. BOTH TIMES RECORDED: Student already completed attendance
             */
            if (!$attendance) {
                // SCENARIO 1: First scan - create sign-in record
                $insert = $conn->prepare(
                    "INSERT INTO AttendsEventSessions (student_id, event_id, start_scan_time, source) 
                     VALUES (?, ?, ?, 'manual')"
                );
                $insert->bind_param('iis', $studentId, $activeEvent['event_id'], $now);
                if ($insert->execute()) {
                    $message = "Successfully signed IN at " . date('g:i A');
                    $messageType = "success";
                } else {
                    $message = "Error recording attendance.";
                    $messageType = "error";
                }
            } elseif ($attendance['start_scan_time'] && !$attendance['end_scan_time']) {
                // SCENARIO 2: Second scan - record sign-out time
                $update = $conn->prepare(
                    "UPDATE AttendsEventSessions SET end_scan_time = ? WHERE attendance_id = ?"
                );
                $update->bind_param('si', $now, $attendance['attendance_id']);
                if ($update->execute()) {
                    $message = "Successfully signed OUT at " . date('g:i A');
                    $messageType = "success";
                } else {
                    $message = "Error recording sign-out.";
                    $messageType = "error";
                }
            } else {
                // SCENARIO 3: Already completed - inform user
                $message = "You have already signed in and out for this event.";
                $messageType = "info";
            }
            $checkAttendance->close();
        }
        $checkStudent->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Colloquium Attendance</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="attendance-page">
    <div class="attendance-container">
        <header class="attendance-header">
            <h1><i class="fas fa-calendar-check"></i> Colloquium Attendance</h1>
            <p class="subtitle">Scan or enter your student ID to sign in/out</p>
        </header>

        <div class="active-event-banner <?php echo $activeEvent ? 'event-active' : 'no-event'; ?>">
            <i class="fas <?php echo $activeEvent ? 'fa-broadcast-tower' : 'fa-exclamation-circle'; ?>"></i>
            <span>Active event: <?php echo $activeEvent ? htmlspecialchars($activeEvent['event_name']) : 'None detected'; ?></span>
        </div>

        <?php if ($message): ?>
        <div class="message <?php echo $messageType; ?>">
            <i class="fas <?php 
                echo $messageType === 'success' ? 'fa-check-circle' : 
                    ($messageType === 'error' ? 'fa-times-circle' : 'fa-info-circle'); 
            ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="attendance-form">
            <div class="input-group">
                <label for="student_id">Student ID</label>
                <input type="text" id="student_id" name="student_id" 
                       placeholder="Scan your ID card or type manually" 
                       autofocus required>
            </div>
            <button type="submit" class="btn-primary">
                <i class="fas fa-sign-in-alt"></i> Sign In/Out
            </button>
            <p class="help-text">This records start/end timestamps in attendance system.</p>
        </form>

        <?php if ($dbError): ?>
        <div class="db-error">
            <i class="fas fa-database"></i>
            <?php echo htmlspecialchars($dbError); ?>
        </div>
        <?php endif; ?>

        <?php if ($activeEvent): ?>
        <div class="event-details">
            <h3><?php echo htmlspecialchars($activeEvent['event_name']); ?></h3>
            <p><i class="fas fa-tag"></i> <?php echo htmlspecialchars($activeEvent['event_type']); ?></p>
            <p><i class="fas fa-clock"></i> <?php echo date('g:i A', strtotime($activeEvent['start_time'])); ?> - <?php echo date('g:i A', strtotime($activeEvent['end_time'])); ?></p>
            <?php if ($activeEvent['location']): ?>
            <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($activeEvent['location']); ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
