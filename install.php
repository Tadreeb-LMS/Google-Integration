<?php
try {
    $modulePath = dirname(__FILE__);
    
    // Create cache and logs directories
    @mkdir($modulePath . '/cache', 0755, true);
    @mkdir($modulePath . '/logs', 0755, true);
    
    echo "✓ Google Meet module installed successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
