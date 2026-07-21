--TEST--
dom DOMNode::replaceWith/before createElement + middle string literal (#21901)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$a = $d->documentElement->firstChild;
$a->replaceWith($d->createElement('x'), 'txt', $d->createElement('y'));
echo $d->saveXML($d->documentElement), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a/><b/></r>');
$a2 = $d2->documentElement->firstChild;
$a2->before($d2->createElement('x'), 'txt', $d2->createElement('y'));
echo $d2->saveXML($d2->documentElement), "\n";

$d3 = new DOMDocument();
$d3->loadXML('<r><a/><b/></r>');
$a3 = $d3->documentElement->firstChild;
$a3->replaceWith('txt', $d3->createElement('x'), $d3->createElement('y'));
echo $d3->saveXML($d3->documentElement), "\n";
--EXPECT--
<r><x/>txt<y/><b/></r>
<r><x/>txt<y/><a/><b/></r>
<r>txt<x/><y/><b/></r>
