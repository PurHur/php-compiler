--TEST--
stdlib array_merge() PHP_INT_MAX keys reindex to list (#9534, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$a = [PHP_INT_MAX => 'a'];
$b = [-PHP_INT_MAX => 'b'];
var_export(array_merge($a, $b));
echo "\n";
var_export(array_is_list(array_merge([PHP_INT_MAX => 1], [0 => 2])));
echo "\n";
?>
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
)
true
