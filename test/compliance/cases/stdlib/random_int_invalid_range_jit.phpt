--TEST--
JIT: random_int() invalid range throws ValueError (#4200)
--FILE--
<?php
$min = 5;
$max = 1;
try {
    random_int($min, $max);
    echo "no_ex\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
random_int(): Argument #1 ($min) must be less than or equal to argument #2 ($max)
