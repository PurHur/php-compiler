<?php
$doc = new DOMDocument();
$root = $doc->createElement('r');
$doc->appendChild($root);
$root->appendChild($doc->createElement('a'));
$frag = $doc->createDocumentFragment();
$frag->appendChild($doc->createElement('b'));
$frag->appendChild($doc->createElement('c'));
$root->appendChild($frag);
$list = $root->childNodes;
echo 'len=', $list->length, "\n";
echo 'i0=', $list->item(0)->nodeName, "\n";
echo 'i1=', $list->item(1)->nodeName, "\n";
echo 'i2=', $list->item(2)->nodeName, "\n";
echo 'xml=', trim($doc->saveXML($root)), "\n";
