<?php

if (empty($configuration['GOOGLE_CLIENT_ID']) && empty($configuration['GOOGLE_SERVICE_ACCOUNT_JSON'])) {
    throw new Exception('A GOOGLE_CLIENT_ID or GOOGLE_SERVICE_ACCOUNT_JSON is required.');
}

echo "✓ Configuration validation passed\n";
?>
