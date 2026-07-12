<?php

declare(strict_types=1);

$doc = new DOMDocument();
$length = $doc->childNodes->length;
if (0 !== $length) {
    echo "FAIL: expected childNodes length 0, got {$length}\n";
    exit(1);
}
echo "OK\n";
