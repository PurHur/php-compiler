--TEST--
stdlib generator_to_array() — VM on PHP 8.4 forward profile (#19131, ext/standard/generator.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
function gen(): Generator {
    yield 'a';
    yield 'b';
}
var_export(generator_to_array(gen()));
echo "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
)
