<?php
declare(strict_types=1);
/**
 * #32848 — AOT ChildNode::after/before multi-arg must refresh held childNodes.
 * Zend/VM: held_len=3 after two new siblings. Prior AOT after: held_len=2 (LiveSlots arity-1 only).
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$a = $el->firstChild;
$a->after($doc->createElement('b'), $doc->createElement('c'));
echo 'after_held_len=', $list->length, "\n";
for ($i = 0; $i < $list->length; $i++) {
    echo 'after_held', $i, '=', $list->item($i)->nodeName, "\n";
}
echo 'after_refetch_len=', $el->childNodes->length, "\n";

$doc2 = new DOMDocument();
$doc2->loadXML('<r><a/></r>');
$el2 = $doc2->documentElement;
$list2 = $el2->childNodes;
$a2 = $el2->firstChild;
$a2->before($doc2->createElement('b'), $doc2->createElement('c'));
echo 'before_held_len=', $list2->length, "\n";
for ($i = 0; $i < $list2->length; $i++) {
    echo 'before_held', $i, '=', $list2->item($i)->nodeName, "\n";
}
echo 'before_refetch_len=', $el2->childNodes->length, "\n";
