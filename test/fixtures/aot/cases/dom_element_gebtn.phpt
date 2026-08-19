--TEST--
AOT: DOMElement::getElementsByTagName descendant NodeList (#32454, ext/dom/element.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/><a/></root>');
$root = $doc->documentElement;
echo $root->getElementsByTagName('a')->length, '|', $root->getElementsByTagName('root')->length, "\n";
--EXPECT--
2|0
