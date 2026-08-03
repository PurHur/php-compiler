--TEST--
stdlib array_filter() consecutive expression-position Closure callbacks (#27344, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$a = [1, 2, 3];
$b = [1, 2, 3];
var_dump(array_filter($a, static fn ($v): bool => $v > 5));
var_dump(array_filter($b, static fn ($v): bool => $v > 1));

function gt5($v) { return $v > 5; }
function gt1($v) { return $v > 1; }
$c = [1, 2, 3];
$d = [1, 2, 3];
var_dump(array_filter($c, gt5(...)));
var_dump(array_filter($d, gt1(...)));
?>
--EXPECT--
array(0) {
}
array(2) {
  [1]=>
  int(2)
  [2]=>
  int(3)
}
array(0) {
}
array(2) {
  [1]=>
  int(2)
  [2]=>
  int(3)
}
