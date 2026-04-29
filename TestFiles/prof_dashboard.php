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
        $pdo            = getDB();
        $stuId          = (int)$_POST['student_id'];
        $evId           = (int)$_POST['event_id'];
        $courseId       = trim($_POST['course_id'] ?? $selectedCourseId);
        $overrideStatus = $_POST['override_status'] ?? 'present';
        $note           = trim($_POST['audit_note'] ?? '');

        if ($overrideStatus === 'absent') {
            // Remove the attendance record entirely (no credit)
            $pdo->prepare(
                "DELETE FROM AttendsEventSessions WHERE student_id=? AND event_id=? AND course_id=?"
            )->execute([$stuId, $evId, $courseId]);
            $_SESSION['flash_message'] = 'Attendance removed — student marked absent.';
            $_SESSION['flash_type']    = 'success';
        } else {
            // Present — use supplied times or default to event start/end
            $evInfo = $pdo->prepare("SELECT start_time, end_time FROM Event WHERE event_id=? LIMIT 1");
            $evInfo->execute([$evId]);
            $evRow = $evInfo->fetch();

            $startTime = ($_POST['start_scan_time'] ?? '') !== ''
                ? $_POST['start_scan_time'] : ($evRow['start_time'] ?? null);
            $endTime   = ($_POST['end_scan_time'] ?? '') !== ''
                ? $_POST['end_scan_time']   : ($evRow['end_time']   ?? null);

            // Upsert using composite PK: student_id + event_id + course_id
            $exists = $pdo->prepare(
                "SELECT 1 FROM AttendsEventSessions WHERE student_id=? AND event_id=? AND course_id=?"
            );
            $exists->execute([$stuId, $evId, $courseId]);

            if ($exists->fetch()) {
                $pdo->prepare(
                    "UPDATE AttendsEventSessions
                     SET start_scan_time=?, end_scan_time=?, audit_note=?, overridden_by=?
                     WHERE student_id=? AND event_id=? AND course_id=?"
                )->execute([$startTime, $endTime, $note, $profId,
                            $stuId, $evId, $courseId]);
            } else {
                $pdo->prepare(
                    "INSERT INTO AttendsEventSessions
                         (student_id, event_id, course_id, start_scan_time, end_scan_time, audit_note, overridden_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                )->execute([$stuId, $evId, $courseId, $startTime, $endTime, $note, $profId]);
            }
            $_SESSION['flash_message'] = 'Attendance override saved — student marked present.';
            $_SESSION['flash_type']    = 'success';
        }
        header("Location: ?prof_id={$profId}&course_id=" . urlencode($courseId));
        exit;
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
        $startTime = $_POST['checkin_time'] ?? date('Y-m-d H:i:s');
        $note = trim($_POST['audit_note'] ?? 'Hackathon check-in by professor');

        // Verify event is a hackathon AND has not yet ended
        $nowCheck   = date('Y-m-d H:i:s');
        $eventCheck = $pdo->prepare(
            "SELECT event_type, start_time, end_time FROM Event WHERE event_id = ? LIMIT 1"
        );
        $eventCheck->execute([$eventId]);
        $event = $eventCheck->fetch();

        if (!$event || strtolower($event['event_type']) !== 'hackathon') {
            $dbError = 'Selected event is not a hackathon.';
        } elseif ($nowCheck > $event['end_time']) {
            // Hackathon already ended — check-in not allowed
            $dbError = 'This hackathon has already ended. Check-in is no longer available.';
        } else {
            $eventStartTime = $event['start_time'];
            $eventEndTime   = $event['end_time'];

            $studentIds = $_POST['student_ids'] ?? [];
            if (!is_array($studentIds)) $studentIds = [];
            $studentIds = array_values(array_filter($studentIds, fn($id) => ctype_digit((string)$id)));

            if (empty($studentIds)) {
                $dbError = 'You must select at least one student for hackathon check-in.';
            } else {
                // Verify all students exist
                $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
                $verifyStmt = $pdo->prepare("SELECT student_id FROM Student WHERE student_id IN ($placeholders)");
                $verifyStmt->execute($studentIds);
                $validStudents = $verifyStmt->fetchAll(PDO::FETCH_COLUMN);

                if (count($validStudents) !== count($studentIds)) {
                    $dbError = 'Some selected students do not exist.';
                } else {
                    // For each student, insert attendance records for ALL their active enrolled courses
                    $insertStmt = $pdo->prepare(
                        "INSERT INTO AttendsEventSessions
                             (student_id, event_id, course_id, start_scan_time,
                              end_scan_time, audit_note, overridden_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                             start_scan_time = VALUES(start_scan_time),
                             end_scan_time   = VALUES(end_scan_time),
                             audit_note      = VALUES(audit_note),
                             overridden_by   = VALUES(overridden_by)"
                    );

                    $getCoursesStmt = $pdo->prepare(
                        "SELECT DISTINCT course_id FROM EnrollmentInCourses 
                         WHERE student_id = ? AND status = 'active'"
                    );

                    $count = 0;
                    foreach ($validStudents as $studentId) {
                        // Get all courses this student is enrolled in
                        $getCoursesStmt->execute([$studentId]);
                        $courses = $getCoursesStmt->fetchAll(PDO::FETCH_COLUMN);

                        // Insert attendance for each course
                        foreach ($courses as $courseId) {
                            $insertStmt->execute([
                                $studentId, $eventId, $courseId,
                                $eventStartTime, $eventEndTime, $note, $profId
                            ]);
                        }
                        $count++;
                    }

                    $_SESSION['flash_message'] = "Hackathon check-in completed for {$count} student(s). "
                        . "Their attendance is now reflected across all their enrolled courses.";
                    $_SESSION['flash_type'] = 'success';
                    header("Location: ?prof_id={$profId}");
                    exit;
                }
            }
        }
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}

// ── DELETE EVENT ──────────────────────────────────────────────────────────────
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
            // Delete the event AND all associated attendance records (cascade delete)
            // First delete all attendance records for this event
            $pdo->prepare(
                "DELETE FROM AttendsEventSessions WHERE event_id = ?"
            )->execute([$eventId]);
            
            // Then delete the event itself
            $deleteStmt = $pdo->prepare("DELETE FROM Event WHERE event_id = ?");
            $deleteStmt->execute([$eventId]);

            $_SESSION['flash_message'] = 'Event "' . htmlspecialchars($event['event_name']) . '" deleted successfully. All attendance records for this event have been removed.';
            $_SESSION['flash_type'] = 'success';
            header("Location: ?prof_id={$profId}");
            exit;
        }
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}


// ── REMOVE PROFESSOR FROM COURSE ─────────────────────────────────────────────
// DISABLED: Only admins can remove courses from assignments
/*
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_from_course') {
    try {
        $pdo      = getDB();
        $courseId = trim($_POST['course_id'] ?? '');
        if ($courseId) {
            // Verify assignment exists first
            $check = $pdo->prepare(
                "SELECT assignment_id FROM CourseAssignment WHERE course_id=? AND professor_id=? LIMIT 1"
            );
            $check->execute([$courseId, $profId]);
            if ($check->fetch()) {
                $pdo->prepare(
                    "DELETE FROM CourseAssignment WHERE course_id=? AND professor_id=?"
                )->execute([$courseId, $profId]);
                $_SESSION['flash_message'] = "You have been removed from course {}.";
                $_SESSION['flash_type']    = 'success';
            } else {
                $_SESSION['flash_message'] = 'Course assignment not found.';
                $_SESSION['flash_type']    = 'error';
            }
        }
        header("Location: ?prof_id={$profId}");
        exit;
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}
*/

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

// ── AJAX: GET ALL STUDENTS FROM ALL PROFESSOR'S COURSES ──────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_all_prof_students') {
    header('Content-Type: application/json');
    try {
        $pdo = getDB();
        $profId = (int)($_GET['prof_id'] ?? 0);

        if (!$profId) {
            echo json_encode(['ok' => false, 'error' => 'Professor ID required']);
            exit;
        }

        // Get ALL distinct students from ALL professor's courses
        $stmt = $pdo->prepare(
            "SELECT DISTINCT s.student_id, s.fname, s.lname
             FROM Student s
             JOIN EnrollmentInCourses e ON s.student_id = e.student_id
             JOIN CourseAssignment ca ON e.course_id = ca.course_id
             WHERE ca.professor_id = ? AND e.status = 'active'
             ORDER BY s.lname, s.fname"
        );
        $stmt->execute([$profId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'students' => $students]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
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
                    // Count events attended: either normal check-in/out within timing rules,
                    // OR professor-override (hackathon manual check-in sets overridden_by)
                    $aStmt = $pdo->prepare(
                        "SELECT COUNT(DISTINCT a.event_id)
                         FROM AttendsEventSessions a
                         JOIN Event ev ON a.event_id = ev.event_id
                         WHERE a.student_id = ?
                           AND a.course_id  = ?
                           AND ev.event_type = ?
                           AND a.end_scan_time IS NOT NULL
                           AND (
                               a.overridden_by IS NOT NULL
                               OR (
                                   a.start_scan_time <= DATE_ADD(ev.start_time, INTERVAL 5 MINUTE)
                                   AND a.end_scan_time >= ev.end_time
                               )
                           )"
                    );
                    $aStmt->execute([$stu['student_id'], $selectedCourseId, $filterType]);
                    $attended = (int)$aStmt->fetchColumn();
                } else {
                    $aStmt = $pdo->prepare(
                        "SELECT COUNT(DISTINCT a.event_id)
                         FROM AttendsEventSessions a
                         JOIN Event ev ON a.event_id = ev.event_id
                         WHERE a.student_id = ?
                           AND a.course_id  = ?
                           AND a.end_scan_time IS NOT NULL
                           AND (
                               a.overridden_by IS NOT NULL
                               OR (
                                   a.start_scan_time <= DATE_ADD(ev.start_time, INTERVAL 5 MINUTE)
                                   AND a.end_scan_time >= ev.end_time
                               )
                           )"
                    );
                    $aStmt->execute([$stu['student_id'], $selectedCourseId]);
                    $attended = (int)$aStmt->fetchColumn();
                }

                $min = (int)$selectedCourse['minimum_events_required'];
                
                // Separate required vs extra credit
                // Required = min(attended, minimum_required)
                // Extra = max(0, attended - minimum_required)
                $requiredAttended = min($attended, $min);
                $extraAttended = max(0, $attended - $min);
                
                // Percentage capped at 100%
                $pct = $min > 0 ? min(100, round($requiredAttended / $min * 100)) : 0;
                $status = $requiredAttended >= $min && $min > 0 ? 'Excellent'
                        : ($pct >= 50 ? 'Fair' : 'Poor');

                $courseStudents[] = $stu + [
                    'events_attended' => $requiredAttended,
                    'extra_attended'  => $extraAttended,
                    'events_total'    => $min,
                    'pct'             => $pct,
                    'meets'           => $requiredAttended >= $min,
                    'status_label'    => $min === 0 ? '—' : $status,
                ];
            }

            // ── Bulk-fetch attended event details for all students in one query ──
            // Keyed by student_id so the table can expand inline per row.
            $studentEventDetails = [];
            if (!empty($courseStudents)) {
                $stuIds      = array_column($courseStudents, 'student_id');
                $placeholders = implode(',', array_fill(0, count($stuIds), '?'));
                $detailParams = array_merge($stuIds, [$selectedCourseId]);
                $detailStmt  = $pdo->prepare(
                    "SELECT a.student_id, ev.event_name, ev.event_type,
                            ev.start_time AS event_start, ev.end_time AS event_end,
                            a.start_scan_time, a.end_scan_time, a.minutes_present,
                            a.overridden_by, a.audit_note
                     FROM AttendsEventSessions a
                     JOIN Event ev ON a.event_id = ev.event_id
                     WHERE a.student_id IN ($placeholders)
                       AND a.course_id = ?
                       AND a.end_scan_time IS NOT NULL
                     ORDER BY ev.start_time DESC"
                );
                $detailStmt->execute($detailParams);
                foreach ($detailStmt->fetchAll() as $row) {
                    $studentEventDetails[$row['student_id']][] = $row;
                }
            }
        }
    }

} catch (PDOException $e) {
    $dbError = 'Database error: ' . $e->getMessage();
}

// ── CSV export (sends file download, exits) ───────────────────────────────────
if ($doExport && !$dbError && $selectedCourse) {
    $exportCourseId   = $selectedCourse['course_id'];
    $exportCourseName = $selectedCourse['course_name'];
    $exportSection    = $selectedCourse['section'];
    $exportSemester   = $selectedCourse['semester'];
    $exportYear       = $selectedCourse['year'];
    $exportMinReq     = (int)$selectedCourse['minimum_events_required'];
    $safeFilename     = preg_replace('/[^A-Za-z0-9_\-]/', '_', $exportCourseId);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $safeFilename . '_attendance_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');

    // Header block matching professor's expected format
    fputcsv($out, ['Course:', $exportCourseId . ' - ' . $exportCourseName]);
    fputcsv($out, ['Section:', $exportSection]);
    fputcsv($out, ['Term:', $exportSemester . ' ' . $exportYear]);
    fputcsv($out, ['Minimum Events Required:', $exportMinReq]);
    fputcsv($out, ['Export Date:', date('F j, Y')]);
    fputcsv($out, []); // blank row

    // Column headers — mirror the roster format (Select, ID, Name, ...)
    // plus appended attendance columns so it can be used alongside the original roster
    fputcsv($out, [
        'Select',           // blank — matches roster column 1
        'ID',               // student_id
        'Name',             // Last, First — matches roster "Name" format
        'Grade Basis',      // blank — matches roster column 4
        'Units',            // blank — matches roster column 5
        'Program and Plan', // year/level — matches roster column 6
        'Level',            // blank — matches roster column 7
        'Exp. Grad Term',   // blank — matches roster column 8
        // Attendance columns appended after roster columns
        'Events Attended',
        'Minimum Required',
        'Attendance %',
        'Meets Requirement',
        'Standing',
    ]);

    foreach ($courseStudents as $s) {
        fputcsv($out, [
            '',                                        // Select (blank)
            $s['student_id'],                          // ID
            $s['lname'] . ', ' . $s['fname'],          // Name (Last, First)
            '',                                        // Grade Basis (blank)
            '',                                        // Units (blank)
            $s['year'],                                // Program and Plan (student year)
            '',                                        // Level (blank)
            '',                                        // Exp. Grad Term (blank)
            $s['events_attended'],
            $exportMinReq,
            $s['pct'] . '%',
            $s['meets'] ? 'Yes' : 'No',
            $s['status_label'],
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
            <p class="subtitle" style="text-decoration:none;">Select a class to view student attendance</p>
            <div class="dashboard-action-row" style="margin-top:1rem; display:flex; gap:1rem; align-items:center; flex-wrap:wrap;">
                <!-- <a href="attendance.php?prof_id=<?= $profId ?>" class="btn-primary" target="_blank" style="text-decoration:none;">
                    <i class="fas fa-id-card"></i> Colloquium Attendance Form
                </a> -->
                <button class="btn-secondary" onclick="openHackathonCheckinModal()" style="background: #003366; color: white; font-weight: 600; padding: 0.75rem 1.5rem;">
                    <i class="fas fa-users"></i> Hackathon Check-in
                </button>
                <span style="color:#555; font-size:.95rem; text-decoration:none;">Open on department tablet for student check-in/out during events.</span>
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
                        // Fetch events with creator name via JOIN — fixes the "created by you" bug
                        $evStmt2 = $pdo->prepare(
                            "SELECT e.event_id, e.event_name, e.event_type, e.start_time, e.end_time,
                                    e.location, e.created_by,
                                    CONCAT(u.fname, ' ', u.lname) AS creator_name,
                                    u.role AS creator_role
                             FROM Event e
                             LEFT JOIN AppUser u ON u.user_id = e.created_by
                             ORDER BY e.start_time DESC
                             LIMIT 20"
                        );
                        $evStmt2->execute([]);
                        $recentEvents = $evStmt2->fetchAll();
                        ?>
                        <?php if (empty($recentEvents)): ?>
                        <tr>
                            <td colspan="5" class="no-data">
                                <i class="fas fa-calendar-times"></i> No events created yet. Click "Create Event" to get started.
                            </td>
                        </tr>
                        <?php else:
                            foreach ($recentEvents as $event):
                                $isMyEvent = ((int)$event['created_by'] === $profId);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($event['event_name']) ?></td>
                            <td>
                                <span class="badge"><?= htmlspecialchars($event['event_type']) ?></span>
                            </td>
                            <td>
                                <?= date('D, M j, Y', strtotime($event['start_time'])) ?><br>
                                <small><?= date('g:i A', strtotime($event['start_time'])) ?> &ndash; <?= date('g:i A', strtotime($event['end_time'])) ?></small>
                            </td>
                            <td><?= htmlspecialchars($event['location']) ?></td>
                            <td>
                                <?php if ($isMyEvent): ?>
                                    <span style="color:#2e7d32;font-size:.82rem;"><i class="fas fa-user-check"></i> You</span>
                                <?php elseif ($event['creator_name']): ?>
                                    <span style="color:#555;font-size:.82rem;"><i class="fas fa-user"></i> <?= htmlspecialchars($event['creator_name']) ?></span>
                                <?php else: ?>
                                    <span style="color:#aaa;font-size:.82rem;">—</span>
                                <?php endif; ?>
                                <div style="margin-top:5px;display:flex;gap:0.4rem;">
                                    <?php 
                                    $isColloquium = strtolower($event['event_type']) === 'colloquium';
                                    $eventEndTime = strtotime($event['end_time']);
                                    // Link available until 10 minutes AFTER event ends (check-out window)
                                    $isActive = ($eventEndTime + (10 * 60)) > time();
                                    ?>
                                    <?php if ($isColloquium && $isActive): ?>
                                    <a href="attendance.php?event_id=<?= $event['event_id'] ?>&prof_id=<?= $profId ?>" class="btn-small btn-primary" target="_blank">
                                        <i class="fas fa-link"></i> Colloquium Attendance Form
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($isMyEvent): ?>
                                    <button class="btn-small btn-danger" onclick="deleteEvent(<?= $event['event_id'] ?>, '<?= htmlspecialchars(addslashes($event['event_name'])) ?>')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                    <?php endif; ?>
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
                <div class="course-card-wrapper" style="position:relative;">
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
                    <!-- Leave Course button removed - only admins can remove courses -->
                </div>
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

            <!-- Student attendance table -->
            <?php if (empty($courseStudents)): ?>
            <p class="no-data"><i class="fas fa-user-slash"></i> No students found.</p>
            <?php else: ?>
            <style>
                .event-detail-row { display:none; background:#f8fafc; }
                .event-detail-row.open { display:table-row; }
                .event-detail-inner { padding:.75rem 1.25rem 1rem; }
                .event-detail-inner table { width:100%; font-size:.83rem; border-collapse:collapse; }
                .event-detail-inner th { text-align:left; color:#666; font-weight:600; padding:.3rem .6rem; border-bottom:1px solid #e2e8f0; background:#f1f5f9; }
                .event-detail-inner td { padding:.35rem .6rem; border-bottom:1px solid #eef2f7; vertical-align:middle; }
                .event-detail-inner tr:last-child td { border-bottom:none; }
                .toggle-events-btn { background:none; border:1px solid #c7d2fe; color:#4338ca; border-radius:6px; padding:.2rem .55rem; font-size:.78rem; cursor:pointer; transition:background .15s,color .15s; white-space:nowrap; }
                .toggle-events-btn:hover { background:#eef2ff; }
                .toggle-events-btn.open { background:#eef2ff; border-color:#6366f1; }
                .credit-yes { color:#2e7d32; font-weight:600; }
                .credit-no  { color:#c62828; }
            </style>
            <div class="table-container">
                <table class="students-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Email</th>
                            <th>Required</th>
                            <th>Extra</th>
                            <th>Attendance Rate</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courseStudents as $stu):
                            $evDetails = $studentEventDetails[$stu['student_id']] ?? [];
                            $detailRowId = 'evdetail-' . $stu['student_id'];
                        ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($stu['fname'] . ' ' . $stu['lname']) ?>
                                <!-- <small style="color:#888;display:block;">ID: <?= $stu['student_id'] ?></small> -->
                            </td>
                            <td><?= htmlspecialchars($stu['email']) ?></td>
                            <td>
                                <span class="attendance-count"><?= $stu['events_attended'] ?></span>
                                / <?= $stu['events_total'] ?>
                            </td>
                            <td>
                                <?php if ($stu['extra_attended'] > 0): ?>
                                    <span class="attendance-count" style="color:#d97706; font-weight:600;">+<?= $stu['extra_attended'] ?></span>
                                <?php else: ?>
                                    <span style="color:#999;">—</span>
                                <?php endif; ?>
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
                            <td style="white-space:nowrap;display:flex;gap:.4rem;align-items:center;">
                                <!-- View attended events -->
                                <button class="toggle-events-btn"
                                        onclick="toggleEvents('<?= $detailRowId ?>', this)"
                                        title="View attended events">
                                    <i class="fas fa-eye"></i> Show
                                </button>
                                <!-- Override -->
                                <button class="btn-small"
                                        onclick="openOverride(<?= $stu['student_id'] ?>, '<?= htmlspecialchars(addslashes($stu['fname'].' '.$stu['lname'])) ?>')"
                                        title="Override attendance">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </td>
                        </tr>
                        <!-- Expandable event detail row -->
                        <tr id="<?= $detailRowId ?>" class="event-detail-row">
                            <td colspan="6">
                                <div class="event-detail-inner">
                                <?php if (empty($evDetails)): ?>
                                    <p style="color:#888;font-size:.85rem;margin:0;"><i class="fas fa-calendar-times"></i> No completed attendance records for this student.</p>
                                <?php else: ?>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Event</th>
                                                <th>Type</th>
                                                <th>Date</th>
                                                <th>Check-In</th>
                                                <th>Check-Out</th>
                                                <!-- <th>Duration</th> -->
                                                <th>Status</th>
                                                <th>Credit</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($evDetails as $ed):
                                            // Credit is given if:
                                            // 1. Overridden by professor, OR
                                            // 2. Checked in within 10 min BEFORE to 5 min AFTER event start AND checked out at/after event end
                                            $hasCredit = $ed['overridden_by'] !== null
                                                || (strtotime($ed['start_scan_time']) >= strtotime($ed['event_start']) - 10*60
                                                    && strtotime($ed['start_scan_time']) <= strtotime($ed['event_start']) + 5*60
                                                    && strtotime($ed['end_scan_time']) >= strtotime($ed['event_end']));
                                        ?>
                                            <tr>
                                                <td><?= htmlspecialchars($ed['event_name']) ?></td>
                                                <td><span class="type-badge"><?= htmlspecialchars($ed['event_type']) ?></span></td>
                                                <td><?= date('M j, Y', strtotime($ed['event_start'])) ?></td>
                                                <td><?= date('g:i A', strtotime($ed['start_scan_time'])) ?></td>
                                                <td><?= date('g:i A', strtotime($ed['end_scan_time'])) ?></td>
                                                <!-- <td><?= $ed['minutes_present'] !== null ? $ed['minutes_present'].' min' : '—' ?></td> -->
                                                <td>
                                                    <!-- Status: Complete/Partial/Incomplete (consistent with student dashboard) -->
                                                    <?php if ($ed['end_scan_time']): ?>
                                                        <span class="status-badge complete"><i class="fas fa-check"></i> Complete</span>
                                                    <?php elseif ($ed['start_scan_time']): ?>
                                                        <span class="status-badge partial"><i class="fas fa-clock"></i> Partial</span>
                                                    <?php else: ?>
                                                        <span class="status-badge incomplete"><i class="fas fa-times"></i> Incomplete</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($hasCredit): ?>
                                                        <span class="credit-yes"><i class="fas fa-check-circle"></i> Yes</span>
                                                    <?php else: ?>
                                                        <span class="credit-no"><i class="fas fa-times-circle"></i> No</span>
                                                    <?php endif; ?>
                                                    <?php if ($ed['audit_note']): ?>
                                                        <span title="<?= htmlspecialchars($ed['audit_note']) ?>" style="color:#aaa;font-size:.75rem;margin-left:.3rem;cursor:help;"><i class="fas fa-info-circle"></i></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                                </div>
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
            <form method="POST" action="?prof_id=<?= $profId ?>&course_id=<?= urlencode($selectedCourseId) ?>">
                <input type="hidden" name="action"     value="override_attendance">
                <input type="hidden" name="student_id" id="overrideStudentId">
                <input type="hidden" name="prof_id"    value="<?= $profId ?>">
                <input type="hidden" name="course_id"  value="<?= htmlspecialchars($selectedCourseId) ?>">

                <div class="form-group">
                    <label>Event</label>
                    <select name="event_id" required>
                        <option value="">-- Select Event --</option>
                        <?php foreach ($allEvents ?? [] as $ev): ?>
                        <option value="<?= $ev['event_id'] ?>">
                            <?= htmlspecialchars($ev['event_name'] . ' (' . date('M j, Y', strtotime($ev['start_time'])) . ')') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Present / Absent toggle -->
                <div class="form-group">
                    <label>Mark Student As</label>
                    <div style="display:flex;gap:1rem;margin-top:.4rem;">
                        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:normal;">
                            <input type="radio" name="override_status" value="present"
                                   checked onchange="toggleOverrideTimes(this.value)">
                            <span style="color:#2e7d32;"><i class="fas fa-check-circle"></i> Present (grant credit)</span>
                        </label>
                        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:normal;">
                            <input type="radio" name="override_status" value="absent"
                                   onchange="toggleOverrideTimes(this.value)">
                            <span style="color:#c62828;"><i class="fas fa-times-circle"></i> Absent (remove credit)</span>
                        </label>
                    </div>
                </div>

                <div id="overrideTimeFields" class="form-row">
                    <div class="form-group">
                        <label>Sign-In Time <small style="color:#888;">(optional)</small></label>
                        <input type="datetime-local" name="start_scan_time" id="overrideStartTime">
                    </div>
                    <div class="form-group">
                        <label>Sign-Out Time <small style="color:#888;">(optional)</small></label>
                        <input type="datetime-local" name="end_scan_time" id="overrideEndTime">
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
        <div class="modal-content" style="max-width:780px;width:95vw;">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-plus"></i> Create New Event</h3>
                <button class="modal-close" onclick="closeModal('createEventModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_event">
                <input type="hidden" name="prof_id" value="<?= $profId ?>">

                <!-- Row 1: Name spans full width -->
                <div class="form-group">
                    <label>Event Name *</label>
                    <input type="text" name="event_name" required placeholder="e.g., Spring Colloquium 2025">
                </div>

                <!-- Row 2: Type + Location side by side -->
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

                <!-- Row 3: Start + End side by side -->
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

    <script>
    function openModal(id)  { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }
    window.onclick = e => { if (e.target.classList.contains('modal')) e.target.classList.remove('active'); };

    // Toggle the expandable event detail row for a student
    function toggleEvents(rowId, btn) {
        const row = document.getElementById(rowId);
        const isOpen = row.classList.toggle('open');
        btn.classList.toggle('open', isOpen);
        btn.innerHTML = isOpen
            ? '<i class="fas fa-eye-slash"></i> Hide'
            : '<i class="fas fa-eye"></i> Show';
    }

    // Pre-populate the override modal with the selected student
    function openOverride(studentId, studentName) {
        document.getElementById('overrideStudentId').value   = studentId;
        document.getElementById('overrideStudentName').textContent = studentName;
        openModal('overrideModal');
    }

    // Delete event with confirmation and cascade deletion of attendance records
    function deleteEvent(eventId, eventName) {
        if (confirm(`Are you sure you want to delete "${eventName}"?\n\nThis will also remove all attendance records for this event and cannot be undone.`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            const a = document.createElement('input'); a.name = 'action'; a.value = 'delete_event'; form.appendChild(a);
            const e = document.createElement('input'); e.name = 'event_id'; e.value = eventId; form.appendChild(e);
            document.body.appendChild(form);
            form.submit();
        }
    }

    function openHackathonCheckinModal() {
        openModal('hackathonCheckinModal');
        loadAllHackathonStudents();
    }

    function loadAllHackathonStudents() {
        const studentList = document.getElementById('hackathonStudentList');
        const searchInput = document.getElementById('hackathonStudentSearch');
        
        // Get prof_id from current URL
        const urlParams = new URLSearchParams(window.location.search);
        const profId = urlParams.get('prof_id');
        
        if (!profId) {
            studentList.innerHTML = '<p class="form-note" style="color:#c62828;"><i class="fas fa-exclamation-circle"></i> Error: Professor ID not found. Refresh the page.</p>';
            return;
        }
        
        studentList.innerHTML = '<p class="form-note"><i class="fas fa-spinner fa-spin"></i> Loading students...</p>';
        searchInput.value = '';

        fetch(`?prof_id=${profId}&action=get_all_prof_students`)
            .then(response => {
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                return response.json();
            })
            .then(data => {
                if (!data.ok) {
                    studentList.innerHTML = `<p class="form-note" style="color:#c62828;"><i class="fas fa-exclamation-circle"></i> Error: ${data.error || 'Unable to load students.'}</p>`;
                    return;
                }
                
                if (!data.students || data.students.length === 0) {
                    studentList.innerHTML = '<p class="form-note">No students found across your courses.</p>';
                    return;
                }

                studentList.innerHTML = '';
                data.students.forEach(student => {
                    const row = document.createElement('div');
                    row.className = 'checkbox-row';
                    row.setAttribute('data-student-name', (student.fname + ' ' + student.lname).toLowerCase());
                    row.setAttribute('data-student-id', student.student_id);
                    row.innerHTML = `
                        <label style="display:flex; align-items:center; gap:0.75rem; margin:0.75rem 0; cursor:pointer; font-weight:normal;">
                            <input type="checkbox" name="student_ids[]" value="${student.student_id}" style="width:20px; height:20px; cursor:pointer;">
                            <span>${student.fname} ${student.lname} (ID: ${student.student_id})</span>
                        </label>
                    `;
                    studentList.appendChild(row);
                });
            })
            .catch(error => {
                console.error('Fetch error:', error);
                studentList.innerHTML = `<p class="form-note" style="color:#c62828;"><i class="fas fa-exclamation-circle"></i> Error: ${error.message}. Refresh and try again.</p>`;
            });
    }

    function searchHackathonStudents() {
        const searchInput = document.getElementById('hackathonStudentSearch');
        const searchTerm = searchInput.value.toLowerCase().trim();
        const studentRows = document.querySelectorAll('#hackathonStudentList .checkbox-row');

        studentRows.forEach(row => {
            const name = row.getAttribute('data-student-name');
            const id = row.getAttribute('data-student-id');

            if (name.includes(searchTerm) || id.includes(searchTerm) || searchTerm === '') {
                row.style.display = 'block';
            } else {
                row.style.display = 'none';
            }
        });
    }

    // Prevent submitting without selected students
    document.addEventListener('DOMContentLoaded', () => {
        const hackathonForm = document.querySelector('#hackathonCheckinModal form');
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

    // Toggle override time fields based on present/absent
    function toggleOverrideTimes(val) {
        document.getElementById('overrideTimeFields').style.display =
            val === 'absent' ? 'none' : '';
    }

    // Remove professor from course - DISABLED: only admins can remove courses
    /*
    function removeCourse(courseId, courseName) {
        if (confirm(`Are you sure you want to leave "" (\)?
You will no longer have access to this course's attendance data.`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            const a = document.createElement('input'); a.name = 'action'; a.value = 'remove_from_course'; form.appendChild(a);
            const c = document.createElement('input'); c.name = 'course_id'; c.value = courseId; form.appendChild(c);
            document.body.appendChild(form);
            form.submit();
        }
    }
    */
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
                        // Only show hackathon events that have NOT ended yet
                        // Once end_time passes, event disappears from the list
                        $hackNow = date('Y-m-d H:i:s');
                        $hackathonStmt = $pdo->prepare(
                            "SELECT event_id, event_name, start_time, end_time
                             FROM Event
                             WHERE LOWER(event_type) = 'hackathon'
                               AND end_time > ?
                             ORDER BY start_time ASC"
                        );
                        $hackathonStmt->execute([$hackNow]);
                        $hackathons = $hackathonStmt->fetchAll();
                        if (empty($hackathons)): ?>
                        <option value="" disabled>No active hackathon events available</option>
                        <?php else:
                        foreach ($hackathons as $hack): ?>
                        <option value="<?= $hack['event_id'] ?>">
                            <?= htmlspecialchars($hack['event_name']) ?>
                            (<?= date('M j, Y g:i A', strtotime($hack['start_time'])) ?>
                             – ends <?= date('g:i A', strtotime($hack['end_time'])) ?>)
                        </option>
                        <?php endforeach;
                        endif; ?>
                    </select>
                </div>

                <div id="hackathonStudentSelection" class="form-group">
                    <label>Students Attending *</label>
                    <input type="text" 
                           id="hackathonStudentSearch" 
                           placeholder="Search students by name or ID..." 
                           style="width:100%; padding:0.75rem; margin-bottom:0.75rem; border:1px solid #ccc; border-radius:4px; font-size:0.9rem;"
                           onkeyup="searchHackathonStudents()">
                    <div id="hackathonStudentList" class="student-checkbox-list"></div>
                    <small>Select the students who attended this hackathon. They will receive full credit across all their enrolled courses.</small>
                </div>

                <div class="form-group">
                    <label>Audit Note</label>
                    <input type="text" name="audit_note" value="Hackathon check-in by professor" placeholder="Reason for check-in">
                    <small>This note is saved alongside the attendance record for audit purposes.</small>
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