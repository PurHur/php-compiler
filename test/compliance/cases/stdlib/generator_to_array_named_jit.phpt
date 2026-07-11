--TEST--
Stdlib: generator_to_array() preserve_keys named parameter JIT (#9633)
--FILE--
<?php
function gen(): Generator {
    yield 'k' => 9;
}

var_export(generator_to_array(gen(), preserve_keys: true));
echo "\n";
--EXPECT--
array (
  'k' => 9,
)
