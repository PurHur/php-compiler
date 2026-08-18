<?php
declare(strict_types=1);

/**
 * SoapClient must decode xsi:type="xsd:int" as int, not string (php-src to_zval_long).
 */
$dir = sys_get_temp_dir() . '/phpc_soap_xsd_repro_' . getmypid();
@mkdir($dir);
$resp = $dir . '/r.xml';
file_put_contents($resp, '<?xml version="1.0" encoding="UTF-8"?>'
    .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
    .' xmlns:ns1="http://example.com/echo"'
    .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
    .' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
    .' SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
    .'<SOAP-ENV:Body><ns1:echoResponse>'
    .'<output xsi:type="xsd:int">42</output>'
    .'</ns1:echoResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>');
$c = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'exceptions' => true,
    'style' => SOAP_RPC,
    'use' => SOAP_ENCODED,
]);
$out = $c->__soapCall('echo', []);
@unlink($resp);
@rmdir($dir);
var_dump($out);
