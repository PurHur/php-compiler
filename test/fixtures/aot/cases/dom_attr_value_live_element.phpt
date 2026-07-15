--TEST--
AOT: DOMAttr::$value write updates Element::getAttribute (live Attr; #19281)
--FILE--
<?php
$d = new DOMDocument();
$e = $d->createElement('a');
$d->appendChild($e);
$e->setAttribute('x', '1');
$attr = $e->getAttributeNode('x');
$attr->value = '2';
echo 'getAttribute=', $e->getAttribute('x'), ' attr.value=', $attr->value, "\n";
?>
--EXPECT--
getAttribute=2 attr.value=2
