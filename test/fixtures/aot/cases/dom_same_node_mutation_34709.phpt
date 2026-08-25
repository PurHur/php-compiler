--TEST--
AOT: replaceChild($n,$n) keeps links; insertBefore($n,$n) Error (#34709)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$r = $d->documentElement;
$a = $r->firstChild;
$b = $a->nextSibling;
$ret = $r->replaceChild($a, $a);
echo 'xml=', $d->saveXML($r), "\n";
echo 'len=', $r->childNodes->length, "\n";
echo 'ret_same=', ($ret === $a) ? 'yes' : 'no', "\n";
echo 'a_parent_is_r=', ($a->parentNode === $r) ? 'yes' : 'no', "\n";
echo 'a_next=', $a->nextSibling ? $a->nextSibling->tagName : 'null', "\n";
echo 'b_prev=', $b->previousSibling ? $b->previousSibling->tagName : 'null', "\n";
try {
    $r->insertBefore($b, $b);
    echo "insert_self=ok\n";
} catch (Throwable $e) {
    echo 'insert_self=', get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
xml=<r><a/><b/></r>
len=2
ret_same=yes
a_parent_is_r=yes
a_next=b
b_prev=a
insert_self=Error:Cannot add newnode as the previous sibling of refnode
