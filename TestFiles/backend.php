<?php
/*
$dbHost = '';
$dbPort = '';
$dbName = '';
$dbUser = '';
$dbPass = '';
*/



require __DIR__ . '/../secrets/db.php'; //ignore this line, it has the actual credentials, 
// refer to the creds in the commented line at the top and enter your local credentials

/*
 above me is just the credentials for the database
*/
$profEmail = null;
if (isset($_GET['prof_email'])) {
    $profEmail = trim((string)$_GET['prof_email']);
    if ($profEmail === '') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'Missing prof_email',
        ]);
        exit;
    }
}

$conn = mysqli_init(); // Initialize the MySQLi connection
if ($conn === false) { // if the connection fails then they will get an error message
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to initialize MySQL',
    ]);
    exit;
}

try {
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5); // Set the connection timeout to 5 seconds
    $connected = @$conn->real_connect($dbHost, $dbUser, $dbPass, $dbName, $dbPort); // Attempt to connect to the database
    if ($connected !== true) { //connection failed but connecting to db was successful, so your credentials are wrong
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => $conn->connect_error ?: 'Unknown MySQL connection error',
        ]);
        exit;
    }

    header('Content-Type: application/json');

    if ($profEmail !== null) {
        $stmt = $conn->prepare('SELECT prof_id FROM professor WHERE prof_email = ? LIMIT 1');
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'error' => 'Failed to prepare statement',
            ]);
            exit;
        }

        $stmt->bind_param('s', $profEmail);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row !== null) {
            // Professor exists: send email with link
            $profId = (int)$row['prof_id'];
            $successLink = "http://localhost:8000/success.html?prof_id=" . urlencode($profId);
            $subject = 'Your Login Link';
            $message = "Hello,\n\nClick the link below to log in:\n" . $successLink . "\n\nIf you did not request this, ignore this email.\n";
            $headers = "From: noreply@colloquim.local\r\n" .
                       "Reply-To: noreply@colloquim.local\r\n" .
                       "X-Mailer: PHP/" . phpversion();

            // NOTE: mail() works only if PHP is configured with a mail server (e.g., postfix, sendmail, or SMTP in php.ini)
            // For production, consider PHPMailer or an email service API.
            $mailSent = mail($profEmail, $subject, $message, $headers);

            echo json_encode([
                'ok' => true,
                'exists' => true,
                'prof_id' => $profId,
                'mail_sent' => $mailSent,
            ]);
            exit;
        } else {
            // Professor not found
            echo json_encode([
                'ok' => true,
                'exists' => false,
                'prof_id' => null,
            ]);
            exit;
        }
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Connected to MySQL successfully',
        'database' => $dbName,
    ]);
} finally {
    $conn->close();//always close the connection
}
?>
