--TEST--
stdlib SoapClient SoapVar XSD_ANYXML raw XML embed (#32241, ext/soap/php_encoding.c)
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
$c->__soapCall('echo', [new SoapVar('<raw>x</raw>', XSD_ANYXML)]);
$s = (string) $c->__getLastRequest();
echo 'raw_embed=', str_contains($s, '<ns1:echo><raw>x</raw></ns1:echo>') ? '1' : '0', "\n";
echo 'not_escaped=', str_contains($s, '&lt;raw&gt;') ? '0' : '1', "\n";
echo 'not_param0=', str_contains($s, '<param0>') ? '0' : '1', "\n";
$c->__soapCall('echo', [new SoapVar('plain', XSD_ANYXML)]);
$plain = (string) $c->__getLastRequest();
echo 'plain_embed=', str_contains($plain, '<ns1:echo>plain</ns1:echo>') ? '1' : '0', "\n";
echo 'plain_untyped=', (str_contains($plain, 'xsi:type') && str_contains($plain, '>plain<')) ? '0' : '1', "\n";
?>
--EXPECT--
raw_embed=1
not_escaped=1
not_param0=1
plain_embed=1
plain_untyped=1
