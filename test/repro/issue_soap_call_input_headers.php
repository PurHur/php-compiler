<?php
/**
 * Repro: SoapClient::__soapCall $inputHeaders per-call SoapHeader (php-src soap.c soap_client_call_impl).
 *
 * php-src merges call headers with __default_headers for that request only.
 */
declare(strict_types=1);

if (!class_exists('SoapClient')) {
    echo "soap_unavailable\n";
    exit(0);
}

$root = dirname(__DIR__);
$wsdl = $root.'/fixtures/soap/echo.wsdl';
$resp = $root.'/fixtures/soap/echo.response.xml';

$client = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
]);
$h = new SoapHeader('http://example.com/auth', 'Token', 'secret', true);
$client->__soapCall('echo', [['input' => 'hello']], null, $h);
$req = (string) $client->__getLastRequest();
echo 'input_header=', (str_contains($req, 'Token') && str_contains($req, 'secret')) ? '1' : '0', "\n";
echo 'must=', str_contains($req, 'mustUnderstand="1"') ? '1' : '0', "\n";

$client->__soapCall('echo', [['input' => 'hello']]);
$req2 = (string) $client->__getLastRequest();
echo 'not_sticky=', str_contains($req2, 'Token') ? '1' : '0', "\n";

$def = new SoapHeader('http://example.com/auth', 'Session', 'sid', false);
$client->__setSoapHeaders($def);
$client->__soapCall('echo', [['input' => 'hello']], null, $h);
$req3 = (string) $client->__getLastRequest();
echo 'merged=', (str_contains($req3, 'Token') && str_contains($req3, 'Session')) ? '1' : '0', "\n";
$posToken = strpos($req3, 'Token');
$posSession = strpos($req3, 'Session');
echo 'call_first=', (false !== $posToken && false !== $posSession && $posToken < $posSession) ? '1' : '0', "\n";
$client->__setSoapHeaders(null);
$arr = [];
$arr[] = $h;
$client->__soapCall('echo', [['input' => 'hello']], null, $arr);
echo 'array_form=', str_contains((string) $client->__getLastRequest(), 'Token') ? '1' : '0', "\n";

