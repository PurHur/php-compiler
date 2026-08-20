<?php
declare(strict_types=1);
/**
 * #32817 — AOT ChildNode::after must refresh held live childNodes (php-src nodelist.c).
 * Zend/VM: held length 4, item1=z; refetch item1=z.
 * Prior AOT: InnerXml short-circuit skipped LiveSlots → held_len=3 then SIGSEGV on item(3).
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
