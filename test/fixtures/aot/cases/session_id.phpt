--TEST--
AOT session_id() get and set (issue #1183)
--FILE--
<?php
session_id('aot-sess');
echo session_id(), "\n";
echo session_id('next'), "\n";
echo session_id(), "\n";
--EXPECT--
aot-sess
aot-sess
next
