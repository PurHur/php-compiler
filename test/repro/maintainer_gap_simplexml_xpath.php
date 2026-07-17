<?php

declare(strict_types=1);

// Issue #19321 — SimpleXMLElement::xpath() absolute and relative queries (ext/simplexml/sxe.c).

$xml = simplexml_load_string('<root><a>1</a><a>2</a></root>');
foreach (['/root/a', 'a'] as $q) {
    $r = $xml->xpath($q);
    echo $q, ' count=', count($r);
    if (count($r) > 0) {
        echo ' first=', (string) $r[0];
    }
    echo "\n";
}
