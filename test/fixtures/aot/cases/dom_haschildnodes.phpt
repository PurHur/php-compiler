--TEST--
AOT: hasChildNodes must not abort as object::haschildnodes (#32427, ext/dom/node.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<root><child/></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild;
echo (int) $root->hasChildNodes(), '|', (int) $leaf->hasChildNodes(), "\n";
--EXPECT--
1|0
