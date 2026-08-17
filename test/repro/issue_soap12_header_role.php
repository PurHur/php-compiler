<?php
/**
 * Repro: SOAP 1.2 SoapHeader role/mustUnderstand (php-src soap.c; #31920).
 */
declare(strict_types=1);

if (!class_exists('SoapClient')) {
    echo "soap_unavailable\n";
    exit(0);
}

$root = dirname(__DIR__);
$resp = $root.'/fixtures/soap/echo.response.xml';

$c12 = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
    'soap_version' => SOAP_1_2,
]);
$c12->__setSoapHeaders(new SoapHeader('urn:h', 'Auth', 'tok', true, 'http://example.com/role'));
$c12->__soapCall('echo', [['input' => 'hello']]);
$req12 = (string) $c12->__getLastRequest();
echo 'role=', str_contains($req12, 'role=') ? '1' : '0', "\n";
echo 'no_actor=', str_contains($req12, 'actor=') ? '0' : '1', "\n";
echo 'mu_true=', str_contains($req12, 'mustUnderstand="true"') ? '1' : '0', "\n";
echo 'no_mu_1=', str_contains($req12, 'mustUnderstand="1"') ? '0' : '1', "\n";

$c12int = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
    'soap_version' => SOAP_1_2,
]);
$c12int->__setSoapHeaders(new SoapHeader('urn:h', 'Next', 'x', true, SOAP_ACTOR_NEXT));
$c12int->__soapCall('echo', [['input' => 'hello']]);
$req12int = (string) $c12int->__getLastRequest();
echo 'role_next=', str_contains($req12int, 'role/next') ? '1' : '0', "\n";

$c11 = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
    'soap_version' => SOAP_1_1,
]);
$c11->__setSoapHeaders(new SoapHeader('urn:h', 'Auth', 'tok', true, 'http://example.com/actor'));
$c11->__soapCall('echo', [['input' => 'hello']]);
$req11 = (string) $c11->__getLastRequest();
echo 'actor11=', str_contains($req11, 'actor=') ? '1' : '0', "\n";
echo 'no_role11=', str_contains($req11, 'role=') ? '0' : '1', "\n";
echo 'mu1_11=', str_contains($req11, 'mustUnderstand="1"') ? '1' : '0', "\n";
