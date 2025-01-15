<?php

require 'vendor/autoload.php';

use App\RackbeatClient;
use App\Helpers\XmlHelper;

// Configuration
$config = require 'src/config/config.php';

// Initialize RackbeatClient and XmlHelper
$rackbeatClient = new RackbeatClient($config['rackbeat']['api_url'], $config['rackbeat']['api_key']);
$xmlHelper = new XmlHelper();





// Load and parse the XML file
$xmlFilePath = __DIR__ . '/D000804.T881654-MOB-1.xml';

$xmlContent = file_get_contents($xmlFilePath);
$parsedData = $xmlHelper->parseXml($xmlContent);

// Import order and get the result
try {
    $orderId = $rackbeatClient->importOrder($xmlContent);
    echo "Order imported successfully. Order ID: " . $orderId . PHP_EOL;
} catch (Exception $e) {
    echo "Error importing order: " . $e->getMessage() . PHP_EOL;
}

// Print parsed data
echo "Parsed Order Data:" . PHP_EOL;
print_r($parsedData);