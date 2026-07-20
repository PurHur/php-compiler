--TEST--
dom DOMNode::replaceChild(createElement, childNodes->item) on property receiver (#21182)
--FILE--
<?php
// Bare property receiver (issue repro) — ARG_SEND must bind createElement + item distinctly.
$d = new DOMDocument();
$d->loadXML('<r><a/><c/></r>');
$list = $d->documentElement->childNodes;
$d->documentElement->replaceChild($d->createElement('b'), $list->item(0));
echo 'oneliner length=', $list->length, ' item0=', $list->item(0)->nodeName, "\n";

// Saved $el receiver (sibling of #21171 shape)
$d2 = new DOMDocument();
$d2->loadXML('<r><a/><c/></r>');
$el2 = $d2->documentElement;
$list2 = $el2->childNodes;
$el2->replaceChild($d2->createElement('b'), $list2->item(0));
echo 'saved_el length=', $list2->length, ' item0=', $list2->item(0)->nodeName, "\n";

// Saved old child + property receiver
$d3 = new DOMDocument();
$d3->loadXML('<r><a/><c/></r>');
$list3 = $d3->documentElement->childNodes;
$old = $list3->item(0);
$d3->documentElement->replaceChild($d3->createElement('b'), $old);
echo 'saved_old length=', $list3->length, ' item0=', $list3->item(0)->nodeName, "\n";
// Same mixed PropertyFetch+MethodCall wiring for insertBefore (gap left by #21171).
$d4 = new DOMDocument();
$d4->loadXML('<r><a/><c/></r>');
$list4 = $d4->documentElement->childNodes;
$d4->documentElement->insertBefore($d4->createElement('b'), $list4->item(1));
echo 'ib_prop length=', $list4->length, ' item1=', $list4->item(1)->nodeName, "\n";
--EXPECT--
oneliner length=2 item0=b
saved_el length=2 item0=b
saved_old length=2 item0=b
ib_prop length=3 item1=b
