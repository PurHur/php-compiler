--TEST--
AOT: DOMXPath::query() before childNodes->item() must not corrupt item() result (#32620)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a>hello</a><b>world</b></root>');
$xpath = new DOMXPath($doc);
$xresult = $xpath->query('//a');
echo "xpath-len:" . $xresult->length . "\n";
echo "xpath-item0:" . $xresult->item(0)->nodeName . "\n";

$list = $doc->documentElement->childNodes;
echo "childNodes-len:" . $list->length . "\n";
echo "item0:" . $list->item(0)->nodeName . "\n";
echo "item1:" . $list->item(1)->nodeName . "\n";
--EXPECT--
xpath-len:1
xpath-item0:a
childNodes-len:2
item0:a
item1:b
