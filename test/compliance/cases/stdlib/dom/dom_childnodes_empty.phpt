--TEST--
stdlib DOMNode::childNodes on empty document/fragment/element (#17617, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
echo get_class($doc->childNodes), "\n";
echo $doc->childNodes->length, "\n";
$frag = new DOMDocumentFragment();
echo get_class($frag->childNodes), "\n";
echo $frag->childNodes->length, "\n";
$el = $doc->createElement('x');
echo get_class($el->childNodes), "\n";
echo $el->childNodes->length, "\n";
?>
--EXPECT--
DOMNodeList
0
DOMNodeList
0
DOMNodeList
0
