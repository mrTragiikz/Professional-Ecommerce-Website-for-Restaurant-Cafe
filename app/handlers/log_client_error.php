<?php
/**
 * Log client-side errors to server error log
 */

// Basic security checks
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Read input
$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

if ($data) {
    $context = $data['context'] ?? 'Client-side';
    $message = $data['message'] ?? 'Unknown error';
    $details = $data['details'] ?? [];

    // Sanitize
    $context = htmlspecialchars(strip_tags($context));
    // Flatten details if array
    if (is_array($details)) {
        $details = json_encode($details);
    }

    $logEntry = "[{$context}] {$message} | Details: {$details}";

    // Log to server error log
    error_log($logEntry);
}

header('Content-Type: application/json');
echo json_encode(['success' => true]);
