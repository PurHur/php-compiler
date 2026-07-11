--TEST--
stdlib preg_quote() JIT NUL byte — \000 escape (issue #12086)
--JIT--
--FILE--
<?php
$quoted = preg_quote("a\0b");
echo $quoted === 'a\\000b' ? 'match' : 'mismatch';
echo "\n";
echo strlen($quoted), "\n";
--EXPECT--
match
6
