<?php
/**
 * STUD_DASHBOARD.PHP - Student Dashboard for Colloquium Attendance
 * 
 * This file displays the student's personal attendance dashboard with:
 * - Statistics: Student ID, total events attended, attendance rate, upcoming events
 * - Two tabs: "Upcoming Events" and "Attendance History"
 * - Upcoming Events tab shows future colloquium events
 * - History tab shows all past attendance records with sign-in/out times
 * 
 * Database Tables Used:
 * - Student: To get student's personal information
 * - Course & EnrollmentInCourses: To determine minimum required events
 * - Event: To list upcoming events
 * - AttendsEventSessions: To track attendance history
 */

session_start();  // Start session for potential future authentication
require __DIR__ . '/../secrets/db.php';

/**
 * DATABASE CONNECTION
 * Establishes connection to MySQL database using credentials from db.php
 */
$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$dbError = null;

/**
 * GET PARAMETERS FROM URL
 * student_id: Which student's dashboard to display (default: 1 for demo)
 * tab: Which tab is active - 'upcoming' (default) or 'history'
 * In production, student_id would come from session after login
 */
$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 1;
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'upcoming';

// Initialize variables to store data
$student = null;          // Student's personal info
$upcomingEvents = [];     // Array of future events
$attendanceHistory = [];  // Array of past attendance records
$totalAttended = 0;       // Count of completed attendances
$totalRequired = 5;       // Minimum events required (default, overwritten by course setting)
$upcomingCount = 0;       // Number of upcoming events

/**
 * CHECK DATABASE CONNECTION AND FETCH DATA
 * Only proceeds if connection is successful
 */
if ($conn->connect_error) {
    $dbError = "Database connection failed: " . $conn->connect_error;
} else {
    /**
     * FETCH STUDENT INFORMATION
     * Retrieves the student's name, email, and year from Student table
     * Uses prepared statement to prevent SQL injection
     */
    $studentQuery = $conn->prepare("SELECT student_id, first_name, last_name, email, year FROM Student WHERE student_id = ?");
    $studentQuery->bind_param('i', $studentId);  // 'i' = integer parameter
    $studentQuery->execute();
    $student = $studentQuery->get_result()->fetch_assoc();
    $studentQuery->close();

    if ($student) {
        /**
         * GET MINIMUM EVENTS REQUIRED FOR THIS STUDENT
         * Looks at all courses the student is enrolled in
         * Takes the maximum requirement if enrolled in multiple courses
         * Uses MAX() to get the highest requirement
         */
        $reqQuery = $conn->prepare("
            SELECT MAX(c.minimum_events_required) as min_req 
            FROM Course c
            JOIN EnrollmentInCourses e ON c.course_id = e.course_id
            WHERE e.student_id = ? AND e.status = 'active'
        ");
        $reqQuery->bind_param('i', $studentId);
        $reqQuery->execute();
        $reqResult = $reqQuery->get_result()->fetch_assoc();
        if ($reqResult && $reqResult['min_req']) {
            $totalRequired = $reqResult['min_req'];
        }
        $reqQuery->close();

        /**
         * COUNT TOTAL COMPLETED ATTENDANCES
         * Only counts records where end_scan_time is NOT NULL
         * This means student both signed in AND signed out
         * Partial attendances (sign-in only) don't count toward requirement
         */
        $attendedQuery = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM AttendsEventSessions 
            WHERE student_id = ? AND end_scan_time IS NOT NULL
        ");
        $attendedQuery->bind_param('i', $studentId);
        $attendedQuery->execute();
        $totalAttended = $attendedQuery->get_result()->fetch_assoc()['count'];
        $attendedQuery->close();

        /**
         * FETCH UPCOMING EVENTS
         * Gets future events (start_time > current time)
         * Orders by start_time so nearest events appear first
         * Limits to 10 events to avoid overwhelming the display
         */
        $now = date('Y-m-d H:i:s');
        $upcomingQuery = $conn->prepare("
            SELECT e.event_id, e.event_name, e.event_type, e.start_time, e.end_time, e.location
            FROM Event e
            WHERE e.start_time > ?
            ORDER BY e.start_time ASC
            LIMIT 10
        ");
        $upcomingQuery->bind_param('s', $now);  // 's' = string (datetime)
        $upcomingQuery->execute();
        $upcomingResult = $upcomingQuery->get_result();
        
        // Build array of upcoming events
        while ($event = $upcomingResult->fetch_assoc()) {
            $upcomingEvents[] = $event;
        }
        $upcomingCount = count($upcomingEvents);
        $upcomingQuery->close();

        /**
         * FETCH ATTENDANCE HISTORY
         * Joins AttendsEventSessions with Event table
         * Gets all attendance records for this student
         * Includes both complete and partial attendances
         * Orders by event date descending (most recent first)
         */
        $historyQuery = $conn->prepare("
            SELECT e.event_id, e.event_name, e.event_type, e.start_time, e.end_time, e.location,
                   a.start_scan_time, a.end_scan_time, a.source
            FROM AttendsEventSessions a
            JOIN Event e ON a.event_id = e.event_id
            WHERE a.student_id = ?
            ORDER BY e.start_time DESC
        ");
        $historyQuery->bind_param('i', $studentId);
        $historyQuery->execute();
        $historyResult = $historyQuery->get_result();
        
        // Build array of attendance history records
        while ($record = $historyResult->fetch_assoc()) {
            $attendanceHistory[] = $record;
        }
        $historyQuery->close();
    }
}

/**
 * CALCULATE ATTENDANCE RATE PERCENTAGE
 * Formula: (events attended / events required) * 100
 * Caps at 100% even if student attends more than required
 * Handles division by zero if totalRequired is 0
 */
$attendanceRate = $totalRequired > 0 ? round(($totalAttended / $totalRequired) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Colloquium Attendance - Student Dashboard</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="dashboard-page">
    <nav class="dashboard-nav">
        <div class="nav-brand">
            <i class="fas fa-graduation-cap"></i>
            <span>Colloquium Attendance</span>
        </div>
        <div class="nav-user">
            <?php if ($student): ?>
            <span><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></span>
            <?php endif; ?>
            <a href="index.html" class="btn-logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </nav>

    <main class="dashboard-main">
        <header class="dashboard-header">
            <h1><i class="fas fa-calendar-check"></i> Colloquium Attendance</h1>
            <p class="subtitle">Track and manage your event attendance</p>
        </header>

        <?php if ($dbError): ?>
        <div class="db-error">
            <i class="fas fa-database"></i>
            <?php echo htmlspecialchars($dbError); ?>
        </div>
        <?php else: ?>

        <!-- Stats Cards -->
        <div class="stats-grid student-stats">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-id-card"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Student ID</span>
                    <span class="stat-value"><?php echo $studentId; ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Total Attended</span>
                    <span class="stat-value"><?php echo $totalAttended; ?> <small>Out of <?php echo $totalRequired; ?> events</small></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon <?php echo $attendanceRate >= 80 ? 'green' : ($attendanceRate >= 50 ? 'orange' : 'red'); ?>">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Attendance Rate</span>
                    <span class="stat-value"><?php echo $attendanceRate; ?>%</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-calendar"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Upcoming Events</span>
                    <span class="stat-value"><?php echo $upcomingCount; ?> <small>Don't miss out!</small></span>
                </div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="tab-navigation">
            <a href="?student_id=<?php echo $studentId; ?>&tab=upcoming" 
               class="tab-link <?php echo $activeTab === 'upcoming' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i> Upcoming Events
            </a>
            <a href="?student_id=<?php echo $studentId; ?>&tab=history" 
               class="tab-link <?php echo $activeTab === 'history' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Attendance History
            </a>
        </div>

        <!-- Tab Content -->
        <?php if ($activeTab === 'upcoming'): ?>
        <section class="events-section">
            <?php if (empty($upcomingEvents)): ?>
            <p class="no-data"><i class="fas fa-calendar-times"></i> No upcoming events scheduled.</p>
            <?php else: ?>
            <div class="event-cards">
                <?php foreach ($upcomingEvents as $event): ?>
                <div class="event-card">
                    <div class="event-type-badge"><?php echo htmlspecialchars($event['event_type']); ?></div>
                    <h3 class="event-title"><?php echo htmlspecialchars($event['event_name']); ?></h3>
                    <div class="event-meta">
                        <p><i class="fas fa-calendar"></i> <?php echo date('D, M j', strtotime($event['start_time'])); ?></p>
                        <p><i class="fas fa-clock"></i> <?php echo date('g:i A', strtotime($event['start_time'])); ?> - <?php echo date('g:i A', strtotime($event['end_time'])); ?></p>
                        <?php if ($event['location']): ?>
                        <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($event['location']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <?php else: ?>
        <!-- History Tab -->
        <section class="history-section">
            <?php if (empty($attendanceHistory)): ?>
            <p class="no-data"><i class="fas fa-history"></i> No attendance records yet.</p>
            <?php else: ?>
            <div class="table-container">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Sign In</th>
                            <th>Sign Out</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendanceHistory as $record): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($record['event_name']); ?></td>
                            <td><span class="type-badge"><?php echo htmlspecialchars($record['event_type']); ?></span></td>
                            <td><?php echo date('M j, Y', strtotime($record['start_time'])); ?></td>
                            <td><?php echo $record['start_scan_time'] ? date('g:i A', strtotime($record['start_scan_time'])) : '-'; ?></td>
                            <td><?php echo $record['end_scan_time'] ? date('g:i A', strtotime($record['end_scan_time'])) : '-'; ?></td>
                            <td>
                                <?php if ($record['end_scan_time']): ?>
                                <span class="status-badge complete"><i class="fas fa-check"></i> Complete</span>
                                <?php elseif ($record['start_scan_time']): ?>
                                <span class="status-badge partial"><i class="fas fa-clock"></i> Partial</span>
                                <?php else: ?>
                                <span class="status-badge incomplete"><i class="fas fa-times"></i> Incomplete</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php endif; ?>
    </main>
</body>
</html>
