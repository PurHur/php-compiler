--TEST--
DOMElement textContent write invalidates held sibling handles (#23817, ext/dom/node.c)
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
try {
    $b->parentNode;
    echo "b_parent=null\n";
} catch (Error $e) {
    echo 'b_parent_err=', $e->getMessage(), "\n";
}
try {
    $b->previousSibling;
    echo "b_prev=null\n";
} catch (Error $e) {
    echo 'b_prev_err=', $e->getMessage(), "\n";
}
echo 'xml=', trim($d->saveXML($d->documentElement)), "\n";
?>
--EXPECT--
text=z
len=1
a_parent=null
b_parent_err=Couldn't fetch DOMElement. Node no longer exists
b_prev_err=Couldn't fetch DOMElement. Node no longer exists
xml=<r>z</r>
