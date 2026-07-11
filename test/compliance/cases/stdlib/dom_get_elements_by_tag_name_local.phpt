--TEST--
stdlib DOMDocument getElementsByTagName — namespace-qualified local name (#14502, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML(
    '<?xml version="1.0"?>'
    .'<root xmlns:ex="http://example.com/ns"><ex:child/><ex:item/></root>'
);
$list = $doc->getElementsByTagName('child');
echo $list->length, "\n";
echo $list->item(0)->localName, "\n";
$item = $doc->getElementsByTagName('item')->item(0);
echo $item->tagName, "\n";
?>
--EXPECT--
1
child
ex:item
