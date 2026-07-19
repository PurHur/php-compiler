<?php

/**
 * Repro #21088 — SoapClient classmap via WSDL element type when response lacks xsi:type.
 */
class Book
{
    public $title;
    public $author;
}

$base = __DIR__ . '/../fixtures/soap';
$wsdl = $base . '/book.wsdl';
$resp = $base . '/book_no_xsi.response.xml';

$plain = new SoapClient($wsdl, [
    'location' => $resp,
    'trace' => 1,
]);
$r1 = $plain->__soapCall('getBook', []);
echo (is_object($r1) && get_class($r1) === 'stdClass') ? 'plain=stdClass' : 'plain=' . (is_object($r1) ? get_class($r1) : gettype($r1));
echo "\n";
echo (is_object($r1) && isset($r1->title) && $r1->title === 'Dune') ? 'plain_title=1' : 'plain_title=0';
echo "\n";

$mapped = new SoapClient($wsdl, [
    'location' => $resp,
    'trace' => 1,
    'classmap' => ['Book' => 'Book'],
]);
$r2 = $mapped->__soapCall('getBook', []);
echo (is_object($r2) && get_class($r2) === 'Book') ? 'map=Book' : 'map=' . (is_object($r2) ? get_class($r2) : gettype($r2));
echo "\n";
echo (is_object($r2) && isset($r2->title) && $r2->title === 'Dune' && isset($r2->author) && $r2->author === 'Herbert')
    ? 'map_props=1' : 'map_props=0';
echo "\n";

// xsi:type path still works with the same WSDL + classmap.
$withXsi = $base . '/book.response.xml';
$mappedXsi = new SoapClient($wsdl, [
    'location' => $withXsi,
    'trace' => 1,
    'classmap' => ['Book' => 'Book'],
]);
$r3 = $mappedXsi->__soapCall('getBook', []);
echo (is_object($r3) && get_class($r3) === 'Book') ? 'xsi=Book' : 'xsi=' . (is_object($r3) ? get_class($r3) : gettype($r3));
echo "\n";
