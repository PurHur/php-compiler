<?php

declare(strict_types=1);

// #33532 — getAttributeNS/hasAttributeNS(null, …) must see setAttribute Attr under AOT.
$d = new DOMDocument();
$e = $d->createElement('e');
$e->setAttribute('k', 'v');
echo 'getNS='.$e->getAttributeNS(null, 'k');
echo ' hasNS='.($e->hasAttributeNS(null, 'k') ? '1' : '0');
echo ' get='.$e->getAttribute('k');
echo "\n";
