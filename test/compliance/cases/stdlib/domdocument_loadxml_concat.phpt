--TEST--
stdlib DOMDocument::loadXML() chained concat inline call arg (#14562, ext/dom/document.c)
--FILE--
<?php
$doc = new DOMDocument();
$ok = $doc->loadXML(
    '<?xml version="1.0"?>'
    . '<root xmlns:ex="http://example.com/ns">'
    . '<ex:child/><other/><ex:item/></root>'
);
$list = $doc->getElementsByTagName('child');
echo (int) $ok, ':', $list->length, ':', $list->item(0)->localName, "\n";
--EXPECT--
1:1:child
