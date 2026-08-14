<?php
/**
 * error_get_last / error_clear_last excess argc → ArgumentCountError (#30674).
 * php-src: ext/standard/error.c
 */
foreach (['error_get_last', 'error_clear_last'] as $fn) {
    try {
        $fn(1);
        echo $fn, "_0:OK\n";
    } catch (ArgumentCountError $e) {
        echo $fn, '_0:ArgumentCountError:', $e->getMessage(), "\n";
    }
    try {
        $fn(1, 2);
        echo $fn, "_1:OK\n";
    } catch (ArgumentCountError $e) {
        echo $fn, '_1:ArgumentCountError:', $e->getMessage(), "\n";
    }
}
error_clear_last();
echo 'error_get_last_2:OK:', error_get_last() === null ? 'null' : 'non-null', "\n";
echo 'error_clear_last_2:OK:', error_clear_last() === null ? 'null' : 'non-null', "\n";
