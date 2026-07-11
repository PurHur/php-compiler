--TEST--
stdlib stripos() JIT binary-safe case-insensitive match (#14000)
--FILE--
<?php
echo stripos("aaW\x00oXX", "w\x00o"), "\n";
echo stripos('Hello World', 'world'), "\n";
--EXPECT--
2
6
