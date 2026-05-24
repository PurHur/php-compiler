--TEST--
stdlib session_id() JIT get and set (issue #1183)
--FILE--
<?php
echo session_id('jitid'), "\n";
echo session_id(), "\n";
--EXPECT--

jitid
