<?php
declare(strict_types=1);

// Issue #11147 — array_pad() array:/length:/value: named parameters (ext/standard/array.stub.php).

$r = array_pad(array: [1], length: 3, value: 0);
if ($r !== [1, 0, 0]) {
    echo "FAIL full named\n";
    exit(1);
}

$m = array_pad([1], length: 3, value: 0);
if ($m !== [1, 0, 0]) {
    echo "FAIL mixed named\n";
    exit(1);
}

echo "OK\n";
