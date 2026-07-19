--TEST--
stdlib bcadd/bcsub/…(null) soft-null on 8.4 (#20973, ext/bcmath/bcmath.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'bcadd' => static fn () => bcadd(null, '1'),
    'bcsub' => static fn () => bcsub(null, '1'),
    'bcmul' => static fn () => bcmul(null, '1'),
    'bcdiv' => static fn () => bcdiv(null, '1'),
    'bcmod' => static fn () => bcmod(null, '1'),
    'bcpow' => static fn () => bcpow(null, '1'),
    'bcsqrt' => static fn () => bcsqrt(null),
    'bccomp' => static fn () => bccomp(null, '1'),
    'empty' => static fn () => bcadd('', '1'),
    'sign' => static fn () => bcadd('-', '1'),
    'dot' => static fn () => bcadd('.', '1'),
] as $n => $fn) {
    try {
        echo $n, '=', $fn(), "\n";
    } catch (Throwable $e) {
        echo $n, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
try {
    bcadd('not-a-number', '1');
    echo "invalid uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
try {
    bcadd(' ', '1');
    echo "ws uncaught\n";
} catch (ValueError $e) {
    echo 'ws: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
bcadd=1
bcsub=-1
bcmul=0
bcdiv=0
bcmod=0
bcpow=0
bcsqrt=0
bccomp=-1
empty=1
sign=1
dot=1
bcmath function(): Argument is not a valid number
ws: bcmath function(): Argument is not a valid number
