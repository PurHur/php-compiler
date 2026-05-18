--TEST--
stdlib strlen() JIT on string literals
--FILE--
<?php
echo strlen(''), "\n";
echo strlen('abc'), "\n";
echo strlen('hello'), "\n";
--EXPECT--
0
3
5
