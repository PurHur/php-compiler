--TEST--
stdlib memory_get_usage()/memory_get_peak_usage() enum operand TypeError (#5986, php-src-strict)
--FILE--
<?php
enum E: int { case A = 1; }

try {
    memory_get_usage(E::A);
    echo "usage uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    memory_get_peak_usage(E::A);
    echo "peak uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    memory_get_usage(true, 1);
    echo "extra uncaught\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
memory_get_usage(): Argument #1 ($real_usage) must be of type bool, E given
memory_get_peak_usage(): Argument #1 ($real_usage) must be of type bool, E given
memory_get_usage() expects at most 1 argument, 2 given
