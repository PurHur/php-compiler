<?php
declare(strict_types=1);
/**
 * #32822 — AOT ChildNode::replaceWith must refresh held live childNodes (php-src nodelist.c).
 * Peer of #32817 after LiveSlots / #32784 replaceChild LiveSlots.
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$b = $list->item(1);
$z = $doc->createElement('z');
$b->replaceWith($z);
echo 'held_len=', $list->length, "\n";
echo 'held0=', ($list->item(0) ? $list->item(0)->nodeName : 'null'), "\n";
echo 'held1=', ($list->item(1) ? $list->item(1)->nodeName : 'null'), "\n";
echo 'held2=', ($list->item(2) ? $list->item(2)->nodeName : 'null'), "\n";
echo 'refetch_len=', $el->childNodes->length, "\n";
echo 'refetch1=', $el->childNodes->item(1)->nodeName, "\n";
