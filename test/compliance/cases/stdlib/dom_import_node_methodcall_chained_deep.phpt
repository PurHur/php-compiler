--TEST--
stdlib DOMDocument::importNode() chained MethodCall + deep bool (#20284, ext/dom/php_dom.c)
--FILE--
<?php
declare(strict_types=1);
if (!class_exists('DOMDocument', false)) {
    print "skip: DOMDocument not available\n";
    exit(0);
}
$d1 = new DOMDocument();
$d1->loadXML('<root><a><b>x</b></a></root>');
$d2 = new DOMDocument();
$d2->loadXML('<root/>');
$n = $d2->importNode($d1->getElementsByTagName('a')->item(0), true);
$d2->documentElement->appendChild($n);
echo $d2->saveXML();
?>
--EXPECT--
<?xml version="1.0"?>
<root><a><b>x</b></a></root>
