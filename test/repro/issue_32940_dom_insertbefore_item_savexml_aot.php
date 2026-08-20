<?php
declare(strict_types=1);
/**
 * #32940 — AOT insertBefore via childNodes->item(N) must update saveXML InnerXml.
 * LiveSlots already refresh held pins (#32801); serialization must match Zend.
 * php-src: ext/dom/node.c dom_node_insert_before / document.c saveXML
 * Peer: #32903 replaceChild InnerXml.
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$el->insertBefore($doc->createElement('x'), $list->item(1));
echo 'held1=', $list->item(1)->nodeName, "\n";
echo 'len=', $list->length, "\n";
echo 'xml=', trim($doc->saveXML($el)), "\n";
echo 'refetch1=', $el->childNodes->item(1)->nodeName, "\n";

$doc2 = new DOMDocument();
$doc2->loadXML('<r><a/><b/><c/></r>');
$el2 = $doc2->documentElement;
$list2 = $el2->childNodes;
$el2->insertBefore($doc2->createElement('x', '9'), $list2->item(1));
echo 'valued_xml=', trim($doc2->saveXML($el2)), "\n";
