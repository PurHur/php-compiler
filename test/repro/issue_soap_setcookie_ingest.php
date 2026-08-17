<?php
/**
 * Repro #31843 — SoapClient Set-Cookie response ingest (php-src php_http.c).
 *
 * Env:
 *   SOAP_SETCOOKIE_PORT  — required (mock server already listening)
 *   SOAP_SETCOOKIE_WSDL / SOAP_SETCOOKIE_BODY optional overrides
 *
 * Host harness (Docker):
 *   php -m | grep -qi soap || apt-get install -y php8.2-soap
 *   PORT=$((28000+$$%1000)); READY=/tmp/sc-$PORT; BODY=test/fixtures/soap/echo.response.xml
 *   php test/fixtures/soap/mock_http_setcookie_server.php $PORT $READY $BODY 4 >/dev/null &
 *   while [ ! -f $READY ]; do sleep 0.05; done
 *   SOAP_SETCOOKIE_PORT=$PORT php bin/vm.php test/repro/issue_soap_setcookie_ingest.php
 */
declare(strict_types=1);

if (!class_exists('SoapClient')) {
    echo "soap_unavailable\n";
    exit(0);
}

$port = (int) (getenv('SOAP_SETCOOKIE_PORT') ?: 0);
if ($port <= 0) {
    fwrite(STDERR, "SOAP_SETCOOKIE_PORT required (start mock_http_setcookie_server.php first)\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$wsdl = getenv('SOAP_SETCOOKIE_WSDL') ?: ($root.'/test/fixtures/soap/echo.wsdl');

$location = 'http://127.0.0.1:'.$port.'/echo';
$client = new SoapClient($wsdl, [
    'location' => $location,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'keep_alive' => false,
]);
$client->__soapCall('echo', [['input' => 'hello']]);
$cookies = $client->__getCookies();

$sess = $cookies['sess'] ?? null;
echo 'sess_val=', (is_array($sess) && ($sess[0] ?? null) === 'abc123') ? '1' : '0', "\n";
echo 'sess_path=', (is_array($sess) && ($sess[1] ?? null) === '/echo') ? '1' : '0', "\n";
echo 'sess_domain=', (is_array($sess) && ($sess[2] ?? null) === '127.0.0.1') ? '1' : '0', "\n";

$tok = $cookies['tok'] ?? null;
echo 'tok_val=', (is_array($tok) && ($tok[0] ?? null) === 'xyz') ? '1' : '0', "\n";
echo 'tok_secure=', (is_array($tok) && !empty($tok[3])) ? '1' : '0', "\n";
echo 'tok_domain=', (is_array($tok) && ($tok[2] ?? null) === '127.0.0.1') ? '1' : '0', "\n";

$client->__soapCall('echo', [['input' => 'hello2']]);
$req = (string) $client->__getLastRequestHeaders();
echo 'cookie_sess=', str_contains($req, 'sess=abc123') ? '1' : '0', "\n";
echo 'cookie_tok=', str_contains($req, 'tok=xyz') ? '1' : '0', "\n";
