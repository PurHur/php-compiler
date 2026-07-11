<?php

declare(strict_types=1);

$f = fopen('php://memory', 'r+');
fwrite($f, "a,b\n");
rewind($f);

try {
    fgetcsv($f, separator: null);
    echo "fail: null separator parsed\n";
    exit(1);
} catch (ValueError $e) {
    if ('fgetcsv(): Argument #3 ($separator) must be a single character' !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'separator')) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
}

fclose($f);
echo "ok\n";
