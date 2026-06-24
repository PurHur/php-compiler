<?php
declare(strict_types=1);

// Issue #11145 — array_slice() array:/offset:/length: named parameters (ext/standard/array.stub.php).

$r = array_slice(array: [1, 2, 3, 4], offset: 1, length: 2);
if ($r !== [2, 3]) {
    echo "FAIL full named\n";
    exit(1);
}

$p = array_slice(array: [1, 2, 3], offset: 1, preserve_keys: true);
if ($p !== [1 => 2, 2 => 3]) {
    echo "FAIL preserve_keys named\n";
    var_export($p);
    exit(1);
}

echo "OK\n";
