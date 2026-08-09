<?php
/** Repro #29506 — fgets($stream, null) ≡ omit length (php-src file.stub.php). */
error_reporting(E_ALL);

$f = fopen('php://memory', 'r');
try {
    var_export(fgets($f, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
} finally {
    fclose($f);
}

$f2 = fopen('php://memory', 'r+');
fwrite($f2, "abc\n");
rewind($f2);
try {
    var_export(fgets($f2, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
} finally {
    fclose($f2);
}

try {
    $f3 = fopen('php://memory', 'r');
    var_export(fgets($f3, 0));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
