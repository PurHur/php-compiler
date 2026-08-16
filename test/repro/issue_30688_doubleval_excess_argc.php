<?php
/**
 * doubleval() excess argc → ArgumentCountError cites doubleval(), not floatval() (#30688).
 * php-src: ext/standard/type.c (doubleval alias of floatval)
 */
try {
    doubleval(1, 1);
    echo "dv_hi:OK\n";
} catch (ArgumentCountError $e) {
    echo 'dv_hi:ArgumentCountError:', $e->getMessage(), "\n";
}
try {
    doubleval();
    echo "dv_lo:OK\n";
} catch (ArgumentCountError $e) {
    echo 'dv_lo:ArgumentCountError:', $e->getMessage(), "\n";
}
echo 'dv_ok:', doubleval('3.5'), "\n";
try {
    floatval(1, 1);
    echo "fv_hi:OK\n";
} catch (ArgumentCountError $e) {
    echo 'fv_hi:ArgumentCountError:', $e->getMessage(), "\n";
}
