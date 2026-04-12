<?php
// Set timezone to match system (PDT)
date_default_timezone_set('America/Los_Angeles');

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

    $profId = isset($_GET['prof_id']) ? (int)$_GET['prof_id'] : 0;
    $adminId = isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : 0;
    $isProfessorAccess = false;
    $isAdminAccess = false;
    $professor = null;
    $admin = null;

    if ($profId > 0) {
        $profStmt = $pdo->prepare("SELECT professor_id, fname, lname FROM Professor WHERE professor_id = ? LIMIT 1");
        $profStmt->execute([$profId]);
        $professor = $profStmt->fetch();
        $isProfessorAccess = (bool)$professor;
    }

    if ($adminId > 0) {
        $adminStmt = $pdo->prepare("SELECT user_id, fname, lname FROM AppUser WHERE user_id = ? AND role = 'admin' LIMIT 1");
        $adminStmt->execute([$adminId]);
        $admin = $adminStmt->fetch();
        $isAdminAccess = (bool)$admin;
    }

    $isAuthorizedAccess = $isProfessorAccess || $isAdminAccess;

    // Find all events that are currently check-in eligible.
    // Regular events open 10 minutes before start and remain open until end.
    // Professors can also check in hackathon students early for hackathon events.
    $now = date('Y-m-d H:i:s');
    if ($isProfessorAccess) {
        $stmt = $pdo->prepare(
            "SELECT event_id, event_name, event_type, start_time, end_time, location
             FROM Event
             WHERE (
                 ? >= DATE_SUB(start_time, INTERVAL 10 MINUTE)
                 AND end_time >= ?
                 AND LOWER(event_type) != 'hackathon'
             )
             OR (
                 LOWER(event_type) = 'hackathon'
                 AND end_time >= ?
             )
             ORDER BY start_time ASC"
        );
        $stmt->execute([$now, $now, $now]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT event_id, event_name, event_type, start_time, end_time, location
             FROM Event
             WHERE ? >= DATE_SUB(start_time, INTERVAL 10 MINUTE)
               AND end_time >= ?
               AND LOWER(event_type) != 'hackathon'
             ORDER BY start_time ASC"
        );
        $stmt->execute([$now, $now]);
    }
    $availableEvents = $stmt->fetchAll();

    // For backward compatibility, set activeEvent to the first one if only one
    $activeEvent = count($availableEvents) === 1 ? $availableEvents[0] : null;

    $manualEvent = !empty($availableEvents); // Any available events are manual check-in eligible

} catch (PDOException $e) {
    $dbError = 'Database connection failed: ' . $e->getMessage();
}

// Handle sign-in/out form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$dbError) {
    $studentId = trim($_POST['student_id'] ?? '');
    $selectedEventId = (int)($_POST['event_id'] ?? 0);

    $error = '';
    if ($studentId === '') {
        $error = 'Please enter your Student ID.';
    }
    if (!$isAuthorizedAccess) {
        $error = 'This page is reserved for professor or admin access. Open it from the professor or admin dashboard.';
    }
    if (empty($availableEvents)) {
        $error = 'No events are currently available for check-in.';
    }
    if ($selectedEventId === 0) {
        $error = 'Please select an event.';
    }

    if ($error) {
        $message = $error;
        $messageType = 'error';
    } else {
        // Find the selected event from available events
        $selectedEvent = null;
        foreach ($availableEvents as $event) {
            if ($event['event_id'] === $selectedEventId) {
                $selectedEvent = $event;
                break;
            }
        }

        if (!$selectedEvent) {
            $message = 'Selected event is no longer available.';
            $messageType = 'error';
        } else {
            try {
                $pdo = getDB();

                // Verify the student exists in the Student table
                $check = $pdo->prepare("SELECT student_id FROM Student WHERE student_id = ? LIMIT 1");
                $check->execute([$studentId]);

                if (!$check->fetch()) {
                    $message = 'Student ID not found. Please check your ID or contact a professor.';
                    $messageType = 'error';
                } else {
                    $eventId = $selectedEvent['event_id'];

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
                        // First scan: insert sign-in record (allowed from 10 min before until event ends)
                        if ($now > $selectedEvent['end_time']) {
                            $message = 'This event has already ended.';
                            $messageType = 'error';
                        } else {
                            $pdo->prepare(
                                "INSERT INTO AttendsEventSessions (student_id, event_id, start_scan_time)
                                 VALUES (?, ?, ?)"
                            )->execute([$studentId, $eventId, $now]);
                            $message = 'Signed IN at ' . date('g:i A') . '. Come back at the end to sign out!';
                            $messageType = 'success';
                        }

                    } elseif ($record['start_scan_time'] && !$record['end_scan_time']) {
                        if ($now < $selectedEvent['end_time']) {
                            $pdo->prepare(
                                "UPDATE AttendsEventSessions
                                 SET end_scan_time = ?, audit_note = ?
                                 WHERE student_id = ? AND event_id = ?"
                            )->execute([$now, 'Early checkout - no credit', $studentId, $eventId]);
                            $message = 'Checked out before the event ended. No credit will be awarded.';
                            $messageType = 'error';
                        } else {
                            $pdo->prepare(
                                "UPDATE AttendsEventSessions
                                 SET end_scan_time = ?
                                 WHERE student_id = ? AND event_id = ?"
                            )->execute([$now, $studentId, $eventId]);
                            $message = 'Signed OUT at ' . date('g:i A') . '. Attendance recorded for credit!';
                            $messageType = 'success';
                        }

                    } else {
                        // Both timestamps already present
                        $message = 'You have already completed check-in and check-out for this event.';
                        $messageType = 'info';
                    }
                }
            } catch (PDOException $e) {
                $message = 'Database error: ' . $e->getMessage();
                $messageType = 'error';
            }
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
        <div class="active-event-banner <?= !empty($availableEvents) ? 'event-active' : 'no-event' ?>">
            <i class="fas <?= !empty($availableEvents) ? 'fa-broadcast-tower' : 'fa-exclamation-circle' ?>"></i>
            <span>
                <?php if (!empty($availableEvents)): ?>
                    <?php if (count($availableEvents) === 1): ?>
                        <?= htmlspecialchars($availableEvents[0]['event_name']) ?> (manual check-in active)
                    <?php else: ?>
                        <?= count($availableEvents) ?> events available for check-in
                    <?php endif; ?>
                <?php else: ?>
                    No events available for check-in
                <?php endif; ?>
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
        <?php if ($isAuthorizedAccess && !empty($availableEvents)): ?>
        <form method="POST" class="attendance-form">
            <?php if (count($availableEvents) > 1): ?>
            <div class="input-group">
                <label for="event_id">Select Event</label>
                <select id="event_id" name="event_id" required>
                    <option value="">Choose an event...</option>
                    <?php foreach ($availableEvents as $event): ?>
                    <option value="<?= $event['event_id'] ?>">
                        <?= htmlspecialchars($event['event_name']) ?> (<?= htmlspecialchars($event['event_type']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <input type="hidden" name="event_id" value="<?= $availableEvents[0]['event_id'] ?>">
            <?php endif; ?>
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
        <?php else: ?>
        <div class="attendance-note">
            <?php if (!$isAuthorizedAccess): ?>
            <p>This page is reserved for professor or admin access. Open it from the professor or admin dashboard.</p>
            <?php elseif (empty($availableEvents)): ?>
            <p>No events are currently available for check-in.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($dbError): ?>
        <div class="db-error"><i class="fas fa-database"></i> <?= htmlspecialchars($dbError) ?></div>
        <?php endif; ?>

        <!-- Show available event details -->
        <?php if (!empty($availableEvents)): ?>
        <div class="event-details">
            <h3>Available Events</h3>
            <?php foreach ($availableEvents as $event): ?>
            <div class="event-item">
                <h4><?= htmlspecialchars($event['event_name']) ?></h4>
                <p><i class="fas fa-tag"></i> <?= htmlspecialchars($event['event_type']) ?></p>
                <p><i class="fas fa-clock"></i>
                   <?= date('g:i A', strtotime($event['start_time'])) ?> –
                   <?= date('g:i A', strtotime($event['end_time'])) ?>
                </p>
                <?php if ($event['location']): ?>
                <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($event['location']) ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
