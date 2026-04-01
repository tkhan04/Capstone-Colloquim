<?php
/**************************************************************
 * Colloquium Attendance (Single-File PHP) — MATCHES YOUR SQL
 *
 * Uses tables from colloquium_database.sql:
 * - AppUser(username, user_id, role, ...)
 * - Student(student_id, user_id, ...)
 * - Event(event_id, start_time, end_time, ...)
 * - AttendsEventSessions(attendance_id, student_id, event_id,
 *   start_scan_time, end_scan_time, source, audit_note)
 *
 * Behavior:
 * - Student enters/scans 7-digit ID (stored in AppUser.username)
 * - Finds the "current event" where NOW() is between start_time/end_time
 * - Toggles sign-in/out for that student for that event:
 *   - if no row exists => INSERT with start_scan_time=NOW()
 *   - if start exists and end is NULL => UPDATE end_scan_time=NOW()
 *   - if both exist => message "already signed out"
 **************************************************************/

// -------------------------
// 1) DB CONFIG (EDIT THESE)
// -------------------------
$db_host = "localhost";
$db_name = "Colloquium";     // your SQL has: USE Colloquium;
$db_user = "YOUR_DB_USERNAME";
$db_pass = "YOUR_DB_PASSWORD";

// If your CS server requires a socket, you can add it like:
// $dsn = "mysql:unix_socket=/var/lib/mysql/mysql.sock;dbname=Colloquium;charset=utf8mb4";

// Timezone (adjust if your server is different)
date_default_timezone_set("America/New_York");


// -------------------------
// 2) CONNECT VIA PDO
// -------------------------
$pdo = null;
$db_error = "";
try {
    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    $db_error = "Database connection failed: " . $e->getMessage();
}


// -------------------------
// 3) HELPERS
// -------------------------
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }


// -------------------------
// 4) HANDLE FORM SUBMIT
// -------------------------
$message = "";
$message_type = "info"; // info | success | error
$current_event = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $student_username = isset($_POST["student_id"]) ? trim($_POST["student_id"]) : "";

    // Validate: exactly 7 digits
    if (!preg_match('/^\d{7}$/', $student_username)) {
        $message = "Please enter a valid 7-digit student ID.";
        $message_type = "error";
    } elseif ($db_error) {
        $message = "Cannot save attendance right now (DB not connected).";
        $message_type = "error";
    } else {
        try {
            // 4a) Find the current event (NOW between start_time and end_time).
            // If multiple overlap, pick the one with the latest start_time.
            $stmt = $pdo->query("
                SELECT event_id, event_name, start_time, end_time
                FROM Event
                WHERE NOW() >= start_time AND NOW() <= end_time
                ORDER BY start_time DESC
                LIMIT 1
            ");
            $current_event = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$current_event) {
                $message = "No active event right now. (There must be an Event row whose time window includes the current time.)";
                $message_type = "error";
            } else {
                $event_id = (int)$current_event["event_id"];

                // 4b) Convert AppUser.username (7-digit ID) -> Student.student_id
                // We also ensure role='student'
                $stmt = $pdo->prepare("
                    SELECT s.student_id
                    FROM AppUser u
                    JOIN Student s ON s.user_id = u.user_id
                    WHERE u.username = ?
                      AND u.role = 'student'
                    LIMIT 1
                ");
                $stmt->execute([$student_username]);
                $stu = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$stu) {
                    $message = "Student ID not found (no matching student in AppUser/Student).";
                    $message_type = "error";
                } else {
                    $student_id = (int)$stu["student_id"];

                    // 4c) See if an attendance row already exists for (student_id, event_id)
                    $stmt = $pdo->prepare("
                        SELECT attendance_id, start_scan_time, end_scan_time
                        FROM AttendsEventSessions
                        WHERE student_id = ?
                          AND event_id = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$student_id, $event_id]);
                    $att = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$att) {
                        // No row yet => INSERT sign-in
                        $stmt = $pdo->prepare("
                            INSERT INTO AttendsEventSessions
                                (student_id, event_id, start_scan_time, end_scan_time, source, audit_note)
                            VALUES
                                (?, ?, NOW(), NULL, 'manual', 'Signed in via web form')
                        ");
                        $stmt->execute([$student_id, $event_id]);

                        $message = "Signed IN for “" . $current_event["event_name"] . "”.";
                        $message_type = "success";

                    } else {
                        // Row exists:
                        if ($att["start_scan_time"] === null) {
                            // Shouldn't usually happen, but handle gracefully:
                            $stmt = $pdo->prepare("
                                UPDATE AttendsEventSessions
                                SET start_scan_time = NOW(),
                                    source = 'manual',
                                    audit_note = 'Start time set via web form'
                                WHERE attendance_id = ?
                            ");
                            $stmt->execute([(int)$att["attendance_id"]]);

                            $message = "Signed IN for “" . $current_event["event_name"] . "”.";
                            $message_type = "success";

                        } elseif ($att["end_scan_time"] === null) {
                            // Has start but no end => SIGN OUT
                            $stmt = $pdo->prepare("
                                UPDATE AttendsEventSessions
                                SET end_scan_time = NOW(),
                                    source = 'manual',
                                    audit_note = 'Signed out via web form'
                                WHERE attendance_id = ?
                            ");
                            $stmt->execute([(int)$att["attendance_id"]]);

                            $message = "Signed OUT for “" . $current_event["event_name"] . "”.";
                            $message_type = "success";

                        } else {
                            // Already signed out
                            $message = "You already signed in AND out for “" . $current_event["event_name"] . "”.";
                            $message_type = "info";
                        }
                    }
                }
            }

        } catch (Exception $e) {
            $message = "Error saving attendance: " . $e->getMessage();
            $message_type = "error";
        }
    }
}


// -------------------------
// 5) UI (HTML/CSS)
// -------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Colloquium Attendance</title>
  <style>
    :root{
      --bg1:#f6f1ef;
      --bg2:#e9eff7;
      --card:#ffffff;
      --text:#111827;
      --muted:#6b7280;
      --border:#e5e7eb;
      --shadow: 0 18px 45px rgba(0,0,0,.12);
      --orange:#f04a16;
      --orange2:#ff5a2a;
    }
    *{ box-sizing:border-box; }
    body{
      margin:0;
      min-height:100vh;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      color:var(--text);
      background: radial-gradient(1200px 700px at 20% 10%, var(--bg1), transparent 60%),
                  radial-gradient(1200px 700px at 85% 35%, var(--bg2), transparent 60%),
                  #f3f4f6;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:24px;
    }
    .card{
      width:min(760px, 92vw);
      background:var(--card);
      border:1px solid rgba(0,0,0,.06);
      border-radius:14px;
      box-shadow:var(--shadow);
      padding:28px 28px 22px;
    }
    .icon{
      width:56px; height:56px;
      border-radius:999px;
      background:var(--orange);
      display:grid;
      place-items:center;
      margin:0 auto 12px;
    }
    .icon svg{ width:28px; height:28px; }
    h1{
      margin:6px 0 6px;
      text-align:center;
      font-size:28px;
      letter-spacing:.2px;
    }
    .subtitle{
      text-align:center;
      color:var(--muted);
      margin:0 0 6px;
      font-size:16px;
    }
    .eventline{
      text-align:center;
      color:var(--muted);
      margin:0 0 18px;
      font-size:13px;
    }
    .label{
      font-weight:600;
      margin:8px 0 8px;
      font-size:14px;
    }
    .inputRow{
      display:flex;
      align-items:center;
      gap:10px;
      padding:12px 14px;
      border:1px solid var(--border);
      border-radius:12px;
      background:#fafafa;
    }
    .inputRow .miniIcon{
      width:22px; height:22px;
      opacity:.55;
      flex:0 0 auto;
    }
    input[type="text"]{
      border:none;
      outline:none;
      width:100%;
      font-size:16px;
      background:transparent;
      color:var(--text);
    }
    .hint{
      text-align:center;
      margin:10px 0 18px;
      font-size:13px;
      color:var(--muted);
    }
    .btn{
      width:100%;
      border:none;
      border-radius:12px;
      padding:14px 16px;
      font-size:16px;
      font-weight:700;
      color:white;
      cursor:pointer;
      background:linear-gradient(90deg, var(--orange), var(--orange2));
      box-shadow: 0 10px 22px rgba(240,74,22,.25);
    }
    .btn:active{ transform: translateY(1px); }
    .alert{
      margin:0 0 14px;
      padding:12px 14px;
      border-radius:12px;
      font-size:14px;
      border:1px solid var(--border);
      background:#f9fafb;
    }
    .alert.success{ border-color:#86efac; background:#ecfdf5; color:#065f46; }
    .alert.error{ border-color:#fecaca; background:#fef2f2; color:#7f1d1d; }
    .alert.info{ border-color:#bfdbfe; background:#eff6ff; color:#1e3a8a; }
    .footerNote{
      margin-top:14px;
      font-size:12px;
      color:var(--muted);
      text-align:center;
    }
    .smallDbErr{
      margin-top:8px;
      font-size:12px;
      color:#7f1d1d;
      text-align:center;
      opacity:.9;
    }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none">
        <path d="M7 3H5a2 2 0 0 0-2 2v2" stroke="white" stroke-width="2" stroke-linecap="round"/>
        <path d="M17 3h2a2 2 0 0 1 2 2v2" stroke="white" stroke-width="2" stroke-linecap="round"/>
        <path d="M7 21H5a2 2 0 0 1-2-2v-2" stroke="white" stroke-width="2" stroke-linecap="round"/>
        <path d="M17 21h2a2 2 0 0 0 2-2v-2" stroke="white" stroke-width="2" stroke-linecap="round"/>
        <path d="M8 12h8" stroke="white" stroke-width="2" stroke-linecap="round"/>
      </svg>
    </div>

    <h1>Colloquium Attendance</h1>
    <p class="subtitle">Scan or enter your student ID to sign in/out</p>

    <?php if ($current_event): ?>
      <p class="eventline">
        Active event: <strong><?= h($current_event["event_name"]) ?></strong>
        (<?= h($current_event["start_time"]) ?> → <?= h($current_event["end_time"]) ?>)
      </p>
    <?php else: ?>
      <p class="eventline">Active event: <strong>None detected</strong></p>
    <?php endif; ?>

    <?php if ($message): ?>
      <div class="alert <?= h($message_type) ?>"><?= h($message) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="label">Student ID</div>
      <div class="inputRow">
        <svg class="miniIcon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="#111827" stroke-width="1.8"/>
          <path d="M20 21a8 8 0 1 0-16 0" stroke="#111827" stroke-width="1.8" stroke-linecap="round"/>
        </svg>

        <input
          type="text"
          name="student_id"
          inputmode="numeric"
          pattern="\d{7}"
          maxlength="7"
          placeholder="Enter 7-digit ID"
          autocomplete="off"
          autofocus
          required
        />
      </div>

      <div class="hint">Scan your ID card or type manually</div>

      <button class="btn" type="submit">Sign In/Out</button>
    </form>

    <div class="footerNote">
      This records start/end timestamps in <code>AttendsEventSessions</code>.
    </div>

    <?php if ($db_error): ?>
      <div class="smallDbErr"><?= h($db_error) ?></div>
    <?php endif; ?>
  </div>
</body>
</html>
