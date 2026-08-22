<?php
/** Repro #33737: AOT adoptNode variable-null must TypeError before NYI. */
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r><a id="x"/></r>');
$n = null;
try {
    $d->adoptNode($n);
    echo "adopt_var=fail\n";
} catch (Throwable $ex) {
    echo 'adopt_var=', get_class($ex), "\n";
}
$miss = $d->getElementById('nope');
try {
    $d->adoptNode($miss);
    echo "adopt_miss=fail\n";
} catch (Throwable $ex) {
    echo 'adopt_miss=', get_class($ex), "\n";
}
$b = new DOMDocument();
try {
    $b->adoptNode($d->documentElement->firstChild);
    echo "adopt_real=fail\n";
} catch (Throwable $ex) {
    echo 'adopt_real=', get_class($ex), ':', $ex->getMessage(), "\n";
}
