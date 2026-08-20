<?php
declare(strict_types=1);
/**
 * #32834 — AOT ParentNode::append/prepend onto empty element must refresh held childNodes.
 * Zend/VM: held_len=1 after first child. Prior AOT: held_len=0 then item(0) SIGSEGV.
 */
$doc = new DOMDocument();
$doc->loadXML('<r/>');
$el = $doc->documentElement;
$list = $el->childNodes;
$z = $doc->createElement('z');
$el->append($z);
echo 'append_held_len=', $list->length, "\n";
echo 'append_held0=', $list->item(0)->nodeName, "\n";
echo 'append_refetch_len=', $el->childNodes->length, "\n";

$doc2 = new DOMDocument();
$doc2->loadXML('<r/>');
$el2 = $doc2->documentElement;
$list2 = $el2->childNodes;
$y = $doc2->createElement('y');
$el2->prepend($y);
echo 'prepend_held_len=', $list2->length, "\n";
echo 'prepend_held0=', $list2->item(0)->nodeName, "\n";
echo 'prepend_refetch_len=', $el2->childNodes->length, "\n";
