<?php
/**
 * Repro #31844 — Cookie send path/domain/secure filters (php-src php_http.c).
 * Requires SOAP_SETCOOKIE_PORT (same mock server as #31843).
 */
declare(strict_types=1);

if (!class_exists('SoapClient')) {
    echo "soap_unavailable\n";
    exit(0);
}

$port = (int) (getenv('SOAP_SETCOOKIE_PORT') ?: 0);
if ($port <= 0) {
    fwrite(STDERR, "SOAP_SETCOOKIE_PORT required\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$wsdl = $root.'/test/fixtures/soap/echo.wsdl';
$location = 'http://127.0.0.1:'.$port.'/echo';
$client = new SoapClient($wsdl, [
    'location' => $location,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'keep_alive' => false,
]);

$client->__soapCall('echo', [['input' => 'a']]);
$client->__setCookie('other', 'nope');
$client->__soapCall('echo', [['input' => 'b']]);
$req = (string) $client->__getLastRequestHeaders();
echo 'sess_sent=', str_contains($req, 'sess=abc123') ? '1' : '0', "\n";
echo 'tok_blocked=', str_contains($req, 'tok=xyz') ? '0' : '1', "\n";
echo 'other_sent=', str_contains($req, 'other=nope') ? '1' : '0', "\n";
