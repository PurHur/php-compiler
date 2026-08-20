<?php
declare(strict_types=1);
/**
 * #32838 — AOT ParentNode::append/prepend multi-arg must refresh held childNodes.
 * Zend/VM: held_len=3 after two new siblings. Prior AOT: held_len=1 (LiveSlots arity-1 only).
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$el->append($doc->createElement('b'), $doc->createElement('c'));
echo 'append_held_len=', $list->length, "\n";
for ($i = 0; $i < $list->length; $i++) {
    echo 'append_held', $i, '=', $list->item($i)->nodeName, "\n";
}
echo 'append_refetch_len=', $el->childNodes->length, "\n";

$doc2 = new DOMDocument();
$doc2->loadXML('<r><a/></r>');
$el2 = $doc2->documentElement;
$list2 = $el2->childNodes;
$el2->prepend($doc2->createElement('b'), $doc2->createElement('c'));
echo 'prepend_held_len=', $list2->length, "\n";
for ($i = 0; $i < $list2->length; $i++) {
    echo 'prepend_held', $i, '=', $list2->item($i)->nodeName, "\n";
}
echo 'prepend_refetch_len=', $el2->childNodes->length, "\n";
