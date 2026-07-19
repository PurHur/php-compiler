--TEST--
stdlib SoapClient WSDL classmap without xsi:type (#21088)
--FILE--
<?php
class Book
{
    public $title;
    public $author;
}

$wsdl = __DIR__ . '/test/fixtures/soap/book.wsdl';
$resp = __DIR__ . '/test/fixtures/soap/book_no_xsi.response.xml';
if (!is_file($wsdl)) {
    $wsdl = dirname(__DIR__, 3) . '/fixtures/soap/book.wsdl';
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/book_no_xsi.response.xml';
}

$plain = new SoapClient($wsdl, [
    'location' => $resp,
    'trace' => 1,
]);
$r1 = $plain->__soapCall('getBook', []);
echo (is_object($r1) && get_class($r1) === 'stdClass') ? 'plain=stdClass' : 'plain=0';
echo "\n";

$mapped = new SoapClient($wsdl, [
    'location' => $resp,
    'trace' => 1,
    'classmap' => ['Book' => 'Book'],
]);
$r2 = $mapped->__soapCall('getBook', []);
echo (is_object($r2) && get_class($r2) === 'Book') ? 'map=Book' : 'map=0';
echo "\n";
echo (is_object($r2) && isset($r2->title) && $r2->title === 'Dune' && isset($r2->author) && $r2->author === 'Herbert')
    ? 'map_props=1' : 'map_props=0';
echo "\n";
?>
--EXPECT--
plain=stdClass
map=Book
map_props=1
