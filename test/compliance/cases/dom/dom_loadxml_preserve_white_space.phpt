--TEST--
dom DOMDocument::$preserveWhiteSpace=false and LIBXML_NOBLANKS strip blank text on loadXML (#20476, ext/dom/document.c)
--FILE--
<?php
$d = new DOMDocument();
$d->preserveWhiteSpace = false;
$d->loadXML("<r>\n  <a/>\n  <b>x</b>\n</r>");
echo 'pws_len=', $d->documentElement->childNodes->length, "\n";
for ($i = 0; $i < $d->documentElement->childNodes->length; $i++) {
    echo 'pws_', $i, '=', $d->documentElement->childNodes->item($i)->nodeName, "\n";
}

$d2 = new DOMDocument();
$d2->loadXML("<r>\n  <a/>\n</r>", LIBXML_NOBLANKS);
echo 'noblanks_len=', $d2->documentElement->childNodes->length, "\n";

$d3 = new DOMDocument();
$d3->preserveWhiteSpace = false;
$d3->loadXML('<r>  <a/>x  <b/>  </r>');
echo 'mixed_len=', $d3->documentElement->childNodes->length, "\n";
for ($i = 0; $i < $d3->documentElement->childNodes->length; $i++) {
    $n = $d3->documentElement->childNodes->item($i);
    echo 'mixed_', $i, ' type=', $n->nodeType, ' val=', var_export($n->nodeValue, true), "\n";
}

// Default preserveWhiteSpace=true keeps blanks.
$d4 = new DOMDocument();
$d4->loadXML("<r>\n  <a/>\n</r>");
echo 'default_len=', $d4->documentElement->childNodes->length, "\n";
--EXPECT--
pws_len=2
pws_0=a
pws_1=b
noblanks_len=1
mixed_len=3
mixed_0 type=1 val=''
mixed_1 type=3 val='x  '
mixed_2 type=1 val=''
default_len=3
