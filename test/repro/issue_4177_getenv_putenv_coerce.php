<?php
/**
 * Repro for #4177 — getenv()/putenv() scalar→string coercion (php-src-strict).
 * Without declare(strict_types=1), Zend coerces int/bool names; with it, TypeError.
 */
putenv('4177KEY=hello');
putenv('1=one');

echo 'getenv int: ', var_export(getenv(1), true), "\n";
echo 'getenv str: ', var_export(getenv('4177KEY'), true), "\n";

try {
    putenv(4177001);
    echo "putenv int: ok\n";
} catch (Throwable $t) {
    echo 'putenv int: ', $t::class, ': ', $t->getMessage(), "\n";
}

$f = fopen('php://memory', 'r+');
fwrite($f, 'x');
rewind($f);
echo 'fread: ', fread($f, 2), "\n";
