--TEST--
stdlib DOMElement::getElementsByTagName descendant list (#32454, ext/dom/element.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/><a/></root>');
$root = $doc->documentElement;
echo $root->getElementsByTagName('a')->length, '|', $root->getElementsByTagName('root')->length, "\n";
--EXPECT--
2|0
