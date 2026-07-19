<?php

/**
 * Repro #21045 — SoapClient classmap applies recursively to nested xsi:type.
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

$resp = __DIR__ . '/../fixtures/soap/book_nested.response.xml';

$c = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'urn:book',
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
