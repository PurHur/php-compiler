<?php
declare(strict_types=1);
/**
 * #32821 — AOT ChildNode::remove must refresh held live childNodes (php-src nodelist.c).
 * Zend/VM: held length 2 after removing first of three.
 * Prior AOT: syncChildNodesLengthSlot(0) replaced the list object → held stayed length 3.
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$a = $el->firstChild;
$a->remove();
echo 'held_len=', $list->length, "\n";
echo 'held0=', ($list->item(0) ? $list->item(0)->nodeName : 'null'), "\n";
echo 'held1=', ($list->item(1) ? $list->item(1)->nodeName : 'null'), "\n";
echo 'refetch_len=', $el->childNodes->length, "\n";
echo 'refetch0=', $el->childNodes->item(0)->nodeName, "\n";
