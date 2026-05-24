--TEST--
AOT session_name() get and set (issue #1184)
--FILE--
<?php
echo session_name(), "\n";
echo session_name('aot-name'), "\n";
echo session_name(), "\n";
echo session_name('next'), "\n";
echo session_name(), "\n";
--EXPECT--
PHPSESSID
PHPSESSID
aot-name
aot-name
next
