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

    public function processOrders()
    {
        $files = $this->sftpClient->listFiles();
        foreach ($files as $file) {
            if ($this->isOrderFile($file)) {
                $localFile = $this->downloadOrderFile($file);
                $xmlData = $this->xmlHelper->parseXml(file_get_contents($localFile));
                $orderId = $this->rackbeatClient->importOrder($xmlData);
                $this->handleConfirmedOrders($orderId);
            }
        }
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