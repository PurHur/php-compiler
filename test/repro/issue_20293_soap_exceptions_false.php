<?php

/**
 * Repro #20293 — SoapClient exceptions=false returns SoapFault.
 */
$dir = sys_get_temp_dir().'/phpc_soap_ex_'.getmypid();
@mkdir($dir);
$wsdl = $dir.'/t.wsdl';
$bad = $dir.'/bad.xml';
file_put_contents($wsdl, '<?xml version="1.0"?><definitions xmlns="http://schemas.xmlsoap.org/wsdl/" targetNamespace="http://t/"></definitions>');
file_put_contents($bad, 'NOT XML');

$c = new SoapClient($wsdl, [
    'location' => $bad,
    'uri' => 'http://t/',
    'exceptions' => false,
    'soap_version' => SOAP_1_1,
]);
$r = $c->__soapCall('x', []);
echo 'is_fault=', is_soap_fault($r) ? 1 : 0, "\n";
echo 'msg=', is_object($r) ? $r->faultstring : 'n/a', "\n";
@unlink($wsdl);
@unlink($bad);
@rmdir($dir);
