--TEST--
stdlib SoapClient xsi:type scalar decode matches Zend to_zval_* (#32413, ext/soap/php_encoding.c)
--FILE--
<?php
function soap_typed_call(string $xsiType, string $text): mixed
{
    $dir = sys_get_temp_dir() . '/phpc_soap_xsd_scalar_' . getmypid();
    @mkdir($dir);
    $resp = $dir . '/r.xml';
    file_put_contents($resp, '<?xml version="1.0" encoding="UTF-8"?>'
        .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
        .' xmlns:ns1="http://example.com/echo"'
        .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
        .' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
        .' SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
        .'<SOAP-ENV:Body><ns1:echoResponse>'
        .'<output xsi:type="xsd:'.$xsiType.'">'.$text.'</output>'
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

    return $out;
}

$int = soap_typed_call('int', '42');
echo 'int_type=', gettype($int), "\n";
echo 'int_val=', $int === 42 ? '1' : '0', "\n";

$boolTrue = soap_typed_call('boolean', 'true');
echo 'bool_true=', ($boolTrue === true) ? '1' : '0', "\n";
$boolFalse = soap_typed_call('boolean', 'false');
echo 'bool_false=', ($boolFalse === false) ? '1' : '0', "\n";
$boolZero = soap_typed_call('boolean', '0');
echo 'bool_zero=', ($boolZero === false) ? '1' : '0', "\n";

$fl = soap_typed_call('float', '1.5');
echo 'float_type=', gettype($fl), "\n";
echo 'float_val=', ($fl === 1.5) ? '1' : '0', "\n";

$b64 = soap_typed_call('base64Binary', base64_encode('hi'));
echo 'b64=', ($b64 === 'hi') ? '1' : '0', "\n";

$hex = soap_typed_call('hexBinary', strtoupper(bin2hex('hi')));
echo 'hex=', ($hex === 'hi') ? '1' : '0', "\n";

$keep = soap_typed_call('string', '42');
echo 'string_kept=', ($keep === '42') ? '1' : '0', "\n";
?>
--EXPECT--
int_type=integer
int_val=1
bool_true=1
bool_false=1
bool_zero=1
float_type=double
float_val=1
b64=1
hex=1
string_kept=1
