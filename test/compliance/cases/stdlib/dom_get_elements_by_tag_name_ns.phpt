--TEST--
stdlib DOMDocument::getElementsByTagNameNS() — namespace lookup (#14454)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<?xml version="1.0"?><root xmlns:ex="http://example.com"><ex:child/><ex:child/></root>');
$list = $doc->getElementsByTagNameNS('http://example.com', 'child');
echo $list->length, "\n";
?>
--EXPECT--
2
