<?php

declare(strict_types=1);

// #33534 — null-NS setAttributeNS must be visible via getAttributeNodeNS under AOT.
$d = new DOMDocument();
$e = $d->createElement('e');
$e->setAttributeNS(null, 'k', 'v');
$n = $e->getAttributeNodeNS(null, 'k');
if ($n === null) {
    echo "null\n";
} else {
    echo 'class=', get_class($n), "\n";
    echo 'value=', $n->value, "\n";
}
