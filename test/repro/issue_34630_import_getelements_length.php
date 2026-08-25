<?php
// #34630: getElementsByTagName on importNode destination must not steal source loadXML count.
$a = new DOMDocument();
$a->loadXML('<r><n><c>t</c></n></r>');
$b = new DOMDocument();
$b->appendChild($b->createElement('root'));
$imp = $b->importNode($a->documentElement->firstChild, true);
echo 'before=', $b->getElementsByTagName('c')->length, "\n";
$b->documentElement->appendChild($imp);
$list = $b->getElementsByTagName('c');
echo 'after=', $list->length, "\n";
$i0 = $list->item(0);
if ($i0 === null) {
    echo "item0=null\n";
} else {
    echo 'item0=', $i0->nodeName, "\n";
}
$i1 = $list->item(1);
if ($i1 === null) {
    echo "item1=null\n";
} else {
    echo 'item1=', $i1->nodeName, "\n";
}
echo 'xml=', $b->saveXML($b->documentElement), "\n";
