<?php
/** Repro #33716: AOT variable-null DOM mutation must TypeError, not SIGSEGV. */
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$e = $d->documentElement;
$n = null;
try {
    $e->appendChild($n);
    echo "appendChild=fail\n";
} catch (Throwable $ex) {
    echo 'appendChild=', get_class($ex), "\n";
}
try {
    $e->removeChild($n);
    echo "removeChild=fail\n";
} catch (Throwable $ex) {
    echo 'removeChild=', get_class($ex), "\n";
}
$miss = $d->getElementById('nope');
try {
    $e->appendChild($miss);
    echo "id=fail\n";
} catch (Throwable $ex) {
    echo 'id=', get_class($ex), "\n";
}
