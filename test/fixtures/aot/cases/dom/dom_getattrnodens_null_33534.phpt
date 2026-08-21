--TEST--
AOT: getAttributeNodeNS(null) sees setAttribute Attr (#33534, ext/dom/element.c)
--FILE--
<?php
$d = new DOMDocument();
$e = $d->createElement('e');
$e->setAttribute('k', 'v');
$n = $e->getAttributeNodeNS(null, 'k');
if ($n === null) {
    echo "null\n";
} else {
    echo $n->value, "\n";
}
--EXPECT--
v
