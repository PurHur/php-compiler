<?php

declare(strict_types=1);

$doc = new DOMDocument();
$ok = $doc->loadXML('<root><!--comment--><child/></root>');
if (!$ok) {
    fwrite(STDERR, "fail: loadXML returned false\n");
    exit(1);
}

$child = $doc->documentElement->firstChild;
if (!$child instanceof DOMComment) {
    fwrite(STDERR, 'fail: first child class '.($child ? $child::class : 'null')."\n");
    exit(1);
}
if ('comment' !== $child->data) {
    fwrite(STDERR, "fail: comment data mismatch got {$child->data}\n");
    exit(1);
}

$roundTrip = $doc->saveXML($child);
if ('<!--comment-->' !== $roundTrip) {
    fwrite(STDERR, "fail: saveXML round-trip got {$roundTrip}\n");
    exit(1);
}

echo "ok\n";
