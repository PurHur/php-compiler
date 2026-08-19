<?php

$doc = new DOMDocument();
$doc->loadXML('<root x="1"/>');
$doc->documentElement->removeAttribute('x');

$doc2 = new DOMDocument();
$doc2->loadXML('<root><a/></root>');
$doc2->getElementsByTagName('a');

$doc3 = new DOMDocument();
$root = $doc3->createElement('root');
$doc3->appendChild($root);
$root->appendChild($doc3->createTextNode('a'));
echo "childLen: ".$root->childNodes->length."\n";
