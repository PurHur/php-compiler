--TEST--
stdlib SoapClient APACHE_MAP response decode to assoc array (#3724, php_encoding.c to_zval_map)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc_soap_map_dec_' . getmypid();
@mkdir($dir);
$resp = $dir . '/r.xml';
file_put_contents($resp, '<?xml version="1.0" encoding="UTF-8"?>'
    .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
    .' xmlns:ns1="http://example.com/echo"'
    .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
    .' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
    .' xmlns:ns2="http://xml.apache.org/xml-soap"'
    .' SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
    .'<SOAP-ENV:Body><ns1:echoResponse>'
    .'<return xsi:type="ns2:Map">'
    .'<item><key xsi:type="xsd:string">k</key><value xsi:type="xsd:string">v</value></item>'
    .'<item><key xsi:type="xsd:int">7</key><value xsi:type="xsd:string">x</value></item>'
    .'</return>'
    .'</ns1:echoResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>');
$c = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'exceptions' => true,
    'style' => SOAP_RPC,
    'use' => SOAP_ENCODED,
]);
$out = $c->__soapCall('echo', []);
@unlink($resp);
@rmdir($dir);
echo is_array($out) ? 'array' : gettype($out), "\n";
echo ($out === ['k' => 'v', 7 => 'x']) ? 'match' : 'mismatch', "\n";
var_export($out);
echo "\n";
?>
--EXPECT--
array
match
array (
  'k' => 'v',
  7 => 'x',
)
