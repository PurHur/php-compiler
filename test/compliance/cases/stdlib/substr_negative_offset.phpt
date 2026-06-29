--TEST--
stdlib substr()/mb_substr() negative offset/length (#13422, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

echo substr('hello', -3), "\n";
echo substr('hello', 0, -2), "\n";
echo substr('abcdef', -4, 2), "\n";
echo mb_substr('hello', -2), "\n";
echo mb_substr('hello', 0, -2), "\n";
--EXPECT--
llo
hel
cd
lo
hel
