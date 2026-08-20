<?php
declare(strict_types=1);
/**
 * #32942 — AOT removeChild via childNodes->item(N) must update saveXML InnerXml.
 * LiveSlots already refresh held pins (#32774); serialization must match Zend.
 * php-src: ext/dom/node.c dom_node_remove_child / document.c saveXML
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$el->removeChild($list->item(1));
echo 'held_len=', $list->length, "\n";
echo 'held0=', $list->item(0)->nodeName, "\n";
echo 'xml=', trim($doc->saveXML($el)), "\n";
echo 'refetch_len=', $el->childNodes->length, "\n";
echo 'refetch0=', $el->childNodes->item(0)->nodeName, "\n";
