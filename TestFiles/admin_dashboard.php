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
$uploadedRosterFiles = [];
$uploadSummary = null;
$lastUploadJsonUrl = '';
$lastUploadJsonName = '';
$lastUploadPreview = [];

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

        // UPLOAD CSV ROSTER -> PARSE -> GENERATE JSON -> IMPORT ENROLLMENTS
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
                        $rawHeaders = fgetcsv($handle);
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
                            while (($row = fgetcsv($handle)) !== false) {
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
                                $uploadDir = __DIR__ . '/uploads/rosters';
                                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                                    $message = "Failed to create roster upload directory.";
                                    $messageType = "error";
                                } else {
                                    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
                                    $safeBase = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$baseName);
                                    if ($safeBase === '') {
                                        $safeBase = 'roster';
                                    }

                                    $jsonFileName = 'course_' . $courseId . '_' . $safeBase . '_' . date('Ymd_His') . '.json';
                                    $jsonPath = $uploadDir . '/' . $jsonFileName;

                                    $jsonPayload = [
                                        'course_id' => $courseId,
                                        'source_csv' => $originalName,
                                        'uploaded_at' => date('c'),
                                        'row_count' => count($parsedRows),
                                        'rows' => $parsedRows,
                                    ];

                                    $jsonWritten = file_put_contents(
                                        $jsonPath,
                                        json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                                    );

                                    if ($jsonWritten === false) {
                                        $message = "CSV parsed, but JSON file could not be written.";
                                        $messageType = "error";
                                    } else {
                                        // Extract rows back from generated JSON before importing.
                                        $decodedJson = json_decode((string)file_get_contents($jsonPath), true);
                                        $rowsFromJson = [];
                                        if (is_array($decodedJson) && isset($decodedJson['rows']) && is_array($decodedJson['rows'])) {
                                            $rowsFromJson = $decodedJson['rows'];
                                        }

                                        $rowCount = count($rowsFromJson);
                                        $processedCount = 0;
                                        $enrolledCount = 0;
                                        $alreadyEnrolledCount = 0;
                                        $missingEmailCount = 0;
                                        $invalidRowCount = 0;
                                        $missingEmails = [];

                                        $findStudentStmt = $conn->prepare("SELECT student_id FROM Student WHERE email = ? LIMIT 1");
                                        $checkEnrollmentStmt = $conn->prepare("SELECT enrollment_id FROM EnrollmentInCourses WHERE student_id = ? AND course_id = ? LIMIT 1");
                                        $insertEnrollmentStmt = $conn->prepare("INSERT INTO EnrollmentInCourses (student_id, course_id, status) VALUES (?, ?, 'active')");

                                        if ($findStudentStmt === false || $checkEnrollmentStmt === false || $insertEnrollmentStmt === false) {
                                            $message = "Could not prepare statements for roster import.";
                                            $messageType = "error";
                                            if ($findStudentStmt) {
                                                $findStudentStmt->close();
                                            }
                                            if ($checkEnrollmentStmt) {
                                                $checkEnrollmentStmt->close();
                                            }
                                            if ($insertEnrollmentStmt) {
                                                $insertEnrollmentStmt->close();
                                            }
                                        } else {
                                            foreach ($rowsFromJson as $csvRow) {
                                                if (!is_array($csvRow)) {
                                                    continue;
                                                }

                                                $processedCount++;
                                                $email = strtolower(getCsvValueByKeys($csvRow, ['email', 'student_email', 'studentemail', 'mail', 'e_mail']));
                                                if ($email === '') {
                                                    $invalidRowCount++;
                                                    continue;
                                                }

                                                $findStudentStmt->bind_param('s', $email);
                                                $findStudentStmt->execute();
                                                $student = $findStudentStmt->get_result()->fetch_assoc();

                                                if (!$student) {
                                                    $missingEmailCount++;
                                                    if (count($missingEmails) < 10) {
                                                        $missingEmails[] = $email;
                                                    }
                                                    continue;
                                                }

                                                $studentId = (int)$student['student_id'];
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
                                                }
                                            }

                                            $findStudentStmt->close();
                                            $checkEnrollmentStmt->close();
                                            $insertEnrollmentStmt->close();

                                            $uploadSummary = [
                                                'rowCount' => $rowCount,
                                                'processedCount' => $processedCount,
                                                'enrolledCount' => $enrolledCount,
                                                'alreadyEnrolledCount' => $alreadyEnrolledCount,
                                                'missingEmailCount' => $missingEmailCount,
                                                'invalidRowCount' => $invalidRowCount,
                                                'missingEmails' => $missingEmails,
                                            ];

                                            $lastUploadPreview = array_slice($rowsFromJson, 0, 10);
                                            $lastUploadJsonName = $jsonFileName;
                                            $lastUploadJsonUrl = 'uploads/rosters/' . rawurlencode($jsonFileName);

                                            $message = "CSV parsed and JSON generated. Imported "
                                                . $enrolledCount
                                                . " student(s), "
                                                . $alreadyEnrolledCount
                                                . " already enrolled, "
                                                . $missingEmailCount
                                                . " email(s) not found.";
                                            $messageType = "success";
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // FETCH UPLOADED ROSTER JSON FILES
    $uploadDir = __DIR__ . '/uploads/rosters';
    if (is_dir($uploadDir)) {
        $jsonFiles = glob($uploadDir . '/*.json');
        if (is_array($jsonFiles)) {
            usort($jsonFiles, function ($a, $b) {
                return filemtime($b) <=> filemtime($a);
            });

            foreach (array_slice($jsonFiles, 0, 20) as $jsonFile) {
                $baseName = basename($jsonFile);
                $uploadedRosterFiles[] = [
                    'name' => $baseName,
                    'url' => 'uploads/rosters/' . rawurlencode($baseName),
                    'modified' => date('M j, Y g:i A', (int)filemtime($jsonFile)),
                ];
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
    $studentQuery = $conn->query("SELECT student_id, first_name, last_name, email, year FROM Student ORDER BY last_name, first_name");
    while ($row = $studentQuery->fetch_assoc()) {
        $students[] = $row;
    }

    // FETCH ENROLLMENTS for roster view
    if ($selectedCourseId) {
        $enrollQuery = $conn->prepare("
            SELECT e.enrollment_id, e.status, s.student_id, s.first_name, s.last_name, s.email, s.year
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

            <div class="uploaded-rosters">
                <h3>Uploaded Rosters (JSON)</h3>
                <?php if (empty($uploadedRosterFiles)): ?>
                <p class="uploaded-empty">No roster uploads yet.</p>
                <?php else: ?>
                <ul class="roster-file-list">
                    <?php foreach ($uploadedRosterFiles as $rosterFile): ?>
                    <li>
                        <a href="<?php echo htmlspecialchars($rosterFile['url']); ?>" target="_blank" rel="noopener">
                            <i class="fas fa-file-code"></i>
                            <?php echo htmlspecialchars($rosterFile['name']); ?>
                        </a>
                        <span><?php echo htmlspecialchars($rosterFile['modified']); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <?php if ($selectedCourseId): ?>
            <div class="upload-card">
                <div class="upload-card-header">
                    <h3>Upload CSV Roster</h3>
                    <?php if ($lastUploadJsonUrl): ?>
                    <a class="json-link" href="<?php echo htmlspecialchars($lastUploadJsonUrl); ?>" target="_blank" rel="noopener">
                        <i class="fas fa-file-arrow-down"></i>
                        View Generated JSON
                    </a>
                    <?php endif; ?>
                </div>

                <form method="POST" enctype="multipart/form-data" class="upload-form">
                    <input type="hidden" name="action" value="upload_roster_csv">
                    <input type="hidden" name="course_id" value="<?php echo $selectedCourseId; ?>">

                    <input type="file" name="roster_csv" accept=".csv,text/csv" required>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-upload"></i> Upload CSV
                    </button>
                </form>
                <p class="upload-hint">Required column: email. Optional columns: first_name, last_name, year.</p>
            </div>

            <?php if ($uploadSummary): ?>
            <div class="upload-summary">
                <h4>Last Upload Summary</h4>
                <div class="summary-grid">
                    <div>
                        <strong><?php echo (int)$uploadSummary['rowCount']; ?></strong>
                        <span>Rows Parsed</span>
                    </div>
                    <div>
                        <strong><?php echo (int)$uploadSummary['enrolledCount']; ?></strong>
                        <span>New Enrollments</span>
                    </div>
                    <div>
                        <strong><?php echo (int)$uploadSummary['alreadyEnrolledCount']; ?></strong>
                        <span>Already Enrolled</span>
                    </div>
                    <div>
                        <strong><?php echo (int)$uploadSummary['missingEmailCount']; ?></strong>
                        <span>Emails Not Found</span>
                    </div>
                </div>

                <?php if (!empty($uploadSummary['missingEmails'])): ?>
                <p class="summary-note">
                    <strong>Sample missing emails:</strong>
                    <?php echo htmlspecialchars(implode(', ', $uploadSummary['missingEmails'])); ?>
                    <?php if ((int)$uploadSummary['missingEmailCount'] > count($uploadSummary['missingEmails'])): ?>
                        ... and <?php echo (int)$uploadSummary['missingEmailCount'] - count($uploadSummary['missingEmails']); ?> more.
                    <?php endif; ?>
                </p>
                <?php endif; ?>

                <?php if (!empty($lastUploadJsonName)): ?>
                <p class="summary-note">
                    <strong>JSON File:</strong> <?php echo htmlspecialchars($lastUploadJsonName); ?>
                </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($lastUploadPreview)): ?>
            <div class="parsed-preview">
                <h4>Parsed JSON Preview (First <?php echo count($lastUploadPreview); ?> rows)</h4>
                <table class="roster-table">
                    <thead>
                        <tr>
                            <th>Row</th>
                            <th>Email</th>
                            <th>First Name</th>
                            <th>Last Name</th>
                            <th>Year</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lastUploadPreview as $csvRow): ?>
                        <tr>
                            <td><?php echo isset($csvRow['_row_number']) ? (int)$csvRow['_row_number'] : 0; ?></td>
                            <td><?php echo htmlspecialchars(getCsvValueByKeys($csvRow, ['email', 'student_email', 'studentemail', 'mail', 'e_mail'])); ?></td>
                            <td><?php echo htmlspecialchars(getCsvValueByKeys($csvRow, ['first_name', 'firstname', 'given_name'])); ?></td>
                            <td><?php echo htmlspecialchars(getCsvValueByKeys($csvRow, ['last_name', 'lastname', 'surname', 'family_name'])); ?></td>
                            <td><?php echo htmlspecialchars(getCsvValueByKeys($csvRow, ['year', 'class_year', 'classyear'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

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
                            <th>Year</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrollments as $enrollment): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($enrollment['first_name'] . ' ' . $enrollment['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($enrollment['email']); ?></td>
                            <td><?php echo htmlspecialchars($enrollment['year']); ?></td>
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
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.classList.remove('active');
        }
    }
    </script>
</body>
</html>
