--TEST--
stdlib preg_match() named capture groups in $matches (#11216, ext/pcre/php_pcre.c)
--FILE--
<?php
preg_match('/(?<n>a)/', 'a', $m);
var_export(array_key_exists('n', $m));
echo "\n";
var_export($m);
echo "\n";
preg_match('/(?<n>a)/', 'a', $m, PREG_UNMATCHED_AS_NULL);
var_export($m);
echo "\n";
preg_match_all('/(?<a>\w)(?<b>\w)/', 'xy', $all, PREG_SET_ORDER);
var_export($all);
--EXPECT--
true
array (
  0 => 'a',
  'n' => 'a',
  1 => 'a',
)
array (
  0 => 'a',
  'n' => 'a',
  1 => 'a',
)
array (
  0 => 
  array (
    0 => 'x',
    'a' => 'x',
    1 => 'x',
    'b' => 'y',
    2 => 'y',
  ),
)
