--TEST--
stdlib preg_quote() NUL byte — \000 escape (ext/standard/string.c, issue #12086)
--FILE--
<?php
$quoted = preg_quote("a\0b");
echo $quoted === 'a\\000b' ? 'match' : 'mismatch';
echo "\n";
echo strlen($quoted), "\n";
--EXPECT--
match
6
