<?php
/**
 * PROF_DASHBOARD.PHP - Professor Dashboard for Colloquium Attendance
 * 
 * This file displays the professor's dashboard where they can:
 * - View all courses they teach
 * - See statistics (total classes, students, events)
 * - Click on a course to view enrolled students and their attendance status
 * 
 * Database Tables Used:
 * - Professor: To get professor information
 * - Course: To get courses taught by the professor
 * - CourseAssignment: Links professors to courses
 * - EnrollmentInCourses: To count students per course
 * - Student: To get student details
 * - AttendsEventSessions: To count events attended by each student
 * - Event: To get total event count
 */

session_start();  // Start session for potential future authentication
$dbConfigPath = __DIR__ . '/../secrets/db.php';
if (!file_exists($dbConfigPath)) {
    $dbConfigPath = __DIR__ . '/../secrets/db.php.example';
}
require $dbConfigPath;

/**
 * DATABASE CONNECTION
 * Establishes connection to MySQL database using credentials from db.php
 */
$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$dbError = null;

/**
 * GET PROFESSOR ID FROM URL
 * For demo/testing, accepts prof_id as URL parameter
 * In production, this would come from session after login
 * Default: professor_id = 1
 */
$professorId = isset($_GET['prof_id']) ? (int)$_GET['prof_id'] : 1;

// Initialize variables to store data
$professor = null;       // Professor's personal info
$courses = [];           // Array of courses taught by this professor
$totalStudents = 0;      // Sum of all students across all courses
$totalEvents = 0;        // Total number of events in the system
$selectedCourse = null;  // Course clicked by user (if any)
$courseStudents = [];    // Students enrolled in selected course

/**
 * CHECK DATABASE CONNECTION AND FETCH DATA
 * Only proceeds if connection is successful
 */
if ($conn->connect_error) {
    $dbError = "Database connection failed: " . $conn->connect_error;
} else {
    /**
     * FETCH PROFESSOR INFORMATION
     * Retrieves the professor's name and email from Professor table
     * Uses prepared statement to prevent SQL injection
     */
    $profQuery = $conn->prepare("SELECT prof_id, name, email FROM professor WHERE prof_id = ?");
    $profQuery->bind_param('i', $professorId);  // 'i' = integer parameter
    $profQuery->execute();
    $professor = $profQuery->get_result()->fetch_assoc();
    $profQuery->close();

    if ($professor) {
        /**
         * FETCH ALL COURSES TAUGHT BY THIS PROFESSOR
         * Joins Course and CourseAssignment tables to find courses
         * Also includes a subquery to count active students in each course
         */
        $courseQuery = $conn->prepare("
            SELECT c.course_id, c.course_name, c.course_code, c.section, c.semester, 
                   c.minimum_events_required,
                   (SELECT COUNT(*) FROM enrollment e WHERE e.course_id = c.course_id AND e.status = 'active') as student_count
            FROM course c
            JOIN course_assignment ca ON c.course_id = ca.course_id
            WHERE ca.professor_id = ?
            ORDER BY c.course_code
        ");
        $courseQuery->bind_param('i', $professorId);
        $courseQuery->execute();
        $coursesResult = $courseQuery->get_result();
        
        /**
         * PROCESS EACH COURSE
         * For each course, also get the event count
         * Accumulate total student count across all courses
         */
        while ($course = $coursesResult->fetch_assoc()) {
            // Get total event count (in production, filter by course's permitted event types)
            $eventCountQuery = $conn->query("SELECT COUNT(*) as count FROM event");
            $eventCount = $eventCountQuery->fetch_assoc()['count'];
            $course['event_count'] = $eventCount;
            
            $courses[] = $course;
            $totalStudents += $course['student_count'];
        }
        $courseQuery->close();
        
        /**
         * GET TOTAL EVENT COUNT
         * Counts all events in the Event table for dashboard statistics
         */
        $eventQuery = $conn->query("SELECT COUNT(*) as total FROM event");
        $totalEvents = $eventQuery->fetch_assoc()['total'];
    }

    /**
     * HANDLE COURSE SELECTION
     * If user clicked on a course card, fetch detailed student information
     */
    if (isset($_GET['course_id'])) {
        $selectedCourseId = (int)$_GET['course_id'];
        
        // Find the selected course in our already-fetched courses array
        foreach ($courses as $c) {
            if ($c['course_id'] == $selectedCourseId) {
                $selectedCourse = $c;
                break;
            }
        }
        
        if ($selectedCourse) {
            /**
             * FETCH STUDENTS IN SELECTED COURSE WITH ATTENDANCE DATA
             * Joins Student and EnrollmentInCourses tables
             * Subquery counts completed attendances (where end_scan_time exists)
             * Only includes actively enrolled students
             */
            $studentQuery = $conn->prepare("
                SELECT s.student_id, s.name, s.email, s.year,
                       (SELECT COUNT(*) FROM attendance_session a WHERE a.student_id = s.student_id AND a.end_scan_time IS NOT NULL) as events_attended
                FROM student s
                JOIN enrollment e ON s.student_id = e.student_id
                WHERE e.course_id = ? AND e.status = 'active'
                ORDER BY s.name
            ");
            $studentQuery->bind_param('i', $selectedCourseId);
            $studentQuery->execute();
            $studentResult = $studentQuery->get_result();
            
            /**
             * PROCESS STUDENT DATA
             * Calculate if each student meets the course's minimum attendance requirement
             */
            while ($student = $studentResult->fetch_assoc()) {
                // Compare events attended vs minimum required for the course
                $student['meets_requirement'] = $student['events_attended'] >= $selectedCourse['minimum_events_required'];
                $courseStudents[] = $student;
            }
            $studentQuery->close();
        }
    }
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
</head>
<body class="dashboard-page">
    <nav class="dashboard-nav">
        <div class="nav-brand">
            <i class="fas fa-graduation-cap"></i>
            <span>Colloquium</span>
        </div>
        <div class="nav-user">
            <?php if ($professor): ?>
            <span><?php echo htmlspecialchars($professor['first_name'] . ' ' . $professor['last_name']); ?></span>
            <?php endif; ?>
            <a href="index.html" class="btn-logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </nav>

    <main class="dashboard-main">
        <header class="dashboard-header">
            <h1><i class="fas fa-chalkboard-teacher"></i> Professor Dashboard</h1>
            <p class="subtitle">Select a class to view student attendance</p>
        </header>

        <?php if ($dbError): ?>
        <div class="db-error">
            <i class="fas fa-database"></i>
            <?php echo htmlspecialchars($dbError); ?>
        </div>
        <?php else: ?>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-book"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Total Classes</span>
                    <span class="stat-value"><?php echo count($courses); ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Total Students</span>
                    <span class="stat-value"><?php echo $totalStudents; ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-calendar-alt"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Total Events</span>
                    <span class="stat-value"><?php echo $totalEvents; ?></span>
                </div>
            </div>
        </div>

        <!-- Course List -->
        <section class="courses-section">
            <h2>Your Classes</h2>
            <div class="course-grid">
                <?php foreach ($courses as $course): ?>
                <a href="?prof_id=<?php echo $professorId; ?>&course_id=<?php echo $course['course_id']; ?>" 
                   class="course-card <?php echo ($selectedCourse && $selectedCourse['course_id'] == $course['course_id']) ? 'selected' : ''; ?>">
                    <div class="course-header">
                        <span class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></span>
                        <span class="course-semester"><?php echo htmlspecialchars($course['semester']); ?></span>
                    </div>
                    <h3 class="course-name"><?php echo htmlspecialchars($course['course_name']); ?></h3>
                    <div class="course-stats">
                        <span><i class="fas fa-user-graduate"></i> Students <?php echo $course['student_count']; ?></span>
                        <span><i class="fas fa-calendar-check"></i> Events <?php echo $course['event_count']; ?></span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Student List (if course selected) -->
        <?php if ($selectedCourse && !empty($courseStudents)): ?>
        <section class="students-section">
            <h2><?php echo htmlspecialchars($selectedCourse['course_code']); ?> - Student Attendance</h2>
            <p class="requirement-note">
                <i class="fas fa-info-circle"></i> 
                Minimum events required: <?php echo $selectedCourse['minimum_events_required']; ?>
            </p>
            
            <div class="table-container">
                <table class="students-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Email</th>
                            <th>Year</th>
                            <th>Events Attended</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courseStudents as $student): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($student['email']); ?></td>
                            <td><?php echo htmlspecialchars($student['year']); ?></td>
                            <td>
                                <span class="attendance-count"><?php echo $student['events_attended']; ?></span>
                                / <?php echo $selectedCourse['minimum_events_required']; ?>
                            </td>
                            <td>
                                <?php if ($student['meets_requirement']): ?>
                                <span class="status-badge complete"><i class="fas fa-check"></i> Complete</span>
                                <?php else: ?>
                                <span class="status-badge incomplete"><i class="fas fa-clock"></i> In Progress</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php elseif ($selectedCourse): ?>
        <section class="students-section">
            <h2><?php echo htmlspecialchars($selectedCourse['course_code']); ?> - Student Attendance</h2>
            <p class="no-data"><i class="fas fa-user-slash"></i> No students enrolled in this course.</p>
        </section>
        <?php endif; ?>

        <?php endif; ?>
    </main>
</body>
</html>
