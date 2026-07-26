--TEST--
range named start/end/step arguments (JIT, issue #23242)
--FILE--
<?php
var_export(range(start: 1, end: 3));
echo PHP_EOL;
var_export(range(start: 1, end: 5, step: 2));
echo PHP_EOL;
--EXPECT--
array (
  0 => 1,
  1 => 2,
  2 => 3,
)
array (
  0 => 1,
  1 => 3,
  2 => 5,
)
