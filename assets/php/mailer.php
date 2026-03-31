<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log file for tracking form submissions
define('LOG_FILE', __DIR__ . '/form_submissions_debug.log');

// Function to log messages
function logMessage($message) {
    $timestamp = date('[Y-m-d H:i:s]');
    file_put_contents(LOG_FILE, $timestamp . ' ' . $message . "\n", FILE_APPEND);
}

// Enhanced error handling and logging
function sendJsonResponse($success, $message = '') {
    header('Content-Type: application/json');
    
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    logMessage('Response: ' . json_encode($response));
    echo json_encode($response);
    exit;
}

// Check request method
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    logMessage('Invalid request method: ' . $_SERVER["REQUEST_METHOD"]);
    http_response_code(405);
    sendJsonResponse(false, 'Method Not Allowed');
}

// Log all received POST data for debugging
logMessage('Received POST data: ' . print_r($_POST, true));

// Validate required fields
$required_fields = ['name', 'email', 'topic', 'budget', 'message'];
$missing_fields = [];

foreach ($required_fields as $field) {
    if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
        $missing_fields[] = $field;
    }
}

// Check for missing fields
if (!empty($missing_fields)) {
    logMessage('Missing fields: ' . implode(', ', $missing_fields));
    http_response_code(400);
    sendJsonResponse(false, 'Missing required fields: ' . implode(', ', $missing_fields));
}

// Validate email format
if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
    logMessage('Invalid email format: ' . $_POST['email']);
    http_response_code(400);
    sendJsonResponse(false, 'Invalid email format');
}

// Define delimiters
define('FIELD_DELIMITER', '፨፨፨');
define('LINE_DELIMITER', '፨፨፨፨፨፨');

// Escape fields to prevent delimiter conflicts
function escapeField($str) {
    return str_replace(FIELD_DELIMITER, '፨፨', $str);
}

// Add timestamp to submissions
$timestamp = date('Y-m-d H:i:s');

// Sanitize and prepare data
$name = escapeField(trim($_POST['name']));
$email = escapeField(trim($_POST['email']));
$topic = escapeField(trim($_POST['topic']));
$budget = escapeField(trim($_POST['budget']));
$message = escapeField(trim($_POST['message']));

// Prepare data string
$data = $name . FIELD_DELIMITER . 
        $email . FIELD_DELIMITER . 
        $topic . FIELD_DELIMITER . 
        $budget . FIELD_DELIMITER . 
        $message . FIELD_DELIMITER .
        $timestamp . LINE_DELIMITER;

// Set file path
$file_path = __DIR__ . '/form_submissions.txt';

// Attempt to write file with comprehensive error checking
try {
    // Ensure directory exists and is writable
    $directory = dirname($file_path);
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    // Write to file
    $write_result = file_put_contents($file_path, $data, FILE_APPEND);

    if ($write_result === false) {
        throw new Exception("Unable to write to file: $file_path");
    }

    // Log successful submission
    logMessage("Successful submission from: $name <$email>");

    // Send success response
    sendJsonResponse(true, 'Form submitted successfully! We will get back to you soon.');

} catch (Exception $e) {
    // Log and report file writing errors
    logMessage('Error: ' . $e->getMessage());
    http_response_code(500);
    sendJsonResponse(false, 'Server error: Unable to save your submission. Please try again later.');
}
?>