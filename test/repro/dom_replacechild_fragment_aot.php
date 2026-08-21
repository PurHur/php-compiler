<?php
$doc = new DOMDocument();
$root = $doc->createElement('r');
$doc->appendChild($root);
$a = $doc->createElement('a');
$b = $doc->createElement('b');
$root->appendChild($a);
$root->appendChild($b);
$frag = $doc->createDocumentFragment();
$frag->appendChild($doc->createElement('x'));
$frag->appendChild($doc->createElement('y'));
$old = $root->replaceChild($frag, $a);
echo 'old=', $old->nodeName, "\n";
echo 'len=', $root->childNodes->length, "\n";
echo 'i0=', $root->childNodes->item(0)->nodeName, "\n";
echo 'i1=', $root->childNodes->item(1)->nodeName, "\n";
echo 'i2=', $root->childNodes->item(2)->nodeName, "\n";
echo 'xml=', trim($doc->saveXML($root)), "\n";
