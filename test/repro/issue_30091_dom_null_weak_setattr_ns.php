<?php

// Weak path: null coerces / nullable ns — must match Zend (#30091).
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r id="a"><c/></r>');
$el = $d->documentElement;

try {
    $el->setIdAttribute(null, true);
    echo "setIdAttribute=fail:no_throw\n";
    exit(1);
} catch (DOMException $e) {
    echo "setIdAttribute=ok:DOMException\n";
}

try {
    $el->setAttributeNS(null, null, 'v');
    echo "setAttributeNS=fail:no_throw\n";
    exit(1);
} catch (ValueError $e) {
    echo "setAttributeNS=ok:ValueError\n";
}

echo 'hasAttributeNS=', $el->hasAttributeNS(null, null) ? 'true' : 'false', "\n";
echo 'getElementsByTagNameNS=', $d->getElementsByTagNameNS(null, 'c')->length, "\n";
echo "ok\n";
