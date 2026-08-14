<?php
/**
 * headers_sent() excess argc → ArgumentCountError (#30705).
 * php-src: ext/standard/head.c PHP_FUNCTION(headers_sent)
 */
$f = null;
$l = null;
try {
    headers_sent($f, $l, 'x');
    echo "hi:OK\n";
} catch (ArgumentCountError $e) {
    echo 'hi:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'hi:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    headers_sent($f, $l, 'x', 'y');
    echo "hi4:OK\n";
} catch (ArgumentCountError $e) {
    echo 'hi4:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'hi4:', get_class($e), ':', $e->getMessage(), "\n";
}

headers_sent();
echo "ok0:1\n";
$f2 = null;
$l2 = null;
headers_sent($f2, $l2);
echo 'ok2:', is_string($f2) && is_int($l2) ? '1' : '0', "\n";
