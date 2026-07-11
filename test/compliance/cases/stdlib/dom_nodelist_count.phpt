--TEST--
stdlib DOMNodeList::count() Countable parity (#14517, ext/dom/nodelist.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$list = $doc->documentElement->childNodes;
echo method_exists($list, 'count') ? 'yes' : 'no', "\n";
echo $list->count(), ' ', $list->length, "\n";
echo count($list), "\n";
$tags = $doc->getElementsByTagName('a');
echo $tags->count(), ' ', $tags->length, "\n";
?>
--EXPECT--
yes
2 2
2
1 1
