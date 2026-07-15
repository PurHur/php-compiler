--TEST--
DOMNode removeChild/replaceChild clear parent/sibling on detached node (#19240, ext/dom/node.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<root><a/><b/></root>');
$a = $d->documentElement->firstChild;
$d->documentElement->removeChild($a);
echo 'rm_parent=', ($a->parentNode === null ? 'null' : $a->parentNode->nodeName), "\n";
echo 'rm_next=', ($a->nextSibling === null ? 'null' : $a->nextSibling->nodeName), "\n";
echo 'rm_prev=', ($a->previousSibling === null ? 'null' : $a->previousSibling->nodeName), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<root><old/></root>');
$old = $d2->documentElement->firstChild;
$d2->documentElement->replaceChild($d2->createElement('new'), $old);
echo 'rep_parent=', ($old->parentNode === null ? 'null' : $old->parentNode->nodeName), "\n";
echo 'rep_next=', ($old->nextSibling === null ? 'null' : $old->nextSibling->nodeName), "\n";
echo 'rep_prev=', ($old->previousSibling === null ? 'null' : $old->previousSibling->nodeName), "\n";
?>
--EXPECT--
rm_parent=null
rm_next=null
rm_prev=null
rep_parent=null
rep_next=null
rep_prev=null
