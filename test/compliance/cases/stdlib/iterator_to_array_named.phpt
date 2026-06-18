--TEST--
Stdlib: iterator_to_array() preserve_keys named parameter (#9631, ext/spl/iterator.c)
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
