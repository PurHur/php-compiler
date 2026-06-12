--TEST--
AOT fnmatch() FNM_* flags (#4400, ext/standard/file.c)
--FILE--
<?php
echo fnmatch('*.TXT', 'a.txt', FNM_CASEFOLD) ? '1' : '0', "\n";
echo fnmatch('*/b', 'a/b', FNM_PATHNAME) ? '1' : '0', "\n";
echo fnmatch('a\\*b', 'a*b') ? '1' : '0', "\n";
echo fnmatch('a\\*b', 'a*b', FNM_NOESCAPE) ? '1' : '0', "\n";
--EXPECT--
1
1
1
0
