<?php
/** Repro #32908 — AOT: ternary DOMNodeList::item()->nodeName string-concat empties. */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$list = $doc->documentElement->childNodes;
echo ($list->item(1) ? $list->item(1)->nodeName : 'null') . '|' . ($list->item(2) ? $list->item(2)->nodeName : 'null');
echo "\n";
echo ($list->item(1) ? $list->item(1)->nodeName : 'null'), '|', ($list->item(2) ? $list->item(2)->nodeName : 'null');
echo "\n";
