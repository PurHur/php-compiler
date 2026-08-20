<?php
// AOT: string-concat of ternary item()->nodeName yields empty (echo-args OK).
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$list = $d->documentElement->childNodes;
echo 'concat=', ($list->item(1) ? $list->item(1)->nodeName : 'null')
  . '|' . ($list->item(2) ? $list->item(2)->nodeName : 'null'), "\n";
echo 'args=', ($list->item(1) ? $list->item(1)->nodeName : 'null'), '|', ($list->item(2) ? $list->item(2)->nodeName : 'null'), "\n";
