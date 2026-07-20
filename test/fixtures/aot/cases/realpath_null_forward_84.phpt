--TEST--
AOT: realpath(null) soft-null on 8.4 forward profile (#20362, ext/standard/file.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$realNull = realpath(null);
$realEmpty = realpath('');
echo 'ok:', \gettype($realNull), ':', ($realNull === $realEmpty ? 'match' : 'mismatch'), "\n";
--EXPECT--
ok:string:match
