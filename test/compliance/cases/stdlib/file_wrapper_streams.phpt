--TEST--
stdlib file() on php://memory and data:// wrapper streams (#13748, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);

var_export(file('php://memory'));
echo "\n";
var_export(file('data://text/plain,hi'));
echo "\n";
var_export(file("data://text/plain,a\nb", FILE_IGNORE_NEW_LINES));
echo "\n";
var_export(file("data://text/plain,a\n\nb", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
echo "\n";
var_export(@file('/nonexistent/phpc_file_wrapper_streams_99999.txt'));
echo "\n";
?>
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
array (
  0 => 'a',
  1 => 'b',
)
false
