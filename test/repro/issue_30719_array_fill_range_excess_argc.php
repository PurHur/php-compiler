<?php
/**
 * array_fill / array_fill_keys / range excess argc → ArgumentCountError (#30719).
 * php-src: ext/standard/array.c
 */
try {
    array_fill(0, 1, 'a', 4);
    echo "fill_hi:OK\n";
} catch (ArgumentCountError $e) {
    echo 'fill_hi:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'fill_hi:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    array_fill(0, 1);
    echo "fill_lo:OK\n";
} catch (ArgumentCountError $e) {
    echo 'fill_lo:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'fill_lo:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    array_fill_keys([1], 'a', 3);
    echo "keys_hi:OK\n";
} catch (ArgumentCountError $e) {
    echo 'keys_hi:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'keys_hi:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    array_fill_keys([1]);
    echo "keys_lo:OK\n";
} catch (ArgumentCountError $e) {
    echo 'keys_lo:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'keys_lo:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    range(1, 3, 1, 4);
    echo "range_hi:OK\n";
} catch (ArgumentCountError $e) {
    echo 'range_hi:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'range_hi:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    range(1);
    echo "range_lo:OK\n";
} catch (ArgumentCountError $e) {
    echo 'range_lo:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'range_lo:', get_class($e), ':', $e->getMessage(), "\n";
}

$f = array_fill(0, 1, 'a');
echo 'ok_fill:', (is_array($f) && $f[0] === 'a') ? '1' : '0', "\n";
$k = array_fill_keys([1], 'a');
echo 'ok_keys:', (is_array($k) && $k[1] === 'a') ? '1' : '0', "\n";
$r = range(1, 3);
echo 'ok_range:', (is_array($r) && $r === [1, 2, 3]) ? '1' : '0', "\n";
