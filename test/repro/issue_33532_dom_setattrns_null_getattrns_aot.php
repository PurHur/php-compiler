<?php

declare(strict_types=1);

// #33532 — null-NS setAttributeNS must be visible via getAttributeNS under AOT.
$d = new DOMDocument();
$e = $d->createElement('e');
$e->setAttributeNS(null, 'k', 'v');
echo 'getNS='.$e->getAttributeNS(null, 'k');
echo ' hasNS='.($e->hasAttributeNS(null, 'k') ? '1' : '0');
echo "\n";
