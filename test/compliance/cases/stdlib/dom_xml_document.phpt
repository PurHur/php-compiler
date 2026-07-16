--TEST--
stdlib Dom\XMLDocument::createFromString()/createEmpty() — PHP 8.4 living DOM (#19581, ext/dom/xml_document.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'createFromString=', (int) method_exists(Dom\XMLDocument::class, 'createFromString'), "\n";
echo 'createEmpty=', (int) method_exists(Dom\XMLDocument::class, 'createEmpty'), "\n";
$x = Dom\XMLDocument::createFromString('<?xml version="1.0"?><root><a/></root>');
echo 'root=', $x->documentElement->nodeName, "\n";
echo 'child=', $x->documentElement->firstChild->nodeName, "\n";
$empty = Dom\XMLDocument::createEmpty();
echo 'empty=', ($empty instanceof Dom\XMLDocument ? 'yes' : 'no'), "\n";
echo 'empty_root=', ($empty->documentElement === null ? 'NULL' : 'set'), "\n";
?>
--EXPECT--
createFromString=1
createEmpty=1
root=root
child=a
empty=yes
empty_root=NULL
