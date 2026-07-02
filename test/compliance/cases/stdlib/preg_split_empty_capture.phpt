--TEST--
stdlib preg_split() zero-width / empty-capture patterns (#14902, ext/pcre/php_pcre.c)
--FILE--
<?php
var_export(preg_split('/()/', 'ab'));
echo "\n";
var_export(preg_split('/a*/', 'baa'));
echo "\n";
--EXPECT--
array (
  0 => '',
  1 => 'a',
  2 => 'b',
  3 => '',
)
array (
  0 => '',
  1 => 'b',
  2 => '',
  3 => '',
)
