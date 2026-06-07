--TEST--
stdlib var_export() arrays and scalars (#5190)
--FILE--
<?php
var_export(['a' => 1, 'b' => 2]);
echo "\n";
var_export(42);
echo "\n";
echo var_export(fdiv(0.0, 0.0), true), "\n";
echo var_export(INF, true), "\n";
echo var_export('hi', true), "\n";
--EXPECT--
array (
  'a' => 1,
  'b' => 2,
)
42
NAN
INF
'hi'
