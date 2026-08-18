--TEST--
stdlib SoapClient SoapVar XSD_NMTOKENS/IDREFS/ENTITIES to_xml_list (#32272, ext/soap/php_encoding.c)
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
$c->__soapCall('echo', [new SoapVar(['a', 'b', 'c'], XSD_NMTOKENS)]);
$s = (string) $c->__getLastRequest();
echo 'nmtokens=', str_contains($s, 'xsi:type="xsd:NMTOKENS">a b c<') ? '1' : '0', "\n";
echo 'not_array=', str_contains($s, '>Array<') ? '0' : '1', "\n";
$c->__soapCall('echo', [new SoapVar(['id1', 'id2'], XSD_IDREFS)]);
$id = (string) $c->__getLastRequest();
echo 'idrefs=', str_contains($id, 'xsi:type="xsd:IDREFS">id1 id2<') ? '1' : '0', "\n";
$c->__soapCall('echo', [new SoapVar(['e1', 'e2'], XSD_ENTITIES)]);
$ent = (string) $c->__getLastRequest();
echo 'entities=', str_contains($ent, 'xsi:type="xsd:ENTITIES">e1 e2<') ? '1' : '0', "\n";
$c->__soapCall('echo', [new SoapVar("a  \tb", XSD_NMTOKENS)]);
$str = (string) $c->__getLastRequest();
echo 'collapse=', str_contains($str, '>a b<') ? '1' : '0', "\n";
?>
--EXPECT--
nmtokens=1
not_array=1
idrefs=1
entities=1
collapse=1
