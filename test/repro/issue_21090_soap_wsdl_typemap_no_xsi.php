<?php

/**
 * Repro #21090 — SoapClient typemap from_xml via WSDL type when response lacks xsi:type.
 */
function book_from_xml(string $xml): string
{
    if (preg_match('/<title[^>]*>([^<]*)</', $xml, $m)) {
        return 'MAPPED:'.$m[1];
    }

    return 'MAPPED';
}

$base = __DIR__ . '/../fixtures/soap';
$wsdl = $base . '/book.wsdl';
$resp = $base . '/book_no_xsi.response.xml';

$client = new SoapClient($wsdl, [
    'location' => $resp,
    'trace' => 1,
    'typemap' => [[
        'type_ns' => 'urn:book',
        'type_name' => 'Book',
        'from_xml' => 'book_from_xml',
    ]],
]);

$r = $client->__soapCall('getBook', []);
echo (is_string($r) && $r === 'MAPPED:Dune') ? 'from_xml=1' : 'from_xml=0:'.var_export($r, true);
echo "\n";

// Without typemap, same fixture stays structural object.
$plain = new SoapClient($wsdl, [
    'location' => $resp,
    'trace' => 1,
]);
$r2 = $plain->__soapCall('getBook', []);
echo (is_object($r2) && get_class($r2) === 'stdClass' && isset($r2->title) && $r2->title === 'Dune')
    ? 'plain=1' : 'plain=0';
echo "\n";
