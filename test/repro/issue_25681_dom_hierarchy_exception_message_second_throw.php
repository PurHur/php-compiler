<?php
/**
 * Second bridged DOMException must keep message/code after catch echo of get_class+getMessage+getCode
 * while the first tree stays live (compliance dom_node_appendchild_ancestor_hierarchy).
 */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$a = $r->appendChild($d->createElement('a'));
try {
    $a->appendChild($r);
} catch (Throwable $e) {
    echo '1 ', get_class($e), ': ', $e->getMessage(), ' code=', $e->getCode(), "\n";
}

$d2 = new DOMDocument();
$r2 = $d2->appendChild($d2->createElement('r'));
$a2 = $r2->appendChild($d2->createElement('a'));
$b2 = $a2->appendChild($d2->createElement('b'));
$x2 = $b2->appendChild($d2->createElement('x'));
try {
    $b2->insertBefore($r2, $x2);
} catch (Throwable $e) {
    echo '2 ', get_class($e), ': ', $e->getMessage(), ' code=', $e->getCode(), "\n";
}
