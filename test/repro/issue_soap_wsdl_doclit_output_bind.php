<?php

/**
 * Repro #21091 — document/literal operation output SDL binds nested types when
 * flat global xsd:element name→type maps collide (Book.author=Person vs Order.author=string).
 */
class Book
{
    public $title;
    public $author;
}

class Person
{
    public $name;
}

$base = __DIR__ . '/../fixtures/soap';
$wsdl = $base . '/book_order.wsdl';
$resp = $base . '/book_nested_no_xsi.response.xml';

$c = new SoapClient($wsdl, [
    'location' => $resp,
    'trace' => 1,
    'classmap' => [
        'Book' => 'Book',
        'Person' => 'Person',
    ],
]);
$r = $c->__soapCall('getBook', []);
echo (is_object($r) && get_class($r) === 'Book') ? 'book=Book' : 'book=' . (is_object($r) ? get_class($r) : gettype($r));
echo "\n";
$author = is_object($r) && isset($r->author) ? $r->author : null;
echo (is_object($author) && get_class($author) === 'Person') ? 'author=Person' : 'author=' . (is_object($author) ? get_class($author) : gettype($author));
echo "\n";
echo (is_object($author) && isset($author->name) && $author->name === 'Herbert') ? 'name=1' : 'name=0';
echo "\n";
echo (is_object($r) && isset($r->title) && $r->title === 'Dune') ? 'title=1' : 'title=0';
echo "\n";
