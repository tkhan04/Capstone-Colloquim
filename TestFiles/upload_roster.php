<?php
/**
 * UPLOAD_ROSTER.PHP - CSV Roster Parser & Student Account Creator
 *
 * Included by admin_dashboard.php.
 * Provides two functions:
 *
 *   parseCsvRoster($tmpPath)
 *     → ['data' => [ [...], ... ]]
 *     → ['error' => 'Human-readable message'] on failure
 *
 *   createStudentAccounts($pdo, $courseId, $rows, $defaultPassword)
 *     → ['created' => int, 'enrolled' => int, 'alreadyIn' => int, 'skipped' => int]
 *
 * ── SUPPORTED CSV FORMAT (Gettysburg PeopleSoft export) ──────────────────────
 * Required columns (case-insensitive):
 *   ID    → 7-digit student ID
 *   Name  → "Lastname,Firstname" or "Lastname,Firstname Middle" (quoted)
 *   Level → Senior / Junior / Sophomore / Freshman  (optional, defaults to Freshman)
 *
 * All other PeopleSoft columns (Select, Grade Basis, Units, Program and Plan,
 * Exp. Grad Term, etc.) are ignored.
 *
 * Email stored as {student_id}@gettysburg.edu so students can log in
 * by typing their 7-digit ID (app.js appends @gettysburg.edu automatically).
 */

// ── CSV PARSER ────────────────────────────────────────────────────────────────

function parseCsvRoster(string $tmpPath): array
{
    if (!is_readable($tmpPath)) {
        return ['error' => 'Uploaded file could not be read.'];
    }

    $handle = fopen($tmpPath, 'r');
    if ($handle === false) {
        return ['error' => 'Could not open the uploaded file.'];
    }

    // Read and normalise header row (strip BOM, lowercase, trim)
    $rawHeaders = fgetcsv($handle);
    if ($rawHeaders === false || $rawHeaders === null) {
        fclose($handle);
        return ['error' => 'The CSV file appears to be empty.'];
    }

    $headers = array_map(function ($h) {
        return strtolower(trim(preg_replace('/^\xef\xbb\xbf/', '', $h)));
    }, $rawHeaders);

    // Map flexible column names to canonical keys.
    // Only ID, Name, and Level are used; everything else is ignored.
    $colMap = [
        'student_id' => ['id', 'student_id', 'studentid', 'gburg_id'],
        'name'       => ['name', 'student name', 'student_name'],
        'level'      => ['level', 'year', 'class_year', 'class year'],
    ];

    $colIndex = [];
    foreach ($colMap as $canonical => $aliases) {
        foreach ($headers as $i => $h) {
            if (in_array($h, $aliases, true)) {
                $colIndex[$canonical] = $i;
                break;
            }
        }
    }

    // ID and Name are required; Level is optional
    foreach (['student_id', 'name'] as $req) {
        if (!isset($colIndex[$req])) {
            fclose($handle);
            return ['error' => "CSV is missing a required column: \"{$req}\". " .
                               'Expected at minimum: ID, Name.'];
        }
    }

    $rows = [];

    while (($raw = fgetcsv($handle)) !== false) {
        // Skip completely blank lines
        if (count(array_filter($raw, fn($c) => trim($c) !== '')) === 0) {
            continue;
        }

        $studentId = trim($raw[$colIndex['student_id']] ?? '');

        // Must be a 7-digit numeric ID; skip anything else (e.g. totals rows)
        if (!preg_match('/^\d{7}$/', $studentId)) {
            continue;
        }

        // ── Parse "Lastname,Firstname [Middle]" ───────────────────────────────
        // PeopleSoft format: "Mustafiz,Maimuna Binte" → lname=Mustafiz, fname=Maimuna
        // lname = everything before the comma
        // fname = first word after the comma (middle names discarded)
        $rawName = trim($raw[$colIndex['name']] ?? '');
        if ($rawName === '' || strpos($rawName, ',') === false) {
            continue;
        }

        $commaPos = strpos($rawName, ',');
        $lname    = trim(substr($rawName, 0, $commaPos));
        $rest     = trim(substr($rawName, $commaPos + 1));

        // First name is the first word; middle names are dropped
        $spacePos = strpos($rest, ' ');
        $fname    = trim($spacePos !== false ? substr($rest, 0, $spacePos) : $rest);

        if ($fname === '' || $lname === '') {
            continue;
        }

        // Store email as {student_id}@gettysburg.edu.
        // Students log in by typing their 7-digit ID; app.js appends @gettysburg.edu,
        // so this matches automatically with no email derivation needed.
        $email = $studentId . '@gettysburg.edu';

        // ── Map Level → year ──────────────────────────────────────────────────
        $rawLevel = isset($colIndex['level']) ? trim($raw[$colIndex['level']] ?? '') : '';
        $levelMap = [
            'freshman'  => 'Freshman',
            'sophomore' => 'Sophomore',
            'junior'    => 'Junior',
            'senior'    => 'Senior',
        ];
        $year = $levelMap[strtolower($rawLevel)] ?? 'Freshman';

        $rows[] = [
            'student_id' => $studentId,
            'fname'      => $fname,
            'lname'      => $lname,
            'email'      => $email,
            'year'       => $year,
        ];
    }

    fclose($handle);

    if (empty($rows)) {
        return ['error' => 'No valid student rows were found in the CSV. ' .
                           'Verify the file uses the standard Gettysburg PeopleSoft export format ' .
                           'with at least ID and Name columns.'];
    }

    return ['data' => $rows];
}

// ── ACCOUNT + ENROLLMENT CREATOR ─────────────────────────────────────────────

function createStudentAccounts(PDO $pdo, string $courseId, array $rows, string $defaultPassword): array
{
    $created   = 0;
    $enrolled  = 0;
    $alreadyIn = 0;
    $skipped   = 0;

    $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);

    foreach ($rows as $row) {
        $studentId = (int)$row['student_id'];
        $fname     = $row['fname'];
        $lname     = $row['lname'];
        $email     = $row['email'];
        $year      = $row['year'];

        try {
            // 1. Create AppUser if they don't already have an account
            $exists = $pdo->prepare(
                "SELECT user_id FROM AppUser WHERE user_id = ? LIMIT 1"
            );
            $exists->execute([$studentId]);

            if (!$exists->fetch()) {
                // New account: insert with temp password and needs-setup flag
                $pdo->prepare(
                    "INSERT INTO AppUser
                        (user_id, fname, lname, email, role, password_hash, is_active, account_needs_setup)
                     VALUES (?, ?, ?, ?, 'student', ?, 1, 1)"
                )->execute([$studentId, $fname, $lname, $email, $hash]);

                $pdo->prepare(
                    "INSERT IGNORE INTO Student (student_id, fname, lname, email, year)
                     VALUES (?, ?, ?, ?, ?)"
                )->execute([$studentId, $fname, $lname, $email, $year]);

                $created++;
            } else {
                // Existing account: update name (but never touch password or setup flag)
                $pdo->prepare(
                    "UPDATE AppUser SET fname = ?, lname = ? WHERE user_id = ?"
                )->execute([$fname, $lname, $studentId]);

                $pdo->prepare(
                    "UPDATE Student SET fname = ?, lname = ? WHERE student_id = ?"
                )->execute([$fname, $lname, $studentId]);
            }

            // 3. Enrol in course (skip if already enrolled)
            $alreadyEnrolled = $pdo->prepare(
                "SELECT enrollment_id
                 FROM EnrollmentInCourses
                 WHERE student_id = ? AND course_id = ?
                 LIMIT 1"
            );
            $alreadyEnrolled->execute([$studentId, $courseId]);

            if ($alreadyEnrolled->fetch()) {
                $alreadyIn++;
            } else {
                $pdo->prepare(
                    "INSERT INTO EnrollmentInCourses (student_id, course_id, status)
                     VALUES (?, ?, 'active')"
                )->execute([$studentId, $courseId]);
                $enrolled++;
            }

        } catch (PDOException $e) {
            $skipped++;
        }
    }

    return [
        'created'   => $created,
        'enrolled'  => $enrolled,
        'alreadyIn' => $alreadyIn,
        'skipped'   => $skipped,
    ];
}