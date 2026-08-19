--TEST--
stdlib DOMNode::lookupNamespaceURI / isDefaultNamespace match Zend xmlSearchNs (#32504, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root xmlns="http://example.com/def" xmlns:foo="http://example.com/foo"><child/></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild;
echo var_export($root->lookupNamespaceURI('foo'), true), '|';
echo var_export($leaf->lookupNamespaceURI('foo'), true), '|';
echo var_export($root->lookupNamespaceURI(null), true), '|';
echo var_export($root->lookupNamespaceURI('xml'), true), '|';
echo var_export($root->lookupNamespaceURI('nope'), true), '|';
echo (int) $root->isDefaultNamespace('http://example.com/def'), '|';
echo (int) $root->isDefaultNamespace('http://example.com/foo'), "\n";
--EXPECT--
'http://example.com/foo'|'http://example.com/foo'|'http://example.com/def'|'http://www.w3.org/XML/1998/namespace'|NULL|1|0
