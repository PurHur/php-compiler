--TEST--
stdlib DOMDocument loadXML/createElement/appendChild (#11895, ext/dom/php_dom.c)
--FILE--
<?php
$d = new DOMDocument();
echo (int) $d->loadXML('<root/>'), "\n";
echo $d->documentElement->nodeName, "\n";
$d2 = new DOMDocument();
$item = $d2->createElement('item');
$d2->appendChild($item);
echo $d2->documentElement->nodeName, "\n";
?>
--EXPECT--
1
root
item
