--TEST--
Stdlib: SoapClient::$httpsocket declared null (fixture / pre-HTTP) (#23904, ext/soap/soap.stub.php)
--FILE--
<?php
declare(strict_types=1);

$c = new SoapClient(null, [
    'location' => 'http://127.0.0.1/',
    'uri' => 'http://test/',
]);
echo 'exists=', (int) property_exists($c, 'httpsocket'), "\n";
echo 'null=', (int) (null === $c->httpsocket), "\n";

$dir = sys_get_temp_dir() . '/phpc_soap_httpsocket_' . getmypid();
@mkdir($dir);
$resp = $dir . '/echo.response.xml';
file_put_contents($resp, '<?xml version="1.0"?>'
    .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
    .'<SOAP-ENV:Body><ns1:echoResponse xmlns:ns1="http://test/">'
    .'<return xsi:type="xsd:string" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
    .' xmlns:xsd="http://www.w3.org/2001/XMLSchema">ok</return>'
    .'</ns1:echoResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>');

$f = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://test/',
]);
$f->__soapCall('echo', ['x']);
echo 'after_fixture_null=', (int) (null === $f->httpsocket), "\n";
@unlink($resp);
@rmdir($dir);
?>
--EXPECT--
exists=1
null=1
after_fixture_null=1
