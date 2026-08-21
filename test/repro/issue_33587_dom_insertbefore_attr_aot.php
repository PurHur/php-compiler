<?php
declare(strict_types=1);
/** #33587 — insertBefore(Attr) must throw Error, not SIGSEGV. */
$d = new DOMDocument();
$e = $d->createElement('r');
$t = $d->createTextNode('t');
$e->appendChild($t);
$a = $d->createAttribute('id');
$a->value = 'x';
try {
    $e->insertBefore($a, $t);
    echo "ok\n";
} catch (Throwable $ex) {
    echo get_class($ex), ':', $ex->getMessage(), "\n";
}
