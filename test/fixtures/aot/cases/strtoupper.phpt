--TEST--
AOT: strtoupper() ASCII string
--FILE--
<?php
echo strtoupper(''), "\n";
echo strtoupper('hello'), "\n";
echo strtoupper('MiXeD'), "\n";
--EXPECT--

HELLO
MIXED
