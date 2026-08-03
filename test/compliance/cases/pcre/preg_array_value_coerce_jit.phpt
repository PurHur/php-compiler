--TEST--
JIT: pcre preg_grep/replace/filter coerce non-string array values (#27164, ext/pcre/php_pcre.c)
--FILE--
<?php
var_export(preg_grep('/^1/', [12, '13', 14.5]));
echo "\n";
var_export(preg_replace('/1/', 'X', [12, '13']));
echo "\n";
var_export(preg_filter('/a/', 'X', ['a', 'b', 'aa', 5]));
echo "\n";
--EXPECT--
array (
  0 => 12,
  1 => '13',
  2 => 14.5,
)
array (
  0 => 'X2',
  1 => 'X3',
)
array (
  0 => 'X',
  2 => 'XX',
)
