--TEST--
AOT: ChildNode::after() append-tail keeps sibling in saveXML (#34136)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><b/></r>');
$b = $d->documentElement->firstChild;
$b->after($d->createElement('c'));
echo 'len=', $d->documentElement->childNodes->length, "\n";
echo $d->saveXML($d->documentElement), "\n";
--EXPECT--
len=2
<r><b/><c/></r>
