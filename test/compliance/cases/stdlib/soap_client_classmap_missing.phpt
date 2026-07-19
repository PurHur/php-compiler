--TEST--
stdlib SoapClient classmap missing class → stdClass (#21047)
--FILE--
<?php
$resp = __DIR__ . '/test/fixtures/soap/book.response.xml';
if (!is_file($resp)) {
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/book.response.xml';
}

$c = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'urn:book',
    'trace' => 1,
    'classmap' => ['Book' => 'DoesNotExistBook'],
]);
$r = $c->__soapCall('getBook', []);
echo (is_object($r) && get_class($r) === 'stdClass') ? 'cls=stdClass' : 'cls=0';
echo "\n";
echo (is_object($r) && isset($r->title) && $r->title === 'Dune') ? 'props=1' : 'props=0';
echo "\n";
?>
--EXPECT--
cls=stdClass
props=1
