--TEST--
AOT: ChildNode::after() append-tail — saveXML keeps new sibling (#34136; ext/dom/php_dom.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><b/></r>');
$b = $d->documentElement->firstChild;
$b->after($d->createElement('c'));
echo 'len=', $d->documentElement->childNodes->length, "\n";
echo $d->saveXML($d->documentElement), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a/><c/></r>');
$a = $d2->documentElement->firstChild;
$a->after($d2->createElement('b'));
echo $d2->saveXML($d2->documentElement), "\n";
--EXPECT--
len=2
<r><b/><c/></r>
<r><a/><b/><c/></r>
