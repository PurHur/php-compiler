--TEST--
AOT: ternary DOMNodeList::item()->nodeName survives string concat (#32908)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$list = $d->documentElement->childNodes;
echo ($list->item(1) ? $list->item(1)->nodeName : 'null')
  . '|'
  . ($list->item(2) ? $list->item(2)->nodeName : 'null')
  . "\n";
echo ($list->item(1) ? $list->item(1)->nodeName : 'null'), '|', ($list->item(2) ? $list->item(2)->nodeName : 'null'), "\n";
--EXPECT--
b|c
b|c
