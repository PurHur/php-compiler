--TEST--
Stdlib: sprintf()/printf() enum case operands (#5580, ext/standard/sprintf.c)
--FILE--
<?php
enum E: int { case A = 1; }
try {
    printf('%s', E::A);
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
echo 'sprintf %d: ', @sprintf('%d', E::A), "\n";
--EXPECT--
Error: Object of class E could not be converted to string
sprintf %d: 1
