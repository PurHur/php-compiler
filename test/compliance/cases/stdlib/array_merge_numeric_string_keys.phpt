--TEST--
stdlib array_merge() — numeric-string keys reindex (ext/standard/array.c #3607)
--FILE--
<?php
$m = array_merge(['0' => 'a'], ['0' => 'b']);
var_dump($m);
echo count($m), "\n";
echo $m[0], "\n";
echo $m[1], "\n";
$a = array_merge([0 => 'a'], [0 => 'b']);
var_dump($a);
--EXPECT--
array(2) {
  [0]=>
  string(1) "a"
  [1]=>
  string(1) "b"
}
2
a
b
array(2) {
  [0]=>
  string(1) "a"
  [1]=>
  string(1) "b"
}
