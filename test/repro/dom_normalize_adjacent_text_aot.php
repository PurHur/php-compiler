<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$root->appendChild($doc->createTextNode('a'));
$root->appendChild($doc->createTextNode('b'));
echo 'pre_len=', $root->childNodes->length, "\n";
$root->normalize();
echo 'post_len=', $root->childNodes->length, "\n";
echo 'text=', $root->textContent, "\n";
echo 'save=', $doc->saveXML($root), "\n";
