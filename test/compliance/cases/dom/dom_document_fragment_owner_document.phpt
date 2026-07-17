--TEST--
DOMDocumentFragment::$ownerDocument after createDocumentFragment (#20203, ext/dom/document.c)
--FILE--
<?php
$doc = new DOMDocument();
$frag = $doc->createDocumentFragment();
echo $frag->ownerDocument === $doc ? "1" : "0";
echo "\n";
$el = $doc->createElement('x');
echo $el->ownerDocument === $doc ? "1" : "0";
echo "\n";
?>
--EXPECT--
1
1
