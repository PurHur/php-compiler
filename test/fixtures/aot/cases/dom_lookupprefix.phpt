--TEST--
AOT: lookupPrefix must not abort as object::lookupprefix (#32493, ext/dom/node.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<root xmlns:foo="http://example.com/foo"><child/></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild;
echo var_export($root->lookupPrefix('http://example.com/foo'), true), '|';
echo var_export($leaf->lookupPrefix('http://example.com/foo'), true), '|';
echo var_export($root->lookupPrefix('http://nope'), true), "\n";
--EXPECT--
'foo'|'foo'|NULL
