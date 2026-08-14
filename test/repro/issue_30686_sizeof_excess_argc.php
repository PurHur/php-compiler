<?php
/**
 * sizeof() excess argc → ArgumentCountError cites sizeof(), not count() (#30686).
 * php-src: ext/standard/array.c (sizeof alias of count)
 */
try {
    sizeof([1], COUNT_NORMAL, 1);
    echo "sz_hi:OK\n";
} catch (ArgumentCountError $e) {
    echo 'sz_hi:ArgumentCountError:', $e->getMessage(), "\n";
}
try {
    sizeof();
    echo "sz_lo:OK\n";
} catch (ArgumentCountError $e) {
    echo 'sz_lo:ArgumentCountError:', $e->getMessage(), "\n";
}
echo 'sz_ok:', sizeof([1, 2]), "\n";
try {
    count([1], COUNT_NORMAL, 1);
    echo "ct_hi:OK\n";
} catch (ArgumentCountError $e) {
    echo 'ct_hi:ArgumentCountError:', $e->getMessage(), "\n";
}
