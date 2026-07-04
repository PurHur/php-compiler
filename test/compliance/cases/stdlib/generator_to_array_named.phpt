--TEST--
Stdlib: generator_to_array() preserve_keys named parameter (#9633, ext/standard/array.c)
--FILE--
<?php
function gen(): Generator {
    yield 'k' => 9;
}

var_export(generator_to_array(gen(), false));
echo "\n";
var_export(generator_to_array(gen(), preserve_keys: false));
echo "\n";
var_export(generator_to_array(gen(), preserve_keys: true));
echo "\n";
--EXPECT--
array (
  0 => 9,
)
array (
  0 => 9,
)
array (
  'k' => 9,
)
