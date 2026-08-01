--TEST--
DOMDocument::loadXML accepts leading UTF-8 BOM (#26565, php-src ext/dom/document.c)
--FILE--
<?php
$d = new DOMDocument();
echo (int) @$d->loadXML("\xEF\xBB\xBF<root/>"), "\n";
echo $d->documentElement->tagName, "\n";

$d2 = new DOMDocument();
echo (int) @$d2->loadXML("\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"UTF-8\"?><item/>"), "\n";
echo $d2->documentElement->tagName, "\n";
echo var_export($d2->encoding, true), "\n";

$d3 = new DOMDocument();
echo (int) @$d3->loadXML("  \xEF\xBB\xBF<root/>"), "\n";

$d4 = new DOMDocument();
echo (int) @$d4->loadXML('<root/>'), "\n";

$d5 = new DOMDocument();
echo (int) @$d5->loadXML("\xEF\xBB\xBF<"), "\n";
?>
--EXPECT--
1
root
1
item
'UTF-8'
0
1
0
