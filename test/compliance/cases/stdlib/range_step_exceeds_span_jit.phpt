--TEST--
range(): step larger than span throws ValueError under JIT int path (#26657)
--FILE--
<?php
try {
    range(0, 1, 2);
    echo "no_ex\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
var_export(range(0, 2, 2));
echo "\n";
--EXPECT--
ValueError
range(): Argument #3 ($step) must not exceed the specified range
array (
  0 => 0,
  1 => 2,
)
