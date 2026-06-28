--TEST--
stdlib mb_split() — multibyte regex split (ext/mbstring/php_mbregex.c, #13367)
--FILE--
<?php
echo function_exists('mb_split') ? 'yes' : 'no', "\n";
var_export(mb_split(',', 'a,b,c'));
echo "\n";
var_export(mb_split(',', 'a,b,c', 2));
echo "\n";
var_export(mb_split(',', ''));
echo "\n";
$r = @mb_split('invalid[', 'abc');
var_export($r);
echo "\n";
--EXPECT--
yes
array (
  0 => 'a',
  1 => 'b',
  2 => 'c',
)
array (
  0 => 'a',
  1 => 'b,c',
)
array (
  0 => '',
)
false
