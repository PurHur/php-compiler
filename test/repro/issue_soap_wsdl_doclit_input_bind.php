<?php

/**
 * Repro #21131 — positional __soapCall args use WSDL input element sequence names.
 */
$wsdl = __DIR__ . '/../fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/../fixtures/soap/echo.response.xml';

$c = new SoapClient($wsdl, [
    'location' => $resp,
    'trace' => 1,
]);
$c->__soapCall('echo', ['hello']);
$req = $c->__getLastRequest();
echo (strpos($req, '<input') !== false && strpos($req, '>hello<') !== false) ? 'input=1' : 'input=0';
echo "\n";
echo (strpos($req, '<param0') !== false) ? 'param0=1' : 'param0=0';
echo "\n";
