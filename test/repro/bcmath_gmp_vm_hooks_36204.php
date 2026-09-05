<?php
// repro: BcMath\Number + GMP ops via VmRuntimeSupport (#36204)
// Needs PHP_COMPILER_PROFILE=8.4 (+ PHP_COMPILER_ENABLE_GMP=1 for GMP half).
use BcMath\Number;

$a = new Number('1.5');
$b = new Number('2.5');
echo 'op+', (string) ($a + $b), ' ';
echo 'eq', ($a == new Number('1.50')) ? '1' : '0', ' ';
echo 'neg', (string) (-$a), ' ';

if (!extension_loaded('gmp') || !function_exists('gmp_init')) {
    echo "gmp-skip\n";
    exit(0);
}
$g = gmp_init(10);
$h = gmp_init(3);
echo 'g+', gmp_strval($g + $h), ' ';
echo 'g~', gmp_strval(~$g), ' ';
echo 'g-', gmp_strval(-$g), "\n";
