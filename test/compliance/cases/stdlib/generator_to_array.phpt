--TEST--
generator_to_array() on Generator (issue #6025)
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
try {
    generator_to_array(new stdClass());
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
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
generator_to_array(): Argument #1 ($generator) must be of type Generator, stdClass given
