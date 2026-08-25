<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$r = $d->documentElement;
$a = $r->firstChild;
$b = $a->nextSibling;
$old = $r->replaceChild($a, $a);
echo 'rc_parent=', ($a->parentNode === $r ? '1' : '0'), "\n";
echo 'rc_old_same=', ($old === $a ? '1' : '0'), "\n";
echo 'rc_first=', $r->firstChild->nodeName, "\n";
try {
    $r->insertBefore($b, $b);
    echo "ib_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo 'ib_parent=', ($b->parentNode === $r ? '1' : '0'), "\n";
echo 'ib_len=', $r->childNodes->length, "\n";
