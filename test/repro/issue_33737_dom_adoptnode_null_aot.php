<?php
/** Repro #33737: AOT adoptNode(null) must TypeError before profile NYI. */
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r><a id="x"/></r>');
$n = null;
try {
    $d->adoptNode($n);
    echo "var=fail\n";
} catch (Throwable $ex) {
    echo 'var=', get_class($ex), ':', $ex->getMessage(), "\n";
}
$miss = $d->getElementById('nope');
try {
    $d->adoptNode($miss);
    echo "id=fail\n";
} catch (Throwable $ex) {
    echo 'id=', get_class($ex), ':', $ex->getMessage(), "\n";
}
