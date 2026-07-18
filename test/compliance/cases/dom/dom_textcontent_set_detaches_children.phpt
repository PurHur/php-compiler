--TEST--
DOMNode textContent/nodeValue set detaches held element children (#20646, ext/dom/node.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a>x</a><b>y</b></r>');
$a = $d->documentElement->firstChild;
$b = $a->nextSibling;
$d->documentElement->textContent = 'z';
echo 'text=', $d->documentElement->textContent, "\n";
echo 'len=', $d->documentElement->childNodes->length, "\n";
echo 'a_parent=', ($a->parentNode === null ? 'null' : 'obj'), "\n";
echo 'b_parent=', ($b->parentNode === null ? 'null' : 'obj'), "\n";
echo 'a_next=', ($a->nextSibling === null ? 'null' : 'obj'), "\n";
echo 'b_prev=', ($b->previousSibling === null ? 'null' : 'obj'), "\n";
echo 'xml=', trim($d->saveXML($d->documentElement)), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><c>old</c></r>');
$c = $d2->documentElement->firstChild;
$d2->documentElement->nodeValue = 'nv';
echo 'nv_parent=', ($c->parentNode === null ? 'null' : 'obj'), "\n";
echo 'nv_text=', $d2->documentElement->textContent, "\n";
?>
--EXPECT--
text=z
len=1
a_parent=null
b_parent=null
a_next=null
b_prev=null
xml=<r>z</r>
nv_parent=null
nv_text=nv
