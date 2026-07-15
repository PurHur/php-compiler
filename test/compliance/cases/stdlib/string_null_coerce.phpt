--TEST--
stdlib Z_PARAM_STR/LONG null coercion on 8.4 forward profile (#19161, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo var_export(dirname(null), true), "\n";
echo var_export(explode(',', null), true), "\n";
echo var_export(ord(null), true), "\n";
echo var_export(chr(null), true), "\n";
echo var_export(parse_url(null), true), "\n";
?>
--EXPECT--
''
array (
  0 => '',
)
0
'' . "\0" . ''
array (
  'path' => '',
)
