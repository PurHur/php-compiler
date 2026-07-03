--TEST--
stdlib DOMDocument::registerNodeClass() custom element (#15334, ext/dom/document.c)
--FILE--
<?php
class MyElement extends DOMElement {}
$d = new DOMDocument();
$d->registerNodeClass('DOMElement', MyElement::class);
$el = $d->createElement('x');
echo $el instanceof MyElement ? "custom\n" : "stock\n";
$plain = new DOMDocument();
$stock = $plain->createElement('y');
echo $stock instanceof DOMElement && !($stock instanceof MyElement) ? "default\n" : "bad\n";
?>
--EXPECT--
custom
default
