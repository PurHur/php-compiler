<?php
/**
 * Repro #32241 — SoapVar XSD_ANYXML raw XML embed (php-src php_encoding.c to_xml_any).
 */
declare(strict_types=1);

if (!class_exists('SoapClient')) {
    echo "soap_unavailable\n";
    exit(0);
}

$resp = dirname(__DIR__).'/fixtures/soap/echo.response.xml';
$c = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'exceptions' => false,
    'style' => SOAP_RPC,
    'use' => SOAP_ENCODED,
]);

$c->__soapCall('echo', [new SoapVar('<raw>x</raw>', XSD_ANYXML)]);
$s = (string) $c->__getLastRequest();
echo 'raw_embed=', str_contains($s, '<ns1:echo><raw>x</raw></ns1:echo>') ? '1' : '0', "\n";
echo 'not_escaped=', str_contains($s, '&lt;raw&gt;') ? '0' : '1', "\n";
echo 'not_param0=', str_contains($s, '<param0>') ? '0' : '1', "\n";

$c->__soapCall('echo', [new SoapVar('plain', XSD_ANYXML)]);
$plain = (string) $c->__getLastRequest();
echo 'plain_embed=', str_contains($plain, '<ns1:echo>plain</ns1:echo>') ? '1' : '0', "\n";
echo 'plain_untyped=', str_contains($plain, 'xsi:type') && str_contains($plain, '>plain<') ? '0' : '1', "\n";
