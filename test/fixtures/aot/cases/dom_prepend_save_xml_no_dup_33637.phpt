--TEST--
AOT: ParentNode::prepend createElement saveXML no duplicate (#33637)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$d->documentElement->prepend($d->createElement('z'));
echo $d->saveXML($d->documentElement), "\n";
$d2 = new DOMDocument();
$d2->loadXML('<r><a/><b/></r>');
$d2->documentElement->prepend($d2->createElement('x'), $d2->createElement('y'));
echo $d2->saveXML($d2->documentElement), "\n";
?>
--EXPECT--
<r><z/><a/></r>
<r><x/><y/><a/><b/></r>
