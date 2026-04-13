<?php
/**
 * GET_STUDENT_COURSES.PHP  –  AJAX helper called by attendance.php
 *
 * Returns the list of courses a student is actively enrolled in so the
 * attendance kiosk can populate the course-picker dropdown live as the
 * student types their ID.
 *
 * Request:  GET ?student_id=XXXXXX[&prof_id=Y | &admin_id=Z]
 * Response: JSON { ok: true,  courses: [...] }
 *           JSON { ok: false, error: "..." }
 *
 * Authorization: same rule as attendance.php — must supply a valid
 * prof_id OR admin_id query parameter.
 */

header('Content-Type: application/json');
date_default_timezone_set('America/New_York');

require __DIR__ . '/../secrets/db.php';

$studentId = trim($_GET['student_id'] ?? '');
$profId    = (int)($_GET['prof_id']   ?? 0);
$adminId   = (int)($_GET['admin_id']  ?? 0);

if ($studentId === '') {
    echo json_encode(['ok' => false, 'error' => 'student_id is required']);
    exit;
}

try {
    $pdo = getDB();

    // Verify authorization
    $authorized = false;

    if ($profId > 0) {
        $s = $pdo->prepare("SELECT professor_id FROM Professor WHERE professor_id = ? LIMIT 1");
        $s->execute([$profId]);
        $authorized = (bool)$s->fetch();
    }

    if (!$authorized && $adminId > 0) {
        $s = $pdo->prepare(
            "SELECT user_id FROM AppUser WHERE user_id = ? AND role = 'admin' LIMIT 1"
        );
        $s->execute([$adminId]);
        $authorized = (bool)$s->fetch();
    }

    if (!$authorized) {
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    // Fetch the student's active enrollments with course details
    $stmt = $pdo->prepare(
        "SELECT e.course_id, c.course_name, c.section, c.semester, c.year
         FROM EnrollmentInCourses e
         JOIN Course c ON e.course_id = c.course_id
         WHERE e.student_id = ? AND e.status = 'active'
         ORDER BY c.course_name"
    );
    $stmt->execute([$studentId]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'courses' => $courses]);

} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
