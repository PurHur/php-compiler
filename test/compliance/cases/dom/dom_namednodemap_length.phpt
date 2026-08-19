--TEST--
stdlib DOMNamedNodeMap length/item matches xmlNode->properties (#32546, ext/dom/namednodemap.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root id="x" class="y"/>');
$attrs = $doc->documentElement->attributes;
echo $attrs->length, '|', $attrs->item(0)->name, '|', $attrs->item(1)->name, "\n";
--EXPECT--
2|id|class
