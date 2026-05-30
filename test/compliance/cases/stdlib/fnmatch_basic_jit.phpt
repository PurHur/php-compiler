--TEST--
stdlib fnmatch() JIT
--FILE--
<?php
echo fnmatch('*.php', 'index.php') ? '1' : '0', "\n";
echo fnmatch('*.php', 'index.txt') ? '1' : '0', "\n";
--EXPECT--
1
0
