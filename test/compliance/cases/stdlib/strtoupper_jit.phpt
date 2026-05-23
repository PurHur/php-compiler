--TEST--
stdlib strtoupper() JIT
--FILE--
<?php
echo strtoupper('hello'), "\n";
--EXPECT--
HELLO
