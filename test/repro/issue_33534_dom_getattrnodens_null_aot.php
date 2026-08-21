<?php

declare(strict_types=1);

// #33534 — getAttributeNodeNS(null, …) must see setAttribute Attr under AOT.
$d = new DOMDocument();
$e = $d->createElement('e');
$e->setAttribute('k', 'v');
$n = $e->getAttributeNodeNS(null, 'k');
if ($n === null) {
    echo "null\n";
} else {
    echo 'class=', get_class($n), "\n";
    echo 'value=', $n->value, "\n";
}
