<?php

/**
 * Repro #20365 — SoapClient stream_context http.header merged into request.
 */
$wsdl = __DIR__ . '/../fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/../fixtures/soap/echo.response.xml';

$ctx = stream_context_create([
    'http' => [
        'header' => "X-Test: 1\r\nX-Soap-Batch: stream-context\r\n",
    ],
]);

$c = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'stream_context' => $ctx,
]);
$c->__soapCall('echo', [['input' => 'hi']]);
$h = $c->__getLastRequestHeaders();
echo (is_string($h) && str_contains($h, 'X-Test: 1')) ? 'xtest=1' : 'xtest=0';
echo "\n";
echo (is_string($h) && str_contains($h, 'X-Soap-Batch: stream-context')) ? 'batch=1' : 'batch=0';
echo "\n";

// Without stream_context still constructs / calls.
$d = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
]);
$d->__soapCall('echo', [['input' => 'hi']]);
$h2 = $d->__getLastRequestHeaders();
echo (is_string($h2) && !str_contains($h2, 'X-Test:')) ? 'plain=1' : 'plain=0';
echo "\n";
