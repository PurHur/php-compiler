--TEST--
Consecutive expression-position array_any/all/find with inline Array_ + arrow (#27347)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_dump(array_any([1, 2, 3], fn($v) => $v > 5));
var_dump(array_any([1, 2, 3], fn($v) => $v > 1));
var_dump(array_all([1, 2, 3], fn($v) => $v > 0));
var_dump(array_find([1, 2, 3], fn($v) => $v === 2));

var_dump(array_filter([1, 2, 3], fn($v) => $v > 5));
var_dump(array_filter([1, 2, 3], fn($v) => $v > 1));
?>
--EXPECT--
bool(false)
bool(true)
bool(true)
int(2)
array(0) {
}
array(2) {
  [1]=>
  int(2)
  [2]=>
  int(3)
}
