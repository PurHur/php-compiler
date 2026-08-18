<?php
/**
 * Repro — SoapVar APACHE_MAP encodes item/key/value Map (php-src to_xml_map).
 */
declare(strict_types=1);

if (!class_exists('SoapClient')) {
    echo "soap_unavailable\n";
    exit(0);
}

$resp = dirname(__DIR__).'/fixtures/soap/echo.response.xml';
$c = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
    'style' => SOAP_RPC,
    'use' => SOAP_ENCODED,
]);
$c->__soapCall('echo', [new SoapVar(['k' => 'v'], APACHE_MAP)]);
$s = (string) $c->__getLastRequest();
echo 'xmlns_apache=', str_contains($s, 'http://xml.apache.org/xml-soap') ? '1' : '0', "\n";
echo 'xsi_map=', (str_contains($s, 'xsi:type="ns2:Map"') || str_contains($s, 'xsi:type="apache:Map"')) ? '1' : '0', "\n";
echo 'item_key=', str_contains($s, '<key xsi:type="xsd:string">k</key>') ? '1' : '0', "\n";
echo 'item_value=', str_contains($s, '<value xsi:type="xsd:string">v</value>') ? '1' : '0', "\n";
echo 'no_bag=', (str_contains($s, 'enc_type') || str_contains($s, 'enc_value')) ? '0' : '1', "\n";
