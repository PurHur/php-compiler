--TEST--
stdlib array_walk() string user-function callback (#13319, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

function double_it(mixed &$value, mixed $key): void
{
    $value = ((int) $value) * 2;
}

$arr = ['x' => 5, 'y' => 10];
array_walk($arr, 'double_it');
var_export($arr);
echo "\n";
--EXPECT--
array (
  'x' => 10,
  'y' => 20,
)
