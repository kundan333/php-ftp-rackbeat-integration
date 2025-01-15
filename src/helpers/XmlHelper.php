<?php

namespace App\Helpers;

class XmlHelper
{
    public function parseXml($xmlString)
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        
        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            throw new Exception('Failed to parse XML: ' . implode(', ', $errors));
        }
        
        return $xml;
    }

    public function generateXml($data)
    {
        $xml = new SimpleXMLElement('<root/>');
        array_walk_recursive($data, function($value, $key) use ($xml) {
            $xml->addChild($key, $value);
        });
        
        return $xml->asXML();
    }
}