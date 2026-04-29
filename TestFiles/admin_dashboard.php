<?php
/**
 * ADMIN_DASHBOARD.PHP - Administrator Dashboard
 *
 * Three tabs: Events | Rosters | Courses
 *
 * DB tables used (exact schema provided):
 *   AppUser(user_id, fname, lname, email, role, password_hash, is_active)
 *   Event(event_id, event_name, event_type, start_time, end_time, location, created_by)
 *   Course(course_id, course_name, section, year, semester, minimum_events_required)
 *   CourseAssignment(assignment_id, course_id, professor_id)
 *   Professor(professor_id FK, fname, lname, email, permitted_event_types)
 *   Student(student_id FK, fname, lname, email, year)
 *   EnrollmentInCourses(enrollment_id, student_id, course_id, status)
 */

session_start();

require __DIR__ . '/../secrets/db.php';
require __DIR__ . '/upload_roster.php';
$adminId         = (int)($_GET['admin_id'] ?? 1);
$activeTab       = $_GET['tab']       ?? 'events';
$selectedCourseId = trim($_GET['course_id'] ?? ''); // VARCHAR(20) - do not cast to int
$message         = '';
$messageType     = '';
$dbError         = '';

// Read flash message set by PRG redirect (upload, add, remove actions)
if (!empty($_SESSION['flash_message'])) {
    $message     = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

try {
    $pdo = getDB();

    // ── POST ACTIONS ────────────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];

        // ── CREATE EVENT ────────────────────────────────────────────────────────
        if ($action === 'create_event') {
            $name      = trim($_POST['event_name'] ?? '');
            $type      = trim($_POST['event_type']  ?? 'Colloquium');
            $startTime = $_POST['start_time'] ?? '';
            $endTime   = $_POST['end_time']   ?? '';
            $location  = trim($_POST['location'] ?? '');

            if (!$name || !$startTime || !$endTime) {
                $message = 'Event name, start time, and end time are required.';
                $messageType = 'error';
            } elseif ($endTime <= $startTime) {
                $message = 'End time must be after start time.';
                $messageType = 'error';
            } else {
                // created_by stores the admin's user_id
                $pdo->prepare(
                    "INSERT INTO Event (event_name, event_type, start_time, end_time, location, created_by)
                     VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([$name, $type, $startTime, $endTime, $location, $adminId]);
                $_SESSION['flash_message'] = 'Event created successfully!';
                $_SESSION['flash_type']    = 'success';
                header("Location: ?admin_id={$adminId}&tab=events");
                exit;
            }
        }

        // ── DELETE EVENT ────────────────────────────────────────────────────────
        if ($action === 'delete_event') {
            $eid = (int)$_POST['event_id'];
            $pdo->prepare("DELETE FROM Event WHERE event_id = ?")->execute([$eid]);
            $_SESSION['flash_message'] = 'Event deleted.';
            $_SESSION['flash_type']    = 'success';
            header("Location: ?admin_id={$adminId}&tab=events");
            exit;
        }

        // ── CREATE COURSE ───────────────────────────────────────────────────────
        if ($action === 'create_course') {
            $courseId  = trim($_POST['course_id']   ?? ''); // e.g. CS360
            $courseName = trim($_POST['course_name'] ?? '');
            $section   = trim($_POST['section']     ?? 'A');
            $year      = (int)($_POST['year']       ?? date('Y'));
            $semester  = trim($_POST['semester']    ?? 'Spring');
            $minEvents = (int)($_POST['minimum_events_required'] ?? 0);
            $profId    = (int)($_POST['professor_id'] ?? 0);

            if (!$courseId || !$courseName) {
                $message = 'Course ID and name are required.';
                $messageType = 'error';
            } else {
                // Insert into Course, then CourseAssignment if professor chosen
                $pdo->prepare(
                    "INSERT IGNORE INTO Course (course_id, course_name, section, year, semester, minimum_events_required)
                     VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([$courseId, $courseName, $section, $year, $semester, $minEvents]);

                if ($profId > 0) {
                    // CourseAssignment links professor to course
                    $pdo->prepare(
                        "INSERT IGNORE INTO CourseAssignment (course_id, professor_id) VALUES (?, ?)"
                    )->execute([$courseId, $profId]);
                }
                $message = 'Course created successfully!';
                $messageType = 'success';
            }
        }

        // ── ASSIGN PROFESSOR TO COURSE ──────────────────────────────────────────
        if ($action === 'assign_professor') {
            $courseId = trim($_POST['course_id'] ?? '');
            $profId   = (int)($_POST['professor_id'] ?? 0);
            if ($courseId && $profId) {
                try {
                    // Check if already assigned
                    $chk = $pdo->prepare(
                        "SELECT assignment_id FROM CourseAssignment WHERE course_id = ? AND professor_id = ? LIMIT 1"
                    );
                    $chk->execute([$courseId, $profId]);
                    $pn = $pdo->prepare("SELECT fname, lname FROM Professor WHERE professor_id = ? LIMIT 1");
                    $pn->execute([$profId]);
                    $prow = $pn->fetch();
                    $profName = $prow ? $prow['fname'] . ' ' . $prow['lname'] : 'That professor';
                    if ($chk->fetch()) {
                        $_SESSION['flash_message'] = "{$profName} is already assigned to {$courseId}.";
                        $_SESSION['flash_type']    = 'error';
                    } else {
                        $pdo->prepare(
                            "INSERT INTO CourseAssignment (course_id, professor_id) VALUES (?, ?)"
                        )->execute([$courseId, $profId]);
                        $_SESSION['flash_message'] = "{$profName} successfully assigned to {$courseId}.";
                        $_SESSION['flash_type']    = 'success';
                    }
                    header("Location: ?admin_id={$adminId}&tab=courses");
                    exit;
                } catch (PDOException $e) {
                    $_SESSION['flash_message'] = 'Error: ' . $e->getMessage();
                    $_SESSION['flash_type']    = 'error';
                    header("Location: ?admin_id={$adminId}&tab=courses");
                    exit;
                }
            }
        }

        // ── REMOVE PROFESSOR FROM COURSE ───────────────────────────────────────
        if ($action === 'remove_professor') {
            $courseId = trim($_POST['course_id'] ?? '');
            $profId   = (int)($_POST['professor_id'] ?? 0);
            if ($courseId && $profId) {
                $pdo->prepare(
                    "DELETE FROM CourseAssignment WHERE course_id = ? AND professor_id = ?"
                )->execute([$courseId, $profId]);
                $_SESSION['flash_message'] = 'Professor removed from course.';
                $_SESSION['flash_type']    = 'success';
                header("Location: ?admin_id={$adminId}&tab=courses");
                exit;
            }
        }

        // ── DELETE COURSE ───────────────────────────────────────────────────────
        if ($action === 'delete_course') {
            $cid = trim($_POST['course_id'] ?? '');
            $pdo->prepare("DELETE FROM Course WHERE course_id = ?")->execute([$cid]);
            $message = 'Course deleted.';
            $messageType = 'success';
        }

        // ── MANUAL ADD STUDENT TO ROSTER ────────────────────────────────────────
        if ($action === 'add_to_roster') {
            $studentId = (int)$_POST['student_id'];
            $courseId  = trim($_POST['course_id'] ?? '');
            if ($selectedCourseId) $courseId = (string)$selectedCourseId;

            // Check not already enrolled
            $existing = $pdo->prepare(
                "SELECT enrollment_id FROM EnrollmentInCourses WHERE student_id = ? AND course_id = ?"
            );
            $existing->execute([$studentId, $courseId]);

            if ($existing->fetch()) {
                $message = 'Student is already enrolled in this course.';
                $messageType = 'error';
            } else {
                $pdo->prepare(
                    "INSERT INTO EnrollmentInCourses (student_id, course_id, status) VALUES (?, ?, 'active')"
                )->execute([$studentId, $courseId]);
                $_SESSION['flash_message'] = 'Student added to roster.';
                $_SESSION['flash_type']    = 'success';
                header("Location: ?admin_id={$adminId}&tab=rosters&course_id=" . urlencode($courseId));
                exit;
            }
        }

        // ── REMOVE STUDENT FROM ROSTER ──────────────────────────────────────────
        if ($action === 'remove_from_roster') {
            $enrollId = (int)$_POST['enrollment_id'];
            $pdo->prepare("DELETE FROM EnrollmentInCourses WHERE enrollment_id = ?")->execute([$enrollId]);
            $_SESSION['flash_message'] = 'Student removed from roster.';
            $_SESSION['flash_type']    = 'success';
            // PRG redirect to keep course selected after remove
            $cid = trim($_POST['course_id'] ?? '');
            header("Location: ?admin_id={$adminId}&tab=rosters" . ($cid ? '&course_id=' . urlencode($cid) : ''));
            exit;
        }

        // ── UPLOAD ROSTER CSV ─────────────────────────────────────────────────────────
        // Accepts Gettysburg PeopleSoft CSV export: ID, Name, Level
        // Creates AppUser + Student rows + enrolls in course
        if ($action === 'upload_roster_csv') {
            $courseId = trim($_POST['course_id'] ?? '');
            if ($selectedCourseId) $courseId = (string)$selectedCourseId;

            if (!$courseId) {
                $message = 'Select a course before uploading a roster.';
                $messageType = 'error';
            } elseif (!isset($_FILES['roster_csv']) || $_FILES['roster_csv']['error'] !== UPLOAD_ERR_OK) {
                $uploadErr = $_FILES['roster_csv']['error'] ?? -1;
                $errMsg = match((int)$uploadErr) {
                    UPLOAD_ERR_NO_FILE  => 'No file was selected. Please choose a CSV file.',
                    UPLOAD_ERR_INI_SIZE,
                    UPLOAD_ERR_FORM_SIZE => 'File is too large.',
                    default             => 'File upload failed (code ' . $uploadErr . '). Please try again.',
                };
                $message = $errMsg;
                $messageType = 'error';
            } else {
                $ext = strtolower(pathinfo($_FILES['roster_csv']['name'], PATHINFO_EXTENSION));
                
                if ($ext !== 'csv') {
                    $message = 'Please upload a .csv file.';
                    $messageType = 'error';
                } else {
                    $tmpPath = $_FILES['roster_csv']['tmp_name'];

                    // Parse the Gettysburg PeopleSoft CSV export
                    $parseResult = parseCsvRoster($tmpPath);
                    
                    // Check for parse errors
                    if (isset($parseResult['error'])) {
                        $message = 'Upload error: ' . $parseResult['error'];
                        $messageType = 'error';
                    } else {
                        // Create student accounts and enrollments using helper
                        $importResult = createStudentAccounts($pdo, $courseId, $parseResult['data'], 'changeme123');
                        
                        $msg = "Upload complete: {$importResult['created']} student(s) created, " .
                               "{$importResult['enrolled']} enrolled, {$importResult['alreadyIn']} already enrolled.";
                        if ($importResult['skipped']) {
                            $msg .= " {$importResult['skipped']} row(s) skipped.";
                        }
                        
                        $_SESSION['flash_message'] = $msg;
                        $_SESSION['flash_type']    = 'success';
                        header("Location: ?admin_id={$adminId}&tab=rosters&course_id=" . urlencode($courseId));
                        exit;
                    }
                }
            }
        }

        // For error cases, keep the course selected from POST data
        if (isset($_POST['course_id'])) {
            $selectedCourseId = trim($_POST['course_id']); // VARCHAR(20), not int
        }
    }

    // ── FETCH DATA FOR DISPLAY ──────────────────────────────────────────────────

    // Events with creator name (newest first)
    $events = $pdo->query(
        "SELECT e.event_id, e.event_name, e.event_type, e.start_time, e.end_time,
                e.location, e.created_by,
                CONCAT(u.fname, ' ', u.lname) AS creator_name,
                u.role AS creator_role
         FROM Event e
         LEFT JOIN AppUser u ON u.user_id = e.created_by
         ORDER BY e.start_time DESC"
    )->fetchAll();

    // Courses with assigned professors (one row per assignment for remove button)
    $courses = $pdo->query(
        "SELECT c.course_id, c.course_name, c.section, c.year, c.semester, c.minimum_events_required,
                GROUP_CONCAT(CONCAT(p.fname,' ',p.lname) SEPARATOR ', ') AS professors
         FROM Course c
         LEFT JOIN CourseAssignment ca ON c.course_id = ca.course_id
         LEFT JOIN Professor p ON ca.professor_id = p.professor_id
         GROUP BY c.course_id
         ORDER BY c.course_id"
    )->fetchAll();

    // Also fetch per-assignment rows so admin can remove individual professors
    // Build courseAssignments as course_id => [array of assignments]
    // FETCH_GROUP keys by the FIRST column, so course_id must come first
    $courseAssignments = [];
    $caRows = $pdo->query(
        "SELECT ca.course_id, ca.assignment_id, ca.professor_id,
                CONCAT(p.fname, ' ', p.lname) AS prof_name
         FROM CourseAssignment ca
         JOIN Professor p ON ca.professor_id = p.professor_id
         ORDER BY ca.course_id, p.lname"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($caRows as $row) {
        $courseAssignments[$row['course_id']][] = $row;
    }

    // All students (for manual add-to-roster dropdown)
    $students = $pdo->query(
        "SELECT student_id, fname, lname, email FROM Student ORDER BY lname, fname"
    )->fetchAll();

    // All professors (for course creation / assignment dropdown)
    $professors = $pdo->query(
        "SELECT professor_id, fname, lname, email FROM Professor ORDER BY lname, fname"
    )->fetchAll();

    // Enrollments for the selected course
    $enrollments = [];
    if ($selectedCourseId) {
        $stmt = $pdo->prepare(
            "SELECT e.enrollment_id, e.status, s.student_id, s.fname, s.lname, s.email
             FROM EnrollmentInCourses e
             JOIN Student s ON e.student_id = s.student_id
             WHERE e.course_id = ?
             ORDER BY s.lname, s.fname"
        );
        $stmt->execute([$selectedCourseId]);
        $enrollments = $stmt->fetchAll();
    }

} catch (PDOException $e) {
    $dbError = 'Database error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Colloquium</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="dashboard-page">

    <!-- Top navigation bar — same style as SRS mockup -->
    <nav class="admin-topnav">
        <div class="nav-brand" style="display:flex;align-items:center;gap:.5rem;">
            <img src="gburglogo.jpg" alt="Gettysburg College" style="height:32px;width:auto;">
            <span class="nav-title">Admin Dashboard</span>
        </div>
        <div class="nav-tabs">
            <a href="?admin_id=<?= $adminId ?>&tab=events"
               class="nav-tab <?= $activeTab==='events'   ? 'active' : '' ?>">
                <i class="fas fa-calendar"></i> Events
            </a>
            <a href="?admin_id=<?= $adminId ?>&tab=rosters"
               class="nav-tab <?= $activeTab==='rosters'  ? 'active' : '' ?>">
                <i class="fas fa-users"></i> Rosters
            </a>
            <a href="?admin_id=<?= $adminId ?>&tab=courses"
               class="nav-tab <?= $activeTab==='courses'  ? 'active' : '' ?>">
                <i class="fas fa-book"></i> Courses
            </a>
        </div>
        <a href="index.html" class="nav-logout"><i class="fas fa-sign-out-alt"></i></a>
    </nav>

    <main class="admin-content">

        <?php if ($dbError): ?>
        <div class="db-error"><i class="fas fa-database"></i> <?= htmlspecialchars($dbError) ?></div>
        <?php else: ?>

        <?php if ($message): ?>
        <div class="toast <?= $messageType ?>">
            <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- Quick Access Actions - COMMENTED OUT: Already available in Professor Dashboard -->
        <!-- <div class="dashboard-action-row" style="margin-bottom:2rem; padding:1rem; background:#f8f9fa; border-radius:8px; border:1px solid #e9ecef;">
            <h3 style="margin:0 0 1rem 0; color:#333;">Quick Actions</h3>
            <div style="display:flex; gap:1rem; align-items:center; flex-wrap:wrap;">
                <a href="attendance.php?admin_id=<?= $adminId ?>" class="btn-primary" target="_blank">
                    <i class="fas fa-id-card"></i> Open Student Check-In Form
                </a>
                <span style="color:#666; font-size:.9rem;">Open on department tablet for student check-in/out during events.</span>
            </div>
        </div> -->

        <!-- ══════════════════ EVENTS TAB ══════════════════ -->
        <?php if ($activeTab === 'events'): ?>
        <div class="admin-section">
            <div class="section-header">
                <div>
                    <h2>Events</h2>
                    <p class="section-subtitle">Create and manage colloquium events</p>
                </div>
                <button class="btn-primary" onclick="openModal('createEventModal')">
                    <i class="fas fa-plus"></i> Create Event
                </button>
            </div>

            <div class="event-list">
                <?php if (empty($events)): ?>
                <p class="no-data"><i class="fas fa-calendar-times"></i> No events created yet. Click "Create Event" to get started.</p>
                <?php else: ?>
                <?php foreach ($events as $ev): ?>
                <div class="event-item">
                    <div class="event-info">
                        <h3><?= htmlspecialchars($ev['event_name']) ?></h3>
                        <div class="event-meta">
                            <span><i class="fas fa-tag"></i> <?= htmlspecialchars($ev['event_type']) ?></span>
                            <span><i class="fas fa-calendar"></i> <?= date('D, M j, Y', strtotime($ev['start_time'])) ?></span>
                            <span><i class="fas fa-clock"></i> <?= date('g:i A', strtotime($ev['start_time'])) ?> &ndash; <?= date('g:i A', strtotime($ev['end_time'])) ?></span>
                            <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($ev['location'] ?: 'TBD') ?></span>
                            <span style="color:#888;font-size:.82rem;">
                                <i class="fas fa-user"></i>
                                <?php if ((int)$ev['created_by'] === $adminId): ?>
                                    <span style="color:#2e7d32;">You</span>
                                <?php elseif ($ev['creator_name']): ?>
                                    <?= htmlspecialchars($ev['creator_name']) ?>
                                    <?php if ($ev['creator_role']): ?><em style="color:#aaa;">(<?= $ev['creator_role'] ?>)</em><?php endif; ?>
                                <?php else: ?>
                                    Unknown
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <div class="event-actions">
                        <!-- Admin can delete any event -->
                        <form method="POST" class="delete-form" onsubmit="return confirm('Delete "<?= htmlspecialchars(addslashes($ev['event_name'])) ?>"? This removes all attendance records too.');">
                            <input type="hidden" name="action"   value="delete_event">
                            <input type="hidden" name="event_id" value="<?= $ev['event_id'] ?>">
                            <button type="submit" class="btn-delete" title="Delete event"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Create Event Modal -->
        <div id="createEventModal" class="modal">
            <div class="modal-content" style="max-width:780px;width:95vw;">
                <div class="modal-header">
                    <h3><i class="fas fa-calendar-plus"></i> Create New Event</h3>
                    <button class="modal-close" onclick="closeModal('createEventModal')">&times;</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_event">
                    <!-- Row 1: Name full width -->
                    <div class="form-group">
                        <label>Event Name *</label>
                        <input type="text" name="event_name" required placeholder="e.g., Colloquium 1">
                    </div>
                    <!-- Row 2: Type + Location -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Event Type</label>
                            <select name="event_type">
                                <option value="Colloquium">Colloquium</option>
                                <option value="Hackathon">Hackathon</option>
                                <option value="ACM Workshop">ACM Workshop</option>
                                <option value="Seminar">Seminar</option>
                                <option value="Panel">Panel Discussion</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Location</label>
                            <input type="text" name="location" placeholder="e.g., Glatfelter Hall 201">
                        </div>
                    </div>
                    <!-- Row 3: Start + End -->
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
                        <button type="submit" class="btn-primary"><i class="fas fa-plus"></i> Create Event</button>
                    </div>
                </form>
            </div>
        </div>

        <?php endif; ?>

        <!-- ══════════════════ ROSTERS TAB ══════════════════ -->
        <?php if ($activeTab === 'rosters'): ?>
        <div class="admin-section">
            <div class="section-header">
                <div>
                    <h2>Rosters</h2>
                    <p class="section-subtitle">Upload or manually manage student enrollment per course</p>
                </div>
            </div>

            <!-- Course selector -->
            <div class="course-selector">
                <label>Select Course:</label>
                <select onchange="window.location.href='?admin_id=<?= $adminId ?>&tab=rosters&course_id='+this.value">
                    <option value="">-- Choose a course --</option>
                    <?php foreach ($courses as $c): ?>
                    <option value="<?= htmlspecialchars($c['course_id']) ?>"
                            <?= ($selectedCourseId !== '' && $selectedCourseId === $c['course_id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['course_id'] . ' - ' . $c['course_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($selectedCourseId): ?>

            <!-- CSV upload -->
            <div class="upload-card">
                <div class="upload-card-header">
                    <h3>Upload CSV Roster</h3>
                </div>
                <form method="POST" enctype="multipart/form-data" class="upload-form" id="rosterUploadForm">
                    <input type="hidden" name="action"    value="upload_roster_csv">
                    <input type="hidden" name="course_id" value="<?= $selectedCourseId ?>">
                    <!-- No 'required' here — the hidden input can't show browser validation UI -->
                    <input type="file" name="roster_csv" id="rosterCsvInput" class="upload-input" accept=".csv">
                    <label for="rosterCsvInput" id="rosterDropzone" class="upload-dropzone">
                        <i class="fas fa-cloud-arrow-up"></i>
                        <span>Drag &amp; drop a CSV here, or click to choose</span>
                    </label>
                    <p class="upload-selected" id="uploadSelectedFile">No file selected.</p>
                    <!-- Explicit submit button so the form always has a way to be submitted -->
                    <button type="submit" class="btn-primary" id="uploadSubmitBtn" style="display:none;">
                        <i class="fas fa-upload"></i> Upload Roster
                    </button>
                </form>
                <p class="upload-hint">
                    <strong>Supported format:</strong> .csv (Gettysburg PeopleSoft export)<br>
                    <strong>CSV columns used:</strong> ID, Name, Level<br>
                    New students are created automatically with temporary password <code>changeme123</code>.
                    They must change it when they first log in.
                </p>
            </div>

            <!-- Manual add from dropdown -->
            <div class="add-student-form">
                <form method="POST" class="inline-form">
                    <input type="hidden" name="action"    value="add_to_roster">
                    <input type="hidden" name="course_id" value="<?= $selectedCourseId ?>">
                    <select name="student_id" required>
                        <option value="">-- Select existing student --</option>
                        <?php foreach ($students as $s): ?>
                        <option value="<?= $s['student_id'] ?>">
                            <?= htmlspecialchars($s['lname'] . ', ' . $s['fname'] . ' (' . $s['student_id'] . ')') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-primary"><i class="fas fa-plus"></i> Add to Roster</button>
                </form>
            </div>

            <!-- Enrolled students -->
            <div class="roster-list">
                <h3>Enrolled Students (<?= count($enrollments) ?>)</h3>
                <?php if (empty($enrollments)): ?>
                <p class="no-data">No students enrolled yet.</p>
                <?php else: ?>
                <table class="roster-table">
                    <thead>
                        <tr><th>Name</th><th>Student ID</th><th>Status</th><th>Remove</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrollments as $en): ?>
                        <tr>
                            <td><?= htmlspecialchars($en['fname'] . ' ' . $en['lname']) ?></td>
                            <td><?= htmlspecialchars($en['student_id']) ?></td>
                            <td><span class="badge <?= $en['status']==='active' ? 'green' : 'orange' ?>">
                                <?= htmlspecialchars($en['status']) ?></span></td>
                            <td>
                                <form method="POST" class="inline" onsubmit="return confirm('Remove this student?');">
                                    <input type="hidden" name="action"        value="remove_from_roster">
                                    <input type="hidden" name="enrollment_id" value="<?= $en['enrollment_id'] ?>">
                                    <input type="hidden" name="course_id"     value="<?= htmlspecialchars($selectedCourseId) ?>">
                                    <button type="submit" class="btn-delete-small"><i class="fas fa-times"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <p class="select-prompt"><i class="fas fa-hand-pointer"></i> Select a course above to manage its roster.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ══════════════════ COURSES TAB ══════════════════ -->
        <?php if ($activeTab === 'courses'): ?>
        <style>
            .course-admin-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(360px,1fr)); gap:1.25rem; margin-top:1rem; }
            .course-admin-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem 1.5rem; box-shadow:0 1px 4px rgba(0,0,0,.06); transition:box-shadow .18s ease; }
            .course-admin-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.1); }
            .course-admin-card-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:.75rem; }
            .course-admin-card-header h3 { margin:0; font-size:1rem; color:#1a202c; line-height:1.3; }
            .course-admin-meta { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1rem; font-size:.8rem; color:#666; }
            .course-admin-meta span { background:#f1f5f9; padding:.15rem .55rem; border-radius:20px; }
            .prof-list { margin-bottom:.85rem; }
            .prof-list-label { font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:#888; margin-bottom:.4rem; }
            .prof-chip { display:inline-flex; align-items:center; gap:.4rem; background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe; border-radius:20px; padding:.25rem .6rem .25rem .75rem; font-size:.83rem; margin:.2rem .2rem .2rem 0; }
            .prof-chip-remove { background:none; border:none; cursor:pointer; color:#6366f1; padding:0; line-height:1; font-size:.8rem; transition:color .15s; }
            .prof-chip-remove:hover { color:#c62828; }
            .assign-prof-row { display:flex; gap:.5rem; align-items:center; padding-top:.75rem; border-top:1px solid #f1f5f9; }
            .assign-prof-row select { flex:1; padding:.4rem .6rem; border:1px solid #ccc; border-radius:6px; font-size:.85rem; }
        </style>
        <div class="admin-section">
            <div class="section-header">
                <div>
                    <h2>Courses</h2>
                    <p class="section-subtitle">Manage courses and professor assignments</p>
                </div>
                <button class="btn-primary" onclick="openModal('createCourseModal')">
                    <i class="fas fa-plus"></i> Create Course
                </button>
            </div>

            <?php if (empty($courses)): ?>
            <p class="no-data"><i class="fas fa-book-open"></i> No courses yet. Click "Create Course" to get started.</p>
            <?php else: ?>
            <div class="course-admin-grid">
                <?php foreach ($courses as $c):
                    $assigned = $courseAssignments[$c['course_id']] ?? [];
                ?>
                <div class="course-admin-card">
                    <div class="course-admin-card-header">
                        <div>
                            <h3><?= htmlspecialchars($c['course_name']) ?></h3>
                            <span class="badge" style="margin-top:.3rem;display:inline-block;"><?= htmlspecialchars($c['course_id']) ?></span>
                        </div>
                        <form method="POST" onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($c['course_name'])) ?>? This cannot be undone.');">
                            <input type="hidden" name="action"    value="delete_course">
                            <input type="hidden" name="course_id" value="<?= htmlspecialchars($c['course_id']) ?>">
                            <button type="submit" class="btn-delete-small" title="Delete course"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>

                    <div class="course-admin-meta">
                        <span><i class="fas fa-users"></i> Section <?= htmlspecialchars($c['section']) ?></span>
                        <span><i class="fas fa-calendar"></i> <?= htmlspecialchars($c['semester'] . ' ' . $c['year']) ?></span>
                        <span><i class="fas fa-calendar-check"></i> Min <?= (int)$c['minimum_events_required'] ?> events</span>
                    </div>

                    <div class="prof-list">
                        <div class="prof-list-label"><i class="fas fa-chalkboard-teacher"></i> Assigned Professors</div>
                        <?php if (empty($assigned)): ?>
                            <span style="color:#aaa;font-size:.85rem;font-style:italic;">No professors assigned</span>
                        <?php else: foreach ($assigned as $asgn): ?>
                            <span class="prof-chip">
                                <i class="fas fa-user"></i>
                                <?= htmlspecialchars($asgn['prof_name']) ?>
                                <form method="POST" style="margin:0;display:inline;"
                                      onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($asgn['prof_name'])) ?> from <?= htmlspecialchars(addslashes($c['course_id'])) ?>?');">
                                    <input type="hidden" name="action"       value="remove_professor">
                                    <input type="hidden" name="course_id"    value="<?= htmlspecialchars($c['course_id']) ?>">
                                    <input type="hidden" name="professor_id" value="<?= $asgn['professor_id'] ?>">
                                    <button type="submit" class="prof-chip-remove" title="Remove from course"><i class="fas fa-times"></i></button>
                                </form>
                            </span>
                        <?php endforeach; endif; ?>
                    </div>

                    <?php
                    // Build set of already-assigned professor IDs for this course
                    $assignedIds = array_column($assigned, 'professor_id');
                    $unassigned  = array_filter($professors, fn($p) => !in_array($p['professor_id'], $assignedIds));
                    ?>
                    <?php if (empty($unassigned)): ?>
                        <div class="assign-prof-row" style="color:#888;font-size:.85rem;font-style:italic;">
                            <i class="fas fa-check-circle" style="color:#2e7d32;"></i> All professors already assigned
                        </div>
                    <?php else: ?>
                    <form method="POST" class="assign-prof-row">
                        <input type="hidden" name="action"    value="assign_professor">
                        <input type="hidden" name="course_id" value="<?= htmlspecialchars($c['course_id']) ?>">
                        <select name="professor_id" required>
                            <option value="">— Assign professor —</option>
                            <?php foreach ($unassigned as $p): ?>
                            <option value="<?= $p['professor_id'] ?>">
                                <?= htmlspecialchars($p['fname'] . ' ' . $p['lname']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-small"><i class="fas fa-plus"></i> Assign</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Create Course Modal -->
        <div id="createCourseModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Create New Course</h3>
                    <button class="modal-close" onclick="closeModal('createCourseModal')">&times;</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_course">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Course ID * (e.g. CS360)</label>
                            <input type="text" name="course_id" required placeholder="CS360">
                        </div>
                        <div class="form-group">
                            <label>Section</label>
                            <input type="text" name="section" value="A" placeholder="A">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Course Name *</label>
                        <input type="text" name="course_name" required placeholder="Principles of Database Systems">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Semester</label>
                            <select name="semester">
                                <option value="Spring">Spring</option>
                                <option value="Fall">Fall</option>
                                <option value="Summer">Summer</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Year</label>
                            <input type="number" name="year" value="<?= date('Y') ?>" min="2020" max="2030">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Minimum Events Required</label>
                        <input type="number" name="minimum_events_required" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>Assign Professor (optional)</label>
                        <select name="professor_id">
                            <option value="">-- None --</option>
                            <?php foreach ($professors as $p): ?>
                            <option value="<?= $p['professor_id'] ?>">
                                <?= htmlspecialchars($p['fname'] . ' ' . $p['lname']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="closeModal('createCourseModal')">Cancel</button>
                        <button type="submit" class="btn-primary">Create Course</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; // end !$dbError ?>
    </main>

    <script>
    // Modal open/close
    function openModal(id)  { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }
    window.onclick = e => { if (e.target.classList.contains('modal')) e.target.classList.remove('active'); };

    // CSV roster upload: show submit button when a file is selected
    (function() {
        const form       = document.getElementById('rosterUploadForm');
        const input      = document.getElementById('rosterCsvInput');
        const dropzone   = document.getElementById('rosterDropzone');
        const label      = document.getElementById('uploadSelectedFile');
        const submitBtn  = document.getElementById('uploadSubmitBtn');
        if (!form) return;

        // When a file is chosen, update label and reveal the submit button
        function fileSelected(files) {
            if (!files || !files.length) return;
            const name = files[0].name;
            label.textContent = 'Selected: ' + name;
            submitBtn.style.display = 'flex'; // show Upload button
        }

        input.addEventListener('change', function() {
            fileSelected(input.files);
        });

        // Validate before submit: make sure a file was actually chosen
        form.addEventListener('submit', function(e) {
            if (!input.files || !input.files.length) {
                e.preventDefault();
                label.textContent = 'Please choose a CSV file first.';
                label.style.color = '#dc3545';
                return;
            }
            submitBtn.textContent = 'Uploading…';
            submitBtn.disabled = true;
        });

        // Drag and drop support
        dropzone.addEventListener('dragover', function(e) {
            e.preventDefault();
            dropzone.classList.add('is-dragover');
        });
        dropzone.addEventListener('dragleave', function() {
            dropzone.classList.remove('is-dragover');
        });
        dropzone.addEventListener('drop', function(e) {
            e.preventDefault();
            dropzone.classList.remove('is-dragover');
            if (e.dataTransfer && e.dataTransfer.files.length) {
                // Assign dropped files to the input
                input.files = e.dataTransfer.files;
                fileSelected(e.dataTransfer.files);
            }
        });
    })();
    </script>
</body>
</html>