--TEST--
AOT: NamedNodeMap length/item must not SIGSEGV (#32546, ext/dom/namednodemap.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<root id="x" class="y"/>');
$attrs = $doc->documentElement->attributes;
echo $attrs->length, '|', $attrs->item(0)->name, '|', $attrs->item(1)->name, "\n";
--EXPECT--
2|id|class
