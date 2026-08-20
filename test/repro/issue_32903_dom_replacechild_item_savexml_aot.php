<?php
declare(strict_types=1);
/**
 * #32903 — AOT replaceChild via childNodes->item(N) must update saveXML InnerXml.
 * LiveSlots already refresh held pins (#32784); serialization must match Zend.
 * php-src: ext/dom/node.c dom_node_replace_child / document.c saveXML
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$el->replaceChild($doc->createElement('x'), $list->item(1));
echo 'held1=', $list->item(1)->nodeName, "\n";
echo 'xml=', trim($doc->saveXML($el)), "\n";
echo 'refetch1=', $el->childNodes->item(1)->nodeName, "\n";

$doc2 = new DOMDocument();
$doc2->loadXML('<r><a/><b/><c/></r>');
$el2 = $doc2->documentElement;
$list2 = $el2->childNodes;
$el2->replaceChild($doc2->createElement('x', '9'), $list2->item(1));
echo 'valued_xml=', trim($doc2->saveXML($el2)), "\n";
