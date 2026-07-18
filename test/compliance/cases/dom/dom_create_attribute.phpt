--TEST--
DOMDocument::createAttribute + setAttributeNode round-trip (#20676)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$a = $d->createAttribute('id');
echo $a->name, "\n";
$a->value = 'foo';
$e = $d->createElement('x');
$d->appendChild($e);
echo (null === $e->setAttributeNode($a) ? 'NULL' : 'prev'), "\n";
echo $e->getAttribute('id'), "\n";
--EXPECT--
id
NULL
foo
