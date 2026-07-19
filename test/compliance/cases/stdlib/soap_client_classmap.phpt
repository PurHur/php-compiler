--TEST--
stdlib SoapClient classmap xsi:type → user class (#21044)
--FILE--
<?php
class Book
{
    public $title;
    public $author;
}

$resp = __DIR__ . '/test/fixtures/soap/book.response.xml';
if (!is_file($resp)) {
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/book.response.xml';
}

$plain = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'urn:book',
    'trace' => 1,
]);
$r1 = $plain->__soapCall('getBook', []);
echo (is_object($r1) && get_class($r1) === 'stdClass') ? 'plain=stdClass' : 'plain=0';
echo "\n";

$mapped = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'urn:book',
    'trace' => 1,
    'classmap' => ['Book' => 'Book'],
]);
$r2 = $mapped->__soapCall('getBook', []);
echo (is_object($r2) && get_class($r2) === 'Book') ? 'map=Book' : 'map=0';
echo "\n";
echo (is_object($r2) && isset($r2->title) && $r2->title === 'Dune' && isset($r2->author) && $r2->author === 'Herbert')
    ? 'map_props=1' : 'map_props=0';
echo "\n";

$slash = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'urn:book',
    'trace' => 1,
    'classmap' => ['Book' => '\\Book'],
]);
$r3 = $slash->__soapCall('getBook', []);
echo (is_object($r3) && get_class($r3) === 'Book') ? 'slash=Book' : 'slash=0';
echo "\n";
?>
--EXPECT--
plain=stdClass
map=Book
map_props=1
slash=Book
