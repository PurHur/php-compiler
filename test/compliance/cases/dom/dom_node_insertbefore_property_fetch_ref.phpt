--TEST--
dom DOMNode::insertBefore() — MethodCall + PropertyFetch ref args (#19719)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$r = $d->documentElement;
$r->insertBefore($d->createElement('x'), $r->lastChild);
echo $d->saveXML($r), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a/></r>');
$d2->documentElement->insertBefore($d2->createElement('b'), $d2->documentElement->firstChild);
echo $d2->saveXML($d2->documentElement), "\n";

function take($a, $b) {
    echo $a->nodeName, ',', $b->nodeName, "\n";
}
$d3 = new DOMDocument();
$d3->loadXML('<r><a/><b/></r>');
$r3 = $d3->documentElement;
take($d3->createElement('x'), $r3->lastChild);
take($r3->lastChild, $d3->createElement('y'));
--EXPECT--
<r><a/><x/><b/></r>
<r><b/><a/></r>
x,b
b,y
