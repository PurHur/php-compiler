--TEST--
pcre preg_* /u modifier rejects invalid UTF-8 subject (#17503, ext/pcre/php_pcre.c)
--FILE--
<?php
$bad = "\xFF";

preg_match('//u', $bad, $m, 0, 0);
echo preg_match('//u', $bad) === false ? 'match_fail' : 'match_ok', "\n";
echo preg_last_error(), "\n";

preg_split('//u', $bad);
echo preg_split('//u', $bad) === false ? 'split_fail' : 'split_ok', "\n";
echo preg_last_error(), "\n";

preg_replace('//u', 'x', $bad);
echo preg_replace('//u', 'x', $bad) === null ? 'replace_null' : 'replace_ok', "\n";
echo preg_last_error(), "\n";

var_export(preg_grep('//u', ['a', $bad]));
echo "\n";
echo preg_last_error(), "\n";
--EXPECT--
match_fail
4
split_fail
4
replace_null
4
array (
  0 => 'a',
)
4
