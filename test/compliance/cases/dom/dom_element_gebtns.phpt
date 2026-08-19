--TEST--
stdlib DOMElement::getElementsByTagNameNS descendant list (#32511, ext/dom/element.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<n:r xmlns:n="http://example.com/ns"><n:a/><b/><n:c/></n:r>');
$root = $doc->documentElement;
$list = $root->getElementsByTagNameNS('http://example.com/ns', '*');
echo 'len=', $list->length, '|';
echo 'i0=', $list->item(0)->localName, '|';
echo 'i1=', $list->item(1)->localName, "\n";
--EXPECT--
len=2|i0=a|i1=c
