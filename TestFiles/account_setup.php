<?php
/**
 * ACCOUNT_SETUP.PHP - Account Completion Page
 *
 * Shown to students on FIRST login after account is created via roster upload.
 * Requires them to:
 * - Set a permanent password (instead of temp "changeme123")
 * - Confirm their email and name
 * - Then redirected to stud_dashboard.php
 */

session_start();
require __DIR__ . '/../secrets/db.php';

$studentId = (int)($_GET['student_id'] ?? 0);
$error     = '';
$student   = null;

try {
    $pdo = getDB();

    // ── Check if student exists and needs setup ──────────────────────────────
    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.fname, u.lname, u.email, s.year, u.account_needs_setup
         FROM AppUser u
         JOIN Student s ON u.user_id = s.student_id
         WHERE u.user_id = ? AND u.account_needs_setup = 1"
    );
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    if (!$student) {
        // Already set up or doesn't exist → redirect to login
        header("Location: index.html");
        exit;
    }

    // ── Handle form submission ────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $newEmail     = strtolower(trim($_POST['email']    ?? ''));
        $newPassword  = $_POST['password']  ?? '';
        $newPassword2 = $_POST['password2'] ?? '';

        // Validate
        if (!preg_match('/^[a-zA-Z0-9._%+-]+@gettysburg\.edu$/', $newEmail)) {
            $error = 'Please enter a valid @gettysburg.edu email address.';
        } elseif ($newPassword !== $newPassword2) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);

            // Update email, password, and mark setup complete
            $pdo->prepare(
                "UPDATE AppUser
                 SET email = ?, password_hash = ?, account_needs_setup = 0
                 WHERE user_id = ?"
            )->execute([$newEmail, $hash, $studentId]);

            $pdo->prepare(
                "UPDATE Student SET email = ? WHERE student_id = ?"
            )->execute([$newEmail, $studentId]);

            // Redirect to dashboard
            header("Location: stud_dashboard.php?student_id={$studentId}&tab=upcoming");
            exit;
        }
    }

} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
}

if (!$student) {
    header("Location: index.html");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Your Account - Colloquium</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .setup-container {
            max-width: 500px;
            margin: 3rem auto;
            padding: 2rem;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #ff6600;
        }
        .setup-title {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.5rem;
        }
        .setup-title h1 {
            margin: 0;
            font-size: 1.8rem;
            color: #003366;
        }
        .setup-subtitle {
            color: #666;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
        }
        .info-section {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e9ecef;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: 600;
            color: #333;
            width: 40%;
        }
        .info-value {
            color: #666;
            word-break: break-word;
            flex: 1;
            text-align: right;
        }
        .divider {
            border: none;
            border-top: 1px solid #e9ecef;
            margin: 1.5rem 0;
        }
    </style>
</head>
<body class="dashboard-page">

    <nav class="admin-topnav">
        <div class="nav-brand" style="display:flex;align-items:center;gap:.5rem;">
            <img src="gburglogo.jpg" alt="Gettysburg College" style="height:32px;width:auto;">
            <span class="nav-title">Account Setup</span>
        </div>
    </nav>

    <main class="admin-content">
        <div class="setup-container">

            <div class="setup-title">
                <i class="fas fa-clipboard-check" style="color:#ff6600;font-size:1.5rem;"></i>
                <h1>Complete Your Account</h1>
            </div>
            <p class="setup-subtitle">
                Welcome to Colloquium! Your account has been created by your instructor.
                Enter your Gettysburg email and set a password to finish setting up your account.
            </p>

            <!-- Error message -->
            <?php if ($error): ?>
            <div class="toast error" style="margin-bottom:1rem;">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <!-- Current account info (read-only) -->
            <div class="info-section">
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-id-card"></i> Gettysburg ID</span>
                    <span class="info-value"><strong><?= $studentId ?></strong></span>
                </div>
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-user"></i> Name</span>
                    <span class="info-value"><?= htmlspecialchars($student['fname'] . ' ' . $student['lname']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-envelope"></i> Email</span>
                    <span class="info-value" style="color:#003366;font-size:.85rem;">Set below ↓</span>
                </div>
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-graduation-cap"></i> Class Year</span>
                    <span class="info-value"><?= htmlspecialchars($student['year']) ?></span>
                </div>
            </div>

            <hr class="divider">

            <!-- Setup form -->
            <form method="POST">
                <div class="input-group" style="margin-bottom:1.25rem;">
                    <label for="email">
                        <i class="fas fa-envelope" style="color:#003366;"></i>
                        Gettysburg Email *
                    </label>
                    <input type="email" id="email" name="email" required
                           placeholder="yourname@gettysburg.edu"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           autocomplete="email"
                           style="border-color: #003366;">
                    <small style="color:#666;margin-top:.25rem;display:block;">
                        Enter your actual Gettysburg email — you can use this or your 7-digit ID to sign in later.
                    </small>
                </div>

                <p style="color:#666;margin-bottom:1rem;">
                    <strong>Create a permanent password:</strong>
                </p>

                <div class="input-group">
                    <label for="password">New Password *</label>
                    <input type="password" id="password" name="password" required
                           placeholder="Enter a password"
                           autocomplete="new-password">
                </div>

                <div class="input-group">
                    <label for="password2">Confirm Password *</label>
                    <input type="password" id="password2" name="password2" required
                           placeholder="Confirm your password"
                           autocomplete="new-password">
                </div>

                <button type="submit" class="btn-login" style="width:100%;margin-top:1.5rem;">
                    <i class="fas fa-check-circle"></i> Complete Setup & Access Dashboard
                </button>
            </form>

            <p style="text-align:center;color:#999;font-size:0.85rem;margin-top:1.5rem;">
                <i class="fas fa-info-circle"></i> 
                After setup, you'll be redirected to your student dashboard.
            </p>
        </div>
    </main>

</body>
</html>