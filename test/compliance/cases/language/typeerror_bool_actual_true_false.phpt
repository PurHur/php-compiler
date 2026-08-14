--TEST--
TypeError actual bool prints true/false not bool on PROFILE=8.4 (#29097 / #31160, GH-8385)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
function need_array(array $x) {}
foreach ([
  'count_false' => fn() => count(false),
  'count_true' => fn() => count(true),
  'need_array_false' => fn() => need_array(false),
  'count_null' => fn() => count(null),
] as $name => $fn) {
  try {
    $fn();
  } catch (Throwable $e) {
    echo $name, ':', $e->getMessage(), "\n";
  }
}
?>
--EXPECTF--
count_false:count(): Argument #1 ($value) must be of type Countable|array, false given
count_true:count(): Argument #1 ($value) must be of type Countable|array, true given
need_array_false:need_array(): Argument #1 ($x) must be of type array, false given, called in %s on line %d
count_null:count(): Argument #1 ($value) must be of type Countable|array, null given
