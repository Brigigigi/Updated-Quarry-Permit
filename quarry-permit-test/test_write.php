<?php
$logPath = __DIR__ . '/storage/logs/test.log';
$message = "Test log at " . date('Y-m-d H:i:s') . "\n";

if (file_put_contents($logPath, $message, FILE_APPEND)) {
    echo "Write successful! Check $logPath";
} else {
    echo "Write failed! Check folder permissions.";
}
