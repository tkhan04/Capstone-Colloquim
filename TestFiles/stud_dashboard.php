<?php
/**
 * STUD_DASHBOARD.PHP - Student Attendance Dashboard
 *
 * Shows: stats (total attended, rate, upcoming), two tabs:
 *   Upcoming Events | Attendance History
 *
 * DB schema used (exact):
 *   Student(student_id, fname, lname, email, year)
 *   EnrollmentInCourses(student_id, course_id, status)
 *   Course(course_id, minimum_events_required)
 *   Event(event_id, event_name, event_type, start_time, end_time, location)
 *   AttendsEventSessions(student_id PK, event_id PK, start_scan_time,
 *                        end_scan_time, minutes_present, audit_note)
 */

session_start();
require __DIR__ . '/db.php';

$studentId = (int)($_GET['student_id'] ?? 1);
$activeTab = $_GET['tab'] ?? 'upcoming';
$dbError   = '';

$student          = null;
$upcomingEvents   = [];
$attendanceHistory = [];
$totalAttended    = 0;
$totalRequired    = 0;
$upcomingCount    = 0;

try {
    $pdo = getDB();

    // ── Student info ──────────────────────────────────────────────────────────
    $stmt = $pdo->prepare(
        "SELECT student_id, fname, lname, email, year FROM Student WHERE student_id = ?"
    );
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    if ($student) {
        // ── Minimum events required (max across all enrolled courses) ─────────
        $stmt = $pdo->prepare(
            "SELECT MAX(c.minimum_events_required)
             FROM Course c
             JOIN EnrollmentInCourses e ON c.course_id = e.course_id
             WHERE e.student_id = ? AND e.status = 'active'"
        );
        $stmt->execute([$studentId]);
        $totalRequired = (int)($stmt->fetchColumn() ?: 0);

        // ── Count completed attendances (both timestamps present) ─────────────
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM AttendsEventSessions
             WHERE student_id = ? AND end_scan_time IS NOT NULL"
        );
        $stmt->execute([$studentId]);
        $totalAttended = (int)$stmt->fetchColumn();

        // ── Upcoming events ───────────────────────────────────────────────────
        $now  = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            "SELECT event_id, event_name, event_type, start_time, end_time, location
             FROM Event
             WHERE start_time > ?
             ORDER BY start_time ASC
             LIMIT 10"
        );
        $stmt->execute([$now]);
        $upcomingEvents = $stmt->fetchAll();
        $upcomingCount  = count($upcomingEvents);

        // ── Attendance history ────────────────────────────────────────────────
        $stmt = $pdo->prepare(
            "SELECT ev.event_id, ev.event_name, ev.event_type, ev.start_time, ev.end_time,
                    ev.location, a.start_scan_time, a.end_scan_time, a.minutes_present, a.audit_note
             FROM AttendsEventSessions a
             JOIN Event ev ON a.event_id = ev.event_id
             WHERE a.student_id = ?
             ORDER BY ev.start_time DESC"
        );
        $stmt->execute([$studentId]);
        $attendanceHistory = $stmt->fetchAll();
    }

} catch (PDOException $e) {
    $dbError = 'Database error: ' . $e->getMessage();
}

// Attendance rate percentage (capped at 100%)
$attendanceRate = $totalRequired > 0
    ? min(100, (int)round($totalAttended / $totalRequired * 100))
    : ($totalAttended > 0 ? 100 : 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Colloquium Attendance – Student Dashboard</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Progress bar for attendance rate — matches SRS mockup */
        .rate-bar { height:8px; background:#eee; border-radius:4px; margin-top:.5rem; }
        .rate-bar-inner { height:8px; border-radius:4px; background:#003366; transition:width .4s; }
    </style>
</head>
<body class="dashboard-page">

    <nav class="dashboard-nav">
        <div class="nav-brand">
            <img src="gburglogo.jpg" alt="Gettysburg College" style="height:32px;width:auto;margin-right:.5rem;">
            <span>Colloquium Attendance</span>
        </div>
        <div class="nav-user">
            <?php if ($student): ?>
            <span>Student ID <?= $studentId ?></span>
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
        <div class="db-error"><i class="fas fa-database"></i> <?= htmlspecialchars($dbError) ?></div>
        <?php else: ?>

        <!-- Stats cards — matches SRS student dashboard mockup -->
        <div class="stats-grid student-stats">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Total Attended</span>
                    <span class="stat-value">
                        <?= $totalAttended ?>
                        <small>Out of <?= $totalRequired ?: '?' ?> events</small>
                    </span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon <?= $attendanceRate >= 80 ? 'green' : ($attendanceRate >= 50 ? 'orange' : 'red') ?>">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Attendance Rate</span>
                    <span class="stat-value"><?= $attendanceRate ?>%</span>
                    <div class="rate-bar">
                        <div class="rate-bar-inner" style="width:<?= $attendanceRate ?>%"></div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-calendar"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Upcoming Events</span>
                    <span class="stat-value"><?= $upcomingCount ?> <small>Don't miss out!</small></span>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tab-navigation">
            <a href="?student_id=<?= $studentId ?>&tab=upcoming"
               class="tab-link <?= $activeTab === 'upcoming' ? 'active' : '' ?>">
                <i class="fas fa-calendar-alt"></i> Upcoming Events
            </a>
            <a href="?student_id=<?= $studentId ?>&tab=history"
               class="tab-link <?= $activeTab === 'history' ? 'active' : '' ?>">
                <i class="fas fa-history"></i> Attendance History
            </a>
        </div>

        <!-- ── UPCOMING EVENTS TAB ── -->
        <?php if ($activeTab === 'upcoming'): ?>
        <section class="events-section">
            <?php if (empty($upcomingEvents)): ?>
            <p class="no-data"><i class="fas fa-calendar-times"></i> No upcoming events right now. Check back soon!</p>
            <?php else: ?>
            <div class="event-cards">
                <?php foreach ($upcomingEvents as $ev): ?>
                <div class="event-card">
                    <div class="event-type-badge"><?= htmlspecialchars($ev['event_type']) ?></div>
                    <h3 class="event-title"><?= htmlspecialchars($ev['event_name']) ?></h3>
                    <div class="event-meta">
                        <p><i class="fas fa-calendar"></i> <?= date('D, M j', strtotime($ev['start_time'])) ?></p>
                        <p><i class="fas fa-clock"></i>
                           <?= date('g:i A', strtotime($ev['start_time'])) ?> –
                           <?= date('g:i A', strtotime($ev['end_time'])) ?>
                        </p>
                        <?php if ($ev['location']): ?>
                        <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($ev['location']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <!-- ── HISTORY TAB ── -->
        <?php else: ?>
        <section class="history-section">
            <?php if (empty($attendanceHistory)): ?>
            <p class="no-data"><i class="fas fa-history"></i> No attendance records yet. Attend an event and check back!</p>
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
                            <th>Duration</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendanceHistory as $rec): ?>
                        <tr>
                            <td><?= htmlspecialchars($rec['event_name']) ?></td>
                            <td><span class="type-badge"><?= htmlspecialchars($rec['event_type']) ?></span></td>
                            <td><?= date('M j, Y', strtotime($rec['start_time'])) ?></td>
                            <td><?= $rec['start_scan_time'] ? date('g:i A', strtotime($rec['start_scan_time'])) : '—' ?></td>
                            <td><?= $rec['end_scan_time']   ? date('g:i A', strtotime($rec['end_scan_time']))   : '—' ?></td>
                            <td>
                                <?php if ($rec['minutes_present'] !== null): ?>
                                    <?= $rec['minutes_present'] ?> min
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <?php if ($rec['end_scan_time']): ?>
                                <span class="status-badge complete"><i class="fas fa-check"></i> Complete</span>
                                <?php elseif ($rec['start_scan_time']): ?>
                                <span class="status-badge partial"><i class="fas fa-clock"></i> Partial</span>
                                <?php else: ?>
                                <span class="status-badge incomplete"><i class="fas fa-times"></i> Incomplete</span>
                                <?php endif; ?>

                                <!-- Show audit note if an override was recorded -->
                                <?php if ($rec['audit_note']): ?>
                                <span title="Override: <?= htmlspecialchars($rec['audit_note']) ?>"
                                      style="color:#888;font-size:.8rem;margin-left:.25rem;">
                                    <i class="fas fa-pen"></i>
                                </span>
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

        <?php endif; // end !$dbError ?>
    </main>
</body>
</html>