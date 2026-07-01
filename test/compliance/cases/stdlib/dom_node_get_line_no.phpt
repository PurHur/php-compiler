--TEST--
stdlib DOMNode::getLineNo() (#14407, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><item/></root>');
$el = $doc->documentElement->firstChild;
echo $el->getLineNo(), "\n";
?>
--EXPECT--
1
