<?php
$d = new DOMDocument();
$d->preserveWhiteSpace = false;
$d->loadXML("<r>\n  <a/>\n  <b>x</b>\n</r>");
echo "pws_len=" . $d->documentElement->childNodes->length . "\n";
for ($i = 0; $i < $d->documentElement->childNodes->length; $i++) {
    $n = $d->documentElement->childNodes->item($i);
    echo "pws_$i=" . $n->nodeName . "\n";
}

$d2 = new DOMDocument();
$d2->loadXML("<r>\n  <a/>\n</r>", LIBXML_NOBLANKS);
echo "noblanks_len=" . $d2->documentElement->childNodes->length . "\n";

$d3 = new DOMDocument();
$d3->preserveWhiteSpace = false;
$d3->loadXML("<r>  <a/>x  <b/>  </r>");
echo "mixed_len=" . $d3->documentElement->childNodes->length . "\n";
for ($i = 0; $i < $d3->documentElement->childNodes->length; $i++) {
    $n = $d3->documentElement->childNodes->item($i);
    echo "mixed_$i type=" . $n->nodeType . " val=" . var_export($n->nodeValue, true) . "\n";
}
