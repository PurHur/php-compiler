--TEST--
stdlib filter_has_var() / filter_input_array() / filter_var_array() batch API (#3294, ext/filter/filter.c)
--GET--
id=42&bad=x
--FILE--
<?php
declare(strict_types=1);

var_export(filter_has_var(INPUT_GET, 'id'));
echo "\n";
var_export(filter_has_var(INPUT_GET, 'missing'));
echo "\n";
var_export(filter_input_array(INPUT_GET, ['id' => FILTER_VALIDATE_INT]));
echo "\n";
var_export(filter_var_array(['email' => 'a@b.c'], ['email' => FILTER_VALIDATE_EMAIL]));
echo "\n";
var_export(filter_var_array(['a' => '1', 'b' => 'x'], ['a' => FILTER_VALIDATE_INT, 'b' => FILTER_VALIDATE_INT]));
echo "\n";
?>
--EXPECT--
true
false
array (
  'id' => 42,
)
array (
  'email' => 'a@b.c',
)
array (
  'a' => 1,
  'b' => false,
)
