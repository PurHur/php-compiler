--TEST--
stdlib bcadd/…(null) TypeError under strict_types JIT (#29977, re-#28992, ext/bcmath)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
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
?>
--EXPECT--
ok:bcadd:bcadd(): Argument #1 ($num1) must be of type string, null given
ok:bcsub:bcsub(): Argument #1 ($num1) must be of type string, null given
ok:bcmul:bcmul(): Argument #1 ($num1) must be of type string, null given
ok:bcdiv:bcdiv(): Argument #1 ($num1) must be of type string, null given
ok:bcmod:bcmod(): Argument #1 ($num1) must be of type string, null given
ok:bcpow:bcpow(): Argument #1 ($num) must be of type string, null given
ok:bcsqrt:bcsqrt(): Argument #1 ($num) must be of type string, null given
ok:bccomp:bccomp(): Argument #1 ($num1) must be of type string, null given
