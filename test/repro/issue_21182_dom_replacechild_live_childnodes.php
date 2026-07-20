<?php
/**
 * Repro #21182 — replaceChild(createElement(...), childNodes->item(...))
 * on $d->documentElement must update live childNodes (php-src ext/dom/node.c).
 */
$d = new DOMDocument();
$d->loadXML('<r><a/><c/></r>');
$list = $d->documentElement->childNodes;
$d->documentElement->replaceChild($d->createElement('b'), $list->item(0));
echo 'oneliner length=', $list->length, ' item0=', ($list->item(0) ? $list->item(0)->nodeName : 'null'), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a/><c/></r>');
$el2 = $d2->documentElement;
$list2 = $el2->childNodes;
$el2->replaceChild($d2->createElement('b'), $list2->item(0));
echo 'saved_el length=', $list2->length, ' item0=', ($list2->item(0) ? $list2->item(0)->nodeName : 'null'), "\n";

$d3 = new DOMDocument();
$d3->loadXML('<r><a/><c/></r>');
$list3 = $d3->documentElement->childNodes;
$old = $list3->item(0);
$d3->documentElement->replaceChild($d3->createElement('b'), $old);
echo 'saved_old length=', $list3->length, ' item0=', ($list3->item(0) ? $list3->item(0)->nodeName : 'null'), "\n";
