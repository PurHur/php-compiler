<?php
declare(strict_types=1);
/**
 * #32821 — AOT ChildNode::replaceWith must refresh held live childNodes (php-src nodelist.c).
 * Zend/VM: held length 3, item0=z; refetch item0=z.
 * Prior AOT: InnerXml-only path skipped LiveSlots → held0 stayed a.
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$a = $el->firstChild;
$z = $doc->createElement('z');
$a->replaceWith($z);
echo 'held_len=', $list->length, "\n";
echo 'held0=', ($list->item(0) ? $list->item(0)->nodeName : 'null'), "\n";
echo 'held1=', ($list->item(1) ? $list->item(1)->nodeName : 'null'), "\n";
echo 'held2=', ($list->item(2) ? $list->item(2)->nodeName : 'null'), "\n";
echo 'refetch_len=', $el->childNodes->length, "\n";
echo 'refetch0=', $el->childNodes->item(0)->nodeName, "\n";
