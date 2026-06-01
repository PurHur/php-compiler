--TEST--
stdlib strspn()/strcspn() optional offset and length (#3734)
--FILE--
<?php
var_dump(strspn('abc', 'a', 1, 1));
var_dump(strcspn('abc', 'a', 1, 1));
var_dump(strspn('abc123', 'abc', 2));
var_dump(strcspn('abc123', '123', 0, 3));
echo strspn('abc123', 'abc'), "\n";
echo strcspn('abc123', '123'), "\n";
--EXPECT--
int(0)
int(1)
int(1)
int(3)
3
3
