<?php
/** Repro #19445 — DOMElement::appendChild/insertBefore(DOMAttr) installs attribute. */
$d = new DOMDocument();
$e = $d->createElement('el');
$d->appendChild($e);
$a = $d->createAttribute('foo');
$a->value = 'bar';
try {
    $e->appendChild($a);
    echo 'ok:', $e->getAttribute('foo');
} catch (Throwable $ex) {
    echo get_class($ex), ':', $ex->getMessage();
}
echo "\n";
