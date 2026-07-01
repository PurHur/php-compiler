--TEST--
stdlib DOMNode firstChild/childNodes tree navigation (#14335, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$root = $doc->documentElement;
echo $root->firstChild->nodeName, "\n";
echo $root->childNodes->length, "\n";
?>
--EXPECT--
a
2
