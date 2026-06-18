--TEST--
Stdlib: iterator_to_array() preserve the preserve_keys named parameter JIT (#9631)
--FILE--
<?php
function gen(): Generator {
    yield 'a' => 1;
    yield 'b' => 2;
}

var_export(iterator_to_array(gen(), true));
echo "\n";
var_export(iterator_to_array(gen(), preserve_keys: false));
echo "\n";
var_export(iterator_to_array(gen(), preserve_keys: true));
echo "\n";
--EXPECT--
array (
  'a' => 1,
  'b' => 2,
)
array (
  0 => 1,
  1 => 2,
)
array (
  'a' => 1,
  'b' => 2,
)
