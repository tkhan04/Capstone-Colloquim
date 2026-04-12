<?php
/**
 * ATTENDANCE.PHP  –  Student check-in / check-out kiosk
 *
 * Rules implemented:
 *  1. Hackathon events are NEVER shown here (professors handle those via their dashboard).
 *  2. Check-in window: 5 minutes before event start_time.
 *  3. Check-out window: until 15 minutes after event end_time, then the event
 *     disappears from the form.
 *  4. Early check-out (before end_time) records the audit_note 'Early checkout - no credit'.
 *  5. When multiple events are active simultaneously, the student must pick one.
 *  6. Student selects which enrolled course the attendance should count for.
 *  7. After every submission the page shows the student's live attendance history.
 *
 * DB tables (exact schema):
 *   Event(event_id, event_name, event_type, start_time, end_time, location)
 *   Student(student_id, fname, lname)
 *   EnrollmentInCourses(student_id, course_id, status)
 *   Course(course_id, course_name, section, semester, year)
 *   AttendsEventSessions(student_id PK, event_id PK, course_id PK,
 *                        start_scan_time, end_scan_time,
 *                        minutes_present [generated], audit_note, overridden_by)
 *   Professor(professor_id, fname, lname)
 *   AppUser(user_id, role, fname, lname)
 */

date_default_timezone_set('America/New_York');

require __DIR__ . '/../secrets/db.php';

$dbError     = null;
$message     = '';
$messageType = '';

// ── Authorization: must be opened from a prof or admin dashboard ─────────────
$profId  = isset($_GET['prof_id'])  ? (int)$_GET['prof_id']  : 0;
$adminId = isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : 0;

$isProfessorAccess  = false;
$isAdminAccess      = false;
$professor          = null;
$admin              = null;
$availableEvents    = [];

try {
    $pdo = getDB();

    if ($profId > 0) {
        $s = $pdo->prepare("SELECT professor_id, fname, lname FROM Professor WHERE professor_id = ? LIMIT 1");
        $s->execute([$profId]);
        $professor         = $s->fetch();
        $isProfessorAccess = (bool)$professor;
    }

    if ($adminId > 0) {
        $s = $pdo->prepare(
            "SELECT user_id, fname, lname FROM AppUser WHERE user_id = ? AND role = 'admin' LIMIT 1"
        );
        $s->execute([$adminId]);
        $admin         = $s->fetch();
        $isAdminAccess = (bool)$admin;
    }

    $isAuthorizedAccess = $isProfessorAccess || $isAdminAccess;

    // Non-hackathon events within the active window:
    //   Opens  : start_time - 5 minutes
    //   Closes : end_time   + 15 minutes  (then gone from the form)
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "SELECT event_id, event_name, event_type, start_time, end_time, location
         FROM Event
         WHERE LOWER(event_type) != 'hackathon'
           AND ? >= DATE_SUB(start_time, INTERVAL 5  MINUTE)
           AND ? <= DATE_ADD(end_time,   INTERVAL 15 MINUTE)
         ORDER BY start_time ASC"
    );
    $stmt->execute([$now, $now]);
    $availableEvents = $stmt->fetchAll();

} catch (PDOException $e) {
    $dbError = 'Database connection failed: ' . $e->getMessage();
}

// ── State populated after a successful POST ──────────────────────────────────
$lastStudentId  = '';
$studentCourses = [];   // enrolled courses for the checkbox list
$checkedCourses = [];   // course_ids that were checked on last POST

// ── Handle POST submission ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$dbError) {

    $rawStudentId    = trim($_POST['student_id'] ?? '');
    $selectedEventId = (int)($_POST['event_id']  ?? 0);
    // course_ids[] is now a multi-value checkbox array
    $selectedCourseIds = array_filter(
        array_map('trim', (array)($_POST['course_ids'] ?? [])),
        fn($v) => $v !== ''
    );
    $checkedCourses  = $selectedCourseIds;
    $lastStudentId   = $rawStudentId;

    // Validate inputs
    $error = '';
    if ($rawStudentId === '') {
        $error = 'Please enter your Student ID.';
    } elseif (!$isAuthorizedAccess) {
        $error = 'This page is reserved for professor or admin access. Open it from your dashboard.';
    } elseif (empty($availableEvents)) {
        $error = 'No events are currently open for check-in.';
    } elseif ($selectedEventId === 0) {
        $error = 'Please select an event.';
    } elseif (empty($selectedCourseIds)) {
        $error = 'Please select at least one course this attendance should count for.';
    }

    if ($error) {
        $message     = $error;
        $messageType = 'error';
    } else {
        // Identify chosen event from available list
        $selectedEvent = null;
        foreach ($availableEvents as $ev) {
            if ((int)$ev['event_id'] === $selectedEventId) { $selectedEvent = $ev; break; }
        }

        if (!$selectedEvent) {
            $message     = 'The selected event is no longer available for check-in.';
            $messageType = 'error';
        } else {
            try {
                $pdo       = getDB();
                $now       = date('Y-m-d H:i:s');
                $eventEnd  = $selectedEvent['end_time'];
                $windowEnd = date('Y-m-d H:i:s', strtotime($eventEnd . ' +15 minutes'));
                $eventId   = (int)$selectedEvent['event_id'];

                // Verify student exists
                $s = $pdo->prepare(
                    "SELECT student_id, fname, lname FROM Student WHERE student_id = ? LIMIT 1"
                );
                $s->execute([$rawStudentId]);
                $studentRow = $s->fetch();

                if (!$studentRow) {
                    $message     = 'Student ID not found. Please check your ID or contact a professor.';
                    $messageType = 'error';
                } else {
                    // Process each selected course independently
                    $results    = [];   // per-course outcome messages
                    $anySuccess = false;
                    $anyWarning = false;

                    foreach ($selectedCourseIds as $courseId) {
                        // Verify student is enrolled in this course
                        $ec = $pdo->prepare(
                            "SELECT enrollment_id FROM EnrollmentInCourses
                             WHERE student_id = ? AND course_id = ? AND status = 'active' LIMIT 1"
                        );
                        $ec->execute([$rawStudentId, $courseId]);
                        if (!$ec->fetch()) {
                            $results[] = "<strong>$courseId</strong>: not actively enrolled — skipped.";
                            continue;
                        }

                        // Look up existing record for this composite PK
                        $ex = $pdo->prepare(
                            "SELECT start_scan_time, end_scan_time
                             FROM AttendsEventSessions
                             WHERE student_id = ? AND event_id = ? AND course_id = ?"
                        );
                        $ex->execute([$rawStudentId, $eventId, $courseId]);
                        $record = $ex->fetch();

                        if (!$record) {
                            // First scan: check-in
                            if ($now > $windowEnd) {
                                $results[] = "<strong>$courseId</strong>: check-in window closed.";
                            } else {
                                $pdo->prepare(
                                    "INSERT INTO AttendsEventSessions
                                         (student_id, event_id, course_id, start_scan_time)
                                     VALUES (?, ?, ?, ?)"
                                )->execute([$rawStudentId, $eventId, $courseId, $now]);
                                $results[]  = "<strong>$courseId</strong>: signed IN ✓";
                                $anySuccess = true;
                            }

                        } elseif ($record['start_scan_time'] && !$record['end_scan_time']) {
                            // Second scan: check-out
                            if ($now < $eventEnd) {
                                // Early — no credit
                                $pdo->prepare(
                                    "UPDATE AttendsEventSessions
                                     SET end_scan_time = ?, audit_note = ?
                                     WHERE student_id = ? AND event_id = ? AND course_id = ?"
                                )->execute([
                                    $now, 'Early checkout - no credit',
                                    $rawStudentId, $eventId, $courseId
                                ]);
                                $results[]  = "<strong>$courseId</strong>: early check-out — no credit.";
                                $anyWarning = true;
                            } else {
                                $pdo->prepare(
                                    "UPDATE AttendsEventSessions
                                     SET end_scan_time = ?
                                     WHERE student_id = ? AND event_id = ? AND course_id = ?"
                                )->execute([$now, $rawStudentId, $eventId, $courseId]);
                                $results[]  = "<strong>$courseId</strong>: signed OUT ✓ — credit earned!";
                                $anySuccess = true;
                            }

                        } else {
                            $results[] = "<strong>$courseId</strong>: already fully checked in/out.";
                        }
                    }

                    // Build combined message
                    $message     = implode('<br>', $results);
                    $messageType = $anySuccess ? 'success' : ($anyWarning ? 'warning' : 'info');
                }

                // Always reload courses so checkboxes re-render after POST
                $sc = $pdo->prepare(
                    "SELECT e.course_id, c.course_name, c.section, c.semester, c.year
                     FROM EnrollmentInCourses e
                     JOIN Course c ON e.course_id = c.course_id
                     WHERE e.student_id = ? AND e.status = 'active'
                     ORDER BY c.course_name"
                );
                $sc->execute([$rawStudentId]);
                $studentCourses = $sc->fetchAll();

            } catch (PDOException $e) {
                $message     = 'Database error: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Colloquium Attendance</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ── warning message (early checkout) ───────────────────────────── */
        .message.warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffc107;
            border-left: 4px solid #ffc107;
            padding: .875rem 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: .5rem;
            font-size: .95rem;
        }
        /* allow HTML in message (course-by-course results) */
        .message { line-height: 1.6; }
        /* ── time note below event list ─────────────────────────────────── */
        .time-note {
            font-size: .8rem;
            color: #888;
            font-style: italic;
            margin-top: .2rem;
        }
        /* ── event select in the form ───────────────────────────────────── */
        .attendance-form select {
            width: 100%;
            padding: .875rem 1rem;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 1rem;
            background: #fff;
            color: #333;
            transition: border-color .2s, box-shadow .2s;
        }
        .attendance-form select:focus {
            outline: none;
            border-color: #ff6600;
            box-shadow: 0 0 0 3px rgba(255,102,0,.1);
        }
        /* ── course checkbox list ───────────────────────────────────────── */
        #coursePickerSection { display: none; }
        .course-checkbox-list {
            display: flex;
            flex-direction: column;
            gap: .55rem;
            margin-top: .5rem;
        }
        .course-checkbox-list label {
            display: flex;
            align-items: center;
            gap: .65rem;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: .7rem 1rem;
            cursor: pointer;
            font-size: .95rem;
            color: #333;
            transition: border-color .15s, background .15s;
        }
        .course-checkbox-list label:hover {
            border-color: #ff6600;
            background: #fff8f5;
        }
        .course-checkbox-list input[type="checkbox"] {
            width: 1.1rem;
            height: 1.1rem;
            accent-color: #003366;
            flex-shrink: 0;
            cursor: pointer;
        }
        .course-checkbox-list label.checked {
            border-color: #003366;
            background: #eef2f8;
        }
        .course-id-tag {
            font-weight: 700;
            color: #003366;
            font-size: .85rem;
            background: #dde6f5;
            padding: .1rem .45rem;
            border-radius: 4px;
        }
    </style>
</head>
<body class="attendance-page">
<div class="attendance-container">

    <!-- Header -->
    <header class="attendance-header">
        <h1><i class="fas fa-calendar-check"></i> Colloquium Attendance</h1>
        <p class="subtitle">Scan or enter your student ID to check in / check out</p>
    </header>

    <!-- Active-event banner -->
    <div class="active-event-banner <?= !empty($availableEvents) ? 'event-active' : 'no-event' ?>">
        <i class="fas <?= !empty($availableEvents) ? 'fa-broadcast-tower' : 'fa-exclamation-circle' ?>"></i>
        <span>
        <?php if (!empty($availableEvents)): ?>
            <?= count($availableEvents) === 1
                ? htmlspecialchars($availableEvents[0]['event_name']) . ' — check-in open'
                : count($availableEvents) . ' events open for check-in' ?>
        <?php else: ?>
            No events currently open for check-in
        <?php endif; ?>
        </span>
    </div>

    <!-- Feedback message -->
    <?php if ($message): ?>
    <div class="message <?= htmlspecialchars($messageType) ?>">
        <i class="fas <?= match($messageType) {
            'success' => 'fa-check-circle',
            'error'   => 'fa-times-circle',
            'warning' => 'fa-exclamation-triangle',
            default   => 'fa-info-circle'
        } ?>" style="flex-shrink:0;margin-top:.1rem;"></i>
        <div><?= $message /* already safe: built from htmlspecialchars'd course IDs + static strings */ ?></div>
    </div>
    <?php endif; ?>

    <!-- Unauthorized -->
    <?php if (!$isAuthorizedAccess): ?>
    <div class="attendance-note">
        <p><i class="fas fa-lock"></i> This page is reserved for professor or admin access.
           Please open it from your dashboard.</p>
    </div>

    <!-- No open events -->
    <?php elseif (empty($availableEvents) && !$dbError): ?>
    <div class="attendance-note">
        <p><i class="fas fa-calendar-times"></i> No events are currently open for check-in.</p>
        <p class="time-note" style="margin-top:.6rem;">
            Check-in opens 5 minutes before an event starts and
            closes 15 minutes after it ends.
        </p>
    </div>

    <!-- Main check-in form -->
    <?php elseif (!$dbError): ?>
    <form method="POST" class="attendance-form" id="checkinForm">

        <!-- Step 1 — Student ID -->
        <div class="input-group">
            <label for="student_id">
                <i class="fas fa-id-card"></i> Student ID
            </label>
            <input type="text"
                   id="student_id"
                   name="student_id"
                   placeholder="Scan your ID card or type manually"
                   autofocus
                   required
                   autocomplete="off"
                   inputmode="numeric"
                   pattern="\d{5,8}"
                   value="<?= htmlspecialchars($lastStudentId) ?>"
                   oninput="onStudentIdInput(this.value)">
        </div>

        <!-- Step 2 — Course checkboxes (revealed after ID typed / after POST) -->
        <div id="coursePickerSection" class="input-group">
            <label style="margin-bottom:.3rem;">
                <i class="fas fa-book"></i>
                Courses this attendance counts for
                <span style="font-weight:400;font-size:.85rem;color:#666;">(tick all that apply)</span>
            </label>
            <div class="course-checkbox-list" id="courseCheckboxList">
                <?php foreach ($studentCourses as $sc):
                    $isChecked = in_array($sc['course_id'], $checkedCourses, true);
                ?>
                <label class="<?= $isChecked ? 'checked' : '' ?>">
                    <input type="checkbox"
                           name="course_ids[]"
                           value="<?= htmlspecialchars($sc['course_id']) ?>"
                           <?= $isChecked ? 'checked' : '' ?>
                           onchange="this.closest('label').classList.toggle('checked', this.checked)">
                    <span class="course-id-tag"><?= htmlspecialchars($sc['course_id']) ?></span>
                    <?= htmlspecialchars($sc['course_name']) ?>
                    <span style="color:#888;font-size:.82rem;margin-left:auto;">
                        <?= htmlspecialchars($sc['semester'] . ' ' . $sc['year']) ?>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
            <p class="time-note" style="margin-top:.5rem;">
                Select all courses for which this event should count toward your attendance requirement.
            </p>
        </div>

        <!-- Step 3 — Event picker (only when > 1 concurrent event) -->
        <?php if (count($availableEvents) > 1): ?>
        <div class="input-group">
            <label for="event_id">
                <i class="fas fa-calendar-alt"></i> Select Event
            </label>
            <select id="event_id" name="event_id" required>
                <option value="">— Choose an event —</option>
                <?php foreach ($availableEvents as $ev): ?>
                <option value="<?= $ev['event_id'] ?>"
                    <?= ((int)($_POST['event_id'] ?? 0) === (int)$ev['event_id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ev['event_name']) ?>
                    (<?= date('g:i A', strtotime($ev['start_time'])) ?>
                     – <?= date('g:i A', strtotime($ev['end_time'])) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php else: ?>
        <input type="hidden" name="event_id" value="<?= $availableEvents[0]['event_id'] ?>">
        <?php endif; ?>

        <button type="submit" class="btn-primary">
            <i class="fas fa-sign-in-alt"></i> Check In / Check Out
        </button>
        <p class="help-text">
            Use this page at the <strong>start</strong> and at the <strong>end</strong> of the event.
        </p>
    </form>
    <?php endif; ?>

    <?php if ($dbError): ?>
    <div class="db-error"><i class="fas fa-database"></i> <?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <!-- ── Available event info ───────────────────────────────────────────── -->
    <?php if (!empty($availableEvents)): ?>
    <div class="event-details" style="margin-top:1.5rem;">
        <h3><i class="fas fa-list"></i> Events Open for Check-In</h3>
        <?php foreach ($availableEvents as $ev): ?>
        <div class="event-item">
            <h4><?= htmlspecialchars($ev['event_name']) ?></h4>
            <p><i class="fas fa-tag"></i> <?= htmlspecialchars($ev['event_type']) ?></p>
            <p>
                <i class="fas fa-clock"></i>
                <?= date('g:i A', strtotime($ev['start_time'])) ?> –
                <?= date('g:i A', strtotime($ev['end_time'])) ?>
            </p>
            <p class="time-note">
                Check-in closes <?= date('g:i A', strtotime($ev['end_time'] . ' +15 minutes')) ?>
            </p>
            <?php if ($ev['location']): ?>
            <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($ev['location']) ?></p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div><!-- /.attendance-container -->

<script>
/**
 * Course checkbox loader:
 *  After the student types a valid-length ID we fetch their enrolled courses
 *  from get_student_courses.php and build checkbox rows, then reveal the section.
 *  If we're re-rendering after a POST the section is shown immediately because
 *  $studentCourses was already populated server-side.
 */
const courseSection      = document.getElementById('coursePickerSection');
const courseCheckboxList = document.getElementById('courseCheckboxList');

// Show immediately if checkboxes already exist (POST re-render)
if (courseCheckboxList && courseCheckboxList.children.length > 0) {
    courseSection.style.display = 'block';
}

let debounceTimer = null;

function onStudentIdInput(val) {
    clearTimeout(debounceTimer);
    const digits = val.replace(/\D/g, '');
    if (digits.length < 5) {
        if (courseSection) courseSection.style.display = 'none';
        if (courseCheckboxList) courseCheckboxList.innerHTML = '';
        return;
    }
    debounceTimer = setTimeout(() => loadCourses(digits), 450);
}

function loadCourses(studentId) {
    let qs = 'student_id=' + encodeURIComponent(studentId);
    <?php if ($profId):  ?>qs += '&prof_id=<?= $profId ?>';<?php endif; ?>
    <?php if ($adminId): ?>qs += '&admin_id=<?= $adminId ?>';<?php endif; ?>

    fetch('get_student_courses.php?' + qs)
        .then(r => r.json())
        .then(data => {
            if (!data.ok || !data.courses || !data.courses.length) {
                if (courseSection) courseSection.style.display = 'none';
                return;
            }
            // Build checkbox rows
            courseCheckboxList.innerHTML = '';
            data.courses.forEach(c => {
                const lbl = document.createElement('label');
                lbl.innerHTML = `
                    <input type="checkbox" name="course_ids[]" value="${c.course_id}"
                           onchange="this.closest('label').classList.toggle('checked', this.checked)">
                    <span class="course-id-tag">${c.course_id}</span>
                    ${c.course_name}
                    <span style="color:#888;font-size:.82rem;margin-left:auto;">${c.semester} ${c.year}</span>
                `;
                courseCheckboxList.appendChild(lbl);
            });
            courseSection.style.display = 'block';
        })
        .catch(() => { /* silently ignore errors */ });
}
</script>
</body>
</html>
