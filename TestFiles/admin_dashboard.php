<?php
/**
 * ADMIN_DASHBOARD.PHP - Administrator Dashboard for Colloquium System
 * 
 * Simplified admin dashboard with two main functions:
 * 1. Events - Create and manage colloquium events
 * 2. Rosters - Add students to course rosters
 * 
 * Database Tables Used:
 * - AppUser: To get admin info
 * - Event: To create/manage events
 * - Course: To list courses for roster management
 * - Student: To list students for enrollment
 * - EnrollmentInCourses: To manage course rosters
 */

session_start();
require __DIR__ . '/../secrets/db.php';

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$dbError = null;
$message = '';
$messageType = '';

$adminId = isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : 1;
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'events';
$selectedCourseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

$admin = null;
$events = [];
$courses = [];
$students = [];
$enrollments = [];

/**
 * Normalizes CSV header names into lowercase snake_case keys.
 */
function normalizeCsvHeader($header)
{
    $normalized = strtolower(trim((string)$header));
    $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
    $normalized = trim((string)$normalized, '_');
    return $normalized;
}

/**
 * Returns the first non-empty value from a CSV row using possible key names.
 */
function getCsvValueByKeys($row, $keys)
{
    foreach ($keys as $key) {
        if (isset($row[$key])) {
            $value = trim((string)$row[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

/**
 * Reads a CSV row with explicit delimiter, enclosure, and escape values
 * to avoid deprecation warnings on newer PHP versions.
 */
function readCsvRow($handle)
{
    return fgetcsv($handle, 0, ',', '"', '\\');
}

/**
 * Maps common CSV year values into the enum expected by Student.year.
 */
function normalizeCsvYear($rawYear)
{
    $year = strtolower(trim((string)$rawYear));

    if ($year === '' || $year === 'freshman' || $year === 'freshmen' || $year === '1' || $year === '1st' || $year === 'first') {
        return 'Freshman';
    }
    if ($year === 'sophomore' || $year === '2' || $year === '2nd' || $year === 'second') {
        return 'Sophomore';
    }
    if ($year === 'junior' || $year === '3' || $year === '3rd' || $year === 'third') {
        return 'Junior';
    }
    if ($year === 'senior' || $year === '4' || $year === '4th' || $year === 'fourth') {
        return 'Senior';
    }

    return 'Freshman';
}

/**
 * Generates a fallback username from an email local-part.
 */
function buildUsernameFromEmail($email)
{
    $localPart = strstr((string)$email, '@', true);
    if ($localPart === false || $localPart === '') {
        $localPart = 'student';
    }

    $base = preg_replace('/[^a-z0-9._-]/i', '', $localPart);
    if ($base === '') {
        $base = 'student';
    }

    return strtolower($base);
}

if ($conn->connect_error) {
    $dbError = "Database connection failed: " . $conn->connect_error;
} else {
    // Get admin info
    $adminQuery = $conn->prepare("SELECT user_id, username, email FROM AppUser WHERE user_id = ? AND role = 'admin'");
    $adminQuery->bind_param('i', $adminId);
    $adminQuery->execute();
    $admin = $adminQuery->get_result()->fetch_assoc();
    $adminQuery->close();

    /**
     * HANDLE FORM SUBMISSIONS
     */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['course_id']) && (int)$_POST['course_id'] > 0) {
        $selectedCourseId = (int)$_POST['course_id'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];

        // CREATE NEW EVENT
        if ($action === 'create_event') {
            $eventName = trim($_POST['event_name'] ?? '');
            $eventType = trim($_POST['event_type'] ?? 'Lecture');
            $startTime = $_POST['start_time'] ?? '';
            $endTime = $_POST['end_time'] ?? '';
            $location = trim($_POST['location'] ?? '');

            if (empty($eventName) || empty($startTime) || empty($endTime)) {
                $message = "Please fill in all required fields.";
                $messageType = "error";
            } else {
                // Generate unique QR tokens
                $startToken = bin2hex(random_bytes(16));
                $endToken = bin2hex(random_bytes(16));

                $stmt = $conn->prepare("INSERT INTO Event (event_name, event_type, start_time, end_time, location, start_qr_token, end_qr_token) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sssssss', $eventName, $eventType, $startTime, $endTime, $location, $startToken, $endToken);

                if ($stmt->execute()) {
                    $message = "Event created successfully!";
                    $messageType = "success";
                } else {
                    $message = "Error creating event: " . $conn->error;
                    $messageType = "error";
                }
                $stmt->close();
            }
        }

        // DELETE EVENT
        if ($action === 'delete_event') {
            $eventId = (int)$_POST['event_id'];
            $stmt = $conn->prepare("DELETE FROM Event WHERE event_id = ?");
            $stmt->bind_param('i', $eventId);

            if ($stmt->execute()) {
                $message = "Event deleted successfully!";
                $messageType = "success";
            } else {
                $message = "Error deleting event.";
                $messageType = "error";
            }
            $stmt->close();
        }

        // ADD STUDENT TO ROSTER
        if ($action === 'add_to_roster') {
            $studentId = (int)$_POST['student_id'];
            $courseId = (int)$_POST['course_id'];

            // Check if already enrolled
            $checkStmt = $conn->prepare("SELECT enrollment_id FROM EnrollmentInCourses WHERE student_id = ? AND course_id = ?");
            $checkStmt->bind_param('ii', $studentId, $courseId);
            $checkStmt->execute();
            $existing = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if ($existing) {
                $message = "Student is already enrolled in this course.";
                $messageType = "error";
            } else {
                $stmt = $conn->prepare("INSERT INTO EnrollmentInCourses (student_id, course_id, status) VALUES (?, ?, 'active')");
                $stmt->bind_param('ii', $studentId, $courseId);

                if ($stmt->execute()) {
                    $message = "Student added to roster successfully!";
                    $messageType = "success";
                } else {
                    $message = "Error adding student to roster.";
                    $messageType = "error";
                }
                $stmt->close();
            }
        }

        // REMOVE FROM ROSTER
        if ($action === 'remove_from_roster') {
            $enrollmentId = (int)$_POST['enrollment_id'];
            $stmt = $conn->prepare("DELETE FROM EnrollmentInCourses WHERE enrollment_id = ?");
            $stmt->bind_param('i', $enrollmentId);

            if ($stmt->execute()) {
                $message = "Student removed from roster.";
                $messageType = "success";
            } else {
                $message = "Error removing student.";
                $messageType = "error";
            }
            $stmt->close();
        }

        // UPLOAD CSV ROSTER -> ADD STUDENTS -> ENROLL TO SELECTED COURSE
        if ($action === 'upload_roster_csv') {
            $courseId = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
            $selectedCourseId = $courseId;

            if ($courseId <= 0) {
                $message = "Please select a course before uploading a roster CSV.";
                $messageType = "error";
            } elseif (!isset($_FILES['roster_csv']) || $_FILES['roster_csv']['error'] !== UPLOAD_ERR_OK) {
                $message = "Could not upload CSV file. Please try again.";
                $messageType = "error";
            } else {
                $originalName = $_FILES['roster_csv']['name'] ?? 'roster.csv';
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                if ($extension !== 'csv') {
                    $message = "Please upload a valid CSV file.";
                    $messageType = "error";
                } else {
                    $handle = fopen($_FILES['roster_csv']['tmp_name'], 'r');
                    if ($handle === false) {
                        $message = "Unable to read CSV file.";
                        $messageType = "error";
                    } else {
                        $rawHeaders = readCsvRow($handle);
                        if ($rawHeaders === false) {
                            $message = "CSV file appears to be empty.";
                            $messageType = "error";
                            fclose($handle);
                        } else {
                            $headers = [];
                            foreach ($rawHeaders as $index => $rawHeader) {
                                $normalized = normalizeCsvHeader($rawHeader);
                                if ($normalized === '') {
                                    $normalized = 'column_' . ($index + 1);
                                }
                                if (in_array($normalized, $headers, true)) {
                                    $normalized .= '_' . ($index + 1);
                                }
                                $headers[] = $normalized;
                            }

                            $parsedRows = [];
                            $lineNumber = 1;
                            while (($row = readCsvRow($handle)) !== false) {
                                $lineNumber++;

                                $hasValue = false;
                                foreach ($row as $cell) {
                                    if (trim((string)$cell) !== '') {
                                        $hasValue = true;
                                        break;
                                    }
                                }
                                if (!$hasValue) {
                                    continue;
                                }

                                $normalizedRow = array_slice(array_pad($row, count($headers), ''), 0, count($headers));
                                $assoc = array_combine($headers, $normalizedRow);
                                if ($assoc === false) {
                                    continue;
                                }
                                $assoc['_row_number'] = $lineNumber;
                                $parsedRows[] = $assoc;
                            }
                            fclose($handle);

                            if (empty($parsedRows)) {
                                $message = "CSV has no data rows to import.";
                                $messageType = "error";
                            } else {
                                $createdStudents = 0;
                                $enrolledCount = 0;
                                $alreadyEnrolledCount = 0;
                                $skippedRows = 0;

                                $findStudentByEmailStmt = $conn->prepare("SELECT student_id FROM Student WHERE email = ? LIMIT 1");
                                $findStudentByIdStmt = $conn->prepare("SELECT student_id FROM Student WHERE student_id = ? LIMIT 1");
                                $findUserByEmailStmt = $conn->prepare("SELECT user_id, role FROM AppUser WHERE email = ? LIMIT 1");
                                $insertUserStmt = $conn->prepare("INSERT INTO AppUser (username, email, role) VALUES (?, ?, 'student')");
                                $insertStudentAutoStmt = $conn->prepare("INSERT INTO Student (user_id, first_name, last_name, email, year) VALUES (?, ?, ?, ?, ?)");
                                $insertStudentWithIdStmt = $conn->prepare("INSERT INTO Student (student_id, user_id, first_name, last_name, email, year) VALUES (?, ?, ?, ?, ?, ?)");
                                $checkEnrollmentStmt = $conn->prepare("SELECT enrollment_id FROM EnrollmentInCourses WHERE student_id = ? AND course_id = ? LIMIT 1");
                                $insertEnrollmentStmt = $conn->prepare("INSERT INTO EnrollmentInCourses (student_id, course_id, status) VALUES (?, ?, 'active')");

                                if (
                                    $findStudentByEmailStmt === false ||
                                    $findStudentByIdStmt === false ||
                                    $findUserByEmailStmt === false ||
                                    $insertUserStmt === false ||
                                    $insertStudentAutoStmt === false ||
                                    $insertStudentWithIdStmt === false ||
                                    $checkEnrollmentStmt === false ||
                                    $insertEnrollmentStmt === false
                                ) {
                                    $message = "Could not prepare statements for roster import.";
                                    $messageType = "error";
                                } else {
                                    foreach ($parsedRows as $csvRow) {
                                        if (!is_array($csvRow)) {
                                            $skippedRows++;
                                            continue;
                                        }

                                        $email = strtolower(getCsvValueByKeys($csvRow, ['email', 'student_email', 'studentemail', 'mail', 'e_mail']));
                                        if ($email === '') {
                                            $skippedRows++;
                                            continue;
                                        }

                                        $firstName = getCsvValueByKeys($csvRow, ['first_name', 'firstname', 'given_name']);
                                        $lastName = getCsvValueByKeys($csvRow, ['last_name', 'lastname', 'surname', 'family_name']);
                                        $fullName = getCsvValueByKeys($csvRow, ['name', 'full_name', 'student_name']);
                                        if (($firstName === '' || $lastName === '') && $fullName !== '') {
                                            $nameParts = preg_split('/\s+/', trim($fullName));
                                            if (is_array($nameParts) && count($nameParts) > 0) {
                                                if ($firstName === '') {
                                                    $firstName = (string)array_shift($nameParts);
                                                }
                                                if ($lastName === '') {
                                                    $lastName = trim(implode(' ', $nameParts));
                                                }
                                            }
                                        }
                                        if ($firstName === '') {
                                            $firstName = 'Student';
                                        }
                                        if ($lastName === '') {
                                            $lastName = 'User';
                                        }

                                        $year = normalizeCsvYear(getCsvValueByKeys($csvRow, ['year', 'class_year', 'classyear']));
                                        $csvStudentIdRaw = getCsvValueByKeys($csvRow, ['student_id', 'studentid', 'id', 'student_number', 'student_no']);
                                        $csvStudentId = ctype_digit($csvStudentIdRaw) ? (int)$csvStudentIdRaw : 0;

                                        $studentId = 0;

                                        if ($csvStudentId > 0) {
                                            $findStudentByIdStmt->bind_param('i', $csvStudentId);
                                            $findStudentByIdStmt->execute();
                                            $existingById = $findStudentByIdStmt->get_result()->fetch_assoc();
                                            if ($existingById) {
                                                $studentId = (int)$existingById['student_id'];
                                            }
                                        }

                                        if ($studentId === 0) {
                                            $findStudentByEmailStmt->bind_param('s', $email);
                                            $findStudentByEmailStmt->execute();
                                            $existingByEmail = $findStudentByEmailStmt->get_result()->fetch_assoc();
                                            if ($existingByEmail) {
                                                $studentId = (int)$existingByEmail['student_id'];
                                            }
                                        }

                                        if ($studentId === 0) {
                                            $userId = 0;

                                            $findUserByEmailStmt->bind_param('s', $email);
                                            $findUserByEmailStmt->execute();
                                            $existingUser = $findUserByEmailStmt->get_result()->fetch_assoc();

                                            if ($existingUser) {
                                                if (($existingUser['role'] ?? '') !== 'student') {
                                                    $skippedRows++;
                                                    continue;
                                                }
                                                $userId = (int)$existingUser['user_id'];
                                            } else {
                                                $baseUsername = buildUsernameFromEmail($email);
                                                for ($attempt = 0; $attempt < 5; $attempt++) {
                                                    $candidate = $baseUsername;
                                                    if ($attempt > 0) {
                                                        $candidate .= '_' . substr(bin2hex(random_bytes(2)), 0, 4);
                                                    }

                                                    $insertUserStmt->bind_param('ss', $candidate, $email);
                                                    if ($insertUserStmt->execute()) {
                                                        $userId = (int)$conn->insert_id;
                                                        break;
                                                    }

                                                    if ((int)$conn->errno !== 1062) {
                                                        break;
                                                    }
                                                }
                                            }

                                            if ($userId <= 0) {
                                                $skippedRows++;
                                                continue;
                                            }

                                            $inserted = false;
                                            if ($csvStudentId > 0) {
                                                $insertStudentWithIdStmt->bind_param('iissss', $csvStudentId, $userId, $firstName, $lastName, $email, $year);
                                                $inserted = $insertStudentWithIdStmt->execute();
                                                if (!$inserted && (int)$conn->errno === 1062) {
                                                    $insertStudentAutoStmt->bind_param('issss', $userId, $firstName, $lastName, $email, $year);
                                                    $inserted = $insertStudentAutoStmt->execute();
                                                }
                                            } else {
                                                $insertStudentAutoStmt->bind_param('issss', $userId, $firstName, $lastName, $email, $year);
                                                $inserted = $insertStudentAutoStmt->execute();
                                            }

                                            if (!$inserted) {
                                                $skippedRows++;
                                                continue;
                                            }

                                            $findStudentByEmailStmt->bind_param('s', $email);
                                            $findStudentByEmailStmt->execute();
                                            $createdStudent = $findStudentByEmailStmt->get_result()->fetch_assoc();

                                            if (!$createdStudent) {
                                                $skippedRows++;
                                                continue;
                                            }

                                            $studentId = (int)$createdStudent['student_id'];
                                            $createdStudents++;
                                        }

                                        $checkEnrollmentStmt->bind_param('ii', $studentId, $courseId);
                                        $checkEnrollmentStmt->execute();
                                        $existingEnrollment = $checkEnrollmentStmt->get_result()->fetch_assoc();

                                        if ($existingEnrollment) {
                                            $alreadyEnrolledCount++;
                                            continue;
                                        }

                                        $insertEnrollmentStmt->bind_param('ii', $studentId, $courseId);
                                        if ($insertEnrollmentStmt->execute()) {
                                            $enrolledCount++;
                                        } else {
                                            $skippedRows++;
                                        }
                                    }

                                    $findStudentByEmailStmt->close();
                                    $findStudentByIdStmt->close();
                                    $findUserByEmailStmt->close();
                                    $insertUserStmt->close();
                                    $insertStudentAutoStmt->close();
                                    $insertStudentWithIdStmt->close();
                                    $checkEnrollmentStmt->close();
                                    $insertEnrollmentStmt->close();

                                    $message = "Upload complete. "
                                        . $createdStudents
                                        . " new student(s) added, "
                                        . $enrolledCount
                                        . " enrolled to this course, "
                                        . $alreadyEnrolledCount
                                        . " already enrolled.";

                                    if ($skippedRows > 0) {
                                        $message .= " " . $skippedRows . " row(s) were skipped.";
                                    }

                                    $messageType = "success";
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // FETCH EVENTS
    $eventQuery = $conn->query("SELECT event_id, event_name, event_type, start_time, end_time, location FROM Event ORDER BY start_time DESC");
    while ($row = $eventQuery->fetch_assoc()) {
        $events[] = $row;
    }

    // FETCH COURSES
    $courseQuery = $conn->query("SELECT course_id, course_name, course_code, section, semester FROM Course ORDER BY course_code");
    while ($row = $courseQuery->fetch_assoc()) {
        $courses[] = $row;
    }

    // FETCH STUDENTS
    $studentQuery = $conn->query("SELECT student_id, first_name, last_name, email FROM Student ORDER BY last_name, first_name");
    while ($row = $studentQuery->fetch_assoc()) {
        $students[] = $row;
    }

    // FETCH ENROLLMENTS for roster view
    if ($selectedCourseId) {
        $enrollQuery = $conn->prepare("
            SELECT e.enrollment_id, e.status, s.student_id, s.first_name, s.last_name, s.email
            FROM EnrollmentInCourses e
            JOIN Student s ON e.student_id = s.student_id
            WHERE e.course_id = ?
            ORDER BY s.last_name, s.first_name
        ");
        $enrollQuery->bind_param('i', $selectedCourseId);
        $enrollQuery->execute();
        $enrollResult = $enrollQuery->get_result();
        while ($row = $enrollResult->fetch_assoc()) {
            $enrollments[] = $row;
        }
        $enrollQuery->close();
    }
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
    <!-- Simple Top Navigation -->
    <nav class="admin-topnav">
        <span class="nav-title">Admin Dashboard</span>
        <div class="nav-tabs">
            <a href="?admin_id=<?php echo $adminId; ?>&tab=events" 
               class="nav-tab <?php echo $activeTab === 'events' ? 'active' : ''; ?>">
                <i class="fas fa-calendar"></i> Events
            </a>
            <a href="?admin_id=<?php echo $adminId; ?>&tab=rosters" 
               class="nav-tab <?php echo $activeTab === 'rosters' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> Rosters
            </a>
        </div>
        <a href="index.html" class="nav-logout"><i class="fas fa-sign-out-alt"></i></a>
    </nav>

    <main class="admin-content">
        <?php if ($dbError): ?>
        <div class="db-error"><i class="fas fa-database"></i> <?php echo htmlspecialchars($dbError); ?></div>
        <?php else: ?>

        <?php if ($message): ?>
        <div class="toast <?php echo $messageType; ?>">
            <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <!-- EVENTS TAB -->
        <?php if ($activeTab === 'events'): ?>
        <div class="admin-section">
            <div class="section-header">
                <div>
                    <h2>Events</h2>
                    <p class="section-subtitle">Create and manage events</p>
                </div>
                <button class="btn-primary" onclick="openModal('createEventModal')">
                    <i class="fas fa-plus"></i> Create Event
                </button>
            </div>

            <div class="event-list">
                <?php if (empty($events)): ?>
                <p class="no-data">No events created yet.</p>
                <?php else: ?>
                <?php foreach ($events as $event): ?>
                <div class="event-item">
                    <div class="event-info">
                        <h3><?php echo htmlspecialchars($event['event_name']); ?></h3>
                        <div class="event-meta">
                            <span><i class="fas fa-calendar"></i> <?php echo date('n/j/Y, g:i:s A', strtotime($event['start_time'])); ?></span>
                            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($event['location'] ?: 'TBD'); ?></span>
                        </div>
                    </div>
                    <form method="POST" class="delete-form" onsubmit="return confirm('Delete this event?');">
                        <input type="hidden" name="action" value="delete_event">
                        <input type="hidden" name="event_id" value="<?php echo $event['event_id']; ?>">
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
                            <option value="Lecture">Lecture</option>
                            <option value="Workshop">Workshop</option>
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
                        <input type="text" name="location" placeholder="e.g., Glatfelter Hall">
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="closeModal('createEventModal')">Cancel</button>
                        <button type="submit" class="btn-primary">Create Event</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- ROSTERS TAB -->
        <?php if ($activeTab === 'rosters'): ?>
        <div class="admin-section">
            <div class="section-header">
                <div>
                    <h2>Rosters</h2>
                    <p class="section-subtitle">Manage student enrollment in courses</p>
                </div>
            </div>

            <!-- Course Selector -->
            <div class="course-selector">
                <label>Select Course:</label>
                <select onchange="window.location.href='?admin_id=<?php echo $adminId; ?>&tab=rosters&course_id=' + this.value">
                    <option value="">-- Choose a course --</option>
                    <?php foreach ($courses as $course): ?>
                    <option value="<?php echo $course['course_id']; ?>" 
                            <?php echo ($selectedCourseId == $course['course_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($selectedCourseId): ?>
            <div class="upload-card">
                <div class="upload-card-header">
                    <h3>Upload CSV Roster</h3>
                </div>

                <form method="POST" enctype="multipart/form-data" class="upload-form" id="rosterUploadForm">
                    <input type="hidden" name="action" value="upload_roster_csv">
                    <input type="hidden" name="course_id" value="<?php echo $selectedCourseId; ?>">

                    <input type="file" name="roster_csv" id="rosterCsvInput" class="upload-input" accept=".csv,text/csv" required>
                    <label for="rosterCsvInput" id="rosterDropzone" class="upload-dropzone">
                        <i class="fas fa-cloud-arrow-up"></i>
                        <span>Drag and drop a CSV here, or click to choose</span>
                    </label>
                    <p class="upload-selected" id="uploadSelectedFile">No file selected.</p>
                </form>
                <p class="upload-hint">Upload CSV with student_id, first_name, last_name, and email. Students are added and enrolled automatically.</p>
            </div>

            <!-- Add Student Form -->
            <div class="add-student-form">
                <form method="POST" class="inline-form">
                    <input type="hidden" name="action" value="add_to_roster">
                    <input type="hidden" name="course_id" value="<?php echo $selectedCourseId; ?>">
                    
                    <select name="student_id" required>
                        <option value="">-- Select Student --</option>
                        <?php foreach ($students as $student): ?>
                        <option value="<?php echo $student['student_id']; ?>">
                            <?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name'] . ' (' . $student['email'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-plus"></i> Add to Roster
                    </button>
                </form>
            </div>

            <!-- Enrolled Students List -->
            <div class="roster-list">
                <h3>Enrolled Students (<?php echo count($enrollments); ?>)</h3>
                
                <?php if (empty($enrollments)): ?>
                <p class="no-data">No students enrolled in this course.</p>
                <?php else: ?>
                <table class="roster-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrollments as $enrollment): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($enrollment['first_name'] . ' ' . $enrollment['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($enrollment['email']); ?></td>
                            <td>
                                <form method="POST" class="inline" onsubmit="return confirm('Remove this student?');">
                                    <input type="hidden" name="action" value="remove_from_roster">
                                    <input type="hidden" name="enrollment_id" value="<?php echo $enrollment['enrollment_id']; ?>">
                                    <button type="submit" class="btn-delete-small">
                                        <i class="fas fa-times"></i>
                                    </button>
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

        <?php endif; ?>
    </main>

    <script>
    /**
     * MODAL FUNCTIONS
     * Open and close modal dialogs for creating events
     */
    function openModal(modalId) {
        document.getElementById(modalId).classList.add('active');
    }
    
    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');
    }

    /**
     * CSV drag-and-drop upload: selecting or dropping a file auto-submits form.
     */
    (function setupRosterCsvAutoUpload() {
        const form = document.getElementById('rosterUploadForm');
        const fileInput = document.getElementById('rosterCsvInput');
        const dropzone = document.getElementById('rosterDropzone');
        const selectedText = document.getElementById('uploadSelectedFile');

        if (!form || !fileInput || !dropzone || !selectedText) {
            return;
        }

        function setSelectedFile(file) {
            selectedText.textContent = file ? `Selected: ${file.name}` : 'No file selected.';
        }

        function submitAfterFileSelection(fileList) {
            if (!fileList || fileList.length === 0) {
                return;
            }

            const file = fileList[0];
            setSelectedFile(file);
            selectedText.textContent = `Uploading: ${file.name} ...`;
            form.submit();
        }

        fileInput.addEventListener('change', function () {
            submitAfterFileSelection(fileInput.files);
        });

        dropzone.addEventListener('dragover', function (event) {
            event.preventDefault();
            dropzone.classList.add('is-dragover');
        });

        dropzone.addEventListener('dragleave', function () {
            dropzone.classList.remove('is-dragover');
        });

        dropzone.addEventListener('drop', function (event) {
            event.preventDefault();
            dropzone.classList.remove('is-dragover');

            if (!event.dataTransfer || !event.dataTransfer.files || event.dataTransfer.files.length === 0) {
                return;
            }

            fileInput.files = event.dataTransfer.files;
            submitAfterFileSelection(event.dataTransfer.files);
        });
    })();
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.classList.remove('active');
        }
    }
    </script>
</body>
</html>
