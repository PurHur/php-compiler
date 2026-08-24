<?php
declare(strict_types=1);
/**
 * #34428 — AOT insertBefore before last sibling must not duplicate child in saveXML.
 * LiveSlots rebuilds INNER_XML; a second compile-time splice must not overwrite that slot.
 * php-src: ext/dom/node.c php_dom_insert_before / document.c saveXML
 * Peer: #33637 prepend, #32940 insertBefore item() saveXML
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><c/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$el->insertBefore($doc->createElement('b'), $list->item(1));
echo 'len=', $list->length, "\n";
echo 'item1=', $list->item(1)->nodeName, "\n";
echo 'xml=', trim($doc->saveXML($el)), "\n";
