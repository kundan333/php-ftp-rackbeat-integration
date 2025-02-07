<?php
namespace App;


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
            'confirmed_time' => $statusData['confirmedTime'] ?? null,
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
            if (isset($data['is_confirmed']) && $data['is_confirmed'] == 0) {
                // Ensure an order_number is stored (update your processOrders to save it if not already)
                if (!isset($data['id']) || empty($data['id'])) {
                    continue;
                }
                
                $fetchedOrder = $this->rackbeatClient->getOrderByNumber($data['id']);

                // Check if the order is booked. Adjust the condition based on your API response.
                if ($fetchedOrder && isset($fetchedOrder['is_booked']) && $fetchedOrder['is_booked'] === true) {
                    $orders[$key]['is_confirmed'] = 1;
                    $orders[$key]['confirmed_time'] = date('Y-m-d H:i:s');
                    
                    // Create order response XML
                    $this->createOrderResponse($fetchedOrder, $data['file']);
                    
                    $changed = true;
                }
            }
        }
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
                $file_upload = $this->sftpClient->uploadFile($order['file'], $remoteFilePath);
                
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
    
        // Create XML with correct structure
        $xmlString = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<OrderResponse 
                xmlns="urn:oasis:names:specification:ubl:schema:xsd:OrderResponse-2" 
                xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" 
                xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">' .
            '<cbc:CustomizationID>urn:fdc:peppol.eu:poacc:trns:order_response_advanced:3</cbc:CustomizationID>' .
            '<cbc:ProfileID>urn:fdc:peppol.eu:poacc:bis:advanced_ordering:3</cbc:ProfileID>' .
            '<cbc:ID>' . $rackbeatOrder['number'] . '</cbc:ID>' .
            '<cbc:IssueDate>' . date('Y-m-d') . '</cbc:IssueDate>' .
            '<cbc:IssueTime>' . date('H:i:s') . '</cbc:IssueTime>' .
            '<cbc:OrderResponseCode>CA</cbc:OrderResponseCode>' . // Changed from AP to CA
            '<cbc:DocumentCurrencyCode>' . $rackbeatOrder['currency'] . '</cbc:DocumentCurrencyCode>' .
            '<cac:OrderReference>' .
            '<cbc:ID>' . (string)$originalXml->xpath('//cbc:ID')[0] . '</cbc:ID>' .
            '</cac:OrderReference>' .
            '<cac:SellerSupplierParty>' .
            '<cac:Party>' .
            '<cbc:EndpointID schemeID="0088">7080010019356</cbc:EndpointID>' .
            '<cac:PartyIdentification>' .
            '<cbc:ID schemeID="0192">997066588</cbc:ID>' .
            '</cac:PartyIdentification>' .
            '<cac:PartyLegalEntity>' .
            '<cbc:RegistrationName>HARTMAN NORDIC AS</cbc:RegistrationName>' .
            '</cac:PartyLegalEntity>' .
            '</cac:Party>' .
            '</cac:SellerSupplierParty>' .
            '<cac:BuyerCustomerParty>' .
            '<cac:Party>' .
            '<cbc:EndpointID schemeID="0088">' . (string)$originalXml->xpath('//cac:BuyerCustomerParty//cbc:EndpointID')[0] . '</cbc:EndpointID>' .
            '<cac:PartyLegalEntity>' .
            '<cbc:RegistrationName>' . (string)$originalXml->xpath('//cac:BuyerCustomerParty//cac:PartyName/cbc:Name')[0] . '</cbc:RegistrationName>' .
            '</cac:PartyLegalEntity>' .
            '</cac:Party>' .
            '</cac:BuyerCustomerParty>';
    
        // Add order lines only if response code is CA
        foreach ($rackbeatOrder['lines'] as $lineIndex => $line) {
            $originalLineId = $lineIndex + 1; // Get original line ID
            $xmlString .= '<cac:OrderLine>' .
                '<cac:LineItem>' .
                '<cbc:ID>' . $line['id'] . '</cbc:ID>' .
                '<cbc:LineStatusCode>5</cbc:LineStatusCode>' . // Using valid UNCL1229 code
                '<cbc:Quantity unitCode="EA">' . $line['quantity'] . '</cbc:Quantity>' .
                '<cac:Price>' .
                '<cbc:PriceAmount currencyID="' . $rackbeatOrder['currency'] . '">' . $line['line_price'] . '</cbc:PriceAmount>' .
                '</cac:Price>' .
                '<cac:Item>' .
                '<cbc:Name>' . htmlspecialchars($line['name'], ENT_XML1) . '</cbc:Name>' .
                '<cac:SellersItemIdentification>' .
                '<cbc:ID>' . $line['item']['number'] . '</cbc:ID>' .
                '</cac:SellersItemIdentification>' .
                '</cac:Item>' .
                '</cac:LineItem>' .
                '<cac:OrderLineReference>' .
                '<cbc:LineID>' . $originalLineId . '</cbc:LineID>' .
                '</cac:OrderLineReference>' .
                '</cac:OrderLine>';
        }
    
        $xmlString .= '</OrderResponse>';
    
        // Save file
        $responseFilename = basename($originalOrderFile);
        $responseFilePath = $responseDir . '/' . $responseFilename;
        file_put_contents($responseFilePath, $xmlString);
        
        return $responseFilePath;
    }
}