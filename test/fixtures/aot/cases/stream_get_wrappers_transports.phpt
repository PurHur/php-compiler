--TEST--
AOT stream_get_wrappers() / stream_get_transports() — native lowering (#3329)
--FILE--
<?php
echo in_array('file', stream_get_wrappers(), true) ? '1' : '0';
echo in_array('tcp', stream_get_transports(), true) ? '1' : '0';
echo "\n";
--EXPECT--
11
