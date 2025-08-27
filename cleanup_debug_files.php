<?php
echo "Cleaning up debug files...\n";

$debug_files = [
    'check_closing_transactions.php',
    'debug_closing_dropdown.php', 
    'create_test_closing.php',
    'cleanup_debug_files.php'
];

$deleted_count = 0;
$failed_count = 0;

foreach ($debug_files as $file) {
    if (file_exists($file)) {
        if (unlink($file)) {
            echo "✅ Deleted: $file\n";
            $deleted_count++;
        } else {
            echo "❌ Failed to delete: $file\n";
            $failed_count++;
        }
    } else {
        echo "⚠️ File not found: $file\n";
    }
}

echo "\nCleanup Summary:\n";
echo "- Files deleted: $deleted_count\n";
echo "- Files failed: $failed_count\n";
echo "\n✅ Debug cleanup completed!\n";
?>
