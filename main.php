<?php

require 'vendor/autoload.php';

use App\RackbeatClient;
use App\Helpers\XmlHelper;
use App\SftpClient;

// Configuration
$config = require 'src/config/config.php';

// Initialize RackbeatClient, XmlHelper, and SftpClient
$rackbeatClient = new RackbeatClient($config['rackbeat']['api_url'], $config['rackbeat']['api_key']);
$xmlHelper = new XmlHelper();
$sftpClient = new SftpClient($config['ftp']['host'], $config['ftp']['port'], $config['ftp']['username'], $config['ftp']['password']);

// Define local directory to save orders
$localOrderDirectory = __DIR__ . '/orders';

// Create local directory if it doesn't exist
if (!is_dir($localOrderDirectory)) {
    mkdir($localOrderDirectory, 0777, true);
}

// Download all orders from remote directory to local directory
$sftpClient->downloadAllOrders($config['order']['remote_order_directory'], $localOrderDirectory);

// Load and parse the XML file
$xmlFilePath = $localOrderDirectory . '/out_816131721_20250120-011230-1737375150910.xml';
$xmlContent = file_get_contents($xmlFilePath);
$parsedData = $xmlHelper->parseXml($xmlContent);

// Import order and get the result
try {
    $orderId = $rackbeatClient->importOrder($xmlContent);

    // Map order ID and XML file name
    echo "Order imported successfully. Order ID: " . $orderId . PHP_EOL;
} catch (Exception $e) {
    echo "Error importing order: " . $e->getMessage() . PHP_EOL;
}

// Print parsed data
echo "Parsed Order Data:" . PHP_EOL;
print_r($parsedData);