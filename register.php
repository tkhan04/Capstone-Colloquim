<?php
/**
 * REGISTER.PHP - Create a new account (student, professor, or admin)
 *
 * On POST: inserts into AppUser, then into the role-specific table (Student or Professor).
 * Uses the provided DB schema exactly:
 *   AppUser(user_id, fname, lname, email, role, password_hash, is_active)
 *   Student(student_id FK, fname, lname, email, year)
 *   Professor(professor_id FK, fname, lname, email, permitted_event_types)
 *
 * user_id is supplied by the user (their Gettysburg 7-digit ID).
 */

require __DIR__ . '/db.php';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId    = (int)trim($_POST['user_id']   ?? '');
    $fname     = trim($_POST['fname']          ?? '');
    $lname     = trim($_POST['lname']          ?? '');
    $email     = strtolower(trim($_POST['email'] ?? ''));
    $role      = trim($_POST['role']           ?? '');
    $password  = $_POST['password']            ?? '';
    $password2 = $_POST['password2']           ?? '';
    $year      = trim($_POST['year']           ?? 'Freshman'); // students only

    // Basic validation
    if (!$userId || !$fname || !$lname || !$email || !$role || !$password) {
        $error = 'All fields are required.';
    } elseif ($password !== $password2) {
        $error = 'Passwords do not match.';
    } elseif (!in_array($role, ['student', 'professor', 'admin'])) {
        $error = 'Invalid role.';
    } else {
        try {
            $pdo  = getDB();
            $hash = password_hash($password, PASSWORD_DEFAULT);

            // Insert into AppUser first (user_id is the Gettysburg ID provided by user)
            $stmt = $pdo->prepare(
                "INSERT INTO AppUser (user_id, fname, lname, email, role, password_hash, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 1)"
            );
            $stmt->execute([$userId, $fname, $lname, $email, $role, $hash]);

            // Insert into role-specific table
            if ($role === 'student') {
                $pdo->prepare(
                    "INSERT INTO Student (student_id, fname, lname, email, year)
                     VALUES (?, ?, ?, ?, ?)"
                )->execute([$userId, $fname, $lname, $email, $year]);
            } elseif ($role === 'professor') {
                $pdo->prepare(
                    "INSERT INTO Professor (professor_id, fname, lname, email)
                     VALUES (?, ?, ?, ?)"
                )->execute([$userId, $fname, $lname, $email]);
            }

            $success = 'Account created! <a href="index.html">Sign in now</a>.';

        } catch (PDOException $e) {
            // Duplicate entry = ID or email already exists
            if ($e->getCode() === '23000') {
                $error = 'That Student/User ID or email is already registered.';
            } else {
                $error = 'Database error: ' . $e->getMessage();
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
    <title>Create Account - Colloquium</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Keep existing card style, add year field toggle */
        #yearGroup { display: none; }
        .register-card { max-width: 460px; }
    </style>
</head>
<body>
    <div class="container register-card">
        <img src="gburglogo.jpg" alt="Gettysburg College Logo" class="logo">
        <h1><i class="fas fa-user-plus" style="color:#ff6600;"></i> Create Account</h1>
        <p class="login-subtitle">Register with your Gettysburg College ID</p>

        <?php if ($success): ?>
            <div class="login-success" style="margin-bottom:1rem;">
                <i class="fas fa-check-circle"></i>
                <?= $success ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="login-error" style="margin-bottom:1rem;">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <!-- 7-digit Gettysburg ID becomes user_id PK -->
            <div class="input-group">
                <label>Gettysburg ID (7 digits)</label>
                <input type="number" name="user_id" placeholder="e.g. 6130001" required
                       min="1000000" max="9999999"
                       value="<?= htmlspecialchars($_POST['user_id'] ?? '') ?>">
            </div>

            <div class="input-group">
                <label>First Name</label>
                <input type="text" name="fname" placeholder="First name" required
                       value="<?= htmlspecialchars($_POST['fname'] ?? '') ?>">
            </div>

            <div class="input-group">
                <label>Last Name</label>
                <input type="text" name="lname" placeholder="Last name" required
                       value="<?= htmlspecialchars($_POST['lname'] ?? '') ?>">
            </div>

            <div class="input-group">
                <label>Email</label>
                <input type="email" name="email" placeholder="yourname@gettysburg.edu" required
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>

            <!-- Role selector: shows/hides year field for students -->
            <div class="input-group">
                <label>Role</label>
                <select name="role" id="roleSelect" onchange="toggleYear(this.value)"
                        style="width:100%;padding:.875rem 1rem;border:1px solid #ccc;border-radius:8px;font-size:1rem;">
                    <option value="">-- Select role --</option>
                    <option value="student"   <?= (($_POST['role']??'')==='student'  ?'selected':'') ?>>Student</option>
                    <option value="professor" <?= (($_POST['role']??'')==='professor'?'selected':'') ?>>Professor</option>
                    <option value="admin"     <?= (($_POST['role']??'')==='admin'    ?'selected':'') ?>>Admin</option>
                </select>
            </div>

            <!-- Year (students only) -->
            <div class="input-group" id="yearGroup">
                <label>Year</label>
                <select name="year"
                        style="width:100%;padding:.875rem 1rem;border:1px solid #ccc;border-radius:8px;font-size:1rem;">
                    <option value="Freshman">Freshman</option>
                    <option value="Sophomore">Sophomore</option>
                    <option value="Junior">Junior</option>
                    <option value="Senior" <?= (($_POST['year']??'')==='Senior'?'selected':'') ?>>Senior</option>
                </select>
            </div>

            <div class="input-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="Choose a password" required>
            </div>

            <div class="input-group">
                <label>Confirm Password</label>
                <input type="password" name="password2" placeholder="Repeat password" required>
            </div>

            <button type="submit" class="btn-login">
                <i class="fas fa-user-plus"></i> Create Account
            </button>
        </form>

        <div class="login-footer">
            <a href="index.html"><i class="fas fa-sign-in-alt"></i> Back to Sign In</a>
        </div>
    </div>

    <script>
    // Show year dropdown only for students
    function toggleYear(role) {
        document.getElementById('yearGroup').style.display = (role === 'student') ? 'block' : 'none';
    }
    // Initialise on page load (e.g. after failed POST)
    toggleYear(document.getElementById('roleSelect').value);
    </script>
</body>
</html>
