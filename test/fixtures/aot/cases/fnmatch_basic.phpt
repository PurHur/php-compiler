--TEST--
AOT fnmatch() pattern match (issue #3189)
--FILE--
<?php
echo fnmatch('*.txt', 'readme.txt') ? '1' : '0', "\n";
echo fnmatch('file?.txt', 'file1.txt') ? '1' : '0', "\n";
echo fnmatch('foo*', 'foo/bar', FNM_PATHNAME) ? '1' : '0', "\n";
--EXPECT--
1
1
0
