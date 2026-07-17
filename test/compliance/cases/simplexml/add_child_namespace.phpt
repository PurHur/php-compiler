--TEST--
SimpleXMLElement::addChild() applies $namespaceURI — xmlns + children() (#19906, ext/simplexml/sxe.c)
--FILE--
<?php
$s = new SimpleXMLElement('<r/>');
$s->addChild('x', '1', 'urn:x');
echo str_replace("\n", '', $s->asXML()), "\n";
echo 'children=', count($s->children('urn:x')), "\n";

$t = new SimpleXMLElement('<r/>');
$t->addChild('n:x', '2', 'urn:n');
echo str_replace("\n", '', $t->asXML()), "\n";
echo 'prefixed=', count($t->children('urn:n')), "\n";
--EXPECT--
<?xml version="1.0"?><r><x xmlns="urn:x">1</x></r>
children=1
<?xml version="1.0"?><r><n:x xmlns:n="urn:n">2</n:x></r>
prefixed=1
