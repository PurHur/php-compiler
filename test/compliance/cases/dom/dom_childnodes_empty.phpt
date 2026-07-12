--TEST--
stdlib DOMNode::childNodes empty DOMNodeList (#17722, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
echo $doc->childNodes->length, "\n";
?>
--EXPECT--
0
