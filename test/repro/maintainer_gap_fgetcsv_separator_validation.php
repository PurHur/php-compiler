<?php

declare(strict_types=1);

$f = fopen('php://memory', 'r+');
fwrite($f, "a,b\n");
rewind($f);

try {
    fgetcsv($f, separator: '');
    echo "fail: empty separator parsed\n";
    exit(1);
} catch (ValueError $e) {
    if ('fgetcsv(): Argument #3 ($separator) must be a single character' !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
}

rewind($f);
$row = fgetcsv($f, separator: ',');
if (!is_array($row) || ['a', 'b'] !== $row) {
    echo 'fail: valid separator ', var_export($row, true), "\n";
    exit(1);
}

fclose($f);
echo "ok\n";
