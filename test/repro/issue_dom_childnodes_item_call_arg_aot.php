<?php
declare(strict_types=1);
/**
 * #34436 — insertBefore(createElement, $el->childNodes->item(N)) keeps distinct ARG_SENDs.
 *
 * php-src: ext/dom/node.c php_dom_insert_before
 * Peer: #25563 getElementsByTagName()->item, #19719 lastChild, #32940 held list.
 */
$d = new DOMDocument();
$d->loadXML('<r><a/><c/></r>');
$el = $d->documentElement;
$el->insertBefore($d->createElement('b'), $el->childNodes->item(1));
echo 'len=', $el->childNodes->length, "\n";
echo 'item1=', $el->childNodes->item(1)->nodeName, "\n";
echo 'xml=', $d->saveXML($el), "\n";
