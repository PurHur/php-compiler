--TEST--
stdlib mb_strlen() UTF-8 character count (VM)
--FILE--
<?php
echo mb_strlen('é', 'UTF-8'), "\n";
echo mb_strlen('hello', 'UTF-8'), "\n";
echo mb_strlen(''), "\n";
echo mb_strlen('abc'), "\n";
--EXPECT--
1
5
0
3
