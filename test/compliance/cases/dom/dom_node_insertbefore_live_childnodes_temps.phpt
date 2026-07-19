--TEST--
dom DOMNode::insertBefore(createElement, childNodes->item) updates live childNodes (#21171)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><c/></r>');
$el = $d->documentElement;
$list = $el->childNodes;
$el->insertBefore($d->createElement('b'), $list->item(1));
echo 'oneliner length=', $list->length, ' item1=', $list->item(1)->nodeName, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a/><c/></r>');
$el2 = $d2->documentElement;
$list2 = $el2->childNodes;
$ref = $list2->item(1);
$el2->insertBefore($d2->createElement('b'), $ref);
echo 'saved_ref length=', $list2->length, ' item1=', $list2->item(1)->nodeName, "\n";

$d3 = new DOMDocument();
$d3->loadXML('<r><a/><c/></r>');
$el3 = $d3->documentElement;
$list3 = $el3->childNodes;
$new = $d3->createElement('b');
$el3->insertBefore($new, $list3->item(1));
echo 'saved_new length=', $list3->length, ' item1=', $list3->item(1)->nodeName, "\n";
--EXPECT--
oneliner length=3 item1=b
saved_ref length=3 item1=b
saved_new length=3 item1=b
