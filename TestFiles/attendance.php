<?php
/**
 * ATTENDANCE.PHP  –  Student Colloquium Attendance Form (Scanner Optimized)
 *
 * Single ID field with automatic check-in/check-out detection:
 *  Check-in window: 10 minutes BEFORE event → 5 minutes AFTER event starts
 *  Check-out window: When event ENDS → 10 minutes AFTER event ends
 *  
 *  Multiple scans of same student ignored (uses first check-in time)
 */

date_default_timezone_set('America/New_York');
require __DIR__ . '/../secrets/db.php';

$dbError     = null;
$message     = '';
$messageType = '';

// ── Authorization ────────────────────────────────────────
$profId  = isset($_GET['prof_id'])  ? (int)$_GET['prof_id']  : 0;
$adminId = isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : 0;
$eventIdFromUrl = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

$isProfessorAccess  = false;
$isAdminAccess      = false;
$professor          = null;
$admin              = null;
$selectedEvent      = null;

try {
    $pdo = getDB();

    if ($profId > 0) {
        $s = $pdo->prepare("SELECT professor_id, fname, lname FROM Professor WHERE professor_id = ? LIMIT 1");
        $s->execute([$profId]);
        $professor         = $s->fetch();
        $isProfessorAccess = (bool)$professor;
    }

    if ($adminId > 0) {
        $s = $pdo->prepare("SELECT user_id, fname, lname FROM AppUser WHERE user_id = ? AND role = 'admin' LIMIT 1");
        $s->execute([$adminId]);
        $admin         = $s->fetch();
        $isAdminAccess = (bool)$admin;
    }

    $isAuthorizedAccess = $isProfessorAccess || $isAdminAccess;

    // Get the event from URL parameter
    if ($eventIdFromUrl > 0) {
        $evStmt = $pdo->prepare(
            "SELECT event_id, event_name, event_type, start_time, end_time, location
             FROM Event
             WHERE event_id = ?
             LIMIT 1"
        );
        $evStmt->execute([$eventIdFromUrl]);
        $selectedEvent = $evStmt->fetch();
    }

} catch (PDOException $e) {
    $dbError = 'Database connection failed: ' . $e->getMessage();
}

$lastStudentId = '';

// ── Page-level window state (used by HTML renderer) ──────────────────────────
// Computed once here so both POST handler and HTML agree on the same $now.
$pageNow = date('Y-m-d H:i:s');
$pageIsExpired         = false;
$pageCheckoutWindowEnd = null;
$pageSecondsUntilExpiry = null;

if ($selectedEvent) {
    $pageCheckoutWindowEnd   = date('Y-m-d H:i:s', strtotime($selectedEvent['end_time'] . ' +10 minutes'));
    $pageIsExpired           = $pageNow > $pageCheckoutWindowEnd;
    $pageSecondsUntilExpiry  = max(0, strtotime($pageCheckoutWindowEnd) - strtotime($pageNow));
}

// ── Handle POST submission ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$dbError) {

    $rawStudentId = trim($_POST['student_id'] ?? '');

    $error = '';
    if ($rawStudentId === '') {
        $error = 'Please scan your Student ID.';
    } elseif (!$isAuthorizedAccess) {
        $error = 'This form is for authorized access only.';
    } elseif (!$selectedEvent) {
        $error = 'No event selected.';
    }

    if ($error) {
        $message     = $error;
        $messageType = 'error';
    } else {
        try {
            $pdo       = getDB();
            $now       = date('Y-m-d H:i:s');
            $eventId   = (int)$selectedEvent['event_id'];
            $eventStart = $selectedEvent['start_time'];
            $eventEnd  = $selectedEvent['end_time'];
            
            // Check-in window: 10 minutes BEFORE event → 5 minutes AFTER event starts
            $checkInWindowStart = date('Y-m-d H:i:s', strtotime($eventStart . ' -10 minutes'));
            $checkInWindowEnd = date('Y-m-d H:i:s', strtotime($eventStart . ' +5 minutes'));
            
            // Check-out window: When event ends → 10 minutes AFTER event ends
            $checkOutWindowStart = $eventEnd;
            $checkOutWindowEnd = date('Y-m-d H:i:s', strtotime($eventEnd . ' +10 minutes'));
            
            // Form expires after check-out window ends
            if ($now > $checkOutWindowEnd) {
                $message     = 'This form is no longer available (event has ended).';
                $messageType = 'error';
            } else {
                // Verify student exists
                $s = $pdo->prepare("SELECT student_id, fname, lname FROM Student WHERE student_id = ? LIMIT 1");
                $s->execute([$rawStudentId]);
                $studentRow = $s->fetch();

                if (!$studentRow) {
                    $message     = 'Student ID not found.';
                    $messageType = 'error';
                } else {
                    // Get ALL active enrolled courses for this student
                    $courseStmt = $pdo->prepare(
                        "SELECT e.course_id, c.minimum_events_required
                         FROM EnrollmentInCourses e
                         JOIN Course c ON e.course_id = c.course_id
                         WHERE e.student_id = ? AND e.status = 'active'"
                    );
                    $courseStmt->execute([$rawStudentId]);
                    $studentEnrolledCourses = $courseStmt->fetchAll();

                    // Deduplicate by course_id — a student enrolled in multiple sections
                    // of the same course would otherwise cause a duplicate primary key
                    // error in AttendsEventSessions on check-in and check-out.
                    $seen = [];
                    $studentEnrolledCourses = array_values(array_filter(
                        $studentEnrolledCourses,
                        function ($e) use (&$seen) {
                            if (isset($seen[$e['course_id']])) return false;
                            $seen[$e['course_id']] = true;
                            return true;
                        }
                    ));

                    if (empty($studentEnrolledCourses)) {
                        $message     = 'Student is not enrolled in any active courses.';
                        $messageType = 'error';
                    } else {
                        // Check if already checked in for this event
                        $checkStmt = $pdo->prepare(
                            "SELECT COUNT(*) FROM AttendsEventSessions 
                             WHERE student_id = ? AND event_id = ? AND start_scan_time IS NOT NULL"
                        );
                        $checkStmt->execute([$rawStudentId, $eventId]);
                        $alreadyCheckedIn = $checkStmt->fetchColumn() > 0;

                        // Check if already checked out
                        $checkoutStmt = $pdo->prepare(
                            "SELECT COUNT(*) FROM AttendsEventSessions 
                             WHERE student_id = ? AND event_id = ? AND end_scan_time IS NOT NULL"
                        );
                        $checkoutStmt->execute([$rawStudentId, $eventId]);
                        $alreadyCheckedOut = $checkoutStmt->fetchColumn() > 0;

                        // ── Late check-in window: event_start+5min → event_end ──────────────
                        // Students arriving after the on-time window get partial credit.
                        $lateCheckInWindowEnd = $eventEnd; // late window closes when event ends

                        // AUTO-DETECT BASED ON TIME
                        if ($alreadyCheckedOut) {
                            // Already completed
                            $message     = "✓ {$studentRow['fname']} – Already checked out";
                            $messageType = 'info';
                        } elseif ($now >= $checkOutWindowStart && $now <= $checkOutWindowEnd) {
                            // ──── AUTO CHECK-OUT ────
                            if (!$alreadyCheckedIn) {
                                $message     = "{$studentRow['fname']} – Not checked in";
                                $messageType = 'error';
                            } else {
                                // Query the ACTUAL pending check-in records as source of truth.
                                // Using AttendsEventSessions (not current enrollment) ensures we
                                // always update the rows that were created at check-in, even if
                                // enrollment changed between check-in and check-out.
                                $pendingStmt = $pdo->prepare(
                                    "SELECT a.course_id, a.audit_note, c.minimum_events_required
                                     FROM AttendsEventSessions a
                                     JOIN Course c ON a.course_id = c.course_id
                                     WHERE a.student_id = ? AND a.event_id = ?
                                       AND a.start_scan_time IS NOT NULL AND a.end_scan_time IS NULL"
                                );
                                $pendingStmt->execute([$rawStudentId, $eventId]);
                                $pendingCheckIns = $pendingStmt->fetchAll();

                                foreach ($pendingCheckIns as $pending) {
                                    $courseId     = $pending['course_id'];
                                    $minRequired  = (int)$pending['minimum_events_required'];
                                    // Use ?: so that both NULL and FALSE (from fetchAll) are treated as ''
                                    $existingNote = $pending['audit_note'] ?: '';
                                    $isLateArrival = strpos($existingNote, 'Late arrival') !== false;

                                    // Count previously completed events for this course
                                    $countStmt = $pdo->prepare(
                                        "SELECT COUNT(DISTINCT event_id)
                                         FROM AttendsEventSessions
                                         WHERE student_id = ? AND course_id = ? AND end_scan_time IS NOT NULL"
                                    );
                                    $countStmt->execute([$rawStudentId, $courseId]);
                                    $completedCount = (int)($countStmt->fetchColumn() ?: 0);

                                    if ($isLateArrival) {
                                        $checkoutNote = 'Late arrival - partial credit';
                                    } elseif ($completedCount >= $minRequired) {
                                        $checkoutNote = 'Extra credit beyond minimum';
                                    } else {
                                        $checkoutNote = 'Required attendance';
                                    }

                                    $pdo->prepare(
                                        "UPDATE AttendsEventSessions
                                         SET end_scan_time = ?, audit_note = ?
                                         WHERE student_id = ? AND event_id = ? AND course_id = ?"
                                    )->execute([$now, $checkoutNote, $rawStudentId, $eventId, $courseId]);
                                }
                                $message     = "✓ {$studentRow['fname']} – Checked out successfully";
                                $messageType = 'success';
                                $lastStudentId = '';
                            }
                        } elseif ($now >= $checkInWindowStart && $now <= $checkInWindowEnd) {
                            // ──── AUTO CHECK-IN (full credit) ────
                            if ($alreadyCheckedIn) {
                                $message     = "✓ {$studentRow['fname']} – Already checked in. Return to scan out when the event ends.";
                                $messageType = 'info';
                            } else {
                                foreach ($studentEnrolledCourses as $enrollment) {
                                    $courseId = $enrollment['course_id'];
                                    $pdo->prepare(
                                        "INSERT INTO AttendsEventSessions
                                             (student_id, event_id, course_id, start_scan_time, audit_note)
                                         VALUES (?, ?, ?, ?, ?)"
                                    )->execute([$rawStudentId, $eventId, $courseId, $now, 'Required attendance']);
                                }
                                $message     = "✓ {$studentRow['fname']} – Checked in successfully";
                                $messageType = 'success';
                                $lastStudentId = '';
                            }
                        } elseif ($now > $checkInWindowEnd && $now < $lateCheckInWindowEnd) {
                            // ──── LATE CHECK-IN (partial credit) ────
                            // Student arrived after the 5-min on-time window but before the event ends.
                            if ($alreadyCheckedIn) {
                                $message     = "✓ {$studentRow['fname']} – Already checked in. Return to scan out when the event ends.";
                                $messageType = 'info';
                            } else {
                                $minutesLate = (int)ceil((strtotime($now) - strtotime($checkInWindowEnd)) / 60);
                                foreach ($studentEnrolledCourses as $enrollment) {
                                    $courseId = $enrollment['course_id'];
                                    $pdo->prepare(
                                        "INSERT INTO AttendsEventSessions
                                             (student_id, event_id, course_id, start_scan_time, audit_note)
                                         VALUES (?, ?, ?, ?, ?)"
                                    )->execute([$rawStudentId, $eventId, $courseId, $now,
                                                'Late arrival - partial credit']);
                                }
                                $message     = "{$studentRow['fname']} – Checked in late. Partial credit only.";
                                $messageType = 'warning';
                                $lastStudentId = '';
                            }
                        } else {
                            // Outside all windows
                            if ($now < $checkInWindowStart) {
                                $minutesUntil = ceil((strtotime($checkInWindowStart) - strtotime($now)) / 60);
                                $message     = "Check-in opens in ~{$minutesUntil} minutes";
                                $messageType = 'warning';
                            } else {
                                $message     = "This form is no longer available (event has ended).";
                                $messageType = 'error';
                            }
                        }
                    }
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
    <title>Student Colloquium Attendance Form</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="attendance-page">

    <nav class="dashboard-nav">
        <div class="nav-brand">
            <img src="gburglogo.jpg" alt="Gettysburg College" style="height:32px;width:auto;margin-right:.5rem;">
            <span>Colloquium Attendance Form</span>
        </div>
    </nav>

    <main class="attendance-main">
        <section class="checkin-section">
            <div class="checkin-container">
                <div class="checkin-header">
                    <h1><i class="fas fa-calendar-check"></i> Student Colloquium Attendance Form</h1>
                </div>

                <?php if ($dbError): ?>
                <div class="toast error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($dbError) ?>
                </div>
                <?php elseif (!$selectedEvent): ?>
                <div class="no-data">
                    <i class="fas fa-calendar-times" style="font-size:3rem;color:#ddd;margin-bottom:1rem;"></i>
                    <p>No event selected.</p>
                    <p style="color:#999;font-size:0.9rem;">Ask your professor for the correct link.</p>
                </div>
                <?php else: ?>

                <div class="event-details">
                    <div class="event-title"><?= htmlspecialchars($selectedEvent['event_name']) ?></div>
                    <div class="event-meta">
                        <span><i class="fas fa-calendar"></i> <?= date('M j, Y', strtotime($selectedEvent['start_time'])) ?></span>
                        <span><i class="fas fa-clock"></i> <?= date('g:i A', strtotime($selectedEvent['start_time'])) ?> – <?= date('g:i A', strtotime($selectedEvent['end_time'])) ?></span>
                        <?php if ($selectedEvent['location']): ?>
                        <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($selectedEvent['location']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($message): ?>
                <div class="toast <?= $messageType ?>" style="margin-top:2rem;font-size:1.3rem;padding:1.5rem;">
                    <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : ($messageType === 'error' ? 'fa-times-circle' : 'fa-info-circle') ?>" style="font-size:2rem;"></i>
                    <div style="font-weight:600;">
                        <?= $message ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($pageIsExpired): ?>
                <!-- ── EXPIRED STATE ── -->
                <div class="toast error" style="margin-top:2rem;font-size:1.15rem;padding:1.5rem;" id="expiredBanner">
                    <i class="fas fa-lock" style="font-size:2rem;"></i>
                    <div>
                        <strong>Colloquium link is expired.</strong><br>
                        <span style="font-size:0.95rem;font-weight:400;">
                            If time ran out before you could scan out, go to your professor to let them know.
                        </span>
                    </div>
                </div>
                <?php else: ?>
                <!-- ── ACTIVE FORM ── -->
                <form method="POST" class="checkin-form" id="checkinForm">
                    <div class="form-group" style="margin-top:2rem;">
                        <label for="student_id" style="font-size:1.2rem;font-weight:600;"><i class="fas fa-id-card"></i> Student ID</label>
                        <input type="text" id="student_id" name="student_id"
                               placeholder="Scan or type Student ID..."
                               value="<?= htmlspecialchars($lastStudentId) ?>"
                               autocomplete="off"
                               autofocus
                               inputmode="numeric"
                               pattern="[0-9]*"
                               maxlength="7"
                               oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                               style="font-size:1.1rem;padding:1rem;margin-top:0.75rem;">
                    </div>

                    <button type="submit" class="btn-primary" style="width:100%;font-size:1.1rem;padding:0.9rem;margin-top:1.5rem;">
                        <i class="fas fa-arrow-right"></i> Submit
                    </button>
                </form>

                <div class="checkin-note" style="margin-top:2rem;font-size:0.9rem;">
                    <i class="fas fa-info-circle"></i>
                    <strong>Full credit check-in:</strong> 10 min before → 5 min after event starts<br>
                    <strong>Partial credit check-in:</strong> After 5 min grace period → until event ends<br>
                    <strong>Check-out:</strong> Event ends → 10 min after event ends
                </div>

                <?php if ($pageSecondsUntilExpiry !== null && $pageSecondsUntilExpiry > 0): ?>
                <script>
                    // Auto-expire the form when the checkout window closes
                    (function () {
                        var secondsLeft = <?= (int)$pageSecondsUntilExpiry ?>;
                        setTimeout(function () {
                            var form = document.getElementById('checkinForm');
                            var note = form ? form.nextElementSibling : null;
                            if (form) form.style.display = 'none';
                            if (note) note.style.display = 'none';

                            var banner = document.createElement('div');
                            banner.className = 'toast error';
                            banner.style.cssText = 'margin-top:2rem;font-size:1.15rem;padding:1.5rem;';
                            banner.innerHTML =
                                '<i class="fas fa-lock" style="font-size:2rem;flex-shrink:0;"></i>' +
                                '<div><strong>Colloquium link is expired.</strong><br>' +
                                '<span style="font-size:0.95rem;font-weight:400;">' +
                                'If time ran out before you could scan out, go to your professor to let them know.' +
                                '</span></div>';

                            var container = document.querySelector('.checkin-container');
                            if (container) container.appendChild(banner);
                        }, secondsLeft * 1000);
                    })();
                </script>
                <?php endif; ?>

                <?php endif; // end $pageIsExpired ?>

                <?php endif; ?>
            </div>
        </section>
    </main>

    <style>
        .attendance-page { background: #f5f5f5; }
        .attendance-main { padding: 2rem; max-width: 600px; margin: 0 auto; }
        .checkin-container { background: white; border-radius: 12px; padding: 2rem; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .checkin-header { text-align: center; margin-bottom: 2rem; }
        .checkin-header h1 { margin: 0; color: #333; font-size: 1.8rem; }
        .event-details { background: #f0f4ff; border-left: 4px solid #667eea; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; }
        .event-title { font-weight: 600; color: #333; font-size: 1.1rem; margin-bottom: 0.5rem; }
        .event-meta { display: flex; flex-direction: column; gap: 0.4rem; font-size: 0.9rem; color: #666; }
        .event-meta span { display: flex; align-items: center; gap: 0.5rem; }
        .checkin-form { margin: 1.5rem 0; }
        .checkin-note { text-align: center; color: #999; padding-top: 1.5rem; border-top: 1px solid #eee; }
        
        /* Toast/Alert Styling */
        .toast {
            padding: 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            font-weight: 500;
        }
        .toast.success {
            background: #e8f5e9;
            color: #1b5e20;
            border: 2px solid #4caf50;
        }
        .toast.error {
            background: #ffebee;
            color: #b71c1c;
            border: 2px solid #f44336;
        }
        .toast.info {
            background: #e3f2fd;
            color: #0d47a1;
            border: 2px solid #2196f3;
        }
        .toast.warning {
            background: #fff3e0;
            color: #e65100;
            border: 2px solid #ff9800;
        }
        .toast i {
            flex-shrink: 0;
            margin-top: 0.1rem;
        }
        .toast div {
            flex: 1;
        }
    </style>

</body>
</html>