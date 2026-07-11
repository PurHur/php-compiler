--TEST--
stdlib ini_get_all(null, false) after var_dump(array_keys(...)) — ConstFetch args not misbound (#15931)
--FILE--
<?php
declare(strict_types=1);

$all = ini_get_all(null, true);
var_dump(array_keys($all['display_errors'] ?? []));
$flat = ini_get_all(null, false);
echo is_string($flat['display_errors'] ?? null) ? "flat string\n" : "flat not string\n";
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
flat string
