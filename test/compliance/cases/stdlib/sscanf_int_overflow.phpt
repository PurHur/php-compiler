--TEST--
sscanf() %d overflow saturates to PHP_INT_MAX/MIN (#9536)
--FILE--
<?php
var_export(sscanf('9223372036854775808', '%d'));
echo "\n";
var_export(sscanf('999999999999999999999', '%d'));
echo "\n";
var_export(sscanf('-9223372036854775809', '%d'));
echo "\n";
?>
--EXPECT--
array (
  0 => 9223372036854775807,
)
array (
  0 => 9223372036854775807,
)
array (
  0 => -9223372036854775808,
)
