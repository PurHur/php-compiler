--TEST--
AOT: ChildNode::replaceWith refreshes held childNodes (#32822)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$b = $list->item(1);
$z = $doc->createElement('z');
$b->replaceWith($z);
echo 'held_len=', $list->length, "\n";
echo 'held0=', $list->item(0)->nodeName, "\n";
echo 'held1=', $list->item(1)->nodeName, "\n";
echo 'held2=', $list->item(2)->nodeName, "\n";
echo 'refetch1=', $el->childNodes->item(1)->nodeName, "\n";
--EXPECT--
held_len=3
held0=a
held1=z
held2=c
refetch1=z
--EXPECT_EXIT--
0
