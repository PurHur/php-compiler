--TEST--
stdlib DOMDocument::createAttributeNS() without root — false + warning (#19200, ext/dom/document.c)
--FILE--
<?php
$doc = new DOMDocument();
$attr = @$doc->createAttributeNS('http://example.com', 'ex:foo');
var_export($attr);
echo "\n";
$doc->loadXML('<root/>');
$attr = $doc->createAttributeNS('http://example.com', 'ex:foo');
echo get_class($attr), "\n";
?>
--EXPECT--
false
DOMAttr
