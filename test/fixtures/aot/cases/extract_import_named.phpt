--TEST--
AOT: extract() imports named locals (#27520, ext/standard/array.c)
--FILE--
<?php
$arr = ['hello' => 'world', 'n' => 7];
extract($arr);
echo $hello, ':', $n, "\n";
--EXPECT--
world:7
