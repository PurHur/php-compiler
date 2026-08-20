<?php
declare(strict_types=1);
/**
 * #32887 — AOT ChildNode::replaceWith multi-arg must refresh held childNodes + saveXML.
 * Peer of #32848 after/before multi; #32822 replaceWith single LiveSlots.
 * Zend/VM: held_len=3 / b c z. Prior AOT: held_len=2 / b z (arity-1 only).
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><z/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$a = $el->firstChild;
$a->replaceWith($doc->createElement('b'), $doc->createElement('c'));
echo 'held_len=', $list->length, "\n";
for ($i = 0; $i < $list->length; $i++) {
    echo 'held', $i, '=', $list->item($i)->nodeName, "\n";
}
echo 'refetch_len=', $el->childNodes->length, "\n";
echo 'save=', $doc->saveXML($el), "\n";
