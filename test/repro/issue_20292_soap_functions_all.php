<?php

/**
 * Repro #20292 — SoapServer::addFunction(SOAP_FUNCTIONS_ALL).
 */
function soap_add1($a)
{
    return (int) $a + 1;
}

$req = '<?xml version="1.0" encoding="UTF-8"?>'
    .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
    .' xmlns:ns1="http://example.com/"'
    .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
    .' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
    .' SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
    .'<SOAP-ENV:Body><ns1:soap_add1><a xsi:type="xsd:int">41</a></ns1:soap_add1></SOAP-ENV:Body>'
    .'</SOAP-ENV:Envelope>';

$server = new SoapServer(null, ['uri' => 'http://example.com/']);
$server->addFunction(SOAP_FUNCTIONS_ALL);
$fns = $server->getFunctions();
echo 'has_user=', (is_array($fns) && in_array('soap_add1', $fns, true)) ? 1 : 0, "\n";
ob_start();
$server->handle($req);
$out = ob_get_clean();
echo 'has_42=', (is_string($out) && str_contains($out, '42')) ? 1 : 0, "\n";
