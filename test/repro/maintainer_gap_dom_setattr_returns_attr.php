<?php

declare(strict_types=1);

// DOMElement::setAttribute() must return the Attr (php-src element.c DOM_RET_OBJ).
// AOT-safe: no fwrite/instanceof/get_class/ownerElement/setAttributeNS (#24538).
$d = new DOMDocument();
$d->loadXML('<r/>');
$el = $d->documentElement;

$a = $el->setAttribute('foo', 'bar');
if (!is_object($a)) {
    echo 'fail: expected object Attr, got ', gettype($a), "\n";
    exit(1);
}
if ($a->name !== 'foo' || $a->value !== 'bar') {
    echo "fail: attr name/value mismatch\n";
    exit(1);
}

$b = $el->setAttribute('foo', 'baz');
if ($b !== $a) {
    echo "fail: rewrite should return same Attr instance\n";
    exit(1);
}
if ($b->value !== 'baz') {
    echo "fail: value not updated\n";
    exit(1);
}
if ($el->getAttribute('foo') !== 'baz') {
    echo "fail: element attribute map not updated\n";
    exit(1);
}

$x = $el->setAttribute('xmlns', 'urn:x');
if ($x !== true) {
    echo 'fail: xmlns setAttribute should return true, got ', gettype($x), "\n";
    exit(1);
}

echo "ok\n";
