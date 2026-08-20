<?php
declare(strict_types=1);
/**
 * #32817 — AOT ChildNode::after must refresh held live childNodes (php-src nodelist.c).
 * firstChild->after hits PROP_USER_SCRIPT_INNER_XML short-circuit; held list must still
 * gain LiveSlots (+1 length / item pins). Peer of #32801 (insertBefore).
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$a = $el->firstChild;
$z = $doc->createElement('z');
$a->after($z);
echo 'held_len=', $list->length, "\n";
echo 'held0=', ($list->item(0) ? $list->item(0)->nodeName : 'null'), "\n";
echo 'held1=', ($list->item(1) ? $list->item(1)->nodeName : 'null'), "\n";
echo 'held2=', ($list->item(2) ? $list->item(2)->nodeName : 'null'), "\n";
echo 'held3=', ($list->item(3) ? $list->item(3)->nodeName : 'null'), "\n";
echo 'refetch_len=', $el->childNodes->length, "\n";
echo 'refetch1=', $el->childNodes->item(1)->nodeName, "\n";
