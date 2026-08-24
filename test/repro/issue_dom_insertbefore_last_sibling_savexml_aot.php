<?php
declare(strict_types=1);
/**
 * insertBefore before the last child must not duplicate the node in saveXML.
 *
 * Live childNodes length/item() stay correct; saveXML previously appended a
 * trailing copy of the inserted element (LiveSlots INNER_XML rebuild then
 * compile-time splice store).
 *
 * php-src: ext/dom/node.c php_dom_insert_before / document.c saveXML
 * Peer: #33637 prepend INNER_XML concat, #32940 insertBefore item() saveXML.
 */
$d = new DOMDocument();
$d->loadXML('<r><a/><c/></r>');
$el = $d->documentElement;
$list = $el->childNodes;
$el->insertBefore($d->createElement('b'), $list->item(1));
echo 'len=', $list->length, "\n";
echo 'item1=', $list->item(1)->nodeName, "\n";
echo 'xml=', $d->saveXML($el), "\n";
