<?php
/**
 * #29977 — classic bcmath null under declare(strict_types=1) → TypeError (Zend 8.4).
 * php-src: ext/bcmath/bcmath.stub.php (string $num1/$num); soft-null only without strict (#20973).
 */
declare(strict_types=1);
error_reporting(E_ALL);
try {
    bcadd(null, '1');
    echo "bad:bcadd\n";
} catch (TypeError $e) {
    echo 'ok:bcadd:', $e->getMessage(), "\n";
}
try {
    bcsub(null, '1');
    echo "bad:bcsub\n";
} catch (TypeError $e) {
    echo 'ok:bcsub:', $e->getMessage(), "\n";
}
try {
    bcmul(null, '1');
    echo "bad:bcmul\n";
} catch (TypeError $e) {
    echo 'ok:bcmul:', $e->getMessage(), "\n";
}
try {
    bcdiv(null, '1');
    echo "bad:bcdiv\n";
} catch (TypeError $e) {
    echo 'ok:bcdiv:', $e->getMessage(), "\n";
}
try {
    bcmod(null, '1');
    echo "bad:bcmod\n";
} catch (TypeError $e) {
    echo 'ok:bcmod:', $e->getMessage(), "\n";
}
try {
    bcpow(null, '1');
    echo "bad:bcpow\n";
} catch (TypeError $e) {
    echo 'ok:bcpow:', $e->getMessage(), "\n";
}
try {
    bcsqrt(null);
    echo "bad:bcsqrt\n";
} catch (TypeError $e) {
    echo 'ok:bcsqrt:', $e->getMessage(), "\n";
}
try {
    bccomp(null, '1');
    echo "bad:bccomp\n";
} catch (TypeError $e) {
    echo 'ok:bccomp:', $e->getMessage(), "\n";
}
