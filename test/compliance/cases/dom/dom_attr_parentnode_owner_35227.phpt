--TEST--
DOMAttr parentNode equals ownerElement after getAttributeNode (#35227)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r a="1"/>');
$a = $d->documentElement->getAttributeNode('a');
echo $a->ownerElement->nodeName, "\n";
echo $a->parentNode->nodeName, "\n";
echo ($a->parentNode === $a->ownerElement) ? 'same' : 'diff', "\n";
?>
--EXPECT--
r
r
same
