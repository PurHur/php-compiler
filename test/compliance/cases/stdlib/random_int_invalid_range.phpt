--TEST--
random_int(): $min > $max throws ValueError (#4200)
--FILE--
<?php
try {
    random_int(5, 1);
    echo "no_ex\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
random_int(): Argument #1 ($min) must be less than or equal to argument #2 ($max)
