<?php
/**
 * Repro #31873 — SoapClient::__soapCall $options location/soapaction (php-src soap.c soap_client_call_impl).
 *
 * Per-call options must override ctor location for that request only.
 * soapaction applies on the non-WSDL branch (php-src sdl == NULL).
 */
declare(strict_types=1);

if (!class_exists('SoapClient')) {
    echo "soap_unavailable\n";
    exit(0);
}

$root = dirname(__DIR__);
$wsdl = $root.'/fixtures/soap/echo.wsdl';
$resp = $root.'/fixtures/soap/echo.response.xml';
$wrong = $wsdl;

$r = new ReflectionMethod('SoapClient', '__soapCall');
$names = [];
foreach ($r->getParameters() as $p) {
    $t = $p->getType();
    $names[] = $p->getName()
        .':'
        .(null !== $t ? (string) $t : '')
        .($p->isOptional() ? '=' : '');
}
echo 'params=', implode(',', $names), "\n";

$client = new SoapClient($wsdl, [
    'location' => $wrong,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
]);
$ret = $client->__soapCall('echo', [['input' => 'hello']], ['location' => $resp]);
echo 'opt_loc=', (is_string($ret) && $ret === 'hello') ? 'hello' : gettype($ret), "\n";

$rp = new ReflectionProperty('SoapClient', 'location');
$rp->setAccessible(true);
$loc = $rp->getValue($client);
echo 'ctor_location_sticky=', (is_string($loc) && $loc === $wrong) ? '1' : '0', "\n";

$named = $client->__soapCall('echo', [['input' => 'hello']], options: ['location' => $resp]);
echo 'named_opt_loc=', (is_string($named) && $named === 'hello') ? 'hello' : gettype($named), "\n";

$client2 = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
]);
$client2->__soapCall('echo', [['input' => 'hello']], ['soapaction' => 'urn:custom-action']);
$hdrs = (string) $client2->__getLastRequestHeaders();
echo 'custom_action=', str_contains($hdrs, 'urn:custom-action') ? '1' : '0', "\n";
$client2->__soapCall('echo', [['input' => 'hello']], ['uri' => 'urn:other']);
echo 'custom_uri=', str_contains((string) $client2->__getLastRequest(), 'urn:other') ? '1' : '0', "\n";
