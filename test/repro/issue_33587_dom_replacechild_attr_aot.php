<?php
declare(strict_types=1);
/** #33587 — replaceChild(Attr) must throw Hierarchy Request, not SIGSEGV. */
$d = new DOMDocument();
$e = $d->createElement('r');
$d->appendChild($e);
$t = $d->createTextNode('t');
$e->appendChild($t);
$a = $d->createAttribute('id');
$a->value = 'x';
try {
    $e->replaceChild($a, $t);
    echo "ok\n";
} catch (Throwable $ex) {
    // Thin-AOT get_class() on DOMException may be empty; message is the Zend parity signal.
    echo $ex->getMessage(), "\n";
}
