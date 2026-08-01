--TEST--
range(): step larger than span throws ValueError (#26657)
--FILE--
<?php
foreach ([[0, 1, 2], [0.0, 1.0, 2.0], ['a', 'c', 5]] as $args) {
    try {
        range(...$args);
        echo "no_ex\n";
    } catch (Throwable $e) {
        echo get_class($e), "\n";
        echo $e->getMessage(), "\n";
    }
}
// Equal step/span and equal endpoints remain valid (php-src boundary uses <, not <=).
var_export(range(0, 2, 2));
echo "\n";
var_export(range(0, 0, 5));
echo "\n";
--EXPECT--
ValueError
range(): Argument #3 ($step) must not exceed the specified range
ValueError
range(): Argument #3 ($step) must not exceed the specified range
ValueError
range(): Argument #3 ($step) must not exceed the specified range
array (
  0 => 0,
  1 => 2,
)
array (
  0 => 0,
)
