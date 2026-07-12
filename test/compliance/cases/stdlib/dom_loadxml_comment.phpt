--TEST--
DOMDocument::loadXML accepts XML comment nodes (#17530)
--FILE--
<?php
$doc = new DOMDocument();
var_dump($doc->loadXML('<root><!--comment--><child/></root>'));
$child = $doc->documentElement->firstChild;
echo $child::class, "\n";
echo $child->data, "\n";
echo $doc->saveXML($child), "\n";
--EXPECT--
bool(true)
DOMComment
comment
<!--comment-->
