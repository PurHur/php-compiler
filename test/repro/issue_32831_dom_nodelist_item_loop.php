<?php
declare(strict_types=1);
/**
 * #32831 — AOT DOMNodeList::item($i) must walk siblings when $i is loop-carried.
 * Stale compileTimeLong=0 on for/while locals folded every call to item(0).
 * php-src: ext/dom/nodelist.c php_dom_nodelist_item
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$list = $doc->documentElement->childNodes;
for ($i = 0; $i < 3; $i++) {
    $n = $list->item($i);
    echo "held{$i}=", ($n ? $n->nodeName : 'null'), "\n";
}
$j = 0;
while ($j < 3) {
    $n = $list->item($j);
    echo "while{$j}=", ($n ? $n->nodeName : 'null'), "\n";
    $j++;
}
echo 'lit0=', $list->item(0)->nodeName, "\n";
echo 'lit1=', $list->item(1)->nodeName, "\n";
echo 'lit2=', $list->item(2)->nodeName, "\n";
