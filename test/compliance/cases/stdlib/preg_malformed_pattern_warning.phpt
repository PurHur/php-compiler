--TEST--
stdlib preg_* malformed pattern — E_WARNING on delimiter failure (ext/pcre/php_pcre.c, issue #12083)
--FILE--
<?php
preg_match('/[', 'x');
$last = error_get_last();
echo null !== $last ? 'warn' : 'silent';
echo "\n";
echo str_contains($last['message'] ?? '', "No ending delimiter '/' found") ? 'match' : 'mismatch';
echo "\n";
preg_replace('/[', 'y', 'x');
$last = error_get_last();
echo str_contains($last['message'] ?? '', 'preg_replace():') ? 'replace' : 'no-replace';
echo "\n";
preg_split('/[', 'a,b');
$last = error_get_last();
echo str_contains($last['message'] ?? '', 'preg_split():') ? 'split' : 'no-split';
echo "\n";
preg_grep('/[', ['x']);
$last = error_get_last();
echo str_contains($last['message'] ?? '', 'preg_grep():') ? 'grep' : 'no-grep';
echo "\n";
--EXPECTF--
PHP Warning:  preg_match(): No ending delimiter '/' found in %s on line %d
PHP Warning:  preg_replace(): No ending delimiter '/' found in %s on line %d
PHP Warning:  preg_split(): No ending delimiter '/' found in %s on line %d
PHP Warning:  preg_grep(): No ending delimiter '/' found in %s on line %d
warn
match
replace
split
grep
