<?php
// AOT: appendChild via held childNodes->item(N) must reorder saveXML (#32947)
// php-src: ext/dom/node.c dom_node_append_child / document.c saveXML
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$el->appendChild($list->item(0));
echo 'held=', $list->length, "\n";
echo 'xml=', $doc->saveXML($el), "\n";
echo 'refetch0=', $el->childNodes->item(0)->nodeName, "\n";
echo 'refetch2=', $el->childNodes->item(2)->nodeName, "\n";
