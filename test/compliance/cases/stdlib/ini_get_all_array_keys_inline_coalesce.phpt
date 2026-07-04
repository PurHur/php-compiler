--TEST--
stdlib array_keys(ini_get_all()[key] ?? []) inline null-coalesce call arg (#16127, re-#15946)
--FILE--
<?php
declare(strict_types=1);

var_dump(array_keys(ini_get_all(null, true)['display_errors'] ?? []));

$a = ['k' => ['x' => 1]];
var_dump(array_keys($a['k'] ?? []));
?>
--EXPECT--
array(3) {
  [0]=>
  string(12) "global_value"
  [1]=>
  string(11) "local_value"
  [2]=>
  string(6) "access"
}
array(1) {
  [0]=>
  string(1) "x"
}
