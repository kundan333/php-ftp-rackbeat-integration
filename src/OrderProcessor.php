<?php
namespace App;


class OrderProcessor
{
    private $sftpClient;
    private $rackbeatClient;
    private $xmlHelper;

    public function __construct($ftpClient, $rackbeatClient, $xmlHelper)
    {
        $this->sftpClient = $ftpClient;
        $this->rackbeatClient = $rackbeatClient;
        $this->xmlHelper = $xmlHelper;
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

    public function handleConfirmedOrders($orderId)
    {
        $status = $this->rackbeatClient->checkOrderStatus($orderId);
        if ($status === 'confirmed') {
            $orderData = $this->rackbeatClient->getOrderData($orderId);
            $this->updateXmlFile($orderData);
            $this->sftpClient->uploadFile('/path/to/local/directory/' . $orderId . '.xml', $orderId . '.xml');
        }
    }

    public function updateXmlFile($orderData)
    {
        $xmlContent = $this->xmlHelper->generateXml($orderData);
        file_put_contents('/path/to/local/directory/' . $orderData['id'] . '.xml', $xmlContent);
    }
}