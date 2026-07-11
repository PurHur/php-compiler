--TEST--
stdlib DOM tree linkage parentNode/childNodes/firstChild (#14466, ext/dom/node.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r/>');
echo $d->documentElement->parentNode->nodeName, "\n";
echo $d->childNodes->length, "\n";
echo $d->firstChild->nodeName, "\n";
$d2 = new DOMDocument();
$el = $d2->createElement('x');
$d2->appendChild($el);
echo $el->parentNode->nodeName, "\n";
?>
--EXPECT--
#document
1
r
#document
