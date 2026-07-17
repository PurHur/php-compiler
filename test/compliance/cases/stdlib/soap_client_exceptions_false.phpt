--TEST--
stdlib SoapClient exceptions=false returns SoapFault (#20293, ext/soap/soap.c)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc_soap_ex_' . getmypid();
@mkdir($dir);
$wsdl = $dir . '/t.wsdl';
$bad = $dir . '/bad.xml';
file_put_contents($wsdl, '<?xml version="1.0"?><definitions xmlns="http://schemas.xmlsoap.org/wsdl/" targetNamespace="http://t/"></definitions>');
file_put_contents($bad, 'NOT XML AT ALL');

$c = new SoapClient($wsdl, [
    'location' => $bad,
    'uri' => 'http://t/',
    'trace' => 1,
    'exceptions' => false,
    'soap_version' => SOAP_1_1,
]);
$r = $c->__soapCall('x', []);
echo 'is_obj=', is_object($r) ? 1 : 0, "\n";
echo 'is_fault=', is_soap_fault($r) ? 1 : 0, "\n";
$fs = is_object($r) ? (string) $r->faultstring : '';
echo 'msg_ok=', str_contains($fs, 'no XML') ? 1 : 0, "\n";

$threw = 0;
try {
    $c2 = new SoapClient($wsdl, [
        'location' => $bad,
        'uri' => 'http://t/',
        'exceptions' => true,
        'soap_version' => SOAP_1_1,
    ]);
    $c2->__soapCall('x', []);
} catch (SoapFault $e) {
    $threw = 1;
}
echo 'default_throws=', $threw, "\n";

@unlink($wsdl);
@unlink($bad);
@rmdir($dir);
?>
--EXPECT--
is_obj=1
is_fault=1
msg_ok=1
default_throws=1
