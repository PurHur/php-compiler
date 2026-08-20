<?php
declare(strict_types=1);
/**
 * #32828 — AOT ParentNode::prepend must refresh held live childNodes (php-src nodelist.c).
 * Zend/VM: held length 3 after prepending onto two children.
 * Prior AOT: NestedJIT syncChildLinkSlots collapsed tree (held_len=2, refetch_len=1).
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$z = $doc->createElement('z');
$el->prepend($z);
echo 'held_len=', $list->length, "\n";
echo 'held0=', ($list->item(0) ? $list->item(0)->nodeName : 'null'), "\n";
echo 'held1=', ($list->item(1) ? $list->item(1)->nodeName : 'null'), "\n";
echo 'held2=', ($list->item(2) ? $list->item(2)->nodeName : 'null'), "\n";
echo 'refetch_len=', $el->childNodes->length, "\n";
echo 'refetch0=', $el->childNodes->item(0)->nodeName, "\n";
echo 'refetch1=', $el->childNodes->item(1)->nodeName, "\n";
