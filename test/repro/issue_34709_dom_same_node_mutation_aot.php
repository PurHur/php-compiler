<?php
/** Repro #34709 — AOT replaceChild($n,$n) must keep links; insertBefore($n,$n) Error. */
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
