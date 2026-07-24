--TEST--
PHP_ROUND_CEILING/FLOOR/TOWARD_ZERO/AWAY_FROM_ZERO withheld on PROFILE=8.2 (#22785)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
foreach ([
    'PHP_ROUND_HALF_UP',
    'PHP_ROUND_HALF_DOWN',
    'PHP_ROUND_HALF_EVEN',
    'PHP_ROUND_HALF_ODD',
    'PHP_ROUND_CEILING',
    'PHP_ROUND_FLOOR',
    'PHP_ROUND_TOWARD_ZERO',
    'PHP_ROUND_AWAY_FROM_ZERO',
] as $c) {
    echo $c, '=', defined($c) ? '1' : '0', "\n";
}
try {
    $mode = PHP_ROUND_CEILING;
    echo 'use=', round(1.1, 0, $mode), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
?>
--EXPECT--
PHP_ROUND_HALF_UP=1
PHP_ROUND_HALF_DOWN=1
PHP_ROUND_HALF_EVEN=1
PHP_ROUND_HALF_ODD=1
PHP_ROUND_CEILING=0
PHP_ROUND_FLOOR=0
PHP_ROUND_TOWARD_ZERO=0
PHP_ROUND_AWAY_FROM_ZERO=0
Error
