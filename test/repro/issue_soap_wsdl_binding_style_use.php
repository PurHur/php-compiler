<?php

/**
 * Repro #21132 — WSDL soap:binding style/use applied (document/literal omits encodingStyle).
 */
$base = __DIR__ . '/../fixtures/soap';
$wsdl = $base . '/book.wsdl';
$resp = $base . '/book_no_xsi.response.xml';

$c = new SoapClient($wsdl, [
    'location' => $resp,
    'trace' => 1,
]);
$c->__soapCall('getBook', []);
$req = $c->__getLastRequest();
echo (strpos($req, 'encodingStyle=') === false) ? 'no_enc=1' : 'no_enc=0';
echo "\n";

// RPC/encoded echo.wsdl should still emit encodingStyle.
$echoWsdl = $base . '/echo.wsdl';
$echoResp = $base . '/echo.response.xml';
$c2 = new SoapClient($echoWsdl, [
    'location' => $echoResp,
    'trace' => 1,
]);
$c2->__soapCall('echo', ['hello']);
$req2 = $c2->__getLastRequest();
echo (strpos($req2, 'encodingStyle=') !== false) ? 'echo_enc=1' : 'echo_enc=0';
echo "\n";
echo (strpos($req2, 'xsi:type=') !== false) ? 'echo_xsi=1' : 'echo_xsi=0';
echo "\n";
