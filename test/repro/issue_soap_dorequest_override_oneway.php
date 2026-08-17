<?php
/**
 * Repro: SoapClient::__doRequest override + $oneWay (php-src soap.c do_request / PHP_METHOD).
 *
 * php-src always call_user_function("__doRequest") so subclass overrides run; oneWay=true
 * returns empty string unless SOAP_WAIT_ONE_WAY_CALLS is set.
 */
declare(strict_types=1);

if (!class_exists('SoapClient')) {
    echo "soap_unavailable\n";
    exit(0);
}

$root = dirname(__DIR__);
$wsdl = $root.'/fixtures/soap/echo.wsdl';
$resp = $root.'/fixtures/soap/echo.response.xml';

class ProbeDoRequest extends SoapClient
{
    public mixed $lastOneWay = 'unset';
    public int $hits = 0;

    public function __doRequest($request, $location, $action, $version, $oneWay = false): ?string
    {
        $this->lastOneWay = $oneWay;
        ++$this->hits;

        return parent::__doRequest($request, $location, $action, $version, $oneWay);
    }
}

$c = new ProbeDoRequest($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
]);
$c->__soapCall('echo', [['input' => 'hello']]);
echo 'hits=', $c->hits, "\n";
echo 'default_oneway=', var_export($c->lastOneWay, true), "\n";

$plain = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
]);
$empty = $plain->__doRequest('<x/>', $resp, 'urn:x', SOAP_1_1, true);
echo 'oneway_empty=', ($empty === '') ? '1' : '0', "\n";

$wait = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
    'features' => SOAP_WAIT_ONE_WAY_CALLS,
]);
$body = $wait->__doRequest('<x/>', $resp, 'urn:x', SOAP_1_1, true);
echo 'wait_returns_body=', (is_string($body) && $body !== '') ? '1' : '0', "\n";
