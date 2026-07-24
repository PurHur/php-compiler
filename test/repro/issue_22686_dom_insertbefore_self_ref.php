<?php
/** Repro #22686 — insertBefore($n, $n) must throw Error, not DOMException NOT_FOUND. */
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$a = $d->documentElement->firstChild;
try {
    $d->documentElement->insertBefore($a, $a);
    echo "ok\n";
    exit(1);
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
    if (!($e instanceof Error) || $e instanceof DOMException) {
        exit(1);
    }
    if ($e->getMessage() !== 'Cannot add newnode as the previous sibling of refnode') {
        exit(1);
    }
}
if ($a->parentNode !== $d->documentElement || $d->documentElement->childNodes->length !== 2) {
    fwrite(STDERR, "tree mutated after rejected insertBefore\n");
    exit(1);
}
echo "PASS\n";
