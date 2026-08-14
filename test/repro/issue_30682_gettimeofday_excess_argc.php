<?php
/**
 * gettimeofday() excess argc → ArgumentCountError (#30682).
 * php-src: ext/standard/microtime.c PHP_FUNCTION(gettimeofday)
 */
try {
    gettimeofday(false, 1);
    echo "hi:OK\n";
} catch (ArgumentCountError $e) {
    echo 'hi:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'hi:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    gettimeofday(false, 1, 2);
    echo "hi3:OK\n";
} catch (ArgumentCountError $e) {
    echo 'hi3:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'hi3:', get_class($e), ':', $e->getMessage(), "\n";
}

$a = gettimeofday();
echo 'ok0:', (is_array($a) && isset($a['sec'], $a['usec'])) ? '1' : '0', "\n";
$f = gettimeofday(true);
echo 'ok1:', is_float($f) ? '1' : '0', "\n";
