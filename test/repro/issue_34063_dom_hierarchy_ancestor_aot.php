<?php
/** #34063 — AOT appendChild/insertBefore(self|ancestor) must throw Hierarchy Request Error. */
error_reporting(E_ALL);

$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$r = $d->documentElement;
$a = $r->firstChild;

try {
    $r->appendChild($r);
    echo "self_append=no-throw\n";
} catch (Throwable $e) {
    echo 'self_append=', $e->getMessage(), ' code=', $e->getCode(), "\n";
}

try {
    $a->appendChild($r);
    echo "anc_append=no-throw\n";
} catch (Throwable $e) {
    echo 'anc_append=', $e->getMessage(), ' code=', $e->getCode(), "\n";
}

try {
    $r->insertBefore($r, $r->firstChild);
    echo "self_ib=no-throw\n";
} catch (Throwable $e) {
    echo 'self_ib=', $e->getMessage(), ' code=', $e->getCode(), "\n";
}

try {
    $a->insertBefore($r, null);
    echo "anc_ib=no-throw\n";
} catch (Throwable $e) {
    echo 'anc_ib=', $e->getMessage(), ' code=', $e->getCode(), "\n";
}

echo "ok\n";
