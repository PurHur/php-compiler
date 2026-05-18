--TEST--
stdlib mb_strlen() JIT byte length
--FILE--
<?php
echo mb_strlen(''), "\n";
echo mb_strlen('abc'), "\n";
echo mb_strlen("hello"), "\n";
--EXPECT--
0
3
5
