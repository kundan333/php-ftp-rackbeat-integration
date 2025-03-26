<?php
namespace App;

use DateTime;


class OrderProcessor
{
    private $sftpClient;
    private $rackbeatClient;
    // private $xmlHelper;

    public function __construct($ftpClient, $rackbeatClient)
    {
        $this->sftpClient = $ftpClient;
        $this->rackbeatClient = $rackbeatClient;
        // $this->xmlHelper = $xmlHelper;
    }

    public function processOrders($remote_dir,$local_dir)
    {

        //download all orders from remote directory to local directory
        $this->sftpClient->downloadAllOrders($remote_dir,$local_dir);
        //loop through all files in the local directory

        $files = array_filter(scandir($local_dir), function($file) {
            return pathinfo($file, PATHINFO_EXTENSION) === 'xml';
        });
        // $files = scandir($local_dir);
        foreach ($files as $file) {
            //check with json if file already processed or send to rackbeat


            if ($this->isOrderFile($file) && !$this->checkOrderProcessed($file)) {

                $localFile = $local_dir . '/' . $file;
                try {
                // var_dump(file_get_contents($localFile));exit;
                $xmlContent = file_get_contents($localFile);


               
                $orderId = $this->rackbeatClient->importOrder($xmlContent);
                if ($orderId) {
                    $this->updateOrderStatus($orderId, [
                        'file_name' => $file,
                        'filePath' => $localFile,
                        'sendToRackbeat' => 1,
                        'rackbeatSendTime' => date('Y-m-d H:i:s')
                    ]);
                
                }
            } catch (\Exception $e) {
                echo "Error importing order: " . $e->getMessage() . PHP_EOL;

                $errorFile = __DIR__ . '/../order-errors.json';
                $errorData = [];

                if (file_exists($errorFile)) {
                    $errorData = json_decode(file_get_contents($errorFile), true);
                }

                $errorData[$file] = [
                    'error' => $e->getMessage(),
                    'order_id' => $orderId ?? null,
                ];

                file_put_contents($errorFile, json_encode($errorData, JSON_PRETTY_PRINT));


            }
        }

        }

    }

    private function checkOrderProcessed($filename){

        $statusFile = __DIR__ . '/../order_status.json';
        if (file_exists($statusFile)) {
            $orderStatus = json_decode(file_get_contents($statusFile), true);

            if (isset($orderStatus[$filename]) && $orderStatus[$filename]['send_to_rackbeat'] === 1) {
            return true;
            }
        }

        return false;
    }


    private function updateOrderStatus($order_id, array $statusData)
    {
        $statusFile = __DIR__ . '/../order_status.json';
        $orderStatus = [];

        if (file_exists($statusFile)) {
            $orderStatus = json_decode(file_get_contents($statusFile), true);
        }

        $orderStatus[$statusData['file_name']] = [
            'id' => $order_id,
            'file' => $statusData['filePath'],
            'send_to_rackbeat' => $statusData['sendToRackbeat'] ?? 0,
            'is_confirmed' => $statusData['isConfirmed'] ?? 0,
            'order_send_to_remote' => $statusData['orderSendToRemote'] ?? 0,
            'rackbeat_send_time' => $statusData['rackbeatSendTime'] ?? null,
            'updated_time' => $statusData['confirmedTime'] ?? null,
        ];

        file_put_contents($statusFile, json_encode($orderStatus, JSON_PRETTY_PRINT));
    }

    private function isOrderFile($file)
    {
        return pathinfo($file, PATHINFO_EXTENSION) === 'xml';
    }

    private function downloadOrderFile($file)
    {
        $localFile = '/path/to/local/directory/' . basename($file);
        $this->sftpClient->downloadFile($file, $localFile);
        return $localFile;
    }

    // public function handleConfirmedOrders($orderId)
    // {
    //     $status = $this->rackbeatClient->checkOrderStatus($orderId);
    //     if ($status === 'confirmed') {
    //         $orderData = $this->rackbeatClient->getOrderData($orderId);
    //         $this->updateXmlFile($orderData);
    //         $this->sftpClient->uploadFile('/path/to/local/directory/' . $orderId . '.xml', $orderId . '.xml');
    //     }
    // }

    // public function updateXmlFile($orderData)
    // {
    //     $xmlContent = $this->xmlHelper->generateXml($orderData);
    //     file_put_contents('/path/to/local/directory/' . $orderData['id'] . '.xml', $xmlContent);
    // }

    public function confirmBookedOrders()
    {
        $orderStatusFile = __DIR__ . '/../order_status.json';
        if (!file_exists($orderStatusFile)) {
            return;
        }
        $orders = json_decode(file_get_contents($orderStatusFile), true);
        $changed = false;
        foreach ($orders as $key => $data) {
            // Ensure an order_number is stored
            if (!isset($data['id']) || empty($data['id'])) {
                continue;
            }
            
            $fetchedOrder = $this->rackbeatClient->getOrderByNumber($data['id']);
            if (!$fetchedOrder) {
                continue;
            }

            // Handle unconfirmed orders (keep existing logic)
            if (isset($data['is_confirmed']) && $data['is_confirmed'] == 0) {
                // Check if the order is booked
                if (isset($fetchedOrder['is_booked']) && $fetchedOrder['is_booked'] === true) {
                    $orders[$key]['is_confirmed'] = 1;
                    // Change confirmed_time to updated_time
                    $orders[$key]['updated_time'] = $fetchedOrder['updated_at'];
                    $changed = true;
                    
                    // Create order response XML
                    $this->createOrderResponse($fetchedOrder, $data['file']);
                }
            } 
            // Handle already confirmed orders - check for updates
            else if (isset($data['is_confirmed']) && $data['is_confirmed'] == 1) {
                // Check if order has been updated since our last check
                if (isset($fetchedOrder['updated_at']) && 
                    (!isset($data['updated_time']) || 
                     strtotime($fetchedOrder['updated_at']) > strtotime($data['updated_time']))) {
                    
                    // Update the timestamp and send to FTP
                    $orders[$key]['updated_time'] = $fetchedOrder['updated_at'];

                    $orders[$key]['order_send_to_remote'] = 0;
                    $changed = true;
                    
                    // Create order response XML and send to FTP
                    $this->createOrderResponse($fetchedOrder, $data['file']);
                }
            }
        }
                
        // Save the updated status file
        if ($changed) {
            file_put_contents($orderStatusFile, json_encode($orders, JSON_PRETTY_PRINT));
        }
    }

    public function sendConfirmedOrdersToRemote($remoteConfirmationDirectory)
    {
        $orderStatusFile = __DIR__ . '/../order_status.json';
        if (!file_exists($orderStatusFile)) {
            return;
        }
        $orders = json_decode(file_get_contents($orderStatusFile), true);
        $changed = false;
        foreach ($orders as $key => &$order) {
            if ((isset($order['is_confirmed']) && $order['is_confirmed'] == 1) 
                && (isset($order['order_send_to_remote']) && $order['order_send_to_remote'] == 0)) {
                
                // Calculate remote file path using the remote confirmation directory and the file's basename
                $remoteFilePath = $remoteConfirmationDirectory. '/' . basename($order['file']);
                echo $remoteFilePath;
                // Upload the file via SFTP

                $responseFile = __DIR__ . '/../order-response/'.basename($order['file']);

                $file_upload = $this->sftpClient->uploadFile($responseFile, $remoteFilePath);
                
                if ($file_upload ) {
                    $order['order_send_to_remote'] = 1;
                    $changed = true;
                }
            }
        }
        if ($changed) {
            file_put_contents($orderStatusFile, json_encode($orders, JSON_PRETTY_PRINT));
        }
    }
    public function createOrderResponse($rackbeatOrder, $originalOrderFile) 
    {
        $responseDir = __DIR__ . '/../order-response';
        if (!is_dir($responseDir)) {
            mkdir($responseDir, 0777, true);
        }
    
        // Load original order
        $originalXml = simplexml_load_file($originalOrderFile);
        $originalXml->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $originalXml->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    
        // Build XML in correct order
        $xmlString = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<OrderResponse xmlns="urn:oasis:names:specification:ubl:schema:xsd:OrderResponse-2" 
                xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" 
                xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">' . "\n" .
            '    <cbc:CustomizationID>urn:fdc:peppol.eu:poacc:trns:order_response:3</cbc:CustomizationID>' . "\n" .
            '    <cbc:ProfileID>urn:fdc:peppol.eu:poacc:bis:ordering:3</cbc:ProfileID>' . "\n" .
            '    <cbc:ID>' . $rackbeatOrder['number'] . '</cbc:ID>' . "\n" .
            '    <cbc:SalesOrderID>' . $rackbeatOrder['number'] . '-' . date('Ymd') . '</cbc:SalesOrderID>' . "\n" .
            '    <cbc:IssueDate>' . date('Y-m-d') . '</cbc:IssueDate>' . "\n" .
            '    <cbc:IssueTime>' . date('H:i:s') . '</cbc:IssueTime>' . "\n" .
            '    <cbc:OrderResponseCode>CA</cbc:OrderResponseCode>' . "\n" .
            '    <cbc:Note>Order processed by Rackbeat</cbc:Note>' . "\n" .
            '    <cbc:DocumentCurrencyCode>' . $rackbeatOrder['currency'] . '</cbc:DocumentCurrencyCode>' . "\n" .
            '    <cbc:CustomerReference>' . $rackbeatOrder['customer']['number'] . '</cbc:CustomerReference>' . "\n" .
            '    <cac:OrderReference>' . "\n" .
            '        <cbc:ID>' . (string)$originalXml->xpath('//cbc:ID')[0] . '</cbc:ID>' . "\n" .
            '    </cac:OrderReference>' . "\n" .
            '    <cac:SellerSupplierParty>' . "\n" .
            '        <cac:Party>' . "\n" .
            '            <cbc:EndpointID schemeID="0088">7080010019356</cbc:EndpointID>' . "\n" .
            '            <cac:PartyIdentification>' . "\n" .
            '                <cbc:ID schemeID="0192">997066588</cbc:ID>' . "\n" .
            '            </cac:PartyIdentification>' . "\n" .
            '            <cac:PartyLegalEntity>' . "\n" .
            '                <cbc:RegistrationName>HARTMAN NORDIC AS</cbc:RegistrationName>' . "\n" .
            '            </cac:PartyLegalEntity>' . "\n" .
            '        </cac:Party>' . "\n" .
            '    </cac:SellerSupplierParty>' . "\n" .
            '    <cac:BuyerCustomerParty>' . "\n" .
            '        <cac:Party>' . "\n" .
            '            <cbc:EndpointID schemeID="0088">7080001302962</cbc:EndpointID>' . "\n" .
            '            <cac:PartyLegalEntity>' . "\n" .
            '                <cbc:RegistrationName>' . (string)$originalXml->xpath('//cac:BuyerCustomerParty//cac:PartyName/cbc:Name')[0] . '</cbc:RegistrationName>' . "\n" .
            '            </cac:PartyLegalEntity>' . "\n" .
            '        </cac:Party>' . "\n" .
            '    </cac:BuyerCustomerParty>' . "\n";

        // Get delivery dates from Rackbeat order

        $startDateTime = new \DateTime($rackbeatOrder['booked_at']);
        $endDateTime = new \DateTime($rackbeatOrder['due_date']);
        
        // Add delivery section with actual dates
        $xmlString .= '    <cac:Delivery>' . "\n" .
            '        <cac:PromisedDeliveryPeriod>' . "\n" .
            // '            <cbc:StartDate>' . $startDateTime->format('Y-m-d') . '</cbc:StartDate>' . "\n" .
            // '            <cbc:StartTime>' . $startDateTime->format('H:i:s') . '</cbc:StartTime>' . "\n" .
            '            <cbc:EndDate>' . $endDateTime->format('Y-m-d') . '</cbc:EndDate>' . "\n" .
            // '            <cbc:EndTime>16:00:00</cbc:EndTime>' . "\n" .
            '        </cac:PromisedDeliveryPeriod>' . "\n" .
            '    </cac:Delivery>' . "\n";
    
        // Add order lines only if response code is CA
        $lineID = 1;
        foreach ($rackbeatOrder['lines'] as $lineIndex => $line) {
            // Build BuyersItemIdentification node only if it exists in the original XML
            $buyersNodes = $originalXml->xpath('//cac:Item[cac:SellersItemIdentification/cbc:ID="' . $line['item']['number'] . '"]/cac:BuyersItemIdentification/cbc:ID');
            $buyersXml = '';
            if (!empty($buyersNodes) && !empty($buyersNodes[0])) {
                $buyersXml = '<cac:BuyersItemIdentification>' . "\n" .
                             '    <cbc:ID>' . $buyersNodes[0] . '</cbc:ID>' . "\n" .
                             '</cac:BuyersItemIdentification>' . "\n";
            }
            
            // Build StandardItemIdentification node if barcode is not empty
            $standardXml = '';
            if (isset($line['item']['barcode']) && !empty($line['item']['barcode'])) {
                $standardXml = '<cac:StandardItemIdentification>' . "\n" .
                               '    <cbc:ID schemeID="0160">' . $line['item']['barcode'] . '</cbc:ID>' . "\n" .
                               '</cac:StandardItemIdentification>' . "\n";
            }
            
            $xmlString .= '<cac:OrderLine>' .
                          '<cac:LineItem>' .
                          '<cbc:ID>' . $lineID . '</cbc:ID>' .
                          '<cbc:LineStatusCode>5</cbc:LineStatusCode>' .
                          '<cbc:Quantity unitCode="EA">' . $line['quantity'] . '</cbc:Quantity>' .
                          '<cac:Price>' .
                          '<cbc:PriceAmount currencyID="' . $rackbeatOrder['currency'] . '">' . $line['line_price'] . '</cbc:PriceAmount>' .
                          '</cac:Price>' .
                          '<cac:Item>' .
                          '<cbc:Name>' . htmlspecialchars($line['name'], ENT_XML1) . '</cbc:Name>' .
                          $buyersXml .
                          '<cac:SellersItemIdentification>' . "\n" .
                          '    <cbc:ID>' . $line['item']['number'] . '</cbc:ID>' . "\n" .
                          '</cac:SellersItemIdentification>' . "\n" .
                          $standardXml .
                          '</cac:Item>' .
                          '</cac:LineItem>' .
                          '<cac:OrderLineReference>' .
                          '<cbc:LineID>' . $lineID . '</cbc:LineID>' .
                          '</cac:OrderLineReference>' .
                          '</cac:OrderLine>';
            $lineID += 1;    
        }
    
        $xmlString .= '</OrderResponse>';
    
        // Save formatted XML
        $dom = new \DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xmlString);
        $formattedXml = $dom->saveXML();
        
        // Save file
        $responseFilename = basename($originalOrderFile);
        $responseFilePath = $responseDir . '/' . $responseFilename;
        file_put_contents($responseFilePath, $formattedXml);
        
        return $responseFilePath;
    }
}