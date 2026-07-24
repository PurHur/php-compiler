--TEST--
filter_var() FILTER_CALLBACK Closure/string/invalid (#22852)
--FILE--
<?php
declare(strict_types=1);

$r = filter_var('abc', FILTER_CALLBACK, ['options' => function ($v) {
    return strtoupper($v);
}]);
var_export($r);
echo "\n";

$r = filter_var('xyz', FILTER_CALLBACK, ['options' => 'strtoupper']);
var_export($r);
echo "\n";

try {
    filter_var('x', FILTER_CALLBACK, ['options' => null]);
    echo "null-callback OK\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$arr = ['a', 'b'];
$cb = static function ($v) {
    return $v.'!';
};
$r = filter_var($arr, FILTER_CALLBACK, ['options' => $cb]);
var_export($r);
echo "\n";

$r = filter_var('a', FILTER_CALLBACK, [
    'flags' => FILTER_FORCE_ARRAY,
    'options' => 'strtoupper',
]);
var_export($r);
echo "\n";

$cb2 = 'strtoupper';
$args = ['a' => ['filter' => FILTER_CALLBACK, 'options' => $cb2]];
var_export(filter_var_array(['a' => 'hi'], $args));
echo "\n";
--EXPECT--
'ABC'
'XYZ'
filter_var(): Option must be a valid callback
array (
  0 => 'a!',
  1 => 'b!',
)
array (
  0 => 'A',
)
array (
  'a' => 'HI',
)
