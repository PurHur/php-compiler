--TEST--
stdlib session_name() JIT get and set (issue #1184)
--FILE--
<?php
echo session_name(), "\n";
echo session_name('APPSESS'), "\n";
echo session_name(), "\n";
--EXPECT--
PHPSESSID
PHPSESSID
APPSESS
