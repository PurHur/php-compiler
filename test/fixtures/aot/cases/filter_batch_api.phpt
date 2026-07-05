--TEST--
AOT: filter_var_array() batch validation (#3294, ext/filter/filter.c)
--FILE--
<?php
declare(strict_types=1);
var_export(filter_var_array(['email' => 'a@b.c'], ['email' => FILTER_VALIDATE_EMAIL]));
echo "\n";
var_export(filter_var_array(['a' => '1', 'b' => 'x'], ['a' => FILTER_VALIDATE_INT, 'b' => FILTER_VALIDATE_INT]));
echo "\n";
--EXPECT--
array (
  'email' => 'a@b.c',
)
array (
  'a' => 1,
  'b' => false,
)
--EXPECT_EXIT--
0
