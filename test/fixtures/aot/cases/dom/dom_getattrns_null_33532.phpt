--TEST--
AOT: getAttributeNS/hasAttributeNS(null) see setAttribute Attr (#33532, ext/dom/element.c)
--FILE--
<?php
$d = new DOMDocument();
$e = $d->createElement('e');
$e->setAttribute('k', 'v');
echo $e->getAttributeNS(null, 'k'), "\n";
echo $e->hasAttributeNS(null, 'k') ? "1\n" : "0\n";
--EXPECT--
v
1
