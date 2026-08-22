<?php
// #33737 — adoptNode(null) TypeError before 8.2 NYI / helper (ext/dom/document.c)
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r id="x"/>');
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
try {
    $d->adoptNode($d->documentElement);
    echo "real=ok\n";
} catch (Throwable $ex) {
    echo 'real=', get_class($ex), ':', $ex->getMessage(), "\n";
}
