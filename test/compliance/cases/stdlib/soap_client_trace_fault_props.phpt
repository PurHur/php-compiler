--TEST--
Stdlib: SoapClient __last_* / __default_headers / __soap_fault props (#23925, ext/soap/soap.stub.php)
--FILE--
<?php
declare(strict_types=1);

$wsdl = __DIR__ . '/test/fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/test/fixtures/soap/echo.response.xml';
if (!is_file($wsdl)) {
    $wsdl = dirname(__DIR__, 3) . '/fixtures/soap/echo.wsdl';
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/echo.response.xml';
}

$c = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
]);
$props = [
    '__last_request',
    '__last_response',
    '__last_request_headers',
    '__last_response_headers',
    '__default_headers',
    '__soap_fault',
];
$exists = 1;
$defaultsNull = 1;
foreach ($props as $p) {
    if (!property_exists($c, $p)) {
        $exists = 0;
    }
    if (null !== $c->$p) {
        $defaultsNull = 0;
    }
}
echo 'exists=', $exists, "\n";
echo 'defaults_null=', $defaultsNull, "\n";

$h = new SoapHeader('http://example.com/auth', 'Token', 'secret', true);
$c->__setSoapHeaders($h);
$dh = $c->__default_headers;
echo 'default_hdrs=', (int) (
    is_array($dh) && 1 === count($dh) && $dh[0] instanceof SoapHeader
), "\n";
$c->__setSoapHeaders(null);
echo 'default_clr=', (int) (null === $c->__default_headers), "\n";

$c->__soapCall('echo', [['input' => 'hello']]);
$lr = $c->__last_request;
$lres = $c->__last_response;
$lrh = $c->__last_request_headers;
$lresh = $c->__last_response_headers;
echo 'req_match=', (int) ($lr === $c->__getLastRequest()), "\n";
echo 'res_match=', (int) ($lres === $c->__getLastResponse()), "\n";
echo 'reqh_match=', (int) ($lrh === $c->__getLastRequestHeaders()), "\n";
echo 'resh_match=', (int) ($lresh === $c->__getLastResponseHeaders()), "\n";
echo 'req_xml=', (int) (is_string($lr) && str_contains($lr, 'hello')), "\n";
echo 'res_xml=', (int) (is_string($lres) && str_contains($lres, 'hello')), "\n";

$dir = sys_get_temp_dir() . '/phpc_soap_tf_' . getmypid();
@mkdir($dir);
$badWsdl = $dir . '/t.wsdl';
$bad = $dir . '/bad.xml';
file_put_contents($badWsdl, '<?xml version="1.0"?><definitions xmlns="http://schemas.xmlsoap.org/wsdl/" targetNamespace="http://t/"></definitions>');
file_put_contents($bad, 'NOT XML AT ALL');
$f = new SoapClient($badWsdl, [
    'location' => $bad,
    'uri' => 'http://t/',
    'trace' => 1,
    'exceptions' => false,
]);
$r = $f->__soapCall('x', []);
$fp = $f->__soap_fault;
echo 'fault_prop=', (int) (
    is_soap_fault($fp)
    && is_soap_fault($r)
    && (string) $fp->faultstring === (string) $r->faultstring
), "\n";
@unlink($badWsdl);
@unlink($bad);
@rmdir($dir);
?>
--EXPECT--
exists=1
defaults_null=1
default_hdrs=1
default_clr=1
req_match=1
res_match=1
reqh_match=1
resh_match=1
req_xml=1
res_xml=1
fault_prop=1
