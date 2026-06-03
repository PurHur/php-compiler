--TEST--
stdlib JIT substr_count() — numeric-string offset/length (#4259)
--FILE--
<?php
echo substr_count('abababa', 'ab', '2', '4'), "\n";
--EXPECT--
2
