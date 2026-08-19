--TEST--
AOT: hasAttributes must not abort as object::hasattributes (#32458, ext/dom/node.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<root id="x"><child/></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild;
echo (int) $root->hasAttributes(), '|', (int) $leaf->hasAttributes(), "\n";
--EXPECT--
1|0
