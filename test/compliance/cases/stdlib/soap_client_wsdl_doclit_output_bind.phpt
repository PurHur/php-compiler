--TEST--
stdlib SoapClient WSDL doc/lit operation output binds nested types (#21091)
--FILE--
<?php
class Book
{
    public $title;
    public $author;
}

class Person
{
    public $name;
}

$wsdl = __DIR__ . '/test/fixtures/soap/book_order.wsdl';
$resp = __DIR__ . '/test/fixtures/soap/book_nested_no_xsi.response.xml';
if (!is_file($wsdl)) {
    $wsdl = dirname(__DIR__, 3) . '/fixtures/soap/book_order.wsdl';
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/book_nested_no_xsi.response.xml';
}

$c = new SoapClient($wsdl, [
    'location' => $resp,
    'trace' => 1,
    'classmap' => [
        'Book' => 'Book',
        'Person' => 'Person',
    ],
]);
$r = $c->__soapCall('getBook', []);
echo (is_object($r) && get_class($r) === 'Book') ? 'book=Book' : 'book=0';
echo "\n";
$author = is_object($r) && isset($r->author) ? $r->author : null;
echo (is_object($author) && get_class($author) === 'Person') ? 'author=Person' : 'author=0';
echo "\n";
echo (is_object($author) && isset($author->name) && $author->name === 'Herbert') ? 'name=1' : 'name=0';
echo "\n";
echo (is_object($r) && isset($r->title) && $r->title === 'Dune') ? 'title=1' : 'title=0';
echo "\n";
?>
--EXPECT--
book=Book
author=Person
name=1
title=1
