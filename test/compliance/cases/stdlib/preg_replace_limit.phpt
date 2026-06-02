--TEST--
stdlib preg_replace() limit parameter (issue #3605, ext/pcre/php_pcre.c)
--FILE--
<?php
echo preg_replace('/a/', 'b', 'aaa', 2), "\n";
echo preg_replace('/a/', 'b', 'aaa'), "\n";
echo preg_replace('/a/', 'b', 'aaa', -1), "\n";
--EXPECT--
bba
bbb
bbb
