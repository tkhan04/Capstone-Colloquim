<?php
// Set timezone to ET Daylight Time (EDT)
date_default_timezone_set('America/New_York');

/**
 * PROF_DASHBOARD.PHP - Professor Dashboard
 *
 * Shows: course cards → click a course → student attendance table with status badges.
 * Also: filter by event type, search by name/ID, CSV export, override attendance.
 *
 * DB schema used (exact):
 *   Professor(professor_id, fname, lname, email, permitted_event_types)
 *   CourseAssignment(assignment_id, course_id, professor_id)
 *   Course(course_id, course_name, section, year, semester, minimum_events_required)
 *   EnrollmentInCourses(enrollment_id, student_id, course_id, status)
 *   Student(student_id, fname, lname, email, year)
 *   AttendsEventSessions(student_id PK, event_id PK, start_scan_time, end_scan_time,
 *                        minutes_present, audit_note, overridden_by)
 *   Event(event_id, event_name, event_type, start_time, end_time, location)
 */

//Test comment

session_start();
require __DIR__ . '/../secrets/db.php';

$profId          = (int)($_GET['prof_id']   ?? 1);
$selectedCourseId = trim($_GET['course_id'] ?? '');
$filterType      = trim($_GET['event_type'] ?? '');
$search          = trim($_GET['search']     ?? '');
$dbError         = '';

// ── Flash message handling ───────────────────────────────────────────────────
$message     = '';
$messageType = 'success';
if (!empty($_SESSION['flash_message'])) {
    $message     = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// ── CSV export flag ───────────────────────────────────────────────────────────
$doExport = isset($_GET['export']) && $_GET['export'] === '1' && $selectedCourseId;

// ── Handle attendance override POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'override_attendance') {
    try {
        $pdo       = getDB();
        $stuId     = (int)$_POST['student_id'];
        $evId      = (int)$_POST['event_id'];
        $startTime = $_POST['start_scan_time'] ?? null;
        $endTime   = $_POST['end_scan_time']   ?? null;
        $note      = trim($_POST['audit_note'] ?? '');

        // Upsert: update if exists, insert if not
        $exists = $pdo->prepare(
            "SELECT 1 FROM AttendsEventSessions WHERE student_id=? AND event_id=?"
        );
        $exists->execute([$stuId, $evId]);

        if ($exists->fetch()) {
            $pdo->prepare(
                "UPDATE AttendsEventSessions
                 SET start_scan_time=?, end_scan_time=?, audit_note=?, overridden_by=?
                 WHERE student_id=? AND event_id=?"
            )->execute([$startTime ?: null, $endTime ?: null, $note, $profId, $stuId, $evId]);
        } else {
            $pdo->prepare(
                "INSERT INTO AttendsEventSessions (student_id, event_id, start_scan_time, end_scan_time, audit_note, overridden_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$stuId, $evId, $startTime ?: null, $endTime ?: null, $note, $profId]);
        }
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}

// ── CREATE EVENT ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_event') {
    try {
        $pdo = getDB();
        $name      = trim($_POST['event_name'] ?? '');
        $type      = trim($_POST['event_type']  ?? 'Colloquium');
        $startTime = $_POST['start_time'] ?? '';
        $endTime   = $_POST['end_time']   ?? '';
        $location  = trim($_POST['location'] ?? '');

        if (!$name || !$startTime || !$endTime) {
            $dbError = 'Event name, start time, and end time are required.';
        } elseif ($endTime <= $startTime) {
            $dbError = 'End time must be after start time.';
        } else {
            // created_by stores the professor's user_id
            $pdo->prepare(
                "INSERT INTO Event (event_name, event_type, start_time, end_time, location, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$name, $type, $startTime, $endTime, $location, $profId]);
            // Redirect to refresh the page
            header("Location: ?prof_id={$profId}");
            exit;
        }
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}

// ── HACKATHON MASS CHECK-IN ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'hackathon_mass_checkin') {
    try {
        $pdo = getDB();
        $eventId = (int)$_POST['event_id'];
        $courseId = trim($_POST['course_id'] ?? '');
        $startTime = $_POST['checkin_time'] ?? date('Y-m-d H:i:s');
        $note = trim($_POST['audit_note'] ?? 'Hackathon check-in by professor');

        if (!$courseId) {
            $dbError = 'Course must be selected for hackathon check-in.';
        } else {
            // Verify the event is a hackathon and get its end time
            $eventCheck = $pdo->prepare("SELECT event_type, end_time FROM Event WHERE event_id = ?");
            $eventCheck->execute([$eventId]);
            $event = $eventCheck->fetch();

            if (!$event || strtolower($event['event_type']) !== 'hackathon') {
                $dbError = 'Selected event is not a hackathon.';
            } else {
                $eventEndTime = $event['end_time'];
                $studentIds = $_POST['student_ids'] ?? [];
                if (!is_array($studentIds)) {
                    $studentIds = [];
                }
                $studentIds = array_values(array_filter($studentIds, fn($id) => ctype_digit((string)$id)));

                if (empty($studentIds)) {
                    $dbError = 'You must select at least one student for hackathon check-in.';
                } else {
                    $selectedTime = str_replace('T', ' ', trim($startTime));
                    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $selectedTime)) {
                        $selectedTime .= ':00';
                    }

                    $parsedTime = date_create_from_format('Y-m-d H:i:s', $selectedTime);
                    if (!$parsedTime) {
                        $parsedTime = new DateTime();
                        $selectedTime = $parsedTime->format('Y-m-d H:i:s');
                    }

                    if ($selectedTime > $eventEndTime) {
                        $dbError = 'Check-in time must be before the hackathon end time.';
                    } else {
                        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
                        $params = array_merge([$courseId], $studentIds);

                        $stmt = $pdo->prepare(
                            "SELECT s.student_id
                             FROM Student s
                             JOIN EnrollmentInCourses e ON s.student_id = e.student_id
                             WHERE e.course_id = ? AND e.status = 'active'
                             AND s.student_id IN ($placeholders)"
                        );
                        $stmt->execute($params);
                        $students = $stmt->fetchAll(PDO::FETCH_COLUMN);

                        if (empty($students)) {
                            $dbError = 'No selected students found for this course.';
                        } elseif (count($students) !== count($studentIds)) {
                            $dbError = 'Some selected students are not active in this course.';
                        } else {
                            $insertStmt = $pdo->prepare(
                                "INSERT INTO AttendsEventSessions (student_id, event_id, start_scan_time, end_scan_time, audit_note, overridden_by)
                                 VALUES (?, ?, ?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE
                                 start_scan_time = VALUES(start_scan_time),
                                 end_scan_time = VALUES(end_scan_time),
                                 audit_note = VALUES(audit_note),
                                 overridden_by = VALUES(overridden_by)"
                            );

                            $count = 0;
                            foreach ($students as $studentId) {
                                $insertStmt->execute([$studentId, $eventId, $selectedTime, $eventEndTime, $note, $profId]);
                                $count++;
                            }

                            $_SESSION['flash_message'] = "Hackathon check-in completed for {$count} students.";
                            $_SESSION['flash_type'] = 'success';
                            header("Location: ?prof_id={$profId}");
                            exit;
                        }
                    }
                }
            }
        }
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}

// ── DELETE EVENT ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_event') {
    try {
        $pdo = getDB();
        $eventId = (int)$_POST['event_id'];

        // Verify the event was created by this professor
        $checkStmt = $pdo->prepare("SELECT event_name FROM Event WHERE event_id = ? AND created_by = ?");
        $checkStmt->execute([$eventId, $profId]);
        $event = $checkStmt->fetch();

        if (!$event) {
            $dbError = 'Event not found or you do not have permission to delete it.';
        } else {
            // Delete the event (cascade will handle attendance records)
            $deleteStmt = $pdo->prepare("DELETE FROM Event WHERE event_id = ?");
            $deleteStmt->execute([$eventId]);

            $_SESSION['flash_message'] = 'Event "' . $event['event_name'] . '" deleted successfully.';
            $_SESSION['flash_type'] = 'success';
            header("Location: ?prof_id={$profId}");
            exit;
        }
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}

// ── AJAX: GET COURSE STUDENTS ────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_course_students') {
    try {
        $pdo = getDB();
        $courseId = trim($_GET['course_id'] ?? '');
        $eventId = (int)($_GET['event_id'] ?? 0);

        if (!$courseId) {
            echo json_encode(['error' => 'Course ID required']);
            exit;
        }

        // Get students enrolled in the course
        $stmt = $pdo->prepare(
            "SELECT s.student_id, s.fname, s.lname
             FROM EnrollmentInCourses e
             JOIN Student s ON e.student_id = s.student_id
             WHERE e.course_id = ? AND e.status = 'active'
             ORDER BY s.lname, s.fname"
        );
        $stmt->execute([$courseId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['students' => $students]);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

try {
    $pdo = getDB();

    // ── Professor info ────────────────────────────────────────────────────────
    $stmt = $pdo->prepare(
        "SELECT professor_id, fname, lname, email, permitted_event_types
         FROM Professor WHERE professor_id = ?"
    );
    $stmt->execute([$profId]);
    $professor = $stmt->fetch();

    // ── Courses this professor teaches ────────────────────────────────────────
    $stmt = $pdo->prepare(
        "SELECT c.course_id, c.course_name, c.section, c.semester, c.year,
                c.minimum_events_required,
                (SELECT COUNT(*) FROM EnrollmentInCourses e
                 WHERE e.course_id = c.course_id AND e.status = 'active') AS student_count
         FROM Course c
         JOIN CourseAssignment ca ON c.course_id = ca.course_id
         WHERE ca.professor_id = ?
         ORDER BY c.year DESC, c.semester, c.course_id"
    );
    $stmt->execute([$profId]);
    $courses = $stmt->fetchAll();

    // ── Total event count ─────────────────────────────────────────────────────
    $totalEvents = (int)$pdo->query("SELECT COUNT(*) FROM Event")->fetchColumn();

    // ── Total students across all courses ─────────────────────────────────────
    $totalStudents = array_sum(array_column($courses, 'student_count'));

    // ── Distinct event types (for filter dropdown) ────────────────────────────
    $eventTypes = $pdo->query(
        "SELECT DISTINCT event_type FROM Event ORDER BY event_type"
    )->fetchAll(PDO::FETCH_COLUMN);

    // ── All events (used in override modal dropdown) ──────────────────────────
    $allEvents = $pdo->query(
        "SELECT event_id, event_name, event_type, start_time FROM Event ORDER BY start_time DESC"
    )->fetchAll();

    // ── Active manual attendance event for professor dashboard access ──────────
    $now = date('Y-m-d H:i:s');
    $activeManualEventStmt = $pdo->prepare(
        "SELECT event_id, event_name, event_type, start_time, end_time, location
         FROM Event
         WHERE start_time <= ? AND end_time >= ? AND LOWER(event_type) != 'hackathon'
         ORDER BY start_time DESC
         LIMIT 1"
    );
    $activeManualEventStmt->execute([$now, $now]);
    $activeManualEvent = $activeManualEventStmt->fetch();

    // ── Selected course data ──────────────────────────────────────────────────
    $selectedCourse  = null;
    $courseStudents  = [];

    if ($selectedCourseId) {
        // Find selected course in already-fetched list
        foreach ($courses as $c) {
            if ($c['course_id'] === $selectedCourseId) {
                $selectedCourse = $c;
                break;
            }
        }

        if ($selectedCourse) {
            // Events that count for this course (filtered by type if professor has restrictions)
            $permittedTypes = $filterType
                ? [$filterType]
                : ($professor && $professor['permitted_event_types']
                    ? array_map('trim', explode(',', $professor['permitted_event_types']))
                    : null);

            // Build event query with optional type filter
            if ($permittedTypes) {
                $placeholders = implode(',', array_fill(0, count($permittedTypes), '?'));
                $evStmt = $pdo->prepare(
                    "SELECT event_id FROM Event WHERE event_type IN ($placeholders)"
                );
                $evStmt->execute($permittedTypes);
            } else {
                $evStmt = $pdo->query("SELECT event_id FROM Event");
            }
            $countableEventIds = $evStmt->fetchAll(PDO::FETCH_COLUMN);
            $countableCount    = count($countableEventIds);

            // Build students query with optional name/ID search
            $searchParam = $search ? "%{$search}%" : null;
            $params      = [$selectedCourseId];
            $searchSql   = '';
            if ($searchParam) {
                $searchSql = "AND (s.fname LIKE ? OR s.lname LIKE ? OR CONCAT(s.fname,' ',s.lname) LIKE ? OR s.student_id LIKE ?)";
                array_push($params, $searchParam, $searchParam, $searchParam, $searchParam);
            }

            $stmt = $pdo->prepare(
                "SELECT s.student_id, s.fname, s.lname, s.email, s.year,
                        e.enrollment_id, e.status
                 FROM EnrollmentInCourses e
                 JOIN Student s ON e.student_id = s.student_id
                 WHERE e.course_id = ? $searchSql
                 ORDER BY s.lname, s.fname"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            // For each student, count completed attendance sessions for countable events
            foreach ($rows as $stu) {
                $attended = 0;
                if ($filterType) {
                    $aStmt = $pdo->prepare(
                        "SELECT COUNT(DISTINCT a.event_id) FROM AttendsEventSessions a JOIN Event ev ON a.event_id = ev.event_id WHERE a.student_id = ? AND a.course_id = ? AND ev.event_type = ? AND a.end_scan_time IS NOT NULL AND a.start_scan_time <= DATE_ADD(ev.start_time, INTERVAL 10 MINUTE) AND a.end_scan_time >= ev.end_time"
                    );
                    $aStmt->execute([$stu['student_id'], $selectedCourseId, $filterType]);
                    $attended = (int)$aStmt->fetchColumn();
                } else {
                    $aStmt = $pdo->prepare(
                        "SELECT COUNT(DISTINCT a.event_id) FROM AttendsEventSessions a JOIN Event ev ON a.event_id = ev.event_id WHERE a.student_id = ? AND a.course_id = ? AND a.end_scan_time IS NOT NULL AND a.start_scan_time <= DATE_ADD(ev.start_time, INTERVAL 10 MINUTE) AND a.end_scan_time >= ev.end_time"
                    );
                    $aStmt->execute([$stu['student_id'], $selectedCourseId]);
                    $attended = (int)$aStmt->fetchColumn();
                }

                $min    = (int)$selectedCourse['minimum_events_required'];
                $pct    = $min > 0 ? round($attended / $min * 100) : 0;
                $status = $attended >= $min && $min > 0 ? 'Excellent'
                        : ($pct >= 50 ? 'Fair' : 'Poor');

                $courseStudents[] = $stu + [
                    'events_attended' => $attended,
                    'events_total'    => $min,  // Changed to minimum required
                    'pct'             => $pct,
                    'meets'           => $attended >= $min,
                    'status_label'    => $min === 0 ? '—' : $status,
                ];
            }
        }
    }

} catch (PDOException $e) {
    $dbError = 'Database error: ' . $e->getMessage();
}

// ── CSV export (sends file download, exits) ───────────────────────────────────
if ($doExport && !$dbError && $selectedCourse) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $selectedCourseId . '_attendance.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID','First Name','Last Name','Email','Year','Events Attended','Minimum Required','% Rate','Meets Requirement','Status']);
    foreach ($courseStudents as $s) {
        fputcsv($out, [
            $s['student_id'], $s['fname'], $s['lname'], $s['email'], $s['year'],
            $s['events_attended'], $s['events_total'], $s['pct'] . '%',
            $s['meets'] ? 'Yes' : 'No', $s['status_label'],
        ]);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professor Dashboard - Colloquium</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Attendance rate colour badges matching the SRS mockup */
        .badge-excellent { background:#e8f5e9; color:#2e7d32; }
        .badge-fair      { background:#fff8e1; color:#f9a825; }
        .badge-poor      { background:#ffebee; color:#c62828; }
        .pct-bar { height:6px; background:#eee; border-radius:3px; margin-top:4px; }
        .pct-bar-inner { height:6px; border-radius:3px; background:#003366; }
        .filter-row { display:flex; gap:.75rem; align-items:center; flex-wrap:wrap; margin-bottom:1.5rem; }
        .filter-row select, .filter-row input {
            padding:.6rem .9rem; border:1px solid #ccc; border-radius:8px; font-size:.9rem; }
        .filter-row .btn-small { padding:.6rem 1rem; font-size:.9rem; }
    </style>
</head>
<body class="dashboard-page">

    <nav class="dashboard-nav">
        <div class="nav-brand">
            <img src="gburglogo.jpg" alt="Gettysburg College" style="height:32px;width:auto;margin-right:.5rem;">
            <span>Colloquium</span>
        </div>
        <div class="nav-user">
            <?php if ($professor): ?>
            <span><?= htmlspecialchars($professor['fname'] . ' ' . $professor['lname']) ?></span>
            <?php endif; ?>
            <a href="index.html" class="btn-logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </nav>

    <main class="dashboard-main">
        <header class="dashboard-header">
            <h1><i class="fas fa-chalkboard-teacher"></i> Professor Dashboard</h1>
            <p class="subtitle">Select a class to view student attendance</p>
            <div class="dashboard-action-row" style="margin-top:1rem; display:flex; gap:1rem; align-items:center; flex-wrap:wrap;">
                <a href="attendance.php?prof_id=<?= $profId ?>" class="btn-primary" target="_blank">
                    <i class="fas fa-id-card"></i> Open Student Check-In Form
                </a>
                <button class="btn-secondary" onclick="openHackathonCheckinModal()">
                    <i class="fas fa-users"></i> Hackathon Check-in
                </button>
                <span style="color:#555; font-size:.95rem;">Open on department tablet for student check-in/out during events.</span>
            </div>
        </header>

        <?php if ($dbError): ?>
        <div class="db-error"><i class="fas fa-database"></i> <?= htmlspecialchars($dbError) ?></div>
        <?php else: ?>

        <?php if ($message): ?>
        <div class="toast <?= $messageType ?>">
            <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- Stats row -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-book"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Total Classes</span>
                    <span class="stat-value"><?= count($courses) ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Total Students</span>
                    <span class="stat-value"><?= $totalStudents ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-calendar-alt"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Total Events</span>
                    <span class="stat-value"><?= $totalEvents ?></span>
                </div>
            </div>
        </div>

        <!-- Event Management Section -->
        <section class="events-section">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <h2>Event Management</h2>
                <button class="btn-primary" onclick="openModal('createEventModal')">
                    <i class="fas fa-plus"></i> Create Event
                </button>
            </div>
            <p class="section-subtitle">Create and manage colloquium events</p>
            
            <!-- Recent Events Table -->
            <div class="events-table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Event Name</th>
                            <th>Type</th>
                            <th>Date & Time</th>
                            <th>Location</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Get recent events created by this professor or all events if admin permissions
                        $stmt = $pdo->prepare(
                            "SELECT event_id, event_name, event_type, start_time, end_time, location, created_by
                             FROM Event
                             WHERE created_by = ? OR ? = 1
                             ORDER BY start_time DESC
                             LIMIT 10"
                        );
                        $stmt->execute([$profId, 1]); // 1 for potential admin check, but for now just professor's events
                        $recentEvents = $stmt->fetchAll();
                        
                        if (empty($recentEvents)): ?>
                        <tr>
                            <td colspan="5" class="no-data">
                                <i class="fas fa-calendar-times"></i> No events created yet. Click "Create Event" to get started.
                            </td>
                        </tr>
                        <?php else: 
                            foreach ($recentEvents as $event): ?>
                        <tr>
                            <td><?= htmlspecialchars($event['event_name']) ?></td>
                            <td>
                                <span class="badge"><?= htmlspecialchars($event['event_type']) ?></span>
                            </td>
                            <td>
                                <?= date('M j, Y', strtotime($event['start_time'])) ?><br>
                                <small><?= date('g:i A', strtotime($event['start_time'])) ?> - <?= date('g:i A', strtotime($event['end_time'])) ?></small>
                            </td>
                            <td><?= htmlspecialchars($event['location']) ?></td>
                            <td>
                                <em>Created by you</em>
                                <div style="margin-top: 5px;">
                                    <button class="btn-small btn-danger" onclick="deleteEvent(<?= $event['event_id'] ?>, '<?= htmlspecialchars(addslashes($event['event_name'])) ?>')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; 
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Course cards -->
        <section class="courses-section">
            <h2>Your Classes</h2>
            <div class="course-grid">
                <?php foreach ($courses as $course): ?>
                <a href="?prof_id=<?= $profId ?>&course_id=<?= urlencode($course['course_id']) ?>"
                   class="course-card <?= $selectedCourseId === $course['course_id'] ? 'selected' : '' ?>">
                    <div class="course-header">
                        <span class="course-code"><?= htmlspecialchars($course['course_id']) ?></span>
                        <span class="course-semester"><?= htmlspecialchars($course['semester'] . ' ' . $course['year']) ?></span>
                    </div>
                    <h3 class="course-name"><?= htmlspecialchars($course['course_name']) ?></h3>
                    <div class="course-stats">
                        <span><i class="fas fa-user-graduate"></i> Students <?= $course['student_count'] ?></span>
                        <span><i class="fas fa-calendar-check"></i> Min Required: <?= $course['minimum_events_required'] ?></span>
                    </div>
                </a>
                <?php endforeach; ?>

                <?php if (empty($courses)): ?>
                <p class="no-data">No courses assigned to you yet.</p>
                <?php endif; ?>
            </div>
        </section>

        <!-- ── Selected course: student attendance table ── -->
        <?php if ($selectedCourse): ?>
        <section class="students-section">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-bottom:1rem;">
                <div>
                    <h2>
                        <a href="?prof_id=<?= $profId ?>" style="color:#888;font-size:.85rem;text-decoration:none;">
                            ← Back to Classes
                        </a><br>
                        <?= htmlspecialchars($selectedCourse['course_id']) ?> –
                        <?= htmlspecialchars($selectedCourse['course_name']) ?>
                    </h2>
                    <p class="requirement-note">
                        <i class="fas fa-info-circle"></i>
                        Minimum required: <?= (int)$selectedCourse['minimum_events_required'] ?> event(s)
                        &nbsp;|&nbsp; Section <?= htmlspecialchars($selectedCourse['section']) ?>
                        &nbsp;|&nbsp; <?= htmlspecialchars($selectedCourse['semester'] . ' ' . $selectedCourse['year']) ?>
                    </p>
                </div>
                <!-- Export CSV button -->
                <a href="?prof_id=<?= $profId ?>&course_id=<?= urlencode($selectedCourseId) ?>&export=1&event_type=<?= urlencode($filterType) ?>&search=<?= urlencode($search) ?>"
                   class="btn-small">
                    <i class="fas fa-download"></i> Export CSV
                </a>
            </div>

            <!-- Filters: event type + search -->
            <form method="GET" class="filter-row">
                <input type="hidden" name="prof_id"   value="<?= $profId ?>">
                <input type="hidden" name="course_id" value="<?= htmlspecialchars($selectedCourseId) ?>">

                <select name="event_type">
                    <option value="">All Event Types</option>
                    <?php foreach ($eventTypes as $et): ?>
                    <option value="<?= htmlspecialchars($et) ?>" <?= $filterType === $et ? 'selected' : '' ?>>
                        <?= htmlspecialchars($et) ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Search by name or student ID">

                <button type="submit" class="btn-small"><i class="fas fa-filter"></i> Filter</button>
                <?php if ($filterType || $search): ?>
                <a href="?prof_id=<?= $profId ?>&course_id=<?= urlencode($selectedCourseId) ?>" class="btn-small">
                    <i class="fas fa-times"></i> Clear
                </a>
                <?php endif; ?>
            </form>

            <!-- Student attendance table matching SRS mockup -->
            <?php if (empty($courseStudents)): ?>
            <p class="no-data"><i class="fas fa-user-slash"></i> No students found.</p>
            <?php else: ?>
            <div class="table-container">
                <table class="students-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Email</th>
                            <th>Events Attended</th>
                            <th>Attendance Rate</th>
                            <th>Status</th>
                            <th>Override</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courseStudents as $stu): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($stu['fname'] . ' ' . $stu['lname']) ?>
                                <small style="color:#888;display:block;">ID: <?= $stu['student_id'] ?></small>
                            </td>
                            <td><?= htmlspecialchars($stu['email']) ?></td>
                            <td>
                                <span class="attendance-count"><?= $stu['events_attended'] ?></span>
                                / <?= $stu['events_total'] ?>
                            </td>
                            <td>
                                <strong><?= $stu['pct'] ?>%</strong>
                                <div class="pct-bar">
                                    <div class="pct-bar-inner" style="width:<?= min($stu['pct'],100) ?>%"></div>
                                </div>
                            </td>
                            <td>
                                <?php
                                $label = $stu['status_label'];
                                $cls   = $label === 'Excellent' ? 'badge-excellent'
                                       : ($label === 'Fair'      ? 'badge-fair' : 'badge-poor');
                                ?>
                                <span class="status-badge <?= $cls ?>"><?= $label ?></span>
                            </td>
                            <td>
                                <!-- Override button opens modal for audited correction -->
                                <button class="btn-small"
                                        onclick="openOverride(<?= $stu['student_id'] ?>, '<?= htmlspecialchars(addslashes($stu['fname'].' '.$stu['lname'])) ?>')">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php endif; // end !$dbError ?>
    </main>

    <!-- ── Override Attendance Modal ── -->
    <div id="overrideModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Override Attendance – <span id="overrideStudentName"></span></h3>
                <button class="modal-close" onclick="closeModal('overrideModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action"     value="override_attendance">
                <input type="hidden" name="student_id" id="overrideStudentId">
                <!-- Keep course_id and prof_id in URL so page reloads correctly -->
                <input type="hidden" name="prof_id"    value="<?= $profId ?>">
                <input type="hidden" name="course_id"  value="<?= htmlspecialchars($selectedCourseId) ?>">

                <div class="form-group">
                    <label>Event</label>
                    <select name="event_id" required>
                        <option value="">-- Select Event --</option>
                        <?php foreach ($allEvents ?? [] as $ev): ?>
                        <option value="<?= $ev['event_id'] ?>">
                            <?= htmlspecialchars($ev['event_name'] . ' (' . date('M j Y', strtotime($ev['start_time'])) . ')') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Sign-In Time</label>
                        <input type="datetime-local" name="start_scan_time">
                    </div>
                    <div class="form-group">
                        <label>Sign-Out Time</label>
                        <input type="datetime-local" name="end_scan_time">
                    </div>
                </div>

                <div class="form-group">
                    <label>Audit Note (required)</label>
                    <input type="text" name="audit_note" required placeholder="Reason for manual override">
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('overrideModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Save Override</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Create Event Modal ── -->
    <div id="createEventModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New Event</h3>
                <button class="modal-close" onclick="closeModal('createEventModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_event">
                <input type="hidden" name="prof_id" value="<?= $profId ?>">

                <div class="form-group">
                    <label>Event Name *</label>
                    <input type="text" name="event_name" required placeholder="e.g., Spring Colloquium 2024">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Event Type</label>
                        <select name="event_type">
                            <option value="Colloquium">Colloquium</option>
                            <option value="Workshop">Workshop</option>
                            <option value="Seminar">Seminar</option>
                            <option value="Hackathon">Hackathon</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Location</label>
                        <input type="text" name="location" placeholder="e.g., Science Center 101">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Start Time *</label>
                        <input type="datetime-local" name="start_time" required>
                    </div>
                    <div class="form-group">
                        <label>End Time *</label>
                        <input type="datetime-local" name="end_time" required>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('createEventModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Create Event</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openModal(id)  { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }
    window.onclick = e => { if (e.target.classList.contains('modal')) e.target.classList.remove('active'); };

    // Pre-populate the override modal with the selected student
    function openOverride(studentId, studentName) {
        document.getElementById('overrideStudentId').value   = studentId;
        document.getElementById('overrideStudentName').textContent = studentName;
        openModal('overrideModal');
    }

    // Hackathon student selection support
    function openHackathonCheckinModal() {
        openModal('hackathonCheckinModal');
    }

    function updateHackathonStudentList() {
        const courseSelect = document.querySelector('#hackathonCheckinModal select[name="course_id"]');
        const studentGroup = document.getElementById('hackathonStudentSelection');
        const studentList = document.getElementById('hackathonStudentList');
        const courseId = courseSelect.value;

        studentList.innerHTML = '';
        if (!courseId) {
            studentGroup.style.display = 'none';
            return;
        }

        fetch(`?action=get_course_students&course_id=${encodeURIComponent(courseId)}`)
            .then(response => response.json())
            .then(data => {
                if (!data.students || !data.students.length) {
                    studentList.innerHTML = '<p class="form-note">No active students found for this course.</p>';
                    studentGroup.style.display = 'block';
                    return;
                }

                studentGroup.style.display = 'block';
                data.students.forEach(student => {
                    const row = document.createElement('div');
                    row.className = 'checkbox-row';
                    row.innerHTML = `
                        <label>
                            <input type="checkbox" name="student_ids[]" value="${student.student_id}">
                            ${student.fname} ${student.lname} (${student.student_id})
                        </label>
                    `;
                    studentList.appendChild(row);
                });
            })
            .catch(() => {
                studentList.innerHTML = '<p class="form-note">Unable to load students. Refresh and try again.</p>';
                studentGroup.style.display = 'block';
            });
    }

    // Prevent submitting without selected students
    document.addEventListener('DOMContentLoaded', () => {
        const hackathonForm = document.querySelector('#hackathonCheckinModal form');
        const courseSelect = document.querySelector('#hackathonCheckinModal select[name="course_id"]');
        if (courseSelect) {
            courseSelect.addEventListener('change', updateHackathonStudentList);
        }
        if (hackathonForm) {
            hackathonForm.addEventListener('submit', function (event) {
                const checked = hackathonForm.querySelectorAll('input[name="student_ids[]"]:checked');
                if (!checked.length) {
                    event.preventDefault();
                    alert('Please select at least one student attending the hackathon.');
                }
            });
        }
    });

    // Delete event function
    function deleteEvent(eventId, eventName) {
        if (confirm(`Are you sure you want to delete the event "${eventName}"? This will also remove all associated attendance records and cannot be undone.`)) {
            // Create a form and submit it
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';

            const actionInput = document.createElement('input');
            actionInput.name = 'action';
            actionInput.value = 'delete_event';
            form.appendChild(actionInput);

            const eventInput = document.createElement('input');
            eventInput.name = 'event_id';
            eventInput.value = eventId;
            form.appendChild(eventInput);

            document.body.appendChild(form);
            form.submit();
        }
    }
    </script>

    <!-- ── Hackathon Check-in Modal ── -->
    <div id="hackathonCheckinModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3>Hackathon Check-in</h3>
                <button class="modal-close" onclick="closeModal('hackathonCheckinModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="hackathon_mass_checkin">

                <div class="form-group">
                    <label>Hackathon Event *</label>
                    <select name="event_id" required>
                        <option value="">-- Select Hackathon --</option>
                        <?php
                        // Get all hackathon events
                        $hackathonStmt = $pdo->query(
                            "SELECT event_id, event_name, start_time, end_time
                             FROM Event
                             WHERE LOWER(event_type) = 'hackathon'
                             ORDER BY start_time DESC"
                        );
                        $hackathons = $hackathonStmt->fetchAll();
                        foreach ($hackathons as $hack): ?>
                        <option value="<?= $hack['event_id'] ?>">
                            <?= htmlspecialchars($hack['event_name']) ?> (<?= date('M j, Y g:i A', strtotime($hack['start_time'])) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Course *</label>
                    <select name="course_id" required>
                        <option value="">-- Select Course --</option>
                        <?php foreach ($courses as $c): ?>
                        <option value="<?= htmlspecialchars($c['course_id']) ?>">
                            <?= htmlspecialchars($c['course_id'] . ' - ' . $c['course_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small>Select only the students who actually attended the hackathon.</small>
                </div>

                <div id="hackathonStudentSelection" class="form-group" style="display:none;">
                    <label>Students Attending *</label>
                    <div id="hackathonStudentList" class="student-checkbox-list"></div>
                    <small>Select the students who attended this hackathon.</small>
                </div>

                <div class="form-group">
                    <label>Check-in Time</label>
                    <input type="datetime-local" name="checkin_time" value="<?= date('Y-m-d\TH:i') ?>">
                    <small>Leave blank to use current time.</small>
                </div>

                <div class="form-group">
                    <label>Audit Note</label>
                    <input type="text" name="audit_note" value="Hackathon check-in by professor" placeholder="Reason for check-in">
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('hackathonCheckinModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Check In Selected Students</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>