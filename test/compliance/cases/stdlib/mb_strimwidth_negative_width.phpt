--TEST--
stdlib mb_strimwidth() negative width (#13416, ext/mbstring/mbstring.c)
--FILE--
<?php
declare(strict_types=1);

$result = mb_strimwidth('abc', 0, -1);
echo $result, "\n";
echo mb_strimwidth('hello', 0, -2), "\n";
--EXPECT--
ab
hel
