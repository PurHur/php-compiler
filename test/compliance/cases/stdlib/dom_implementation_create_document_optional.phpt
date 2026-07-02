--TEST--
stdlib DOMImplementation::createDocument() optional args (#14408, ext/dom/php_dom.c)
--FILE--
<?php
$impl = new DOMImplementation();
$empty = $impl->createDocument();
echo var_export($empty->documentElement, true), "\n";
$root = $impl->createDocument(null, 'root');
echo $root->documentElement->nodeName, "\n";
?>
--EXPECT--
NULL
root
