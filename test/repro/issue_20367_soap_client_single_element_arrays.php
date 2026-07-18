<?php

/**
 * Repro #20367 — SoapClient features SOAP_SINGLE_ELEMENT_ARRAYS.
 */
$resp = __DIR__ . '/../fixtures/soap/single_element.response.xml';

$plain = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/user',
    'trace' => 1,
]);
$r1 = $plain->__soapCall('getUser', []);
$e1 = is_object($r1) && isset($r1->email) ? $r1->email : null;
echo (is_string($e1) && $e1 === 'a@b.c') ? 'plain=1' : 'plain=0';
echo "\n";

$feat = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/user',
    'trace' => 1,
    'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
]);
$r2 = $feat->__soapCall('getUser', []);
$e2 = is_object($r2) && isset($r2->email) ? $r2->email : null;
echo (is_array($e2) && $e2 === ['a@b.c']) ? 'feat=1' : 'feat=0';
echo "\n";
