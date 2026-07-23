--TEST--
DOMNode textContent='' leaves empty text child (live childNodes length 1) (#22657, ext/dom/node.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><span>1</span></r>');
$r = $d->documentElement;
$ch = $r->childNodes;
$r->textContent = '';
echo 'len=', $ch->length;
if ($ch->length) {
    echo ' type=', $ch->item(0)->nodeType, ' value=', var_export($ch->item(0)->nodeValue, true);
}
echo "\n";
$r->textContent = 'hi';
echo 'hi_len=', $ch->length, ' value=', var_export($ch->item(0)->nodeValue, true), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><c>old</c></r>');
$ch2 = $d2->documentElement->childNodes;
$d2->documentElement->nodeValue = '';
echo 'nv_len=', $ch2->length;
if ($ch2->length) {
    echo ' type=', $ch2->item(0)->nodeType, ' value=', var_export($ch2->item(0)->nodeValue, true);
}
echo "\n";
?>
--EXPECT--
len=1 type=3 value=''
hi_len=1 value='hi'
nv_len=1 type=3 value=''
