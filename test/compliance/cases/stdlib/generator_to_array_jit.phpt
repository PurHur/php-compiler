--TEST--
stdlib generator_to_array() JIT/AOT (issue #6025)
--FILE--
<?php
function gen(): Generator {
    yield 'a' => 1;
    yield 'b' => 2;
}
var_export(function_exists('generator_to_array'));
echo "\n";
var_export(generator_to_array(gen()));
echo "\n";
var_export(generator_to_array(gen(), true));
echo "\n";
--EXPECT--
true
array (
  0 => 1,
  1 => 2,
)
array (
  'a' => 1,
  'b' => 2,
)
