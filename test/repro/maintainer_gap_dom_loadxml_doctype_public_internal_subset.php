<?php

declare(strict_types=1);

// #20504 — PUBLIC + internal subset must load and expose DOMDocumentType (php-src-strict).
$d = new DOMDocument();
$xml = '<!DOCTYPE r PUBLIC "pub" "sys" [<!ELEMENT r EMPTY>]><r/>';
if (!$d->loadXML($xml)) {
    echo "loadXML failed\n";
    exit(1);
}
if (null === $d->doctype) {
    echo "doctype null\n";
    exit(1);
}
if ($d->doctype->name !== 'r') {
    echo "name={$d->doctype->name}\n";
    exit(1);
}
if ($d->doctype->publicId !== 'pub') {
    echo "publicId={$d->doctype->publicId}\n";
    exit(1);
}
if ($d->doctype->systemId !== 'sys') {
    echo "systemId={$d->doctype->systemId}\n";
    exit(1);
}

// SYSTEM + internal subset (same parse path)
$d2 = new DOMDocument();
$xml2 = '<!DOCTYPE r SYSTEM "sys" [<!ELEMENT r EMPTY>]><r/>';
if (!$d2->loadXML($xml2) || null === $d2->doctype || $d2->doctype->systemId !== 'sys') {
    echo "SYSTEM+subset failed\n";
    exit(1);
}

echo "ok\n";
