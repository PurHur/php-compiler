--TEST--
Dom\DtdNamedNodeMap for DocumentType entities/notations (#21014)
--SKIPIF--
<?php
if (!class_exists('Dom\\XMLDocument')) {
    die('skip Dom\\XMLDocument requires PHP_COMPILER_PROFILE=8.4 (#21014)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'DtdNamedNodeMap=', class_exists('Dom\\DtdNamedNodeMap') ? 'yes' : 'no', "\n";

$dtd = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE root [
  <!ENTITY foo "bar">
  <!NOTATION gif SYSTEM "image/gif">
]>
<root/>
XML;

$doc = Dom\XMLDocument::createFromString($dtd);
$entities = $doc->doctype->entities;
$notations = $doc->doctype->notations;
echo 'entities=', get_class($entities), "\n";
echo 'notations=', get_class($notations), "\n";
echo 'entities_dtd=', ($entities instanceof Dom\DtdNamedNodeMap) ? 'yes' : 'no', "\n";
echo 'notations_dtd=', ($notations instanceof Dom\DtdNamedNodeMap) ? 'yes' : 'no', "\n";
echo 'entities_named=', ($entities instanceof Dom\NamedNodeMap) ? 'yes' : 'no', "\n";
echo 'entity=', get_class($entities->getNamedItem('foo')), "\n";
echo 'notation=', get_class($notations->getNamedItem('gif')), "\n";

$legacy = new DOMDocument();
$legacy->loadXML($dtd);
echo 'legacy_entities=', get_class($legacy->doctype->entities), "\n";
echo 'legacy_notations=', get_class($legacy->doctype->notations), "\n";

$empty = Dom\XMLDocument::createEmpty();
$dt = $empty->implementation->createDocumentType('root', '', '');
echo 'empty_entities=', get_class($dt->entities), "\n";
echo 'empty_notations=', get_class($dt->notations), "\n";
?>
--EXPECT--
DtdNamedNodeMap=yes
entities=Dom\DtdNamedNodeMap
notations=Dom\DtdNamedNodeMap
entities_dtd=yes
notations_dtd=yes
entities_named=no
entity=Dom\Entity
notation=Dom\Notation
legacy_entities=DOMNamedNodeMap
legacy_notations=DOMNamedNodeMap
empty_entities=Dom\DtdNamedNodeMap
empty_notations=Dom\DtdNamedNodeMap
