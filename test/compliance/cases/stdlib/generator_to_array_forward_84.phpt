--TEST--
stdlib generator_to_array() — PHP 8.4 forward profile (#18084, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
function gen(): Generator {
    yield 'a' => 1;
    yield 'b' => 2;
}

echo 'exists=', function_exists('generator_to_array') ? 'yes' : 'no', "\n";
var_export(generator_to_array(gen()));
echo "\n";
var_export(generator_to_array(gen(), true));
--EXPECT--
exists=yes
array (
  0 => 1,
  1 => 2,
)
array (
  'a' => 1,
  'b' => 2,
)
