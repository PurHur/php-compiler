<?php

declare(strict_types=1);

// #33532 — getAttributeNS/hasAttributeNS(null, …) must match Zend after setAttribute.
// Null NS args can carry a stale compileTimeString; prefer isNullConstant (peer #33528).
$d = new DOMDocument();
$d->loadXML('<e/>');
$e = $d->documentElement;
$e->setAttribute('k', 'v');
echo 'get=[', $e->getAttributeNS(null, 'k'), ']';
echo ' has=[', $e->hasAttributeNS(null, 'k') ? 'yes' : 'no', ']';
echo ' plain=[', $e->getAttribute('k'), ']';
echo "\n";

// After setAttributeNS(null, …) — same open-tag bag as #33526.
$d2 = new DOMDocument();
$d2->loadXML('<r/>');
$e2 = $d2->createElement('e');
$e2->setAttributeNS(null, 'a', 'b');
$d2->documentElement->appendChild($e2);
$child = $d2->documentElement->firstChild;
echo 'nsget=[', $child->getAttributeNS(null, 'a'), ']';
echo ' nshas=[', $child->hasAttributeNS(null, 'a') ? 'yes' : 'no', ']';
echo "\n";
