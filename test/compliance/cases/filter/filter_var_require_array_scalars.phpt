--TEST--
filter_var() FILTER_REQUIRE_ARRAY filters list elements (#29047, ext/filter/filter.c)
--FILE--
<?php
declare(strict_types=1);

var_export(filter_var(['1', '2', 'x'], FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY));
echo "\n";
var_export(filter_var('1', FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY));
echo "\n";
// Prefer $opts variable — inline array+Closure is a separate VM materialization gap.
$cb = static function ($v) {
    return is_numeric($v) ? (int) $v : false;
};
$opts = [
    'flags' => FILTER_REQUIRE_ARRAY,
    'options' => $cb,
];
var_export(filter_var(['1', '2', 'x'], FILTER_CALLBACK, $opts));
echo "\n";
var_export(filter_var('1', FILTER_CALLBACK, $opts));
echo "\n";
--EXPECT--
array (
  0 => 1,
  1 => 2,
  2 => false,
)
false
array (
  0 => 1,
  1 => 2,
  2 => false,
)
false
