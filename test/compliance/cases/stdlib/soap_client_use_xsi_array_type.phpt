--TEST--
stdlib SoapClient features SOAP_USE_XSI_ARRAY_TYPE (#21715 / #32221 Zend Array xsi:type)
--FILE--
<?php
$resp = __DIR__ . '/test/fixtures/soap/echo.response.xml';
if (!is_file($resp)) {
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/echo.response.xml';
}
$base = [
    'location' => $resp,
    'uri' => 'http://example.com/',
    'trace' => 1,
];
$plain = new SoapClient(null, $base);
$plain->__soapCall('op', [['a', 'b']]);
$r0 = (string) $plain->__getLastRequest();
$feat = new SoapClient(null, $base + ['features' => SOAP_USE_XSI_ARRAY_TYPE]);
$feat->__soapCall('op', [['a', 'b']]);
$r1 = (string) $feat->__getLastRequest();
echo (strpos($r0, 'SOAP-ENC:arrayType=') !== false) ? 'plain_arrayType=1' : 'plain_arrayType=0';
echo "\n";
echo (strpos($r0, 'xsi:type="SOAP-ENC:Array"') !== false) ? 'plain_xsi_array=1' : 'plain_xsi_array=0';
echo "\n";
echo (strpos($r1, 'SOAP-ENC:arrayType=') !== false) ? 'feat_arrayType=1' : 'feat_arrayType=0';
echo "\n";
echo (strpos($r1, 'xsi:type="SOAP-ENC:Array"') !== false) ? 'feat_xsi_array=1' : 'feat_xsi_array=0';
echo "\n";
echo ($r0 !== $r1) ? 'diff=1' : 'diff=0';
echo "\n";
?>
--EXPECT--
plain_arrayType=1
plain_xsi_array=1
feat_arrayType=1
feat_xsi_array=1
diff=0
