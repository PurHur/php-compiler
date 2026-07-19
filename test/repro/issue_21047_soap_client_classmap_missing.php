<?php

/**
 * Repro #21047 — classmap pointing at a missing class falls back to stdClass.
 */
$resp = __DIR__ . '/../fixtures/soap/book.response.xml';

$c = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'urn:book',
    'trace' => 1,
    'classmap' => ['Book' => 'DoesNotExistBook'],
]);
$r = $c->__soapCall('getBook', []);
echo (is_object($r) && get_class($r) === 'stdClass') ? 'cls=stdClass' : 'cls=' . (is_object($r) ? get_class($r) : gettype($r));
echo "\n";
echo (is_object($r) && isset($r->title) && $r->title === 'Dune' && isset($r->author) && $r->author === 'Herbert')
    ? 'props=1' : 'props=0';
echo "\n";
