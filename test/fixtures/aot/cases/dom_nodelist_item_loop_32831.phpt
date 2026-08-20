--TEST--
AOT: DOMNodeList::item($i) loop-carried index walks siblings (#32831)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$list = $doc->documentElement->childNodes;
for ($i = 0; $i < 3; $i++) {
    $n = $list->item($i);
    echo 'held', $i, '=', ($n ? $n->nodeName : 'null'), "\n";
}
--EXPECT--
held0=a
held1=b
held2=c
