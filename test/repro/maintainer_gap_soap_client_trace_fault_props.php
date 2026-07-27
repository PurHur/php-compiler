<?php
declare(strict_types=1);
// Maintainer gap: SoapClient __last_* / __default_headers / __soap_fault undeclared (#23925)
$wsdl = __DIR__ . '/../fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/../fixtures/soap/echo.response.xml';

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
foreach ($props as $p) {
    $ex = property_exists($c, $p);
    $null = null === $c->$p;
    echo $p, ' exists=', (int) $ex, ' null=', (int) $null, "\n";
}

$h = new SoapHeader('http://example.com/auth', 'Token', 'secret', true);
$c->__setSoapHeaders($h);
$dh = $c->__default_headers;
echo 'default_hdrs=', (int) (is_array($dh) && 1 === count($dh)), "\n";

$c->__soapCall('echo', [['input' => 'hello']]);
$lr = $c->__last_request;
$lres = $c->__last_response;
$lrh = $c->__last_request_headers;
$lresh = $c->__last_response_headers;
echo 'req_match=', (int) ($lr === $c->__getLastRequest()), "\n";
echo 'res_match=', (int) ($lres === $c->__getLastResponse()), "\n";
echo 'reqh_match=', (int) ($lrh === $c->__getLastRequestHeaders()), "\n";
echo 'resh_match=', (int) ($lresh === $c->__getLastResponseHeaders()), "\n";
echo 'req_has_xml=', (int) (is_string($lr) && str_contains($lr, 'hello')), "\n";
