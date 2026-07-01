<?php
echo class_exists('DOMDocumentFragment') ? "class ok\n" : "class missing\n";
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$frag = $doc->createDocumentFragment();
echo $frag instanceof DOMDocumentFragment ? "instance ok\n" : "instance fail\n";
$child = $doc->createElement('item');
$frag->appendChild($child);
$root->appendChild($frag);
echo $frag->childNodes->length, "\n";
echo $root->childNodes->length, "\n";
echo $root->firstChild->nodeName, "\n";
echo trim($doc->saveXML()), "\n";
