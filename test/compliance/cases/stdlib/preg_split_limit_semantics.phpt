--TEST--
stdlib preg_split() limit 0/1 semantics (#10545, ext/pcre/php_pcre.c)
--FILE--
<?php
$subject = 'a,b,c';
var_export(preg_split('/,/', $subject, 0));
echo "\n";
var_export(preg_split('/,/', $subject, 1));
echo "\n";
var_export(preg_split('/,/', $subject, 2));
echo "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
  2 => 'c',
)
array (
  0 => 'a,b,c',
)
array (
  0 => 'a',
  1 => 'b,c',
)
