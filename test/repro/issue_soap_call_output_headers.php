<?php
/**
 * Repro: SoapClient::__soapCall &$outputHeaders (php-src soap.c + php_packet_soap.c).
 *
 * php-src array_inits the by-ref 5th argument and fills it from SOAP Header children.
 */
declare(strict_types=1);

if (!class_exists('SoapClient')) {
    echo "soap_unavailable\n";
    exit(0);
}

$root = dirname(__DIR__);
$wsdl = $root.'/fixtures/soap/echo.wsdl';
$resp = $root.'/fixtures/soap/echo.response.xml';
$respHdr = $root.'/fixtures/soap/echo.response.with_header.xml';

$r = new ReflectionMethod('SoapClient', '__soapCall');
$p = $r->getParameters();
$outP = $p[4] ?? null;
echo 'out_name=', $outP ? $outP->getName() : 'missing', "\n";
echo 'out_byref=', ($outP && $outP->isPassedByReference()) ? '1' : '0', "\n";

$client = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
]);
$out = ['sentinel' => 1];
$client->__soapCall('echo', [['input' => 'hello']], null, null, $out);
echo 'cleared=', (is_array($out) && !array_key_exists('sentinel', $out)) ? '1' : '0', "\n";
echo 'keys=', is_array($out) ? implode(',', array_map('strval', array_keys($out))) : gettype($out), "\n";

$clientH = new SoapClient($wsdl, [
    'location' => $respHdr,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
]);
$hdrs = ['sentinel' => 1];
$ret = $clientH->__soapCall('echo', [['input' => 'hello']], null, null, $hdrs);
echo 'body=', (is_string($ret) && $ret === 'hello') ? 'hello' : gettype($ret), "\n";
echo 'Token=', (is_array($hdrs) && isset($hdrs['Token']) && $hdrs['Token'] === 'secret') ? 'secret' : (is_array($hdrs) ? json_encode($hdrs) : gettype($hdrs)), "\n";
echo 'named_cleared=';
$named = ['sentinel' => 1];
$client->__soapCall('echo', [['input' => 'hello']], outputHeaders: $named);
echo (is_array($named) && !array_key_exists('sentinel', $named)) ? '1' : '0', "\n";
