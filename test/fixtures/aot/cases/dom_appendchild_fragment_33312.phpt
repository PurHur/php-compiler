--TEST--
AOT: DocumentFragment appendChild expands children (#33312)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a/></r>');
$f = $doc->createDocumentFragment();
$f->appendChild($doc->createElement('b'));
$f->appendChild($doc->createElement('c'));
$doc->documentElement->appendChild($f);
$list = $doc->documentElement->childNodes;
echo 'len=', $list->length, "\n";
echo trim($doc->saveXML($doc->documentElement)), "\n";
echo $list->item(1)->nodeName, ',', $list->item(2)->nodeName, "\n";
--EXPECT--
len=3
<r><a/><b/><c/></r>
b,c
