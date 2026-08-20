<?php
// #32908 — ternary item()->nodeName in string concat must match echo-args form.
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$list = $d->documentElement->childNodes;
echo ($list->item(1) ? $list->item(1)->nodeName : 'null')
  . '|'
  . ($list->item(2) ? $list->item(2)->nodeName : 'null')
  . "\n";
echo ($list->item(1) ? $list->item(1)->nodeName : 'null'), '|', ($list->item(2) ? $list->item(2)->nodeName : 'null'), "\n";
