--TEST--
stdlib SoapClient SoapVar APACHE_MAP item/key/value (#32222, ext/soap/php_encoding.c)
--FILE--
<?php
$resp = __DIR__ . '/test/fixtures/soap/echo.response.xml';
if (!is_file($resp)) {
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/echo.response.xml';
}
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
?>
--EXPECT--
xmlns_apache=1
xsi_map=1
item_key=1
item_value=1
no_bag=1
