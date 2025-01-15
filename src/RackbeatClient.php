<?php

namespace App;

use GuzzleHttp\Client;

class RackbeatClient
{
    private $apiUrl;
    private $apiKey;
    private $client;

    public function __construct($apiUrl, $apiKey)
    {
        $this->apiUrl = $apiUrl;
        $this->apiKey = $apiKey;
        $this->client = new Client([
            'base_uri' => $this->apiUrl,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function importOrder($xmlData)
    {
        $orderData = $this->parseXmlData($xmlData);

        // Look up the customer in Rackbeat using the EAN number
        $customer = $this->getCustomerByEan($orderData['customer_ean']);
        if (!$customer) {
            throw new \Exception('Customer not found');
        }

        // Create the order in Rackbeat
        $orderPayload = [
            'customer_id' => $customer['id'],
            'layout_id' => null,
            'employee_id' => null,
            'our_reference_id' => null,
            'delivery_responsible_id' => null,
            'your_reference_id' => null,
            'payment_terms_id' => null,
            'delivery_terms' => null,
            'other_reference' => $orderData['their_reference'],
            'vat_zone' => 'domestic',
            'heading' => $orderData['order_number'],
            'currency' => 'NOK',
            'currency_rate' => null,
            'number' => null,
            'barcode' => null,
            'pdf_layout' => null,
            'lines' => $orderData['order_lines'],
            'general_discount' => null,
            'deliver_at' => $orderData['delivery_date'],
            'order_date' => date('Y-m-d'),
            'note' => null,
            'address_name' => $orderData['address_name'],
            'address_street' => $orderData['address_street'],
            'address_street2' => null,
            'address_state' => null,
            'address_city' => $orderData['address_city'],
            'address_zipcode' => $orderData['address_zipcode'],
            'address_country' => $orderData['address_country'],
            'delivery_address_name' => $orderData['delivery_address_name'],
            'delivery_address_street' => $orderData['delivery_address_street'],
            'delivery_address_street2' => null,
            'delivery_address_state' => null,
            'delivery_address_city' => $orderData['delivery_address_city'],
            'delivery_address_zipcode' => $orderData['delivery_address_zipcode'],
            'delivery_address_country' => $orderData['delivery_address_country'],
            'billing_address' => [],
            'delivery_address' => [],
            'custom_fields' => null,
        ];

        $response = $this->client->post('/orders/drafts', [
            'json' => $orderPayload,
        ]);

        $responseData = json_decode($response->getBody(), true);
        return $responseData['id'];
    }

    private function parseXmlData($xmlData)
    {
        $xml = simplexml_load_string($xmlData);
        $namespaces = $xml->getNamespaces(true);
        $xml->registerXPathNamespace('ns', $namespaces['']);

        $orderData = [];

        // Extract customer EAN number
        $customerEanNodes = $xml->xpath('//ns:Body/ns:ORDERS/ns:g002/ns:NAD[ns:e01_3035="BY"]/ns:cmp01/ns:e01_3039');
        $orderData['customer_ean'] = (string) ($customerEanNodes[0] ?? '');

        // Extract other fields
        $orderData['delivery_address'] = (string) ($xml->xpath('//ns:Body/ns:ORDERS/ns:g002/ns:NAD[ns:e01_3035="BY"]/ns:cmp04/ns:e01_3042')[0] ?? '');
        $orderData['their_reference'] = (string) ($xml->xpath('//ns:Body/ns:ORDERS/ns:BGM/ns:cmp02/ns:e01_1004')[0] ?? '');
        $orderData['order_number'] = (string) ($xml->xpath('//ns:Body/ns:ORDERS/ns:BGM/ns:cmp02/ns:e01_1004')[0] ?? '');
        $orderData['delivery_date'] = (string) ($xml->xpath('//ns:Body/ns:ORDERS/ns:DTM/ns:cmp01/ns:e02_2380')[0] ?? '');
        $orderData['order_lines'] = $this->parseOrderLines($xml->xpath('//ns:Body/ns:ORDERS/ns:g028/ns:LIN'));

        // Extract address fields
        $orderData['address_name'] = (string) ($xml->xpath('//ns:Body/ns:ORDERS/ns:g002/ns:NAD[ns:e01_3035="BY"]/ns:cmp03/ns:e01_3036')[0] ?? '');
        $orderData['address_street'] = (string) ($xml->xpath('//ns:Body/ns:ORDERS/ns:g002/ns:NAD[ns:e01_3035="BY"]/ns:cmp04/ns:e01_3042')[0] ?? '');
        $orderData['address_city'] = (string) ($xml->xpath('//ns:Body/ns:ORDERS/ns:g002/ns:NAD[ns:e01_3035="BY"]/ns:e02_3164')[0] ?? '');
        $orderData['address_zipcode'] = (string) ($xml->xpath('//ns:Body/ns:ORDERS/ns:g002/ns:NAD[ns:e01_3035="BY"]/ns:e03_3251')[0] ?? '');
        $orderData['address_country'] = (string) ($xml->xpath('//ns:Body/ns:ORDERS/ns:g002/ns:NAD[ns:e01_3035="BY"]/ns:e04_3207')[0] ?? '');

        // Extract delivery address fields
        $orderData['delivery_address_name'] = (string) ($xml->xpath('//ns:Body/ns:ORDERS/ns:g002/ns:NAD[ns:e01_3035="DP"]/ns:cmp03/ns:e01_3036')[0] ?? '');
        $orderData['delivery_address_street'] = (string) ($xml->xpath('//ns:Body/ns:ORDERS/ns:g002/ns:NAD[ns:e01_3035="DP"]/ns:cmp04/ns:e01_3042')[0] ?? '');
        $orderData['delivery_address_city'] = (string) ($xml->xpath('//ns:Body/ns:ORDERS/ns:g002/ns:NAD[ns:e01_3035="DP"]/ns:e02_3164')[0] ?? '');
        $orderData['delivery_address_zipcode'] = (string) ($xml->xpath('//ns:Body/ns:ORDERS/ns:g002/ns:NAD[ns:e01_3035="DP"]/ns:e03_3251')[0] ?? '');
        $orderData['delivery_address_country'] = (string) ($xml->xpath('//ns:Body/ns:ORDERS/ns:g002/ns:NAD[ns:e01_3035="DP"]/ns:e04_3207')[0] ?? '');

        return $orderData;
    }

    private function parseOrderLines($orderLines)
    {
        $lines = [];
        foreach ($orderLines as $line) {
            $lines[] = [
                'product_id' => (string) $line->cmp01->e01_7140,
                'quantity' => (int) $line->QTY->cmp01->e02_6060,
                'price' => (float) $line->MOA->cmp01->e02_5004,
            ];
        }
        return $lines;
    }

    private function getCustomerByEan($ean)
    {
        $response = $this->client->get('/customers', [
            'query' => ['ean' => $ean],
        ]);

        $responseData = json_decode($response->getBody(), true);

        // Log API response
        error_log('API Response: ' . print_r($responseData, true));

        return $responseData[0] ?? null;
    }

    public function checkOrderStatus($orderId)
    {
        $response = $this->client->get("/orders/{$orderId}");
        $order = json_decode($response->getBody(), true);
        return $order['status'];
    }

    public function updateOrderStatus($orderId, $status)
    {
        $response = $this->client->put("/orders/{$orderId}", [
            'json' => ['status' => $status],
        ]);

        return json_decode($response->getBody(), true);
    }
}