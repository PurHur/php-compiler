--TEST--
language DOM collection length before nullsafe in array literal survives dead-temp release (#28555)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r x="1" y="2"><a/></r>');
$attrs = $doc->documentElement->attributes;
$list = $doc->getElementsByTagName('a');
$cn = $doc->documentElement->childNodes;

echo json_encode([$attrs->length, $attrs->getNamedItem('x')?->nodeValue]), "\n";
echo json_encode([$list->length, $list->item(0)?->nodeName]), "\n";
echo json_encode([$cn->length, $cn->item(0)?->nodeName]), "\n";
?>
--EXPECT--
[2,"1"]
[1,"a"]
[1,"a"]
