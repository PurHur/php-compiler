--TEST--
AOT: DOMNode::insertBefore updates held live childNodes (#32801)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$n = $doc->createElement('n');
$el->insertBefore($n, $list->item(1));
echo 'held_len=', $list->length, "\n";
echo 'held0=', ($list->item(0) ? $list->item(0)->nodeName : 'null'), "\n";
echo 'held1=', ($list->item(1) ? $list->item(1)->nodeName : 'null'), "\n";
echo 'held2=', ($list->item(2) ? $list->item(2)->nodeName : 'null'), "\n";
echo 'held3=', ($list->item(3) ? $list->item(3)->nodeName : 'null'), "\n";
echo 'refetch_len=', $el->childNodes->length, "\n";
echo 'refetch1=', $el->childNodes->item(1)->nodeName, "\n";
--EXPECT--
held_len=4
held0=a
held1=n
held2=b
held3=c
refetch_len=4
refetch1=n
