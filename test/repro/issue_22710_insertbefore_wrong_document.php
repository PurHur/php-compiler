<?php
// #22710 — insertBefore foreign node must be Wrong Document Error (code 4)
// Chained PropertyFetch args must not collapse onto one ARG_SEND temp.
$d1 = new DOMDocument();
$d1->loadXML('<r><a/></r>');
$d2 = new DOMDocument();
$d2->loadXML('<r><b/></r>');

function peek($x, $y) {
    echo 'peek=', $x->nodeName, ',', $y->nodeName, ',same=', ($x === $y ? '1' : '0'), "\n";
}
peek($d2->documentElement->firstChild, $d1->documentElement->firstChild);

try {
    $d1->documentElement->insertBefore($d2->documentElement->firstChild, $d1->documentElement->firstChild);
    echo "NO_THROW\n";
} catch (DOMException $e) {
    echo 'code=', $e->getCode(), ' msg=', $e->getMessage(), "\n";
} catch (Throwable $t) {
    echo get_class($t), ': ', $t->getMessage(), "\n";
}
