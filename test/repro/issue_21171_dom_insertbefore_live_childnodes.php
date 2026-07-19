<?php
/**
 * Repro #21171 — insertBefore(createElement(...), childNodes->item(...))
 * must update live childNodes length/indices (php-src ext/dom/node.c).
 */
$d = new DOMDocument();
$d->loadXML('<r><a/><c/></r>');
$el = $d->documentElement;
$list = $el->childNodes;

// One-liner shape (failing on VM/JIT)
$el->insertBefore($d->createElement('b'), $list->item(1));
echo 'oneliner length=', $list->length, ' item1=', ($list->item(1) ? $list->item(1)->nodeName : 'null'), "\n";

// Reset and saved-ref shape (should stay green)
$d2 = new DOMDocument();
$d2->loadXML('<r><a/><c/></r>');
$el2 = $d2->documentElement;
$list2 = $el2->childNodes;
$ref = $list2->item(1);
$el2->insertBefore($d2->createElement('b'), $ref);
echo 'saved_ref length=', $list2->length, ' item1=', ($list2->item(1) ? $list2->item(1)->nodeName : 'null'), "\n";

// Reset and saved-new shape
$d3 = new DOMDocument();
$d3->loadXML('<r><a/><c/></r>');
$el3 = $d3->documentElement;
$list3 = $el3->childNodes;
$new = $d3->createElement('b');
$el3->insertBefore($new, $list3->item(1));
echo 'saved_new length=', $list3->length, ' item1=', ($list3->item(1) ? $list3->item(1)->nodeName : 'null'), "\n";
