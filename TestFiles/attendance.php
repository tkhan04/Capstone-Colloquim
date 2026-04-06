<?php
/**
 * ATTENDANCE.PHP - Manual student check-in / check-out page
 *
 * A student walks up, enters their 7-digit Gettysburg ID (or scans their
 * physical card — the scanner types the ID into the field automatically).
 * The system finds the active event and records start_scan_time on first
 * entry and end_scan_time on second entry.
 *
 * DB tables used (matches provided schema exactly):
 *   Event(event_id, event_name, event_type, start_time, end_time, location)
 *   Student(student_id)
 *   AttendsEventSessions(student_id PK, event_id PK, start_scan_time,
 *                        end_scan_time, minutes_present [generated], audit_note,
 *                        overridden_by)
 */

 require __DIR__ . '/../secrets/db.php';

$dbError     = null;
$activeEvent = null;
$message     = '';
$messageType = '';

try {
    $pdo = getDB();

    // Find any event currently in progress (now between start_time and end_time)
    $now  = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "SELECT event_id, event_name, event_type, start_time, end_time, location
         FROM Event
         WHERE start_time <= ? AND end_time >= ?
         LIMIT 1"
    );
    $stmt->execute([$now, $now]);
    $activeEvent = $stmt->fetch();

} catch (PDOException $e) {
    $dbError = 'Database connection failed: ' . $e->getMessage();
}

// Handle sign-in/out form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$dbError) {
    $studentId = trim($_POST['student_id'] ?? '');
    
    // Remove all non-numeric characters from scanner input
    $studentId = preg_replace('/[^0-9]/', '', $studentId);

    if ($studentId === '') {
        $message     = 'Please enter your Student ID.';
        $messageType = 'error';
    } elseif (!$activeEvent) {
        $message     = 'No active event right now. Check back when an event is in progress.';
        $messageType = 'error';
    } else {
        try {
            $pdo = getDB();

            // Verify the student exists in the Student table
            $check = $pdo->prepare("SELECT student_id FROM Student WHERE student_id = ? LIMIT 1");
            $check->execute([$studentId]);

            if (!$check->fetch()) {
                $message     = 'Student ID not found. Please check your ID or contact a professor.';
                $messageType = 'error';
            } else {
                $eventId = $activeEvent['event_id'];

                // Check for existing attendance record (composite PK: student_id + event_id)
                $existing = $pdo->prepare(
                    "SELECT start_scan_time, end_scan_time
                     FROM AttendsEventSessions
                     WHERE student_id = ? AND event_id = ?"
                );
                $existing->execute([$studentId, $eventId]);
                $record = $existing->fetch();

                $now = date('Y-m-d H:i:s');

                if (!$record) {
                    // First scan: insert sign-in record
                    $pdo->prepare(
                        "INSERT INTO AttendsEventSessions (student_id, event_id, start_scan_time)
                         VALUES (?, ?, ?)"
                    )->execute([$studentId, $eventId, $now]);
                    $message     = 'Signed IN at ' . date('g:i A') . '. Come back at the end to sign out!';
                    $messageType = 'success';

                } elseif ($record['start_scan_time'] && !$record['end_scan_time']) {
                    // Second scan: record sign-out time
                    $pdo->prepare(
                        "UPDATE AttendsEventSessions
                         SET end_scan_time = ?
                         WHERE student_id = ? AND event_id = ?"
                    )->execute([$now, $studentId, $eventId]);
                    $message     = 'Signed OUT at ' . date('g:i A') . '. Attendance recorded — thank you!';
                    $messageType = 'success';

                } else {
                    // Both timestamps already present
                    $message     = 'You have already completed check-in and check-out for this event.';
                    $messageType = 'info';
                }
            }
        } catch (PDOException $e) {
            $message     = 'Database error: ' . $e->getMessage();
            $messageType = 'error';
        }
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
            <p class="subtitle">Scan or enter your student ID to check in/out</p>
        </header>

        <!-- Active event banner -->
        <div class="active-event-banner <?= $activeEvent ? 'event-active' : 'no-event' ?>">
            <i class="fas <?= $activeEvent ? 'fa-broadcast-tower' : 'fa-exclamation-circle' ?>"></i>
            <span>
                <?= $activeEvent
                    ? 'Active event: ' . htmlspecialchars($activeEvent['event_name'])
                    : 'No active event detected' ?>
            </span>
        </div>

        <!-- Feedback message -->
        <?php if ($message): ?>
        <div class="message <?= $messageType ?>">
            <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : ($messageType === 'error' ? 'fa-times-circle' : 'fa-info-circle') ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- Check-in form: student types or scanner types their 7-digit ID -->
        <form method="POST" class="attendance-form">
            <div class="input-group">
                <label for="student_id">Student ID</label>
                <input type="text" id="student_id" name="student_id"
                       placeholder="Scan your ID card or type manually"
                       autofocus required autocomplete="off"
                       inputmode="numeric" pattern="\d{6,8}">
            </div>
            <button type="submit" class="btn-primary">
                <i class="fas fa-sign-in-alt"></i> Check In / Check Out
            </button>
            <p class="help-text">Use this page at the start <em>and</em> end of the event.</p>
        </form>

        <?php if ($dbError): ?>
        <div class="db-error"><i class="fas fa-database"></i> <?= htmlspecialchars($dbError) ?></div>
        <?php endif; ?>

        <!-- Show current event details when one is active -->
        <?php if ($activeEvent): ?>
        <div class="event-details">
            <h3><?= htmlspecialchars($activeEvent['event_name']) ?></h3>
            <p><i class="fas fa-tag"></i> <?= htmlspecialchars($activeEvent['event_type']) ?></p>
            <p><i class="fas fa-clock"></i>
               <?= date('g:i A', strtotime($activeEvent['start_time'])) ?> –
               <?= date('g:i A', strtotime($activeEvent['end_time'])) ?>
            </p>
            <?php if ($activeEvent['location']): ?>
            <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($activeEvent['location']) ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
