<?php
header('Content-Type: application/json');

// Define delimiters
define('FIELD_DELIMITER', '፨፨፨');
define('LINE_DELIMITER', '፨፨፨፨፨፨');

// File path
$file_path = __DIR__ . '/form_submissions.txt';

// Function to log messages
function logMessage($message) {
    $timestamp = date('[Y-m-d H:i:s]');
    file_put_contents(__DIR__ . '/form_submissions_debug.log', $timestamp . ' ' . $message . "\n", FILE_APPEND);
}

// Get the contact data from the POST request
$json_data = file_get_contents('php://input');
$submission_to_delete = json_decode($json_data, true);

if (!$submission_to_delete || !is_array($submission_to_delete) || count($submission_to_delete) < 3) {
    echo json_encode(['success' => false, 'error' => 'Invalid submission data']);
    logMessage('Invalid delete request: ' . $json_data);
    exit;
}

// Read the current contents of the file
if (!file_exists($file_path)) {
    echo json_encode(['success' => false, 'error' => 'Submissions file not found']);
    logMessage('Submissions file not found during delete operation');
    exit;
}

$file_contents = file_get_contents($file_path);
if ($file_contents === false) {
    echo json_encode(['success' => false, 'error' => 'Unable to read submissions file']);
    logMessage('Unable to read submissions file during delete operation');
    exit;
}

// Split the contents into lines
$lines = explode(LINE_DELIMITER, $file_contents);

// Find and remove the line containing the submission to delete
// We match based on name, email and timestamp (which should be unique)
$updated_lines = array_filter($lines, function($line) use ($submission_to_delete, $field_delimiter) {
    if (empty(trim($line))) return true; // Keep empty lines
    
    $fields = explode(FIELD_DELIMITER, $line);
    
    // Check if we have enough fields to compare
    if (count($fields) < 6) return true;
    
    // Match name, email and timestamp (fields 0, 1, and 5)
    return !($fields[0] == $submission_to_delete[0] && 
             $fields[1] == $submission_to_delete[1] && 
             $fields[5] == $submission_to_delete[5]);
});

// Join the remaining lines back together
$updated_contents = implode(LINE_DELIMITER, $updated_lines);

// Write the updated contents back to the file
if (file_put_contents($file_path, $updated_contents) === false) {
    echo json_encode(['success' => false, 'error' => 'Unable to write updated submissions to file']);
    logMessage('Unable to write updated submissions during delete operation');
    exit;
}

logMessage('Successfully deleted submission from: ' . $submission_to_delete[0] . ' <' . $submission_to_delete[1] . '>');
echo json_encode(['success' => true, 'message' => 'Submission deleted successfully']);
?>