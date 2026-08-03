--TEST--
AOT: loadXML whitespace + normalizeDocument childNodes length (#27260, ext/dom/document.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML("<r> <a/> </r>");
$d->normalizeDocument();
echo $d->documentElement->childNodes->length, "\n";
--EXPECT--
3
