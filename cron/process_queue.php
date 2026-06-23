<?php
/**
 * Cron Job: Process Email & SMS Queues
 * Run every minute: * * * * * php -q /path/to/cron/process_queue.php
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli') {
    die('Access denied');
}

// Adjust path as needed
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/functions/email.php';

// Check database connection
if (!isset($pdo)) {
    echo "ERROR: Database connection failed. \$pdo is not set.\n";
    echo "This usually means credentials in secure_config are incorrect or the database server is unreachable.\n";
    exit(1);
}

// Set time limit to avoid timeouts (should run fast, but just in case)
set_time_limit(55);

// Max emails to process per run
$BATCH_SIZE = 20;
$MAX_TRIES = 5;

// ==========================================
// PROCESS EMAIL QUEUE
// ==========================================
echo "Processing Email Queue...\n";

try {
    // 1. Select and Lock Items
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, to_email, to_name, subject, body, tries 
        FROM email_queue 
        WHERE status = 'pending' OR (status = 'failed' AND tries < ?)
        ORDER BY created_at ASC 
        LIMIT ?
        FOR UPDATE
    ");
    $stmt->execute([$MAX_TRIES, $BATCH_SIZE]);
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($emails) > 0) {
        // 2. Mark as processing immediately so other workers don't grab them
        $ids = array_column($emails, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $updateStmt = $pdo->prepare("UPDATE email_queue SET status = 'processing', updated_at = NOW() WHERE id IN ($placeholders)");
        $updateStmt->execute($ids);

        // Commit immediately to release lock and save 'processing' state
        $pdo->commit();

        // 3. Process each email individually
        foreach ($emails as $email) {
            echo "Sending email to {$email['to_email']} (ID: {$email['id']})... ";

            // Re-load config inside loop if needed
            $emailConfig = [];
            if (function_exists('getEmailConfig')) {
                $emailConfig = getEmailConfig();
            }

            $sent = false;
            $errorMsg = null;

            try {
                if (!empty($emailConfig) && isset($emailConfig['smtp_enabled']) && $emailConfig['smtp_enabled']) {
                    $sent = sendEmailViaSMTP($email['to_email'], $email['to_name'], $email['subject'], $email['body'], $emailConfig);
                } else {
                    // Fallback to mail()
                    $headers = [
                        'MIME-Version: 1.0',
                        'Content-type: text/html; charset=UTF-8',
                        'From: JustKleek <noreply@justkleek.com>',
                        'Reply-To: JustKleek <noreply@justkleek.com>',
                        'X-Mailer: PHP/' . phpversion()
                    ];
                    $sent = @mail($email['to_email'], $email['subject'], $email['body'], implode("\r\n", $headers));
                }

                if ($sent) {
                    echo "OK\n";
                    $updateQ = $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW() WHERE id = ?");
                    $updateQ->execute([$email['id']]);
                } else {
                    throw new Exception("Mail function returned false");
                }

            } catch (Exception $e) {
                echo "FAILED: " . $e->getMessage() . "\n";
                $updateQ = $pdo->prepare("
                    UPDATE email_queue 
                    SET status = 'failed', tries = tries + 1, last_error = ? 
                    WHERE id = ?
                ");
                $updateQ->execute([$e->getMessage(), $email['id']]);
            }
        }
    } else {
        $pdo->rollBack(); // No emails found, release lock
        echo "No pending emails.\n";
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
}

// ==========================================
// PROCESS SMS QUEUE (Skeleton for future)
// ==========================================
// Repeat similar logic for sms_queue table...
echo "Done.\n";
