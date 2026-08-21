<?php
/**
 * AOT: DocumentFragment appendChild / insertBefore must expand children (#33312).
 * php-src: ext/dom/node.c dom_node_append_child / dom_node_insert_before
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/></r>');
$f = $doc->createDocumentFragment();
$f->appendChild($doc->createElement('b'));
$f->appendChild($doc->createElement('c'));
$doc->documentElement->appendChild($f);
$list = $doc->documentElement->childNodes;
echo 'append_len=', $list->length, "\n";
echo 'append_xml=', trim($doc->saveXML($doc->documentElement)), "\n";
echo 'append_i1=', $list->item(1)->nodeName, ' append_i2=', $list->item(2)->nodeName, "\n";

$doc2 = new DOMDocument();
$doc2->loadXML('<r><z/></r>');
$f2 = $doc2->createDocumentFragment();
$f2->appendChild($doc2->createElement('b'));
$f2->appendChild($doc2->createElement('c'));
$doc2->documentElement->insertBefore($f2, $doc2->documentElement->firstChild);
$list2 = $doc2->documentElement->childNodes;
echo 'ib_len=', $list2->length, "\n";
echo 'ib_xml=', trim($doc2->saveXML($doc2->documentElement)), "\n";
echo 'ib_i0=', $list2->item(0)->nodeName, ' ib_i1=', $list2->item(1)->nodeName, ' ib_i2=', $list2->item(2)->nodeName, "\n";
