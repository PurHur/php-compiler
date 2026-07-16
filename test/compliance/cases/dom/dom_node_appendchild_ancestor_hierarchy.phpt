--TEST--
DOMNode appendChild/insertBefore/replaceChild ancestor — Hierarchy Request Error (#19753)
--FILE--
<?php
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$a = $r->appendChild($d->createElement('a'));
try {
    $a->appendChild($r);
    echo "appendChild unexpected_ok\n";
} catch (Throwable $e) {
    echo 'appendChild ', get_class($e), ': ', $e->getMessage(), ' code=', $e->getCode(), "\n";
}

$d2 = new DOMDocument();
$r2 = $d2->appendChild($d2->createElement('r'));
$a2 = $r2->appendChild($d2->createElement('a'));
$b2 = $a2->appendChild($d2->createElement('b'));
$x2 = $b2->appendChild($d2->createElement('x'));
try {
    $b2->insertBefore($r2, $x2);
    echo "insertBefore unexpected_ok\n";
} catch (Throwable $e) {
    echo 'insertBefore ', get_class($e), ': ', $e->getMessage(), ' code=', $e->getCode(), "\n";
}

$d3 = new DOMDocument();
$r3 = $d3->appendChild($d3->createElement('r'));
$a3 = $r3->appendChild($d3->createElement('a'));
$b3 = $a3->appendChild($d3->createElement('b'));
$x3 = $b3->appendChild($d3->createElement('x'));
try {
    $b3->replaceChild($r3, $x3);
    echo "replaceChild unexpected_ok\n";
} catch (Throwable $e) {
    echo 'replaceChild ', get_class($e), ': ', $e->getMessage(), ' code=', $e->getCode(), "\n";
}

try {
    $a->appendChild($a);
    echo "self appendChild unexpected_ok\n";
} catch (Throwable $e) {
    echo 'self ', get_class($e), ': ', $e->getMessage(), ' code=', $e->getCode(), "\n";
}
--EXPECT--
appendChild DOMException: Hierarchy Request Error code=3
insertBefore DOMException: Hierarchy Request Error code=3
replaceChild DOMException: Hierarchy Request Error code=3
self DOMException: Hierarchy Request Error code=3
