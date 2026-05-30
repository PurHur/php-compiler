--TEST--
stdlib fnmatch() glob pattern match
--FILE--
<?php
echo fnmatch('*.txt', 'readme.txt') ? '1' : '0', "\n";
echo fnmatch('*.txt', 'readme.php') ? '1' : '0', "\n";
echo fnmatch('file?.txt', 'file1.txt') ? '1' : '0', "\n";
echo fnmatch('file?.txt', 'file12.txt') ? '1' : '0', "\n";
echo fnmatch('foo*', 'foo/bar') ? '1' : '0', "\n";
echo fnmatch('foo*', 'foo/bar', FNM_PATHNAME) ? '1' : '0', "\n";
echo fnmatch('FILE?.TXT', 'file1.txt', FNM_CASEFOLD) ? '1' : '0', "\n";
--EXPECT--
1
0
1
0
1
0
1
