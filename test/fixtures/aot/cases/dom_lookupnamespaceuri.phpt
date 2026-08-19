--TEST--
AOT: lookupNamespaceURI must not abort as object::lookupnamespaceuri (#32502, ext/dom/node.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<root xmlns:foo="http://example.com/foo" xmlns="http://example.com/default"><child/></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild;
echo var_export($root->lookupNamespaceURI('foo'), true), '|';
echo var_export($leaf->lookupNamespaceURI('foo'), true), '|';
echo var_export($root->lookupNamespaceURI(null), true), '|';
echo var_export($root->lookupNamespaceURI('xml'), true), '|';
echo var_export($root->lookupNamespaceURI('nope'), true), "\n";
--EXPECT--
'http://example.com/foo'|'http://example.com/foo'|'http://example.com/default'|'http://www.w3.org/XML/1998/namespace'|NULL
