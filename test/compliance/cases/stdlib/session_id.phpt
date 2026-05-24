--TEST--
stdlib session_id() get and set (issue #1183)
--FILE--
<?php
echo session_id(), "\n";
echo session_id('abc123'), "\n";
echo session_id(), "\n";
echo session_id(''), "\n";
echo session_id(), "\n";
--EXPECT--


abc123
abc123

