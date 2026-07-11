--TEST--
stdlib DOMDocument getElementsByTagName / DOMNodeList (#14336, ext/dom/php_dom.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$list = $doc->getElementsByTagName('a');
echo $list->length, $list->item(0)->nodeName, "\n";
?>
--EXPECT--
1a
