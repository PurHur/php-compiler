<?php

declare(strict_types=1);

/**
 * APACHE_MAP response must decode to a PHP assoc array (php-src to_zval_map).
 * Host php-soap optional — uses VmSoapClient::decodeResponse via autoload.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use PHPCompiler\ext\soap\VmSoapClient;

if (!\class_exists(\SoapFault::class, false)) {
    require dirname(__DIR__, 2).'/ext/soap/bootstrap_soapfault.php';
}

$xml = '<?xml version="1.0" encoding="UTF-8"?>'
    .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
    .' xmlns:ns1="http://example.com/echo"'
    .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
    .' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
    .' xmlns:ns2="http://xml.apache.org/xml-soap"'
    .' SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
    .'<SOAP-ENV:Body><ns1:echoResponse>'
    .'<return xsi:type="ns2:Map">'
    .'<item><key xsi:type="xsd:string">k</key><value xsi:type="xsd:string">v</value></item>'
    .'<item><key xsi:type="xsd:int">7</key><value xsi:type="xsd:string">x</value></item>'
    .'</return>'
    .'</ns1:echoResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>';

$rm = new ReflectionMethod(VmSoapClient::class, 'decodeResponse');
$rm->setAccessible(true);
$out = $rm->invoke(null, $xml, 'echo');
var_export($out);
echo "\n";
