--TEST--
stdlib preg_match_all() JIT match count
--FILE--
<?php
echo preg_match_all('/\w+/', 'one two three'), "\n";
echo preg_match_all('/\w+/', ''), "\n";
--EXPECT--
3
0
