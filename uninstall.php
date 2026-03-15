<?php
try {
    // In a typical uninstall script, we clean up the directories
    $modulePath = dirname(__FILE__);
    
    // Clean up cache
    if (is_dir($modulePath . '/cache')) {
        $files = glob($modulePath . '/cache/*');
        foreach($files as $file) {
            if(is_file($file)) {
                @unlink($file);
            }
        }
    }
    
    // Clean up logs
    if (is_dir($modulePath . '/logs')) {
        $files = glob($modulePath . '/logs/*');
        foreach($files as $file) {
            if(is_file($file)) {
                @unlink($file);
            }
        }
    }
    
    echo "✓ Google Meet module uninstalled successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
