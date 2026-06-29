--TEST--
stdlib file() on php://memory and data:// wrapper streams (#13748)
--FILE--
<?php
declare(strict_types=1);

var_export(file('php://memory'));
echo "\n";
var_export(file('data://text/plain,hi'));
echo "\n";
var_export(file("data://text/plain,a\nb", FILE_IGNORE_NEW_LINES));
echo "\n";
--EXPECT--
array (
)
array (
  0 => 'hi',
)
array (
  0 => 'a',
  1 => 'b',
)
