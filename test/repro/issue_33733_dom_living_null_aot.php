<?php
/** Repro #33733: AOT isEqualNode / compareDocumentPosition must not SIGSEGV on variable null. */
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$e = $d->documentElement;
$n = null;
echo 'eq_self=', (int) $e->isEqualNode($e), "\n";
echo 'eq_var=', (int) $e->isEqualNode($n), "\n";
$miss = $d->getElementById('nope');
echo 'eq_miss=', (int) $e->isEqualNode($miss), "\n";
try {
    $e->compareDocumentPosition($n);
    echo "cdp_var=fail\n";
} catch (Throwable $ex) {
    echo 'cdp_var=', get_class($ex), "\n";
}
try {
    $e->compareDocumentPosition($miss);
    echo "cdp_miss=fail\n";
} catch (Throwable $ex) {
    echo 'cdp_miss=', get_class($ex), "\n";
}
