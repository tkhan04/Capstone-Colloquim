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

require __DIR__ . '/db.php';

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

// ── Helper: normalize CSV header to lowercase_snake_case ──────────────────────
function normalizeCsvHeader($h) {
    $h = strtolower(trim((string)$h));
    $h = preg_replace('/[^a-z0-9]+/', '_', $h);
    return trim($h, '_');
}

// ── Helper: pull first non-empty value from a CSV row using candidate keys ────
function csvVal($row, $keys) {
    foreach ($keys as $k) {
        if (isset($row[$k]) && trim((string)$row[$k]) !== '') return trim((string)$row[$k]);
    }
    return '';
}

// ── Helper: map raw year strings to Student.year ENUM values ─────────────────
function normalizeYear($raw) {
    $y = strtolower(trim((string)$raw));
    if (in_array($y, ['freshman','freshmen','1','1st','first'], true))  return 'Freshman';
    if (in_array($y, ['sophomore','2','2nd','second'], true))           return 'Sophomore';
    if (in_array($y, ['junior','3','3rd','third'], true))               return 'Junior';
    if (in_array($y, ['senior','4','4th','fourth'], true))              return 'Senior';
    return 'Freshman'; // safe default
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
                    $pdo->prepare(
                        "INSERT IGNORE INTO CourseAssignment (course_id, professor_id) VALUES (?, ?)"
                    )->execute([$courseId, $profId]);
                    $message = 'Professor assigned to course.';
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = 'Error assigning professor: ' . $e->getMessage();
                    $messageType = 'error';
                }
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

        // ── UPLOAD CSV ROSTER ───────────────────────────────────────────────────
        // CSV must have at minimum: student_id, fname (or first_name), lname (or last_name), email
        // Optional: year
        // Creates AppUser + Student rows if they don't exist, then enrolls student in the course.
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
            } elseif (strtolower(pathinfo($_FILES['roster_csv']['name'], PATHINFO_EXTENSION)) !== 'csv') {
                $message = 'Please upload a .csv file.';
                $messageType = 'error';
            } else {
                $handle = fopen($_FILES['roster_csv']['tmp_name'], 'r');
                $rawHeaders = fgetcsv($handle, 0, ',', '"', '\\');
                $headers = array_map('normalizeCsvHeader', $rawHeaders ?: []);

                $created = $enrolled = $skipped = $alreadyIn = 0;

                while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                    // Skip blank rows
                    if (!array_filter(array_map('trim', $row))) continue;

                    $assoc = array_combine(
                        $headers,
                        array_slice(array_pad($row, count($headers), ''), 0, count($headers))
                    );

                    $email     = strtolower(csvVal($assoc, ['email','student_email','mail']));
                    $fname     = csvVal($assoc, ['fname','first_name','firstname','given_name']);
                    $lname     = csvVal($assoc, ['lname','last_name','lastname','surname']);
                    $rawYear   = csvVal($assoc, ['year','class_year','classyear']);
                    $csvStuId  = csvVal($assoc, ['student_id','studentid','id','student_number']);
                    $stuIdInt  = ctype_digit($csvStuId) ? (int)$csvStuId : 0;
                    $yearEnum  = normalizeYear($rawYear);

                    // full_name fallback
                    if (!$fname || !$lname) {
                        $full = csvVal($assoc, ['name','full_name','student_name']);
                        $parts = preg_split('/\s+/', trim($full));
                        if (!$fname) $fname = array_shift($parts) ?: 'Student';
                        if (!$lname) $lname = implode(' ', $parts) ?: 'User';
                    }

                    if (!$email) { $skipped++; continue; }

                    // Find or create student (look up by ID first, then email)
                    $studentId = 0;

                    if ($stuIdInt > 0) {
                        $r = $pdo->prepare("SELECT student_id FROM Student WHERE student_id = ? LIMIT 1");
                        $r->execute([$stuIdInt]);
                        if ($row2 = $r->fetch()) $studentId = (int)$row2['student_id'];
                    }

                    if (!$studentId) {
                        $r = $pdo->prepare("SELECT student_id FROM Student WHERE email = ? LIMIT 1");
                        $r->execute([$email]);
                        if ($row2 = $r->fetch()) $studentId = (int)$row2['student_id'];
                    }

                    if (!$studentId) {
                        // Need to create AppUser + Student rows
                        // Check if AppUser already exists for this email
                        $r = $pdo->prepare("SELECT user_id FROM AppUser WHERE email = ? LIMIT 1");
                        $r->execute([$email]);
                        $existing = $r->fetch();

                        if ($existing) {
                            $userId = (int)$existing['user_id'];
                        } else {
                            // Insert AppUser with a temp password; admin can reset later
                            $hash = password_hash('changeme123', PASSWORD_DEFAULT);
                            try {
                                if ($stuIdInt > 0) {
                                    $pdo->prepare(
                                        "INSERT INTO AppUser (user_id, fname, lname, email, role, password_hash, is_active)
                                         VALUES (?, ?, ?, ?, 'student', ?, 1)"
                                    )->execute([$stuIdInt, $fname, $lname, $email, $hash]);
                                    $userId = $stuIdInt;
                                } else {
                                    $pdo->prepare(
                                        "INSERT INTO AppUser (fname, lname, email, role, password_hash, is_active)
                                         VALUES (?, ?, ?, 'student', ?, 1)"
                                    )->execute([$fname, $lname, $email, $hash]);
                                    $userId = (int)$pdo->lastInsertId();
                                }
                            } catch (PDOException $e) { $skipped++; continue; }
                        }

                        // Insert Student row (student_id = user_id per schema FK)
                        try {
                            $pdo->prepare(
                                "INSERT IGNORE INTO Student (student_id, fname, lname, email, year)
                                 VALUES (?, ?, ?, ?, ?)"
                            )->execute([$userId, $fname, $lname, $email, $yearEnum]);
                            $studentId = $userId;
                            $created++;
                        } catch (PDOException $e) { $skipped++; continue; }
                    }

                    // Enroll in course
                    $chk = $pdo->prepare(
                        "SELECT enrollment_id FROM EnrollmentInCourses WHERE student_id = ? AND course_id = ?"
                    );
                    $chk->execute([$studentId, $courseId]);
                    if ($chk->fetch()) { $alreadyIn++; continue; }

                    try {
                        $pdo->prepare(
                            "INSERT INTO EnrollmentInCourses (student_id, course_id, status) VALUES (?, ?, 'active')"
                        )->execute([$studentId, $courseId]);
                        $enrolled++;
                    } catch (PDOException $e) { $skipped++; }
                }
                fclose($handle);

                // PRG: redirect back to GET URL so the course stays selected and refreshing won't re-POST
                $msg = "Upload complete: {$created} student(s) created, {$enrolled} enrolled, {$alreadyIn} already enrolled.";
                if ($skipped) $msg .= " {$skipped} row(s) skipped.";
                $_SESSION['flash_message'] = $msg;
                $_SESSION['flash_type']    = 'success';
                header("Location: ?admin_id={$adminId}&tab=rosters&course_id=" . urlencode($courseId));
                exit;
            }
        }

        // For error cases, keep the course selected from POST data
        if (isset($_POST['course_id'])) {
            $selectedCourseId = trim($_POST['course_id']); // VARCHAR(20), not int
        }
    }

    // ── FETCH DATA FOR DISPLAY ──────────────────────────────────────────────────

    // Events (newest first)
    $events = $pdo->query(
        "SELECT event_id, event_name, event_type, start_time, end_time, location FROM Event ORDER BY start_time DESC"
    )->fetchAll();

    // Courses with optional professor name via CourseAssignment
    $courses = $pdo->query(
        "SELECT c.course_id, c.course_name, c.section, c.year, c.semester, c.minimum_events_required,
                GROUP_CONCAT(CONCAT(p.fname,' ',p.lname) SEPARATOR ', ') AS professors
         FROM Course c
         LEFT JOIN CourseAssignment ca ON c.course_id = ca.course_id
         LEFT JOIN Professor p ON ca.professor_id = p.professor_id
         GROUP BY c.course_id
         ORDER BY c.course_id"
    )->fetchAll();

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
                            <span><i class="fas fa-calendar"></i> <?= date('n/j/Y, g:i A', strtotime($ev['start_time'])) ?></span>
                            <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($ev['location'] ?: 'TBD') ?></span>
                        </div>
                    </div>
                    <!-- Delete event -->
                    <form method="POST" class="delete-form" onsubmit="return confirm('Delete this event?');">
                        <input type="hidden" name="action"   value="delete_event">
                        <input type="hidden" name="event_id" value="<?= $ev['event_id'] ?>">
                        <button type="submit" class="btn-delete"><i class="fas fa-trash"></i></button>
                    </form>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Create Event Modal -->
        <div id="createEventModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Create New Event</h3>
                    <button class="modal-close" onclick="closeModal('createEventModal')">&times;</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_event">
                    <div class="form-group">
                        <label>Event Name *</label>
                        <input type="text" name="event_name" required placeholder="e.g., Colloquium 1">
                    </div>
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
                    <div class="form-group">
                        <label>Location</label>
                        <input type="text" name="location" placeholder="e.g., Glatfelter Hall 201">
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="closeModal('createEventModal')">Cancel</button>
                        <button type="submit" class="btn-primary">Create Event</button>
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
                    CSV columns: <strong>student_id, fname, lname, email, year</strong>
                    (or first_name / last_name / name). New students are created automatically
                    with a temporary password of <code>changeme123</code>.
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
                            <?= htmlspecialchars($s['lname'] . ', ' . $s['fname'] . ' (' . $s['email'] . ')') ?>
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
                        <tr><th>Name</th><th>Email</th><th>Status</th><th>Remove</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrollments as $en): ?>
                        <tr>
                            <td><?= htmlspecialchars($en['fname'] . ' ' . $en['lname']) ?></td>
                            <td><?= htmlspecialchars($en['email']) ?></td>
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
        <div class="admin-section">
            <div class="section-header">
                <div>
                    <h2>Courses</h2>
                    <p class="section-subtitle">Create courses and assign professors</p>
                </div>
                <button class="btn-primary" onclick="openModal('createCourseModal')">
                    <i class="fas fa-plus"></i> Create Course
                </button>
            </div>

            <!-- Courses table -->
            <?php if (empty($courses)): ?>
            <p class="no-data"><i class="fas fa-book-open"></i> No courses yet. Create one above.</p>
            <?php else: ?>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Course ID</th>
                            <th>Name</th>
                            <th>Section</th>
                            <th>Semester / Year</th>
                            <th>Min Events</th>
                            <th>Professor(s)</th>
                            <th>Assign Prof</th>
                            <th>Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses as $c): ?>
                        <tr>
                            <td><span class="badge"><?= htmlspecialchars($c['course_id']) ?></span></td>
                            <td><?= htmlspecialchars($c['course_name']) ?></td>
                            <td><?= htmlspecialchars($c['section']) ?></td>
                            <td><?= htmlspecialchars($c['semester'] . ' ' . $c['year']) ?></td>
                            <td><?= (int)$c['minimum_events_required'] ?></td>
                            <td><?= htmlspecialchars($c['professors'] ?: '—') ?></td>
                            <td>
                                <!-- Quick assign professor inline -->
                                <form method="POST" style="display:flex;gap:.5rem;align-items:center;">
                                    <input type="hidden" name="action"    value="assign_professor">
                                    <input type="hidden" name="course_id" value="<?= htmlspecialchars($c['course_id']) ?>">
                                    <select name="professor_id" style="padding:.3rem .5rem;border:1px solid #ccc;border-radius:4px;font-size:.85rem;">
                                        <option value="">-- Prof --</option>
                                        <?php foreach ($professors as $p): ?>
                                        <option value="<?= $p['professor_id'] ?>">
                                            <?= htmlspecialchars($p['fname'] . ' ' . $p['lname']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn-small"><i class="fas fa-link"></i></button>
                                </form>
                            </td>
                            <td>
                                <form method="POST" class="inline" onsubmit="return confirm('Delete course?');">
                                    <input type="hidden" name="action"    value="delete_course">
                                    <input type="hidden" name="course_id" value="<?= htmlspecialchars($c['course_id']) ?>">
                                    <button type="submit" class="btn-delete-small"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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