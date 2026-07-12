--TEST--
stdlib mb_ereg*() — multibyte POSIX regex API (ext/mbstring/php_mbregex.c, #4635)
--FILE--
<?php
declare(strict_types=1);
echo function_exists('mb_ereg') ? 'yes' : 'no', "\n";
echo function_exists('mb_eregi') ? 'yes' : 'no', "\n";
echo function_exists('mb_ereg_replace') ? 'yes' : 'no', "\n";
echo function_exists('mb_regex_encoding') ? 'yes' : 'no', "\n";
echo function_exists('mb_regex_set_options') ? 'yes' : 'no', "\n";
var_export(mb_regex_encoding());
echo "\n";
var_export(mb_ereg('^[a-z]+$', 'hello'));
echo "\n";
var_export(mb_ereg('^[a-z]+$', 'HELLO'));
echo "\n";
$regs = [];
var_export(mb_ereg('([a-z]+)([0-9]+)', 'abc123', $regs));
echo "\n";
var_export($regs);
echo "\n";
var_export(mb_eregi('HELLO', 'hello'));
echo "\n";
var_export(mb_ereg_replace('a', 'A', 'abc'));
echo "\n";
var_export(mb_ereg_replace('nomatch', 'X', 'abc'));
echo "\n";
$r = @mb_ereg('invalid[', 'abc');
var_export($r);
echo "\n";
?>
--EXPECT--
yes
yes
yes
yes
yes
'UTF-8'
true
false
true
array (
  0 => 'abc123',
  1 => 'abc',
  2 => '123',
)
true
'Abc'
'abc'
false
