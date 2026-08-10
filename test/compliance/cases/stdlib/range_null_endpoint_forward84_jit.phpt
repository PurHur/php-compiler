--TEST--
range() null $start/$end DEP then coerce under PROFILE=8.4 (JIT) (#29348)
--ENV--
PHP_COMPILER_PROFILE=8.4 forward
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
var_export(range(null, 3));
echo "\n";
var_export(range(0, null));
echo "\n";
--EXPECT--
ERR[8192]: range(): Passing null to parameter #1 ($start) of type string|int|float is deprecated
array (
  0 => 0,
  1 => 1,
  2 => 2,
  3 => 3,
)
ERR[8192]: range(): Passing null to parameter #2 ($end) of type string|int|float is deprecated
array (
  0 => 0,
)
