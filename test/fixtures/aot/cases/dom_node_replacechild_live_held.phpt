--TEST--
AOT: replaceChild refreshes held live childNodes (#32784)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$el->replaceChild($doc->createElement('n'), $list->item(1));
echo 'held_len=', $list->length, "\n";
echo 'held_item1=', $list->item(1)->nodeName, "\n";
echo 'held_item2=', $list->item(2)->nodeName, "\n";
echo 'refetch_item1=', $el->childNodes->item(1)->nodeName, "\n";
?>
--EXPECT--
held_len=3
held_item1=n
held_item2=c
refetch_item1=n
