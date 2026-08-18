<?php

declare(strict_types=1);

// Issue #24666 — zip_open named $filename + zip_* stub names (php-src ext/zip/php_zip.stub.php).
// Zip is withheld unless host ext/zip or PHP_COMPILER_ENABLE_ZIP=1 (#25010).

if (!function_exists('zip_open')) {
    fwrite(STDERR, "fail: zip_open missing (set PHP_COMPILER_ENABLE_ZIP=1)\n");
    exit(1);
}

$n = [];
foreach ((new ReflectionFunction('zip_open'))->getParameters() as $p) {
    $n[] = $p->getName();
}
echo 'params=', implode(',', $n), "\n";
echo 'pos=', var_export(@zip_open('/no/such.zip'), true), "\n";
echo 'named=', var_export(@zip_open(filename: '/no/such.zip'), true), "\n";

$c = [];
foreach ((new ReflectionFunction('zip_entry_close'))->getParameters() as $p) {
    $c[] = $p->getName();
}
echo 'close_params=', implode(',', $c), "\n";
try {
    zip_entry_close(zip_entry: false);
} catch (Throwable $e) {
    echo 'zip_entry=', $e->getMessage(), "\n";
}
try {
    zip_entry_close(zip_ent: false);
} catch (Throwable $e) {
    echo 'zip_ent=', $e->getMessage(), "\n";
}
