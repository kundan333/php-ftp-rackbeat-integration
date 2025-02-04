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

        //var_dump($customer['number']);exit();

        if (!$customer) {
            throw new \Exception('Customer not found with ean: ' . $orderData['customer_ean']);
        }

        $employeeId= $this->getEmployeeIDName('Ordrekontor MR');
        

        // Create the order in Rackbeat
        $orderPayload = [
            'customer_id' => $customer['number'],
            'layout_id' => $customer['layout_id'],
            'employee_id' => $employeeId,
            'our_reference_id' => $employeeId, //Ordrekontor MR
            // 'delivery_responsible_id' => null,
            // 'your_reference_id' => null,
            'payment_terms_id' => $customer['payment_terms_id'],
            // 'delivery_terms' => null,
            // 'other_reference' => $orderData['their_reference']??'',
            'vat_zone' => 'domestic',
            'heading' => $orderData['order_number'],
            'currency' => 'NOK',
            // 'currency_rate' => null,
            // 'number' => null,
            // 'barcode' => null,
            // 'pdf_layout' => null,
            'lines' => $orderData['order_lines'],
            // 'general_discount' => null,
            'deliver_at' => $orderData['delivery_date'],
            'order_date' => date('Y-m-d'),
            // 'note' => null,
            'address_name' => $orderData['address_name'],
            'address_street' => $orderData['address_street'],
            'address_street2' => $orderData['address_street2'],
            // 'address_state' => null,
            'address_city' => $orderData['address_city'],
            'address_zipcode' => $orderData['address_zipcode'],
            'address_country' => $orderData['address_country'],
            'delivery_address_name' => $orderData['delivery_address_name'],
            'delivery_address_street' => $orderData['delivery_address_street'],
            'delivery_address_street2' =>  $orderData['delivery_address_street2'],
            // 'delivery_address_state' => null,
            'delivery_address_city' => $orderData['delivery_address_city'],
            'delivery_address_zipcode' => $orderData['delivery_address_zipcode'],
            'delivery_address_country' => $orderData['delivery_address_country'],
            'billing_address' => [],
            'delivery_address' => [
                'email'=>$orderData['delivery_address_email'],
            'phone'=>$orderData['delivery_address_phone']
        ],
            // 'custom_fields' => null,
        ];



        $response = $this->client->post('api/orders/drafts', [
            'json' => $orderPayload,
        ]);

        $responseData = json_decode($response->getBody(), true);

       // file_put_contents(__DIR__ . '/response.txt', json_encode($responseData, JSON_PRETTY_PRINT));

        if (!isset($responseData['order']['number'])) {
            return null;
        }

        return $responseData['order']['number'];
    }

    private function parseXmlData($xmlData)
    {
        $orderData = [];

        try {
        $xml = simplexml_load_string($xmlData);
        
        $namespaces = $xml->getNamespaces(true);
        $xml->registerXPathNamespace('cbc', $namespaces['cbc']);
        $xml->registerXPathNamespace('cac', $namespaces['cac']);

        // Extract customer EAN number
        $customerEanNodes = $xml->xpath('//cac:AccountingCustomerParty/cac:Party/cbc:EndpointID');
        $orderData['customer_ean'] = (string) ($customerEanNodes[0] ?? '');

        // Extract other fields
        // $orderData['their_reference'] = (string) ($xml->xpath('//cbc:CustomerReference')[0] ?? '');
        $orderData['order_number'] = (string) ($xml->xpath('//cbc:ID')[0] ?? '');
        $orderData['delivery_date'] = (string) ($xml->xpath('//cac:RequestedDeliveryPeriod/cbc:StartDate')[0] ?? '');
        $orderData['order_lines'] = $this->parseOrderLines($xml->xpath('//cac:OrderLine'));

        // Extract address fields
        $orderData['address_name'] = (string) ($xml->xpath('//cac:AccountingCustomerParty/cac:Party/cac:PartyName/cbc:Name')[0] ?? '');
        $orderData['address_street'] = (string) ($xml->xpath('//cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:StreetName')[0] ?? '');
        $orderData['address_street2'] = (string) ($xml->xpath('//cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:AdditionalStreetName')[0] ?? '');
        $orderData['address_city'] = (string) ($xml->xpath('//cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:CityName')[0] ?? '');
        $orderData['address_zipcode'] = (string) ($xml->xpath('//cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:PostalZone')[0] ?? '');
        // $orderData['address_country'] = (string) ($xml->xpath('//cac:BuyerCustomerParty/cac:Party/cac:PostalAddress/cac:Country/cbc:IdentificationCode')[0] ?? '');
        $orderData['address_country'] ="Norge";
        // Extract delivery address fields
        $orderData['delivery_address_name'] = (string) ($xml->xpath('//cac:DeliveryLocation/cbc:Name')[0] ?? '');
        $orderData['delivery_address_street'] = (string) ($xml->xpath('//cac:DeliveryLocation/cac:Address/cbc:StreetName')[0] ?? '');
        $orderData['delivery_address_street2'] = (string) ($xml->xpath('//cac:DeliveryLocation/cac:Address/cbc:AdditionalStreetName')[0] ?? '');
        $orderData['delivery_address_city'] = (string) ($xml->xpath('//cac:DeliveryLocation/cac:Address/cbc:CityName')[0] ?? '');
        $orderData['delivery_address_zipcode'] = (string) ($xml->xpath('//cac:DeliveryLocation/cac:Address/cbc:PostalZone')[0] ?? '');
        $orderData['delivery_address_email'] = (string) ($xml->xpath('//cac:Delivery/cac:DeliveryParty/cac:Contact/cbc:ElectronicMail')[0] ?? '');
        $orderData['delivery_address_phone'] = (string) ($xml->xpath('//cac:Delivery/cac:DeliveryParty/cac:Contact/cbc:Telephone')[0] ?? '');
        // $orderData['delivery_address_country'] = (string) ($xml->xpath('//cac:DeliveryLocation/cac:Address/cac:Country/cbc:IdentificationCode')[0] ?? '');
        $orderData['delivery_address_country'] ="Norge";
        } catch (\Throwable $e) {
            file_put_contents(__DIR__ . '/../error.txt', $e->getMessage());
        }


        return $orderData;
    }

    private function parseOrderLines($orderLines)
    {
        $lines = [];
        foreach ($orderLines as $line) {
            $lines[] = [
                'item_id' => (string) $line->xpath('cac:LineItem/cac:Item/cac:SellersItemIdentification/cbc:ID')[0],
                // 'name' => (string) $line->xpath('cac:LineItem/cac:Item/cbc:Name')[0],
                'quantity' => (string) $line->xpath('cac:LineItem/cbc:Quantity')[0],
                // 'line_price' => (string) $line->xpath('cac:LineItem/cbc:LineExtensionAmount')[0],
                // 'cost_price' => null,
                // 'variations' => [],
                // 'location_id' => null,
                // 'discount_percentage' => null,
                // 'vat_percentage' => (string) $line->xpath('cac:LineItem/cac:Item/cac:ClassifiedTaxCategory/cbc:Percent')[0],
                // 'unit_id' => (string) $line->xpath('cac:LineItem/cbc:Quantity/@unitCode')[0],
                // 'custom_fields' => [],
            ];
        }
        // var_dump($lines);exit;
        return $lines;
    }

    public function getCustomer($customerId)
    {
        $response = $this->client->get('api/customers/' . $customerId);

        $responseData = json_decode($response->getBody(), true);

        return $responseData['customer'] ?? null;
    }

    private function getCustomerByEan($ean)
    {
        $response = $this->client->get('api/customers', [
            'query' => ['simple_filter[ean]' => $ean],
        ]);

        $responseData = json_decode($response->getBody(), true);

        return $responseData['customers'][0] ?? null;
    }

    private function getEmployeeIDName($name)
    {
        $response = $this->client->get('api/employees', [
            'query' => ['name' => $name],
        ]);

        $responseData = json_decode($response->getBody(), true);

        return $responseData['employees'][0]['number'] ?? '';
    }

    public function checkOrderStatus($orderId)
    {
        $response = $this->client->get("api/orders/{$orderId}");
        $order = json_decode($response->getBody(), true);
        return $order['status'];
    }

    public function updateOrderStatus($orderId, $status)
    {
        $response = $this->client->put("api/orders/{$orderId}", [
            'json' => ['status' => $status],
        ]);

        return json_decode($response->getBody(), true);
    }

    public function getOrderByNumber($orderNumber)
    {
        $response = $this->client->get("api/orders", [
            'query' => ['simple_filter[number]' => $orderNumber],
        ]);

        $orderData = json_decode($response->getBody(), true);


        if (isset($orderData['orders']) && count($orderData['orders']) > 0) {
            return $orderData['orders'][0];
        }

        return null;
    }

    public function checkOrderIsBooked($orderData){

        if ($orderData !== null && isset($orderData['is_booked']) && $orderData['is_booked'] === true) {
            return true;
        }
        return false;

    }



}